<?php
/**
 * admin/02-employees/api/leave-job.php - mark an Employee as having LEFT THE JOB.
 *
 * POST: id
 *
 * Sets users.left_job_at = NOW(), is_active = 0, clears the device binding,
 * and bumps security_stamp_at so any session still open on the employee's
 * phone is killed on its next request (require_employee() re-checks
 * left_job_at fresh every request - see includes/auth.php).
 *
 * NOT a delete: every record the employee ever created (attendance, visits,
 * routes, evidence photos) stays fully intact and browsable under the
 * "Former Employees" view. Reversible with api/rejoin.php.
 *
 * Super Admin only, same as Lock / Delete.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me);
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id       = (int) ($_POST['id'] ?? 0);
$employee = $id ? employee_find($pdo, $id) : null;

if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}
if ($employee['left_job_at'] !== null) {
    // already marked as left - nothing to do
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id);
}

$pdo->beginTransaction();
try {
    // Retire any active device-binding row (audit trail), then unbind.
    if (!empty($employee['device_id'])) {
        $pdo->prepare(
            "UPDATE field_devices SET status = 'replaced'
              WHERE user_id = ? AND device_id = ? AND status = 'active'"
        )->execute([$id, $employee['device_id']]);
    }

    $pdo->prepare(
        "UPDATE users
            SET left_job_at = NOW(),
                is_active = 0,
                device_id = NULL,
                device_bound_at = NULL,
                security_stamp_at = NOW()
          WHERE id = ? AND role = 'employee'"
    )->execute([$id]);

    // Kill any stay-logged-in tokens for good measure (security_stamp_at
    // already invalidates them; this is the clean follow-through).
    $pdo->prepare('DELETE FROM field_remember_tokens WHERE user_id = ?')->execute([$id]);

    employee_audit($pdo, $me['id'], 'Employee.leave_job', $id,
        ['left_job_at' => null, 'is_active' => (int) $employee['is_active']],
        ['left_job_at' => 'now', 'is_active' => 0]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=leavejob');
}

redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&ok=leftjob');
