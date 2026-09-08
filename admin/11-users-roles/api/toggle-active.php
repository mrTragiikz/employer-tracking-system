<?php
/**
 * admin/11-users-roles/api/toggle-active.php - flip is_active on a regular Admin.
 *
 * POST: id
 * The Super Admin row is never touched here (always active, not a target of
 * this action). Refuses to deactivate your own account.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // Users is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id = (int) ($_POST['id'] ?? 0);
$target = $id ? admin_find($pdo, $id) : null;

if (!$target || !empty($target['is_super_admin'])) {
    redirect(APP_URL . '/admin/11-users-roles/');
}

if ((int) $target['id'] === (int) $me['id']) {
    $_SESSION['admin_form_error'] = ['_' => 'You cannot deactivate your own account.'];
    redirect(APP_URL . '/admin/11-users-roles/');
}

$next = (int) $target['is_active'] === 1 ? 0 : 1;

$pdo->prepare(
    "UPDATE users SET is_active = ? WHERE id = ? AND role = 'admin' AND is_super_admin = 0"
)->execute([$next, $id]);

admin_audit($pdo, $me['id'], 'admin.toggle_active', $id,
    ['is_active' => (int) $target['is_active']],
    ['is_active' => $next]);

redirect(APP_URL . '/admin/11-users-roles/?ok=updated');
