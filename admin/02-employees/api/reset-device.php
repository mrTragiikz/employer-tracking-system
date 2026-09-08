<?php
/**
 * admin/02-employees/api/reset-device.php - clear an Employee's bound device.
 *
 * POST: id
 * Fraud rule 10: an Employee login is locked to one device_id. When they get a new
 * phone the admin clears the binding here; the next successful login re-binds.
 * The old binding is kept in field_devices with status 'replaced'.
 * Also bumps security_stamp_at, ending any session still open on the OLD
 * phone - clearing the binding is usually "this phone is gone", so that old
 * session should not keep working just because its cookie is still valid.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // clearing an Employee's device binding is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id = (int) ($_POST['id'] ?? 0);
$employee = $id ? employee_find($pdo, $id) : null;

if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}

$pdo->beginTransaction();
try {
    if (!empty($employee['device_id'])) {
        $pdo->prepare(
            "UPDATE field_devices SET status = 'replaced'
              WHERE user_id = ? AND device_id = ? AND status = 'active'"
        )->execute([$id, $employee['device_id']]);
    }

    $pdo->prepare(
        "UPDATE users SET device_id = NULL, device_bound_at = NULL, security_stamp_at = NOW()
          WHERE id = ? AND role = 'employee'"
    )->execute([$id]);

    // "New phone" - the old phone's stay-logged-in tokens are dead. The
    // security_stamp_at bump above already invalidates them on the next
    // request; deleting the rows now is the clean follow-through.
    $pdo->prepare('DELETE FROM field_remember_tokens WHERE user_id = ?')->execute([$id]);

    employee_audit($pdo, $me['id'], 'Employee.reset_device', $id,
        ['device_id' => $employee['device_id']], ['device_id' => null]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=device');
}

redirect(APP_URL . '/admin/02-employees/?ok=device');
