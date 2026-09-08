<?php
/**
 * CSRF protection. One token per session, required on every state-changing
 * request (forms AND api/ POST/PUT/DELETE).
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || empty($_SESSION['csrf_time'])
        || (time() - $_SESSION['csrf_time']) > CSRF_TOKEN_TTL) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_time'] = time();
    }
    return $_SESSION['csrf'];
}

/** Hidden input for HTML forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the incoming token. Checks POST body, then X-CSRF-Token header
 * (used by api/ fetch calls). Never verifies on GET.
 */
function csrf_check(): bool
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return true;
    }
    $sent = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? (json_body()['csrf_token'] ?? '');

    return is_string($sent)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $sent);
}

/** Enforce, or stop the request. Call at the top of every handler. */
function csrf_require(): void
{
    if (!csrf_check()) {
        // 403, not 419: 419 ("Authentication Timeout") is a non-standard
        // Laravel-ism that PHP's http_response_code() doesn't recognise, so
        // some SAPIs turn it into a 500. 403 Forbidden is the correct
        // standard code for "the request could not be authenticated".
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            json_error('Bad or missing CSRF token. Reload the page and try again.', 403);
        }
        http_response_code(403);
        exit('Bad or missing CSRF token. Go back, reload, and try again.');
    }
}
