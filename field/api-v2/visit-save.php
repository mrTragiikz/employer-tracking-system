<?php
/**
 * POST field/api-v2/visit-save.php - log a visit (mobile).
 *
 * Header: Authorization: Bearer <token>
 * Body: multipart/form-data
 *   shop_name   required
 *   area_name   required
 *   lat, lng    required, numbers
 *   accuracy    optional
 *   shop_photo  required image file
 *
 * 200: { "ok": true, "visit": <visit object> }
 * 4xx: { "ok": false, "error": "...", "fields": { "shop_name": "..." } }
 *
 * Runs the SAME visit_perform() the web form uses - rule 8 (no duplicate
 * shop today), one-open-visit-at-a-time, shop auto-learn, per-hop distance.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
require dirname(__DIR__) . '/visit/_repo.php';
require dirname(__DIR__) . '/checkinout/_repo.php'; // field_today_attendance()
require dirname(__DIR__, 2) . '/includes/upload.php';
api_method('POST');

$me    = api_require();
$today = server_today();

$noFile = ['error' => UPLOAD_ERR_NO_FILE];
$upload = save_camera_photo($_FILES['shop_photo'] ?? $noFile, 0);

$result = visit_perform(
    $pdo, $me, $today, $_POST, $upload,
    $_FILES['shop_photo']['name'] ?? null
);

if (!$result['ok']) {
    if (!empty($upload['stored_path'])) {
        delete_upload($upload['stored_path']);
    }
    $errs = $result['errors'];
    json_error($errs['_'] ?? $errs['shop_name'] ?? 'Could not save the visit.', 422, ['fields' => $errs]);
}

// return the freshly-saved visit (with its photo)
$row = $pdo->prepare(
    "SELECT v.*, p.stored_path AS photo_path
       FROM visits v
  LEFT JOIN photos p ON p.visit_id = v.id AND p.photo_kind = 'visit'
      WHERE v.id = ? LIMIT 1"
);
$row->execute([(int) $result['visit_id']]);
$v = $row->fetch();

json_out(['ok' => true, 'visit' => $v ? api_visit($v) : null]);
