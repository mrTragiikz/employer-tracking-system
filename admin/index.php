<?php
/**
 * admin/index.php - /track/admin/ entry.
 * Logged-in admin -> dashboard; anyone else -> admin login.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$u = current_user();
if ($u && $u['role'] === 'admin') {
    redirect(admin_url('dashboard'));
}
redirect(APP_URL . '/admin/login/');
