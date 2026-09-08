<?php
/**
 * admin/02-employees/api/delete.php - soft-delete an Employee.
 *
 * POST: id
 * Sets users.deleted_at = NOW() and deactivates the account. Past visits and
 * attendance keep pointing at the row (FK ON DELETE CASCADE would wipe history,
 * so we never hard-delete).
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // deleting Employees is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id = (int) ($_POST['id'] ?? 0);
$employee = $id ? employee_find($pdo, $id) : null;

if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users
            SET deleted_at = NOW(), is_active = 0
          WHERE id = ? AND role = 'employee'"
    )->execute([$id]);

    employee_audit($pdo, $me['id'], 'Employee.delete', $id, [
        'name' => $employee['name'], 'phone' => $employee['phone'],
    ], null);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=delete');
}

redirect(APP_URL . '/admin/02-employees/?ok=deleted');
