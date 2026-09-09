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
 * Perform a check-in. Shared by the web endpoint
 * (field/checkinout/api/checkin.php) and the mobile API
 * (field/api-v2/checkin.php) so the rules live in exactly one place.
 *
 * $input        raw form fields (lat, lng, accuracy, location_denied, odometer_km)
 * $upload       the return of save_camera_photo($_FILES[...]) - already
 *               validated by the caller; pass ['ok'=>false,'error'=>'...']
 *               to fail on the photo.
 * $originalName optional original filename for the photo row (or null).
 *
 * @return array{ok:bool, errors:array<string,string>, attendance_id?:int}
 *   On !ok the caller shows $errors and (if it stored a photo) deletes it.
 *   On ok the attendance row + check-in photo row are committed.
 */
function checkin_perform(PDO $pdo, array $me, string $today, array $input, array $upload, ?string $originalName = null): array
{
    // already checked in?
    if (field_today_attendance($pdo, (int) $me['id'], $today) !== null) {
        return ['ok' => false, 'errors' => ['_' => 'You are already checked in today.']];
    }
    // check-in window policy
    if (function_exists('attendance_checkin_blocked') && attendance_checkin_blocked($today)) {
        return ['ok' => false, 'errors' => ['_' => 'The check-in window has closed for today.']];
    }

    $check = checkin_validate($input);
    if (!$upload['ok']) {
        $check['ok'] = false;
        $check['errors']['photo'] = $upload['error'] ?? 'The photo upload failed.';
    }
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $d = $check['data'];

    // device_id is not on $me for the web session - look it up
    $myDeviceId = $pdo->prepare('SELECT device_id FROM users WHERE id = ?');
    $myDeviceId->execute([$me['id']]);
    $myDeviceId = $myDeviceId->fetchColumn() ?: null;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO attendance
               (employee_id, work_date, check_in_at, check_in_lat, check_in_lng,
                check_in_accuracy_m, check_in_device_id, check_in_odometer_km,
                location_denied, status)
             VALUES
               (:employee_id, :work_date, NOW(), :lat, :lng,
                :accuracy, :device_id, :odometer_km, :location_denied, 'open')"
        )->execute([
            ':employee_id'     => $me['id'],
            ':work_date'       => $today,
            ':lat'             => $d['lat'],
            ':lng'             => $d['lng'],
            ':accuracy'        => $d['accuracy'],
            ':device_id'       => $myDeviceId,
            ':odometer_km'     => $d['odometer_km'],
            ':location_denied' => $d['location_denied'],
        ]);
        $attendanceId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO photos
               (photo_kind, attendance_id, employee_id, work_date, taken_at,
                lat, lng, stored_path, original_name, mime, bytes, width, height,
                sha256, captured_via)
             VALUES
               ('checkin', :attendance_id, :employee_id, :work_date, NOW(),
                :lat, :lng, :stored_path, :original_name, :mime, :bytes, :width, :height,
                :sha256, 'camera')"
        )->execute([
            ':attendance_id' => $attendanceId,
            ':employee_id'   => $me['id'],
            ':work_date'     => $today,
            ':lat'           => $d['lat'],
            ':lng'           => $d['lng'],
            ':stored_path'   => $upload['stored_path'],
            ':original_name' => $originalName,
            ':mime'          => $upload['mime'],
            ':bytes'         => $upload['bytes'],
            ':width'         => $upload['width'],
            ':height'        => $upload['height'],
            ':sha256'        => $upload['sha256'],
        ]);

        $pdo->commit();
        return ['ok' => true, 'attendance_id' => $attendanceId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'errors' => ['_' => 'Could not save your check-in. Please try again.']];
    }
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

/**
 * Perform a check-out. Shared by the web endpoint and the mobile API.
 * Runs compute_day() for the whole day, writes each hop's distance/time
 * onto its visit row, and freezes the day totals into the attendance row.
 *
 * REQUIRES includes/distance.php to be loaded (compute_day, haversine_km).
 *
 * @return array{ok:bool, errors:array<string,string>, totals?:array}
 */
function checkout_perform(PDO $pdo, array $me, string $today, array $input, array $upload, ?string $originalName = null): array
{
    $attendance = field_today_attendance($pdo, (int) $me['id'], $today);
    if ($attendance === null) {
        return ['ok' => false, 'errors' => ['_' => 'Please check in first.']];
    }
    if (!empty($attendance['check_out_at'])) {
        return ['ok' => false, 'errors' => ['_' => 'Your day is already checked out.']];
    }
    if (field_visit_open_one($pdo, (int) $attendance['id']) !== null) {
        return ['ok' => false, 'errors' => ['_' => 'Mark your current visit Done before checking out.']];
    }

    $check = checkout_validate($input, (float) $attendance['check_in_odometer_km']);
    if (!$upload['ok']) {
        $check['ok'] = false;
        $check['errors']['photo'] = $upload['error'] ?? 'The photo upload failed.';
    }
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $d = $check['data'];

    $pdo->beginTransaction();
    try {
        $vs = $pdo->prepare('SELECT * FROM visits WHERE attendance_id = ? ORDER BY seq ASC');
        $vs->execute([(int) $attendance['id']]);
        $visitRows = $vs->fetchAll();

        // compute_day() only adds the "last visit -> check-out" leg when the
        // attendance row it's given already has check_out fields set - feed
        // it the about-to-be-saved values.
        $checkOutAt = server_now();
        $forCompute = $attendance;
        $forCompute['check_out_at']  = $checkOutAt;
        $forCompute['check_out_lat'] = $d['lat'];
        $forCompute['check_out_lng'] = $d['lng'];

        $day    = compute_day($forCompute, $visitRows);
        $totals = $day['totals'];

        // per-visit hop distance/time (hops that ARRIVE at a visit)
        $hopUpd = $pdo->prepare(
            'UPDATE visits SET hop_straight_km = :s, hop_road_km = :r, hop_seconds = :sec WHERE id = :id'
        );
        foreach ($day['hops'] as $hop) {
            if ($hop['to_kind'] !== 'visit' || $hop['to_ref_id'] === null) {
                continue;
            }
            $hopUpd->execute([
                ':s' => $hop['straight_km'], ':r' => $hop['road_km'],
                ':sec' => $hop['seconds'], ':id' => $hop['to_ref_id'],
            ]);
        }

        $totalSeconds = max(0, strtotime($checkOutAt) - strtotime($attendance['check_in_at']));

        $pdo->prepare(
            "UPDATE attendance SET
                check_out_at = :check_out_at, check_out_lat = :lat, check_out_lng = :lng,
                check_out_accuracy_m = :accuracy, check_out_odometer_km = :odometer_km,
                total_hops = :total_hops, straight_km = :straight_km, road_km = :road_km,
                road_factor_used = :rf, total_seconds = :total_seconds,
                shop_seconds = :shop_seconds, road_seconds = :road_seconds, status = 'closed'
             WHERE id = :id"
        )->execute([
            ':check_out_at'  => $checkOutAt,
            ':lat'           => $d['lat'],
            ':lng'           => $d['lng'],
            ':accuracy'      => $d['accuracy'],
            ':odometer_km'   => $d['odometer_km'],
            ':total_hops'    => $totals['total_hops'],
            ':straight_km'   => $totals['straight_km'],
            ':road_km'       => $totals['road_km'],
            ':rf'            => $totals['road_factor_used'],
            ':total_seconds' => $totalSeconds,
            ':shop_seconds'  => $totals['shop_seconds'],
            ':road_seconds'  => $totals['road_seconds'],
            ':id'            => (int) $attendance['id'],
        ]);

        $pdo->prepare(
            "INSERT INTO photos
               (photo_kind, attendance_id, employee_id, work_date, taken_at,
                lat, lng, stored_path, original_name, mime, bytes, width, height,
                sha256, captured_via)
             VALUES
               ('checkout', :attendance_id, :employee_id, :work_date, NOW(),
                :lat, :lng, :stored_path, :original_name, :mime, :bytes, :width, :height,
                :sha256, 'camera')"
        )->execute([
            ':attendance_id' => (int) $attendance['id'],
            ':employee_id'   => $me['id'],
            ':work_date'     => $today,
            ':lat'           => $d['lat'],
            ':lng'           => $d['lng'],
            ':stored_path'   => $upload['stored_path'],
            ':original_name' => $originalName,
            ':mime'          => $upload['mime'],
            ':bytes'         => $upload['bytes'],
            ':width'         => $upload['width'],
            ':height'        => $upload['height'],
            ':sha256'        => $upload['sha256'],
        ]);

        $pdo->commit();
        return ['ok' => true, 'totals' => $totals];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'errors' => ['_' => 'Could not save your check-out. Please try again.']];
    }
}
