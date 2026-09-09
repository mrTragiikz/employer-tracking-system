<?php
/**
 * field/visit/api/complete.php - mark the current open visit as Done (web).
 *
 * POST: csrf_token, visit_id
 *
 * The rule (visit must belong to this employee, be part of today's
 * attendance, still be open) lives in visit_complete_perform()
 * (field/visit/_repo.php) so the mobile API runs the same code.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
$me = require_employee();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/checkinout/_repo.php'; // field_today_attendance()

$formUrl = APP_URL . '/field/visit/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($formUrl);
}
if (!csrf_check()) {
    $_SESSION['visit_form_error'] = ['_' => 'Your session expired. Please try again.'];
    redirect($formUrl);
}

$result = visit_complete_perform(
    $pdo, $me, server_today(), (int) ($_POST['visit_id'] ?? 0)
);

if (!$result['ok']) {
    $_SESSION['visit_form_error'] = ['_' => $result['error']];
    redirect($formUrl);
}

redirect($formUrl . '?ok=completed');
