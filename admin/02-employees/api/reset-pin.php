<?php
/**
 * admin/02-employees/api/reset-pin.php - set a new 4-digit PIN for an Employee.
 *
 * POST: id, pin (exactly 4 digits)
 * Stores only the hash. Clears any lockout so the Employee can log in immediately.
 * Also bumps security_stamp_at, which ends the Employee's CURRENT session (if
 * any) on their next request - a PIN reset is usually "phone lost/stolen,
 * lock them out now", so the old session must not keep working after this.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // resetting an Employee password is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id = (int) ($_POST['id'] ?? 0);
$pin = trim((string) ($_POST['pin'] ?? ''));

$employee = $id ? employee_find($pdo, $id) : null;
if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}

if (!preg_match('/^\d{4}$/', $pin)) {
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=pin');
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users
            SET secret_hash = ?, failed_logins = 0, locked_until = NULL, security_stamp_at = NOW()
          WHERE id = ? AND role = 'employee'"
    )->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);

    employee_audit($pdo, $me['id'], 'Employee.reset_pin', $id, null, ['pin_changed' => true]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=pin');
}

redirect(APP_URL . '/admin/02-employees/?ok=pin');
