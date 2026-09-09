<?php
/**
 * admin/10-settings/_repo.php - read + write the `settings` table.
 *
 * The Settings section is deliberately small: it edits a handful of typed
 * policy rows, each with a validator. Nothing here is destructive - a bad
 * value is rejected, the rest are saved, and every change is audit-logged.
 */

declare(strict_types=1);

/**
 * Split a stored 24h "HH:MM" into 12-hour picker parts. Falls back to
 * 09:00 on anything malformed/empty, so the picker always has a sane
 * starting position rather than blank/broken selects.
 *
 * @return array{hour12:int, minute:int, ampm:'AM'|'PM'}
 */
function time24_to_parts(string $t): array
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t, $m)) {
        $t = '09:00';
        preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t, $m);
    }
    $h24 = (int) $m[1];
    $min = (int) $m[2];
    $ampm = $h24 >= 12 ? 'PM' : 'AM';
    $h12  = $h24 % 12;
    if ($h12 === 0) $h12 = 12;
    return ['hour12' => $h12, 'minute' => $min, 'ampm' => $ampm];
}

/** "HH:MM" 24h -> "9:00 AM" style, for display (never for storage). */
function fmt_time_12h(string $t): string
{
    $p = time24_to_parts($t);
    return sprintf('%d:%02d %s', $p['hour12'], $p['minute'], $p['ampm']);
}

/**
 * The settings this section exposes, grouped for the page.
 * Each field: type, label, help, and (for select) options.
 * `type` drives both the input and the validator.
 */
function settings_schema(): array
{
    return [
        'attendance' => [
            'title' => 'Attendance check-in',
            'icon'  => 'bi-calendar-check',
            'note'  => 'Controls the daily check-in window for field Employees.',
            'fields' => [
                'attendance_cutoff_enabled' => [
                    'type'  => 'bool',
                    'label' => 'Enforce a check-in window',
                    'help'  => 'When on, an Employee who checks in at or after the Check-in Portal Closing Time is blocked and marked Absent for the day.',
                ],
                'attendance_checkin_open_time' => [
                    'type'  => 'time12',
                    'label' => 'Check-in Portal Opening Time',
                    'help'  => 'Local time (Asia/Kathmandu). The check-in portal opens at this time - shown to Employees for reference; does not by itself block anything earlier.',
                ],
                'attendance_cutoff_time' => [
                    'type'  => 'time12',
                    'label' => 'Check-in Portal Closing Time',
                    'help'  => 'Local time (Asia/Kathmandu). The check-in portal closes at this time - checking in at or after this is blocked outright, and the day is recorded as Absent.',
                ],
            ],
        ],

        'tracking' => [
            'title' => 'Live location tracking',
            'icon'  => 'bi-broadcast',
            'note'  => 'Android app only. Records where a worker travels between check-in and check-out, shown as the real road path on the route maps and the "Live Track" button. iOS / web workers are unaffected - a browser cannot track in the background. Does NOT change any audit figure (KM / time are still computed from check-in, visits and check-out only).',
            'fields' => [
                'live_tracking_enabled' => [
                    'type'  => 'bool',
                    'label' => 'Enable live location tracking',
                    'help'  => 'When on: app workers\' phones send their GPS position while checked in, the "Live Track" button appears on each app worker\'s page, and the route maps show the real path they rode. When off: everything reverts to the current checkpoint-line behaviour and the app stops sending location.',
                ],
                'live_tracking_interval_s' => [
                    'type'  => 'int',
                    'label' => 'Ping interval (seconds)',
                    'min'   => 15,
                    'max'   => 600,
                    'help'  => 'How often each phone sends a location fix. Lower = smoother "live" movement but more battery. 90 is a good balance; use 20-30 for a near-live moving dot after you have tested battery on real phones.',
                ],
            ],
        ],
    ];
}

/** All keys this section can write. */
function settings_editable_keys(): array
{
    $keys = [];
    foreach (settings_schema() as $group) {
        foreach ($group['fields'] as $key => $_) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/** Current stored values for the editable keys (raw strings). */
function settings_values(PDO $pdo): array
{
    $keys = settings_editable_keys();
    $in   = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT key_name, value FROM settings WHERE key_name IN ($in)");
    $stmt->execute($keys);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['key_name']] = $r['value'];
    }
    return $out;
}

/**
 * Validate one field's submitted value against its schema entry.
 * Returns [ok, normalisedValue|null, error|null].
 */
function settings_validate_field(array $field, mixed $raw): array
{
    switch ($field['type']) {
        case 'bool':
            return [true, in_array((string) $raw, ['1', 'on', 'true', 'yes'], true) ? '1' : '0', null];

        case 'int':
            if (!is_numeric($raw)) {
                return [false, null, 'must be a number'];
            }
            $n = (int) $raw;
            if (isset($field['min']) && $n < $field['min']) {
                return [false, null, 'must be at least ' . $field['min']];
            }
            if (isset($field['max']) && $n > $field['max']) {
                return [false, null, 'must be at most ' . $field['max']];
            }
            return [true, (string) $n, null];

        case 'time':
        case 'time12': // same stored format (24h "HH:MM") - only the input UI differs, see setting.php
            $t = trim((string) $raw);
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) {
                return [false, null, 'must be a valid time'];
            }
            return [true, $t, null];

        case 'select':
            $t = (string) $raw;
            if (!array_key_exists($t, $field['options'])) {
                return [false, null, 'is not a valid choice'];
            }
            return [true, $t, null];

        default:
            return [true, (string) $raw, null];
    }
}

/**
 * Save the submitted values. Returns [savedCount, errors(map key=>msg)].
 * Only rows that actually changed are written + audited.
 */
function settings_save(PDO $pdo, int $actorId, array $post): array
{
    $before  = settings_values($pdo);
    $errors  = [];
    $changed = [];

    foreach (settings_schema() as $group) {
        foreach ($group['fields'] as $key => $field) {
            // bool checkboxes are absent from POST when unchecked
            $raw = $field['type'] === 'bool' ? ($post[$key] ?? '0') : ($post[$key] ?? null);
            if ($raw === null) {
                continue; // field not on this form submission
            }
            [$ok, $val, $err] = settings_validate_field($field, $raw);
            if (!$ok) {
                $errors[$key] = $field['label'] . ' ' . $err . '.';
                continue;
            }
            if (($before[$key] ?? null) !== $val) {
                $changed[$key] = $val;
            }
        }
    }

    if ($errors || !$changed) {
        return [0, $errors];
    }

    $upd = $pdo->prepare(
        "INSERT INTO settings (key_name, value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)"
    );
    $pdo->beginTransaction();
    foreach ($changed as $key => $val) {
        $upd->execute([$key, $val, $actorId]);
    }
    // one audit row for the batch
    $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, entity, entity_id, before_json, after_json, ip)
         VALUES (?, 'setting.update', 'settings', NULL, ?, ?, ?)"
    )->execute([
        $actorId,
        json_encode(array_intersect_key($before, $changed), JSON_UNESCAPED_UNICODE),
        json_encode($changed, JSON_UNESCAPED_UNICODE),
        client_ip_bin(),
    ]);
    $pdo->commit();

    return [count($changed), []];
}
