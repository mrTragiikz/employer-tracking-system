<?php
/**
 * Shared entry guard for every section endpoint under
 * admin/<section>/api/*.php and field/<section>/api/*.php
 *
 * Usage - first two lines of every such file:
 *
 * require dirname(__DIR__, 3) . '/includes/api.php'; // boot + JSON errors + CSRF
 * $me = require_admin(); // or require_field()
 *
 * dirname(__DIR__, 3) walks: api/ -> <section>/ -> admin|field/ -> project root.
 *
 * This file:
 * - boots the app (session, timezone, $pdo, helpers)
 * - forces JSON content-type + nosniff
 * - requires an authenticated session (any role)
 * - enforces CSRF on POST/PUT/PATCH/DELETE
 * It does NOT pick the role - the endpoint calls require_admin()/require_field() next.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!is_logged_in()) {
    json_error('Not authenticated.', 401);
}

csrf_require();

/**
 * Convenience: require a specific request method or 405.
 * api_method('POST');
 */
function api_method(string ...$allowed): void
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($m, array_map('strtoupper', $allowed), true)) {
        header('Allow: ' . implode(', ', $allowed));
        json_error('Method not allowed.', 405);
    }
}
