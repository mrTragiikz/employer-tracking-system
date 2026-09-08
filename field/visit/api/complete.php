<?php
/**
 * field/visit/api/complete.php - mark the current open visit as Done.
 *
 * POST:
 *   csrf_token
 *   visit_id
 *
 * Sets visits.left_at = NOW() and dwell_seconds (left_at - arrived_at) for
 * the given visit, only if it belongs to this employee, is part of today's
 * attendance, and is still open (left_at IS NULL). An employee is only ever
 * at one shop at a time - this is what re-enables "Log a Visit" for the
 * next stop. Browser form endpoint, redirects rather than returning JSON -
 * same pattern as field/visit/api/save.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
$me = require_employee();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/checkinout/_repo.php';

$formUrl = APP_URL . '/field/visit/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($formUrl);
}
if (!csrf_check()) {
    $_SESSION['visit_form_error'] = ['_' => 'Your session expired. Please try again.'];
    redirect($formUrl);
}

$today      = server_today();
$attendance = field_today_attendance($pdo, (int) $me['id'], $today);

if ($attendance === null) {
    redirect($formUrl);
}

$visitId = (int) ($_POST['visit_id'] ?? 0);
$open    = visit_open_one($pdo, (int) $attendance['id']);

if ($visitId <= 0 || $open === null || (int) $open['id'] !== $visitId) {
    $_SESSION['visit_form_error'] = ['_' => 'That visit is not open, or is already marked Done.'];
    redirect($formUrl);
}

$dwellSeconds = max(0, strtotime('now') - strtotime($open['arrived_at']));

$pdo->prepare(
    "UPDATE visits SET left_at = NOW(), dwell_seconds = ? WHERE id = ? AND employee_id = ?"
)->execute([$dwellSeconds, $visitId, $me['id']]);

redirect($formUrl . '?ok=completed');
