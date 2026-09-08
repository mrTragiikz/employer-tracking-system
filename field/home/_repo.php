<?php
/**
 * field/home/_repo.php
 *
 * Data access for the Employee home/dashboard page. Pure functions over
 * $pdo, scoped to one employee - no output.
 */

declare(strict_types=1);

/** Today's attendance row for this employee, or null if not checked in yet. */
function field_today_attendance(PDO $pdo, int $employeeId, string $workDate): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM attendance WHERE employee_id = ? AND work_date = ? LIMIT 1"
    );
    $st->execute([$employeeId, $workDate]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * The home-page stat tiles for this employee, today. Every value is a real
 * query - degrades to 0 with no data, same pattern as
 * 02-employees/_repo.php::employee_stats().
 *
 * km_today ("Productive Work KM") is computed live via includes/distance.php
 * compute_day() from the actual GPS points saved at check-in and each visit
 * so far - not the cached attendance.road_km column, which is only ever
 * written when the day closes and would show 0.0 for every open day.
 */
function field_home_stats(PDO $pdo, int $employeeId, string $workDate, ?array $attendance, array $visits): array
{
    $visitCount = count($visits);
    $kmToday = 0.0;

    if ($attendance !== null) {
        $day = compute_day($attendance, $visits);
        $kmToday = $day['totals']['road_km'];
    }

    return [
        'visits_today' => $visitCount,
        'km_today'     => $kmToday,
    ];
}

/** "Good morning" / "Good afternoon" / "Good evening", server time. */
function field_greeting(): string
{
    $hour = (int) date('G');
    if ($hour < 12) return 'Good morning';
    if ($hour < 17) return 'Good afternoon';
    return 'Good evening';
}
