<?php
/**
 * admin/02-employees/api/rejoin.php - reinstate a former Employee.
 *
 * POST: id
 *
 * Clears users.left_job_at and reactivates the account (is_active = 1). The
 * device binding stays cleared - they pair a fresh phone on their next login,
 * same as any returning employee. Reverses api/leave-job.php.
 *
 * Super Admin only.
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
if ($employee['left_job_at'] === null) {
    // not a former employee - nothing to do
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users
            SET left_job_at = NULL,
                is_active = 1,
                failed_logins = 0,
                locked_until = NULL
          WHERE id = ? AND role = 'employee'"
    )->execute([$id]);

    employee_audit($pdo, $me['id'], 'Employee.rejoin', $id,
        ['left_job_at' => (string) $employee['left_job_at'], 'is_active' => 0],
        ['left_job_at' => null, 'is_active' => 1]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=rejoin');
}

redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&ok=rejoined');
