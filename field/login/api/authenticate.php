<?php
/**
 * field/login/api/authenticate.php - verify Employee credentials, open a session.
 *
 * POST (form-encoded, from field/login/index.php):
 *   csrf_token
 *   phone
 *   pin        4 digits
 *   device_id  client-persisted id (localStorage) - enforces the one-device
 *              rule (10); see employee_authenticate() in includes/auth.php
 *
 * Success -> redirect to field home.
 * Failure -> flash an error into the session, redirect back to the login page.
 *
 * This is a browser form endpoint (not a JSON api/), so it redirects rather
 * than returning JSON. It still enforces POST + CSRF.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';

$loginUrl = APP_URL . '/field/login/';

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
if (is_logged_in() && current_user()['role'] === 'employee') {
    redirect(APP_URL . '/field/home/');
}

$phone    = (string) ($_POST['phone'] ?? '');
$pin      = (string) ($_POST['pin'] ?? '');
$deviceId = trim((string) ($_POST['device_id'] ?? ''));

if ($deviceId === '') {
    // JS didn't run / localStorage blocked - no device id to bind against.
    $_SESSION['login_error'] = 'Could not verify this device. Please enable JavaScript and try again.';
    $_SESSION['login_phone'] = mb_substr(trim($phone), 0, 20);
    session_write_close();
    redirect($loginUrl);
}

$result = employee_authenticate($pdo, $phone, $pin, $deviceId);

if (is_array($result)) {
    login_session($result);
    // Stay logged in: drop the long-lived remember-me cookie so this phone
    // stays signed in across app closes / restarts until an explicit Logout
    // or an admin action (Lock / Reset PIN / Reset Device). Best-effort -
    // a failure here does not affect the (already successful) login.
    issue_remember_token($pdo, (int) $result['id'], $deviceId);
    session_write_close();
    redirect(APP_URL . '/field/home/');
}

if ($result === 'suspended') {
    // Distinct from a wrong-PIN error: the credentials were fine, the
    // account itself is locked - shown as a full blocking notice, not a
    // small inline error above a still-usable form (field/login/index.php).
    session_write_close();
    redirect($loginUrl . '?suspended=1');
}

if ($result === 'left') {
    // The employee has permanently left the job. A plain, final message -
    // NOT the suspended screen (which polls for an unlock that never comes).
    session_write_close();
    redirect($loginUrl . '?left=1');
}

$messages = [
    'throttled' => 'Too many failed attempts. Wait a few minutes and try again.',
    'locked'    => 'This account is locked after repeated failures. Try again in 15 minutes.',
    'bad'       => 'Wrong phone number or PIN.',
    'device'    => 'This account is already signed in on a different phone. Ask your admin to reset your device.',
];

$_SESSION['login_error'] = $messages[$result] ?? $messages['bad'];
$_SESSION['login_phone'] = mb_substr(trim($phone), 0, 20);
session_write_close();
redirect($loginUrl);
