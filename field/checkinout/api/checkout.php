<?php
/**
 * field/checkinout/api/checkout.php - record today's check-out.
 *
 * POST (multipart, from field/checkinout/index.php):
 *   csrf_token
 *   lat, lng, accuracy, location_denied   auto-captured by JS, never typed
 *   odometer_photo                        live camera capture (required)
 *   odometer_km                           hand-typed number (required, >= check-in KM)
 *
 * Browser form endpoint - redirects rather than returning JSON, same
 * pattern as checkin.php. Blocked unless: checked in today, not already
 * checked out, and no visit still open (an employee is only ever at one
 * shop at a time - see field_visit_open_one()).
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
require dirname(__DIR__, 3) . '/includes/distance.php';
$me = require_employee();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 3) . '/includes/upload.php';

$formUrl = APP_URL . '/field/checkinout/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($formUrl);
}
if (!csrf_check()) {
    $_SESSION['checkinout_form_error'] = ['_' => 'Your session expired. Please try again.'];
    redirect($formUrl);
}

$today      = server_today();
$attendance = field_today_attendance($pdo, (int) $me['id'], $today);

if ($attendance === null) {
    $_SESSION['checkinout_form_error'] = ['_' => 'Please check in first.'];
    redirect($formUrl);
}
if (!empty($attendance['check_out_at'])) {
    // Already checked out - nothing to do here.
    redirect($formUrl);
}
if (field_visit_open_one($pdo, (int) $attendance['id']) !== null) {
    $_SESSION['checkinout_form_error'] = ['_' => 'Mark your current visit Done before checking out.'];
    redirect($formUrl);
}

$check = checkout_validate($_POST, (float) $attendance['check_in_odometer_km']);

$noFile = ['error' => UPLOAD_ERR_NO_FILE];
$upload = save_camera_photo($_FILES['odometer_photo'] ?? $noFile, 0);
if (!$upload['ok']) {
    $check['ok'] = false;
    $check['errors']['odometer_photo'] = $upload['error'];
}

if (!$check['ok']) {
    if (!empty($upload['stored_path'])) {
        delete_upload($upload['stored_path']);
    }
    $_SESSION['checkinout_form_error'] = $check['errors'];
    redirect($formUrl);
}

$d = $check['data'];

$pdo->beginTransaction();
try {
    $visits = $pdo->prepare('SELECT * FROM visits WHERE attendance_id = ? ORDER BY seq ASC');
    $visits->execute([(int) $attendance['id']]);
    $visitRows = $visits->fetchAll();

    // compute_day() only adds the final "last visit -> check-out" leg when
    // the attendance row it's given already has check_out_at/lat/lng set -
    // $attendance here was fetched BEFORE this check-out, so it's still
    // NULL. Feed it the about-to-be-saved check-out fields instead, or the
    // whole trip home is silently dropped from straight_km/road_km.
    $checkOutAt = server_now();
    $attendanceForCompute = $attendance;
    $attendanceForCompute['check_out_at']  = $checkOutAt;
    $attendanceForCompute['check_out_lat'] = $d['lat'];
    $attendanceForCompute['check_out_lng'] = $d['lng'];

    $day = compute_day($attendanceForCompute, $visitRows);
    $totals = $day['totals'];

    // Persist each hop's own distance/time onto the visit it arrives at
    // (hop_straight_km/hop_road_km/hop_seconds) - compute_day() already
    // works these out per leg (check-in -> visit 1, visit 1 -> visit 2, ...)
    // via day_points()'s ref_id, but until now nothing ever wrote them back
    // to the `visits` row, so every visit's hop_road_km sat at its column
    // default of 0.000 forever - not a display bug, a missing write. Only
    // hops that arrive AT a visit (to_kind === 'visit') apply here; the
    // final "last visit -> check-out" leg belongs to the day's totals only,
    // not to any single visit row.
    $hopUpdate = $pdo->prepare(
        'UPDATE visits SET hop_straight_km = :straight_km, hop_road_km = :road_km, hop_seconds = :seconds WHERE id = :id'
    );
    foreach ($day['hops'] as $hop) {
        if ($hop['to_kind'] !== 'visit' || $hop['to_ref_id'] === null) {
            continue;
        }
        $hopUpdate->execute([
            ':straight_km' => $hop['straight_km'],
            ':road_km'     => $hop['road_km'],
            ':seconds'     => $hop['seconds'],
            ':id'          => $hop['to_ref_id'],
        ]);
    }

    $totalSeconds = max(0, strtotime($checkOutAt) - strtotime($attendance['check_in_at']));

    $stmt = $pdo->prepare(
        "UPDATE attendance SET
            check_out_at = :check_out_at,
            check_out_lat = :lat,
            check_out_lng = :lng,
            check_out_accuracy_m = :accuracy,
            check_out_odometer_km = :odometer_km,
            total_hops = :total_hops,
            straight_km = :straight_km,
            road_km = :road_km,
            road_factor_used = :road_factor_used,
            total_seconds = :total_seconds,
            shop_seconds = :shop_seconds,
            road_seconds = :road_seconds,
            status = 'closed'
         WHERE id = :id"
    );
    $stmt->execute([
        ':check_out_at'     => $checkOutAt,
        ':lat'              => $d['lat'],
        ':lng'              => $d['lng'],
        ':accuracy'         => $d['accuracy'],
        ':odometer_km'      => $d['odometer_km'],
        ':total_hops'       => $totals['total_hops'],
        ':straight_km'      => $totals['straight_km'],
        ':road_km'          => $totals['road_km'],
        ':road_factor_used' => $totals['road_factor_used'],
        ':total_seconds'    => $totalSeconds,
        ':shop_seconds'     => $totals['shop_seconds'],
        ':road_seconds'     => $totals['road_seconds'],
        ':id'               => (int) $attendance['id'],
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
        ':original_name' => $_FILES['odometer_photo']['name'] ?? null,
        ':mime'          => $upload['mime'],
        ':bytes'         => $upload['bytes'],
        ':width'         => $upload['width'],
        ':height'        => $upload['height'],
        ':sha256'        => $upload['sha256'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    delete_upload($upload['stored_path'] ?? null);
    $_SESSION['checkinout_form_error'] = ['_' => 'Could not save your check-out. Please try again.'];
    redirect($formUrl);
}

redirect($formUrl . '?ok=checkout');
