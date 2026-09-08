<?php
/**
 * field/attendance/_repo.php
 *
 * Data access for the Employee's own attendance overview (Day / Month /
 * All time - same concept as the admin's Employee Detail Overview tab,
 * admin/02-employees/_view_overview.php, but scoped to "myself" and with
 * no cross-employee admin controls). This tab is READ-ONLY - the actual
 * check-in/check-out actions live in field/checkinout/. Pure functions
 * over $pdo - no output. Self-contained by design, no requiring admin
 * files.
 */

declare(strict_types=1);

/** "-" placeholder for an empty value - same convention as the admin side. */
function fa_dash(): string
{
    return '-';
}

/** One attendance row (+ visit_count) for one day, or null. */
function field_attendance_day(PDO $pdo, int $employeeId, string $date): ?array
{
    $st = $pdo->prepare(
        "SELECT a.*,
                (SELECT COUNT(*) FROM visits v WHERE v.attendance_id = a.id) AS visit_count
           FROM attendance a
          WHERE a.employee_id = ? AND a.work_date = ?
          LIMIT 1"
    );
    $st->execute([$employeeId, $date]);
    $row = $st->fetch();
    return $row ?: null;
}

/** All visits for one attendance day, in visit order, with a photo if any. */
function field_day_visits(PDO $pdo, int $attendanceId): array
{
    $st = $pdo->prepare(
        "SELECT v.*,
                (SELECT p.stored_path FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit' ORDER BY p.id LIMIT 1) AS photo_path
           FROM visits v
          WHERE v.attendance_id = ?
          ORDER BY v.seq ASC, v.arrived_at ASC"
    );
    $st->execute([$attendanceId]);
    return $st->fetchAll();
}

/**
 * "Day" mode statement - a compact stat list for one date, same shape as
 * the admin's employee_statement().
 */
function field_statement(PDO $pdo, int $employeeId, string $date, string $nowTs): array
{
    $att = field_attendance_day($pdo, $employeeId, $date);

    $ci = $att ? new DateTimeImmutable($att['check_in_at']) : null;
    $co = ($att && $att['check_out_at']) ? new DateTimeImmutable($att['check_out_at']) : null;

    $activeSecs = null;
    if ($ci) {
        $end = $co ?: new DateTimeImmutable($nowTs);
        $activeSecs = max(0, $end->getTimestamp() - $ci->getTimestamp());
    }

    return [
        'has_attendance'   => (bool) $att,
        'status'           => $att['status'] ?? null,
        'visits'           => $att ? (int) $att['visit_count'] : 0,
        'km'               => $att ? (float) $att['road_km'] : 0.0,
        'shop_secs'        => $att ? (int) $att['shop_seconds'] : 0,
        'road_secs'        => $att ? (int) $att['road_seconds'] : 0,
        'active_secs'      => $activeSecs,
        'first_check_in'   => $ci,
        'last_check_out'   => $co,
        'check_in_km'      => $att && $att['check_in_odometer_km'] !== null ? (float) $att['check_in_odometer_km'] : null,
        'check_out_km'     => $att && $att['check_out_odometer_km'] !== null ? (float) $att['check_out_odometer_km'] : null,
    ];
}

/**
 * "Day" mode timeline - check-in row, one row per visit, check-out row (if
 * any), ordered by time. Same shape as the admin's employee_timeline().
 */
function field_timeline(PDO $pdo, int $employeeId, string $date): array
{
    $att = field_attendance_day($pdo, $employeeId, $date);
    if (!$att) {
        return [];
    }

    $rows = [];
    $rows[] = [
        'kind'     => 'checkin',
        'at'       => new DateTimeImmutable($att['check_in_at']),
        'label'    => 'Check-in',
        'lat'      => (float) $att['check_in_lat'],
        'lng'      => (float) $att['check_in_lng'],
        'accuracy' => $att['check_in_accuracy_m'] !== null ? (float) $att['check_in_accuracy_m'] : null,
        'remark'   => 'Day started',
    ];

    $ordinals = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];
    foreach (field_day_visits($pdo, (int) $att['id']) as $i => $v) {
        $ord  = $ordinals[$i] ?? (($i + 1) . 'th');
        $left = $v['left_at'] ? new DateTimeImmutable($v['left_at']) : null;
        $rows[] = [
            'kind'       => 'visit',
            'visit_no'   => $i + 1,
            'at'         => new DateTimeImmutable($v['arrived_at']),
            'label'      => $v['shop_name'],
            'area'       => $v['area_name'],
            'lat'        => (float) $v['lat'],
            'lng'        => (float) $v['lng'],
            'accuracy'   => $v['accuracy_m'] !== null ? (float) $v['accuracy_m'] : null,
            'remark'     => $ord . ' visit',
            'left_at'    => $left,
            'dwell_secs' => $v['dwell_seconds'] !== null ? (int) $v['dwell_seconds'] : null,
            'photo_path' => $v['photo_path'] ?? null,
        ];
    }

    if ($att['check_out_at']) {
        $rows[] = [
            'kind'     => 'checkout',
            'at'       => new DateTimeImmutable($att['check_out_at']),
            'label'    => 'Check-out',
            'lat'      => $att['check_out_lat'] !== null ? (float) $att['check_out_lat'] : null,
            'lng'      => $att['check_out_lng'] !== null ? (float) $att['check_out_lng'] : null,
            'accuracy' => $att['check_out_accuracy_m'] !== null ? (float) $att['check_out_accuracy_m'] : null,
            'remark'   => 'Day ended',
        ];
    }

    usort($rows, static fn($a, $b) => $a['at'] <=> $b['at']);
    return $rows;
}

/**
 * "Month" / "All time" mode totals - same shape as the admin's
 * employee_period_totals(). $from/$to null means all time.
 */
function field_period_totals(PDO $pdo, int $employeeId, ?string $from, ?string $to): array
{
    $where  = 'employee_id = ?';
    $params = [$employeeId];
    if ($from !== null && $to !== null) {
        $where .= ' AND work_date BETWEEN ? AND ?';
        $params[] = $from;
        $params[] = $to;
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) AS days,
                SUM(status = 'incomplete') AS incomplete,
                COALESCE(SUM(road_km), 0) AS road_km,
                COALESCE(SUM(shop_seconds), 0) AS shop_secs,
                COALESCE(SUM(road_seconds), 0) AS road_secs,
                COALESCE(SUM(total_seconds), 0) AS total_secs
           FROM attendance
          WHERE $where"
    );
    $st->execute($params);
    $a = $st->fetch();

    $visitWhere  = 'v.employee_id = ?';
    $visitParams = [$employeeId];
    if ($from !== null && $to !== null) {
        $visitWhere .= ' AND v.work_date BETWEEN ? AND ?';
        $visitParams[] = $from;
        $visitParams[] = $to;
    }
    $vst = $pdo->prepare(
        "SELECT COUNT(*) AS visits,
                COUNT(DISTINCT shop_norm) AS shops,
                AVG(dwell_seconds) AS avg_dwell
           FROM visits v
          WHERE $visitWhere"
    );
    $vst->execute($visitParams);
    $v = $vst->fetch();

    return [
        'days'           => (int) ($a['days'] ?? 0),
        'incomplete'     => (int) ($a['incomplete'] ?? 0),
        'productive_km'  => (float) ($a['road_km'] ?? 0),
        'shop_secs'      => (int) ($a['shop_secs'] ?? 0),
        'road_secs'      => (int) ($a['road_secs'] ?? 0),
        'total_secs'     => (int) ($a['total_secs'] ?? 0),
        'visits'         => (int) ($v['visits'] ?? 0),
        'shops'          => (int) ($v['shops'] ?? 0),
        'avg_dwell_secs' => $v['avg_dwell'] !== null ? (int) round((float) $v['avg_dwell']) : null,
    ];
}

/**
 * "Month" mode day list - one row per calendar day in [from, to], newest
 * first, marked Yes/No for attendance. Same shape as the admin's
 * employee_calendar().
 */
function field_calendar(PDO $pdo, int $employeeId, string $from, string $to, string $today): array
{
    $st = $pdo->prepare(
        "SELECT a.*,
                (SELECT COUNT(*) FROM visits v WHERE v.attendance_id = a.id) AS visit_count
           FROM attendance a
          WHERE a.employee_id = ? AND a.work_date BETWEEN ? AND ?
          ORDER BY a.work_date DESC"
    );
    $st->execute([$employeeId, $from, $to]);
    $byDate = [];
    foreach ($st->fetchAll() as $r) {
        $byDate[$r['work_date']] = $r;
    }

    $start = new DateTimeImmutable($from);
    $end   = new DateTimeImmutable($to);
    if ($start->diff($end)->days > 366) {
        $start = $end->modify('-366 days');
    }

    $out = [];
    for ($d = $end; $d >= $start; $d = $d->modify('-1 day')) {
        $key = $d->format('Y-m-d');
        if ($key > $today) {
            continue;
        }
        $out[] = isset($byDate[$key])
            ? ['date' => $key, 'has_attendance' => true] + $byDate[$key]
            : ['date' => $key, 'has_attendance' => false];
    }
    return $out;
}
