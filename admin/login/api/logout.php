<?php
/**
 * admin/login/api/logout.php - end the admin session.
 *
 * POST only (CSRF-protected). Linked from the topbar user menu (header.php).
 * Always redirects to the login page, whether or not a session existed.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && csrf_check()) {
    $u = current_user();
    if ($u) {
        try {
            auth_event($pdo, (int) $u['id'], 'logout', null);
        } catch (Throwable $e) {
            // best effort
        }
    }
    logout_session();
}

redirect(APP_URL . '/admin/login/?bye=1');
