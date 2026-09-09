<?php
/**
 * field/visit/api/save.php - record a visit (web form, the "Log a Visit"
 * modal on field/visit/index.php).
 *
 * POST (multipart): csrf_token, shop_name, area_name, lat, lng, accuracy, shop_photo
 *
 * All the rules (must be checked in, rule 8 no-duplicate-shop, one open visit
 * at a time, shop auto-learn, the per-hop distance) live in visit_perform()
 * (field/visit/_repo.php) so the mobile API runs the exact same code.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
require dirname(__DIR__, 3) . '/includes/distance.php';
$me = require_employee();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/checkinout/_repo.php'; // field_today_attendance()
require dirname(__DIR__, 3) . '/includes/upload.php';

$formUrl = APP_URL . '/field/visit/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($formUrl);
}
if (!csrf_check()) {
    $_SESSION['visit_form_error'] = ['_' => 'Your session expired. Please try again.'];
    redirect($formUrl);
}

$today  = server_today();
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
    $_SESSION['visit_form_error'] = $result['errors'];
    redirect($formUrl);
}

redirect($formUrl . '?ok=visit');
