<?php
/**
 * admin/11-users-roles/api/delete.php - soft-delete a regular Admin.
 *
 * POST: id
 * The Super Admin row can never be deleted (checked below, regardless of who
 * is asking). An admin also cannot delete their own account this way - use
 * another admin's session to remove an account.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // Users is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id = (int) ($_POST['id'] ?? 0);
$target = $id ? admin_find($pdo, $id) : null;

if (!$target) {
    redirect(APP_URL . '/admin/11-users-roles/');
}

if (!empty($target['is_super_admin'])) {
    $_SESSION['admin_form_error'] = ['_' => 'The Super Admin account cannot be deleted.'];
    redirect(APP_URL . '/admin/11-users-roles/');
}

if ((int) $target['id'] === (int) $me['id']) {
    $_SESSION['admin_form_error'] = ['_' => 'You cannot delete your own account.'];
    redirect(APP_URL . '/admin/11-users-roles/');
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        "UPDATE users
            SET deleted_at = NOW(), is_active = 0
          WHERE id = ? AND role = 'admin' AND is_super_admin = 0"
    )->execute([$id]);

    admin_audit($pdo, $me['id'], 'admin.delete', $id, [
        'name' => $target['name'], 'username' => $target['username'],
    ], null);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/11-users-roles/?err=delete');
}

redirect(APP_URL . '/admin/11-users-roles/?ok=deleted');
