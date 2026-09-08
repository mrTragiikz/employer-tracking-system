<?php
/**
 * admin/02-employees/api/toggle-lock.php - suspend or reinstate an Employee.
 *
 * POST: id, action ('lock' | 'unlock')
 *
 * Locking sets users.is_active = 0 - this doesn't just block the Employee's
 * NEXT login (that already happened via is_active), it also ends their
 * CURRENT session on the very next field page they load, because
 * require_employee() (includes/auth.php) re-checks is_active fresh from
 * the DB on every request. Their session is force-logged-out server-side
 * with a "You Have Been Suspended" message on field/login/ - and that
 * screen polls field/login/api/status.php, so UNLOCKING reopens the login
 * form on the employee's phone automatically, no manual refresh needed.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // locking/unlocking an Employee is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

$employee = $id ? employee_find($pdo, $id) : null;
if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}

if (!in_array($action, ['lock', 'unlock'], true)) {
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id);
}

$newActive = $action === 'unlock' ? 1 : 0;

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users SET is_active = ? WHERE id = ? AND role = 'employee'"
    )->execute([$newActive, $id]);

    employee_audit($pdo, $me['id'], $action === 'lock' ? 'Employee.lock' : 'Employee.unlock', $id,
        ['is_active' => (int) $employee['is_active']],
        ['is_active' => $newActive]
    );

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=lock');
}

redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&ok=' . $action . 'ed');
