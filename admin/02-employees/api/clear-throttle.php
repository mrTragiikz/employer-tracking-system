<?php
/**
 * admin/02-employees/api/clear-throttle.php - end an Employee's 15-minute
 * login lockout early.
 *
 * POST: id
 *
 * Distinct from api/toggle-lock.php (users.is_active - a deliberate,
 * permanent-until-toggled suspension the admin sets). This clears the
 * AUTOMATIC throttle lock (users.failed_logins / locked_until) that kicks
 * in after too many wrong PINs in a row - see EMPLOYEE_MAX_FAILED_LOGINS
 * in config/config.php and employee_authenticate() in includes/auth.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // clearing a lockout is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id = (int) ($_POST['id'] ?? 0);

$employee = $id ? employee_find($pdo, $id) : null;
if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}

$pdo->prepare(
    "UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ? AND role = 'employee'"
)->execute([$id]);

employee_audit($pdo, $me['id'], 'Employee.clear_throttle', $id, null, null);

redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&ok=unlocked_throttle');
