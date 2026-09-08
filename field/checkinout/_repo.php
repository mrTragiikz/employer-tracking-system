<?php
/**
 * field/checkinout/_repo.php
 *
 * Data access for the "Check In/Out" tab - the actual check-in and
 * check-out actions, moved off the Attendance page (which is now a
 * read-only Overview) so it has its own screen. Pure functions over $pdo,
 * scoped to one employee - no output. Self-contained by design.
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
 * The visit still open (left_at IS NULL) for this attendance day, if any -
 * checking out is blocked while one is open (an employee is only ever at
 * one shop at a time). Same check as field/visit/_repo.php::visit_open_one().
 */
function field_visit_open_one(PDO $pdo, int $attendanceId): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM visits WHERE attendance_id = ? AND left_at IS NULL ORDER BY seq DESC LIMIT 1"
    );
    $st->execute([$attendanceId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Validate the check-in form input (everything except the photo, which the
 * API endpoint validates via save_camera_photo()).
 *
 * @return array{ok:bool, errors:array<string,string>, data:array}
 */
function checkin_validate(array $in): array
{
    $errors = [];

    $lat = $in['lat'] ?? '';
    $lng = $in['lng'] ?? '';
    $locationDenied = !empty($in['location_denied']);

    // Unlike check-out (check_out_lat/lng are nullable - a shift can end
    // without a fresh fix), check_in_lat/lng are NOT NULL in the schema: a
    // check-in always needs a real location, so a denied/missing fix is
    // rejected outright rather than falling back to a placeholder coordinate.
    if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
        $errors['_'] = 'Could not read your location. Please allow location access and try again.';
    }

    $km = trim((string) ($in['odometer_km'] ?? ''));
    if ($km === '' || !is_numeric($km) || (float) $km < 0 || (float) $km > 999999) {
        $errors['odometer_km'] = 'Enter a valid bike KM reading.';
    }

    return [
        'ok'     => empty($errors),
        'errors' => $errors,
        'data'   => [
            'lat'             => $lat !== '' && is_numeric($lat) ? (float) $lat : null,
            'lng'             => $lng !== '' && is_numeric($lng) ? (float) $lng : null,
            'accuracy'        => is_numeric($in['accuracy'] ?? null) ? (float) $in['accuracy'] : null,
            'location_denied' => $locationDenied ? 1 : 0,
            'odometer_km'     => $km !== '' && is_numeric($km) ? (float) $km : null,
        ],
    ];
}

/**
 * Validate the check-out form input (everything except the photo).
 * Same shape as checkin_validate() - location + odometer photo + KM.
 *
 * @return array{ok:bool, errors:array<string,string>, data:array}
 */
function checkout_validate(array $in, float $checkInKm): array
{
    $errors = [];

    $lat = $in['lat'] ?? '';
    $lng = $in['lng'] ?? '';
    $locationDenied = !empty($in['location_denied']);

    if (!$locationDenied && ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng))) {
        $errors['_'] = 'Could not read your location. Please allow location access and try again.';
    }

    $km = trim((string) ($in['odometer_km'] ?? ''));
    if ($km === '' || !is_numeric($km) || (float) $km < 0 || (float) $km > 999999) {
        $errors['odometer_km'] = 'Enter a valid bike KM reading.';
    } elseif ((float) $km < $checkInKm) {
        $errors['odometer_km'] = 'Check-out KM cannot be less than your check-in KM (' . number_format($checkInKm, 1) . ' km).';
    }

    return [
        'ok'     => empty($errors),
        'errors' => $errors,
        'data'   => [
            'lat'             => $lat !== '' && is_numeric($lat) ? (float) $lat : null,
            'lng'             => $lng !== '' && is_numeric($lng) ? (float) $lng : null,
            'accuracy'        => is_numeric($in['accuracy'] ?? null) ? (float) $in['accuracy'] : null,
            'location_denied' => $locationDenied ? 1 : 0,
            'odometer_km'     => $km !== '' && is_numeric($km) ? (float) $km : null,
        ],
    ];
}
