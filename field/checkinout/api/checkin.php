<?php
/**
 * field/checkinout/api/checkin.php - record today's check-in (web form).
 *
 * POST (multipart, from field/checkinout/index.php):
 *   csrf_token
 *   lat, lng, accuracy, location_denied   auto-captured by JS, never typed
 *   odometer_photo                        live camera capture (required)
 *   odometer_km                           hand-typed number (required)
 *
 * All the rules + the DB writes live in checkin_perform() (see
 * field/checkinout/_repo.php) so the mobile API (field/api-v2/checkin.php)
 * runs the exact same code. This file is just the web wrapper: CSRF, the
 * photo upload, then redirect with a flash.
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

$today  = server_today();
$noFile = ['error' => UPLOAD_ERR_NO_FILE];
$upload = save_camera_photo($_FILES['odometer_photo'] ?? $noFile, 0);

$result = checkin_perform(
    $pdo, $me, $today, $_POST, $upload,
    $_FILES['odometer_photo']['name'] ?? null
);

if (!$result['ok']) {
    if (!empty($upload['stored_path'])) {
        delete_upload($upload['stored_path']);
    }
    $_SESSION['checkinout_form_error'] = $result['errors'];
    redirect($formUrl);
}

redirect($formUrl . '?ok=checkin');
