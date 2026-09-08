<?php
/**
 * Config loader. This file defines NOTHING itself - no values, no data,
 * nothing a reader could learn from it. It only requires secure_config.php,
 * which is where every real constant is defined (connection details AND
 * app/business settings alike).
 *
 * secure_config.php is required, not optional: if it's missing, this fails
 * loudly (a fatal error) rather than silently booting with no config at all.
 *
 * secure_config/ ships as a sibling of this config/ folder so it can be
 * dragged outside public_html in one move on a live server (cPanel File
 * Manager), making it physically unreachable over HTTP no matter how
 * .htaccess is configured - see secure_config/.htaccess for the
 * defense-in-depth layer that applies before that move happens.
 */

declare(strict_types=1);

// Include-safe: bail if config has already been loaded this request.
if (defined('APP_ENV')) {
    return;
}

// Temporary deploy diagnostic: ?__debug=1 turns on error output for THIS
// request before any config is loaded, so a fatal in config/secure_config/db
// is visible instead of a blank 500. This form only accepts the literal "1"
// (it can't check APP_SECRET - that isn't defined yet); bootstrap.php
// re-checks with the real secret straight after. Remove once the site works.
if (isset($_GET['__debug']) && $_GET['__debug'] === '1') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// This file's own location always correctly identifies the project root -
// unlike secure_config.php, config.php never moves. secure_config.php uses
// this (rather than its own __DIR__) to build filesystem paths like
// UPLOAD_DIR, so those stay correct whether secure_config.php currently
// sits inside the project (dev) or has already been dragged above
// public_html on a live server.
define('TRACK_ROOT', dirname(__DIR__));

// secure_config/ normally sits right here (sibling of config/). On a live
// server it may be dragged one level ABOVE the web root so it can't be
// served over HTTP - support both without any code change on deploy.
$secureConfig = TRACK_ROOT . '/secure_config/secure_config.php';
if (!is_file($secureConfig)) {
    $secureConfig = dirname(TRACK_ROOT) . '/secure_config/secure_config.php';
}
if (!is_file($secureConfig)) {
    http_response_code(500);
    exit('Configuration file not found. secure_config/secure_config.php is missing.');
}
require $secureConfig;
