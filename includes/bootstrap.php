<?php
/**
 * Loaded first by EVERY entry point.
 *   - Section pages:      require dirname(__DIR__, 2) . '/includes/bootstrap.php';
 *   - Section endpoints:  require dirname(__DIR__, 3) . '/includes/api.php';  (which loads this)
 * Sets timezone, error handling, session, and exposes $pdo + helpers.
 */

declare(strict_types=1);

// Idempotent: safe to require more than once per request.
if (defined('TRACK_BOOTSTRAPPED')) {
    return;
}
define('TRACK_BOOTSTRAPPED', true);

require __DIR__ . '/../config/config.php';

// ---- Timezone: server time only (Anti-Fraud rule 4) ----------------------
date_default_timezone_set(APP_TZ);

// ---- Temporary deploy diagnostic --------------------------------------
// Open  ?__debug=<APP_SECRET>  ONCE - it drops a short-lived cookie, and
// every request after that (POSTs included, e.g. a form save that 500s)
// shows the full error until you open  ?__debug=off  or the cookie expires
// (30 min). Needs the exact APP_SECRET, so it's safe to leave in - but
// remove this whole block once the site is confirmed working.
if (defined('APP_SECRET')) {
    $dbgOn  = isset($_GET['__debug']) && hash_equals(APP_SECRET, (string) $_GET['__debug']);
    $dbgOff = isset($_GET['__debug']) && $_GET['__debug'] === 'off';
    if ($dbgOn) {
        setcookie('__trackdbg', APP_SECRET, time() + 1800, '/');
        $_COOKIE['__trackdbg'] = APP_SECRET;
    } elseif ($dbgOff) {
        setcookie('__trackdbg', '', time() - 1, '/');
        unset($_COOKIE['__trackdbg']);
    }
    if (isset($_COOKIE['__trackdbg']) && hash_equals(APP_SECRET, (string) $_COOKIE['__trackdbg'])) {
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        error_reporting(E_ALL);
        define('TRACK_DEBUG_REQUEST', true);
    }
}

// ---- Error handling -----------------------------------------------------
error_reporting(E_ALL);
if (APP_ENV === 'production' && !defined('TRACK_DEBUG_REQUEST')) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    // Log into the app's own logs/ dir when it exists and is writable;
    // otherwise fall back to the host's default error log (never let a
    // missing/unwritable path itself become the reason for a 500).
    $logDir = dirname(__DIR__) . '/logs';
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . '/php-error.log');
    }
} else {
    ini_set('display_errors', '1');
}

// ---- Session: httponly, secure (in prod), regenerate on login -----------
// The field app ("stay logged in") gets a much longer session cookie + GC
// window than the admin panel. A remember-me token (includes/auth.php) rebuilds
// the session even past this window, but a long session means the token is
// consulted rarely rather than on every request. The admin panel keeps the
// short SESSION_LIFETIME. Branch on the request path - the session name is
// shared, only its lifetime differs.
$isFieldRequest = str_contains($_SERVER['REQUEST_URI'] ?? '', '/field/');
$sessionLifetime = ($isFieldRequest && defined('FIELD_SESSION_LIFETIME'))
    ? (int) FIELD_SESSION_LIFETIME
    : SESSION_LIFETIME;

if ($isFieldRequest && defined('FIELD_SESSION_LIFETIME')) {
    // Keep PHP's garbage collector from deleting an idle field session before
    // its cookie would expire.
    ini_set('session.gc_maxlifetime', (string) FIELD_SESSION_LIFETIME);
}

// The mobile JSON API (field/api-v2/) is stateless - bearer token, no session.
// Its _boot.php defines TRACK_STATELESS_REQUEST before requiring this file so
// no PHP session is started (no session cookie, no session file lock).
if (!defined('TRACK_STATELESS_REQUEST') && session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'domain'   => '',
        'secure'   => COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require __DIR__ . '/db.php';        // -> $pdo
require __DIR__ . '/helpers.php';
require __DIR__ . '/csrf.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/settings.php';

// ---- DEV ONLY: auto-login while the real login pages don't exist yet -------
// Enable by adding to secure_config/secure_config.php:  define('DEV_AUTOLOGIN', 'admin');
// Values: 'admin' | 'employee'. Hard-gated to development; ignored in production.
if (APP_ENV === 'development' && defined('DEV_AUTOLOGIN') && !is_logged_in()) {
    dev_autologin((string) DEV_AUTOLOGIN);
}
