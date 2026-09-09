<?php
/**
 * POST field/api-v2/checkout.php - record today's check-out (mobile).
 *
 * Header: Authorization: Bearer <token>
 * Body: multipart/form-data
 *   lat, lng           (numbers; can be absent only if location_denied="1")
 *   accuracy           optional
 *   location_denied    "1" | absent
 *   odometer_km        required, must be >= check-in KM
 *   odometer_photo     required image file
 *
 * 200: { "ok": true, "attendance": <attendance object>, "totals": {...} }
 * 4xx: { "ok": false, "error": "...", "fields": {...} }
 *
 * Runs the SAME checkout_perform() (compute_day, per-hop writes, day totals)
 * the web form uses.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
require dirname(__DIR__) . '/checkinout/_repo.php';
require dirname(__DIR__, 2) . '/includes/upload.php';
api_method('POST');

$me    = api_require();
$today = server_today();

$noFile = ['error' => UPLOAD_ERR_NO_FILE];
$upload = save_camera_photo($_FILES['odometer_photo'] ?? $noFile, 0);

$result = checkout_perform(
    $pdo, $me, $today, $_POST, $upload,
    $_FILES['odometer_photo']['name'] ?? null
);

if (!$result['ok']) {
    if (!empty($upload['stored_path'])) {
        delete_upload($upload['stored_path']);
    }
    $errs = $result['errors'];
    json_error($errs['_'] ?? 'Could not check out.', 422, ['fields' => $errs]);
}

$att = field_today_attendance($pdo, (int) $me['id'], $today);
json_out([
    'ok'         => true,
    'attendance' => api_attendance($att, server_now()),
    'totals'     => $result['totals'],
]);
