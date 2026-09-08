<?php
/**
 * admin/login/api/authenticate.php - verify admin credentials, open a session.
 *
 * POST (form-encoded, from admin/login/index.php):
 *   csrf_token
 *   username
 *   password
 *
 * Success -> redirect to the dashboard.
 * Failure -> flash an error into the session, redirect back to the login page.
 *
 * This is a browser form endpoint (not a JSON api/), so it redirects rather
 * than returning JSON. It still enforces POST + CSRF.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';

$loginUrl = APP_URL . '/admin/login/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($loginUrl);
}

// This lives under api/ but it is a browser form, not a JSON endpoint:
// on a bad token, bounce back to the form rather than emitting JSON.
if (!csrf_check()) {
    $_SESSION['login_error'] = 'Your session expired. Please try again.';
    session_write_close();
    redirect($loginUrl);
}

// Already signed in? Nothing to do.
if (is_logged_in() && current_user()['role'] === 'admin') {
    redirect(admin_url('dashboard'));
}

$username = (string) ($_POST['username'] ?? '');
$password = (string) ($_POST['password'] ?? '');

$result = admin_authenticate($pdo, $username, $password);

if (is_array($result)) {
    login_session($result);
    session_write_close();
    redirect(admin_url('dashboard'));
}

$messages = [
    'throttled' => 'Too many failed attempts. Wait a few minutes and try again.',
    'locked'    => 'This account is locked after repeated failures. Try again in 15 minutes.',
    'bad'       => 'Wrong username or password.',
];

$_SESSION['login_error'] = $messages[$result] ?? $messages['bad'];
$_SESSION['login_username'] = mb_substr(trim($username), 0, 60);
session_write_close();
redirect($loginUrl);
