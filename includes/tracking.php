<?php
/**
 * includes/tracking.php - read helpers for the live location feed.
 *
 * DISPLAY-ONLY. Everything here reads `location_pings`, which no audit code
 * ever touches. Guarded against a double require.
 *
 * Loaded by: admin/02-employees/api/live-track.php,
 *            admin/components/mapbox/api/pings.php,
 *            and anywhere a route map wants the real trail.
 */

declare(strict_types=1);

if (defined('TRACK_TRACKING_LOADED')) {
    return;
}
define('TRACK_TRACKING_LOADED', true);

/**
 * The attendance row for one employee on one date (own light query so this
 * file stays self-contained - does not depend on the admin _repo.php).
 */
function tracking_attendance(PDO $pdo, int $employeeId, string $date): ?array
{
    $st = $pdo->prepare(
        'SELECT * FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1'
    );
    $st->execute([$employeeId, $date]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * All GPS pings for an employee on a date, oldest first. Optionally only the
 * ones after $sinceIso (for the live modal's incremental polling).
 *
 * @return list<array{lat:float,lng:float,accuracy_m:?float,speed_kmh:?float,recorded_at:string}>
 */
function tracking_pings(PDO $pdo, int $employeeId, string $date, ?string $sinceIso = null): array
{
    $params = [$employeeId, $date . ' 00:00:00', $date . ' 23:59:59'];
    $sinceSql = '';
    if ($sinceIso !== null && $sinceIso !== '') {
        $ts = strtotime($sinceIso);
        if ($ts !== false) {
            $sinceSql = ' AND recorded_at > ?';
            $params[] = date('Y-m-d H:i:s', $ts);
        }
    }

    $st = $pdo->prepare(
        "SELECT lat, lng, accuracy_m, speed_kmh, recorded_at
           FROM location_pings
          WHERE employee_id = ?
            AND recorded_at BETWEEN ? AND ?" . $sinceSql . "
       ORDER BY recorded_at ASC, id ASC"
    );
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'lat'         => (float) $r['lat'],
            'lng'         => (float) $r['lng'],
            'accuracy_m'  => $r['accuracy_m'] !== null ? (float) $r['accuracy_m'] : null,
            'speed_kmh'   => $r['speed_kmh'] !== null ? (float) $r['speed_kmh'] : null,
            'recorded_at' => (new DateTimeImmutable($r['recorded_at']))->format('c'),
        ];
    }
    return $out;
}

/**
 * The state of one employee's tracking for a date, for the "Live Track"
 * modal. States:
 *   tracking_off  - the kill switch is off
 *   web_user      - no bound device (uses the web field app, cannot be tracked)
 *   not_started   - has a device, but has not checked in on this date
 *   live          - checked in, not checked out (bike is moving / could be)
 *   checked_out   - the day is closed; the path is final
 *
 * @return array{state:string, checked_in_at:?string, checked_out_at:?string,
 *   last_ping_at:?string, speed_kmh:?float, point_count:int}
 */
function tracking_state(PDO $pdo, array $employee, string $date): array
{
    $base = [
        'state'          => 'not_started',
        'checked_in_at'  => null,
        'checked_out_at' => null,
        'last_ping_at'   => null,
        'speed_kmh'      => null,
        'point_count'    => 0,
    ];

    if (!live_tracking_enabled()) {
        $base['state'] = 'tracking_off';
        return $base;
    }
    if (empty($employee['device_id'])) {
        $base['state'] = 'web_user';
        return $base;
    }

    $att = tracking_attendance($pdo, (int) $employee['id'], $date);
    if ($att === null) {
        $base['state'] = 'not_started';
        return $base;
    }

    $base['checked_in_at']  = $att['check_in_at']  ? (new DateTimeImmutable($att['check_in_at']))->format('c') : null;
    $base['checked_out_at'] = $att['check_out_at'] ? (new DateTimeImmutable($att['check_out_at']))->format('c') : null;
    $base['state'] = !empty($att['check_out_at']) ? 'checked_out' : 'live';

    $last = $pdo->prepare(
        'SELECT recorded_at, speed_kmh FROM location_pings
          WHERE attendance_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1'
    );
    $last->execute([(int) $att['id']]);
    if ($row = $last->fetch()) {
        $base['last_ping_at'] = (new DateTimeImmutable($row['recorded_at']))->format('c');
        $base['speed_kmh']    = $row['speed_kmh'] !== null ? (float) $row['speed_kmh'] : null;
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM location_pings WHERE attendance_id = ?');
    $cnt->execute([(int) $att['id']]);
    $base['point_count'] = (int) $cnt->fetchColumn();

    return $base;
}

/**
 * The day's checkpoints (check-in -> visits -> check-out) in the same
 * {kind,label,at,lat,lng,no} shape the route maps already use. Its own light
 * queries so this file needs nothing from the admin _repo.php.
 */
function tracking_checkpoints(PDO $pdo, int $employeeId, string $date): array
{
    $att = tracking_attendance($pdo, $employeeId, $date);
    if ($att === null || empty($att['check_in_at'])) {
        return [];
    }

    $pts = [[
        'kind'  => 'checkin',
        'label' => 'Check-in',
        'at'    => (new DateTimeImmutable($att['check_in_at']))->format('c'),
        'lat'   => (float) $att['check_in_lat'],
        'lng'   => (float) $att['check_in_lng'],
        'no'    => null,
    ]];

    $vs = $pdo->prepare(
        'SELECT shop_name, arrived_at, lat, lng, seq
           FROM visits WHERE attendance_id = ? ORDER BY seq ASC'
    );
    $vs->execute([(int) $att['id']]);
    foreach ($vs->fetchAll() as $i => $v) {
        $pts[] = [
            'kind'  => 'visit',
            'label' => $v['shop_name'],
            'at'    => (new DateTimeImmutable($v['arrived_at']))->format('c'),
            'lat'   => (float) $v['lat'],
            'lng'   => (float) $v['lng'],
            'no'    => $i + 1,
        ];
    }

    if (!empty($att['check_out_at']) && $att['check_out_lat'] !== null) {
        $pts[] = [
            'kind'  => 'checkout',
            'label' => 'Check-out',
            'at'    => (new DateTimeImmutable($att['check_out_at']))->format('c'),
            'lat'   => (float) $att['check_out_lat'],
            'lng'   => (float) $att['check_out_lng'],
            'no'    => null,
        ];
    }

    return $pts;
}
