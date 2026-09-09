<?php
/**
 * Runtime settings, read from the `settings` table with config.php as fallback.
 * Cached for the request. Admin Settings screen writes here (with audit_log).
 */

declare(strict_types=1);

function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;

    if ($cache === null) {
        global $pdo;
        $cache = [];
        try {
            $rows = $pdo->query('SELECT key_name, value, value_type FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['key_name']] = setting_cast($r['value'], $r['value_type']);
            }
        } catch (Throwable $e) {
            // settings table not imported yet - fall through to defaults
        }
    }

    return $cache[$key] ?? $default;
}

function setting_cast(string $value, string $type): mixed
{
    return match ($type) {
        'int' => (int) $value,
        'decimal' => (float) $value,
        'bool' => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
        'json' => json_decode($value, true),
        default => $value,
    };
}

// ---- Typed accessors used across the app --------------------------------
function road_factor(): float
{
    return (float) setting('road_factor', DEFAULT_ROAD_FACTOR);
}

function impossible_speed_kmh(): int
{
    return (int) setting('impossible_speed_kmh', IMPOSSIBLE_SPEED_KMH);
}

function bulk_entry_gap_seconds(): int
{
    return (int) setting('bulk_entry_gap_seconds', BULK_ENTRY_GAP_SECONDS);
}

function same_location_tolerance_m(): int
{
    return (int) setting('same_location_tol_m', SAME_LOCATION_TOLERANCE_M);
}

// ---- Live location tracking (Android app only) ------------------------
// Master switch for the whole feature. OFF by default. When off:
//   - the "Live Track" button is hidden on the employee page
//   - field/api-v2/ping.php refuses pings (app stops its GPS service)
//   - the route maps fall back to the checkpoint-line behaviour everywhere
// A browser cannot background-track, so this only ever affects the app.
function live_tracking_enabled(): bool
{
    return (bool) setting('live_tracking_enabled', false);
}

/**
 * How often (seconds) the app should send a GPS fix while checked in.
 * Tunable from Settings without an app rebuild - the app reads it back off
 * every ping response. Clamped to a sane 15s..600s.
 */
function live_tracking_interval_s(): int
{
    $s = (int) setting('live_tracking_interval_s', 90);
    return max(15, min(600, $s));
}

// ---- Attendance check-in window policy ---------------------------------
// Simplified to ONE rule, always enforced the same way: a check-in attempted
// at/after the latest-allowed time is BLOCKED outright (the attendance row
// is never created, so the Employee is Absent for that day exactly the same
// way any other no-show is - see checkin_validate()/checkin.php). There is
// no "mark late but let them in" option any more; if that's ever wanted
// again, it needs deciding fresh, not resurrecting the old dropdown.
function attendance_cutoff_enabled(): bool
{
    return (bool) setting('attendance_cutoff_enabled', false);
}

/** "HH:MM" 24h - the official start of the check-in window (informational). */
function attendance_checkin_open_time(): string
{
    $t = trim((string) setting('attendance_checkin_open_time', '09:00'));
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '09:00';
}

/** "HH:MM" 24h, or null when the policy is off / unset / malformed. */
function attendance_cutoff_time(): ?string
{
    if (!attendance_cutoff_enabled()) {
        return null;
    }
    $t = trim((string) setting('attendance_cutoff_time', ''));
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : null;
}

/**
 * The window open/close times as "HH:MM:SS" strings for a given work date.
 * Both null when the policy is off. Callers compare the attempted check-in
 * time against these.
 */
function attendance_open_for(string $workDate): ?string
{
    if (!attendance_cutoff_enabled()) {
        return null;
    }
    return (new DateTimeImmutable($workDate . ' ' . attendance_checkin_open_time() . ':00'))->format('Y-m-d H:i:s');
}

function attendance_cutoff_for(string $workDate): ?string
{
    $base = attendance_cutoff_time();
    if ($base === null) {
        return null;
    }
    return (new DateTimeImmutable($workDate . ' ' . $base . ':00'))->format('Y-m-d H:i:s');
}

/**
 * True if a check-in attempted RIGHT NOW (server time) for this work date is
 * OUTSIDE the check-in window (before it opens, or at/after it closes) and
 * must be blocked. Always false when the policy is off. Uses "now", not a
 * stored check_in_at, because this is a PRE-check - it runs before any
 * attendance row exists, to decide whether to let the check-in happen at
 * all (see checkin_validate()).
 *
 * Blocks BOTH sides of the window on purpose: an attempt at 2 AM against a
 * 7 AM-10:30 AM window is just as much "not allowed right now" as an
 * attempt at 11 AM - the Employee sees the exact same "too late"/blocked
 * message and has no check-in option either way (there's no separate "too
 * early" message - the field app doesn't distinguish which side of the
 * window was missed, only that today's window isn't open right now).
 */
function attendance_checkin_blocked(string $workDate): bool
{
    if (!attendance_cutoff_enabled()) {
        return false;
    }
    $now = strtotime(server_now());
    $open  = attendance_open_for($workDate);
    $close = attendance_cutoff_for($workDate);
    if ($open !== null && $now < strtotime($open)) {
        return true;
    }
    if ($close !== null && $now >= strtotime($close)) {
        return true;
    }
    return false;
}
