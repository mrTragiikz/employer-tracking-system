<?php
/**
 * Front door. Sends people to the right place:
 *   - logged-in admin   -> admin dashboard
 *   - logged-in Employee  -> field home (attendance)
 *   - nobody            -> field login (the common case; admins know the /admin URL)
 */

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';

$u = current_user();

if ($u && $u['role'] === 'admin') {
    redirect(admin_url('dashboard'));
}
if ($u && $u['role'] === 'employee') {
    redirect(APP_URL . '/field/home/');
}
redirect(APP_URL . '/field/login/');
