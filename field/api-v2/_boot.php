<?php
/**
 * field/api-v2/_boot.php - shared entry for every mobile JSON API endpoint.
 *
 * The mobile app (Flutter) is stateless: it sends
 *   Authorization: Bearer <rowId>.<hex>
 * on every request. No PHP session, no cookie.
 *
 * First lines of every field/api-v2 endpoint:
 *
 *   require dirname(__DIR__, 2) . '/includes/bootstrap.php';   // NO - use this file
 *   require dirname(__DIR__) . '/api-v2/_boot.php';
 *   $me = api_require();          // 401s if the token is missing / invalid
 *   api_method('POST');           // optional method guard
 *
 * This file:
 *   - boots the app stateless (TRACK_STATELESS_REQUEST -> no session)
 *   - forces JSON responses + CORS-safe headers
 *   - exposes $pdo, all helpers, api_require(), api_method(), body()
 */

declare(strict_types=1);

// Stateless: bootstrap.php checks this and skips session_start().
define('TRACK_STATELESS_REQUEST', true);

require dirname(__DIR__, 2) . '/includes/bootstrap.php'; // -> $pdo, helpers, auth

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// The app is a native client, not a browser page, so CORS is not really in
// play - but a permissive header keeps a webview/debug tool from choking.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Require a valid bearer token. Returns the employee array (id, name, phone,
 * code, photo_path, device_id, token_row). 401 on any failure - the app then
 * clears its stored token and shows the login screen.
 */
function api_require(): array
{
    global $pdo;
    $me = api_user($pdo);
    if ($me === null) {
        json_error('Not authenticated.', 401);
    }
    return $me;
}

/** Require a specific request method, else 405. */
function api_method(string ...$allowed): void
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($m, array_map('strtoupper', $allowed), true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error('Method not allowed.', 405);
    }
}

/**
 * Request body as an array. Accepts JSON (Content-Type: application/json) OR
 * multipart/urlencoded form fields (for the photo-upload endpoints, where the
 * file rides in $_FILES and the rest in $_POST).
 */
function body(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') === 0) {
        return json_body();
    }
    return $_POST;
}
