<?php
/**
 * field/checkinout/api/checkin.php - record today's check-in.
 *
 * POST (multipart, from field/checkinout/index.php):
 *   csrf_token
 *   lat, lng, accuracy, location_denied   auto-captured by JS, never typed
 *   odometer_photo                        live camera capture (required)
 *   odometer_km                           hand-typed number (required)
 *
 * Browser form endpoint (not a JSON api/), so it redirects rather than
 * returning JSON - same pattern as field/login/api/authenticate.php. One
 * attendance row per employee per day (DB-enforced); a second attempt the
 * same day is refused.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
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

$today = server_today();
if (field_today_attendance($pdo, (int) $me['id'], $today) !== null) {
    // Already checked in - nothing to do here.
    redirect($formUrl);
}

// Check-in window policy (Settings > Attendance check-in): past the latest
// allowed time, the check-in is refused outright - no attendance row is
// created, so the Employee is simply Absent for today, same as anyone who
// never attempted to check in at all (see includes/settings.php
// attendance_checkin_blocked()). Checked here, server-side, before doing
// any upload work - field/checkinout/index.php also hides the check-in form
// once blocked, but that's a courtesy for a normal page load; a directly
// POSTed request must be refused for real too.
if (attendance_checkin_blocked($today)) {
    redirect($formUrl);
}

// $_SESSION['user'] does not carry device_id (see login_session() in
// includes/auth.php) - look it up fresh so check_in_device_id isn't
// silently left null.
$deviceIdStmt = $pdo->prepare('SELECT device_id FROM users WHERE id = ?');
$deviceIdStmt->execute([$me['id']]);
$myDeviceId = $deviceIdStmt->fetchColumn() ?: null;

$check = checkin_validate($_POST);

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
    $stmt = $pdo->prepare(
        "INSERT INTO attendance
           (employee_id, work_date, check_in_at, check_in_lat, check_in_lng,
            check_in_accuracy_m, check_in_device_id, check_in_odometer_km,
            location_denied, status)
         VALUES
           (:employee_id, :work_date, NOW(), :lat, :lng,
            :accuracy, :device_id, :odometer_km,
            :location_denied, 'open')"
    );
    $stmt->execute([
        ':employee_id'      => $me['id'],
        ':work_date'        => $today,
        ':lat'              => $d['lat'],
        ':lng'              => $d['lng'],
        ':accuracy'         => $d['accuracy'],
        ':device_id'        => $myDeviceId,
        ':odometer_km'      => $d['odometer_km'],
        ':location_denied'  => $d['location_denied'],
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
    $_SESSION['checkinout_form_error'] = ['_' => 'Could not save your check-in. Please try again.'];
    redirect($formUrl);
}

redirect($formUrl . '?ok=checkin');
