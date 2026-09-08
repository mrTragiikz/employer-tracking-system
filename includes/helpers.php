<?php
/**
 * Small shared helpers. Output escaping, JSON responses, server time.
 */

declare(strict_types=1);

/** Escape for HTML output. Use on EVERYTHING echoed into a page. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current server datetime string (Asia/Kathmandu). The only time source we trust. */
function server_now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function server_today(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

/**
 * A CSS/JS URL under APP_URL with a cache-busting ?v=<mtime> query string,
 * so an edited file is guaranteed to be re-fetched by the browser instead
 * of silently serving a stale cached copy (bit us repeatedly during field
 * app development - Chrome/mobile browsers cache .css/.js aggressively and
 * a plain reload isn't always enough).
 *
 * @param string $url an APP_URL-based asset URL, e.g. APP_URL.'/field/x/css/x.css'
 */
function asset_url(string $url): string
{
    if (!defined('APP_URL') || !str_starts_with($url, APP_URL . '/')) {
        return $url;
    }
    $relative = substr($url, strlen(APP_URL) + 1);
    $path     = dirname(__DIR__) . '/' . $relative;
    $mtime    = is_file($path) ? filemtime($path) : false;
    return $mtime !== false ? $url . '?v=' . $mtime : $url;
}

/** Send a JSON response and stop. */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Standard JSON error. */
function json_error(string $message, int $status = 400, array $extra = []): never
{
    json_out(['ok' => false, 'error' => $message] + $extra, $status);
}

/** Read a JSON request body into an array (for api/ POSTs). */
function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Client IP as packed binary for INET6_ATON-style storage, or null. */
function client_ip_bin(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = @inet_pton($ip);
    return $packed !== false ? $packed : null;
}

function client_ua(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Redirect helper. */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/**
 * Admin section URL by short slug. The folders are numbered so the file tree
 * lists them in sidebar order, so the slug ('employees') differs from the folder
 * ('02-employees'). This is the single source of truth for that mapping.
 *
 *   admin_url('dashboard')  => http://localhost/track/admin/01-dashboard/
 *   admin_url('users')      => http://localhost/track/admin/11-users-roles/
 *
 * Unknown slugs pass through unchanged (so admin_url('login') still works).
 */
function admin_url(string $slug): string
{
    static $dirs = [
        'dashboard'     => '01-dashboard',
        'employees'     => '02-employees',
        'visits'        => '03-visits',
        'attendance'    => '04-attendance',
        'routes'        => '05-routes-map',
        'reports'       => '06-reports',
        'alerts'        => '07-alerts',
        'photos'        => '08-evidence-photos',
        'announcements' => '09-announcements',
        'settings'      => '10-settings',
        'users'         => '11-users-roles',
    ];
    return APP_URL . '/admin/' . ($dirs[$slug] ?? $slug) . '/';
}

/**
 * Nepali/English label lookup. Extend lang/ne.php as UI grows.
 * Falls back to the key itself so nothing is ever blank.
 */
function t(string $key): string
{
    static $map = null;
    if ($map === null) {
        $file = __DIR__ . '/../lang/' . APP_LOCALE . '.php';
        $map = is_file($file) ? (require $file) : [];
    }
    return $map[$key] ?? $key;
}
