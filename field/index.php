<?php
/**
 * field/index.php - /track/field/ entry.
 * Logged-in field user -> home (attendance); anyone else -> field login.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

$u = current_user();
if ($u && $u['role'] === 'employee') {
    redirect(APP_URL . '/field/home/');
}
redirect(APP_URL . '/field/login/');
