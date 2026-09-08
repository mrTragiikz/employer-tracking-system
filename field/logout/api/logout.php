<?php
/**
 * field/logout/api/logout.php - end the employee's session.
 *
 * POST only (CSRF-protected), reached from the confirmation button on
 * field/logout/index.php. Always redirects to the Employee login page,
 * whether or not a session existed - same pattern as
 * admin/login/api/logout.php.
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

redirect(APP_URL . '/field/login/');
