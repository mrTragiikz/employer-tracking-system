<?php
/**
 * field/routes/_repo.php
 *
 * Data access for the Employee's own "Routes" tab - a plain text list of
 * one day's stops (check-in -> visit 1 -> visit 2 -> ... -> check-out), NO
 * live map (deliberately - avoids a per-view Mapbox API cost for something
 * that's just as clear as a numbered list). Pure functions over $pdo,
 * scoped to "myself" - no output. Self-contained by design.
 */

declare(strict_types=1);

/** One attendance row (+ visit_count) for one day, or null. */
function routes_attendance_day(PDO $pdo, int $employeeId, string $date): ?array
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

/**
 * The day's stop sequence: check-in (Start), one row per visit, check-out
 * (if the day is closed). Same shape/ordering idea as
 * field/attendance/_repo.php::field_timeline(), built independently here
 * per the self-contained-section pattern.
 */
function routes_points(PDO $pdo, int $employeeId, string $date): array
{
    $att = routes_attendance_day($pdo, $employeeId, $date);
    if (!$att) {
        return [];
    }

    $points = [];
    $points[] = [
        'kind'  => 'start',
        'no'    => null,
        'at'    => new DateTimeImmutable($att['check_in_at']),
        'label' => 'Start point',
        'sub'   => null,
        'lat'   => (float) $att['check_in_lat'],
        'lng'   => (float) $att['check_in_lng'],
    ];

    $vs = $pdo->prepare(
        "SELECT shop_name, area_name, arrived_at, lat, lng
           FROM visits WHERE attendance_id = ? ORDER BY seq ASC"
    );
    $vs->execute([(int) $att['id']]);
    foreach ($vs->fetchAll() as $i => $v) {
        $points[] = [
            'kind'  => 'visit',
            'no'    => $i + 1,
            'at'    => new DateTimeImmutable($v['arrived_at']),
            'label' => $v['shop_name'],
            'sub'   => $v['area_name'],
            'lat'   => (float) $v['lat'],
            'lng'   => (float) $v['lng'],
        ];
    }

    if ($att['check_out_at']) {
        $points[] = [
            'kind'  => 'end',
            'no'    => null,
            'at'    => new DateTimeImmutable($att['check_out_at']),
            'label' => 'End point',
            'sub'   => null,
            'lat'   => $att['check_out_lat'] !== null ? (float) $att['check_out_lat'] : null,
            'lng'   => $att['check_out_lng'] !== null ? (float) $att['check_out_lng'] : null,
        ];
    }

    return $points;
}
