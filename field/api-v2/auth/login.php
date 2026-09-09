<?php
/**
 * POST field/api-v2/auth/login.php  - employee login for the mobile app.
 *
 * Body (JSON): { "phone": "...", "pin": "1234", "device_id": "<uuid>" }
 *
 * Success 200:
 *   { "ok": true, "token": "<rowId>.<hex>",
 *     "employee": { id, name, phone, code, photo_url } }
 *
 * Failure 401:
 *   { "ok": false, "error": "...", "reason": "bad|locked|throttled|suspended|left|device" }
 *
 * All the real checking (throttle, lock, left-the-job, suspended, PIN, one-
 * device rule + first-login device binding) is done by the SAME
 * employee_authenticate() the web login uses - this endpoint just calls it
 * and, on success, mints a bearer token instead of opening a PHP session.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';
api_method('POST');

$in       = body();
$phone    = trim((string) ($in['phone'] ?? ''));
$pin      = (string) ($in['pin'] ?? '');
$deviceId = trim((string) ($in['device_id'] ?? ''));

if ($phone === '' || $pin === '' || $deviceId === '') {
    json_error('Phone, PIN and device_id are all required.', 422);
}

$result = employee_authenticate($pdo, $phone, $pin, $deviceId);

if (is_array($result)) {
    $token = api_issue_token($pdo, (int) $result['id'], $deviceId);

    $photoUrl = !empty($result['photo_path'])
        ? rtrim(UPLOAD_URL, '/') . '/' . ltrim((string) $result['photo_path'], '/')
        : null;

    json_out([
        'ok'    => true,
        'token' => $token,
        'employee' => [
            'id'        => (int) $result['id'],
            'name'      => $result['name'],
            'phone'     => $result['phone'] ?? $phone,
            'code'      => $result['code'] ?? null,
            'photo_url' => $photoUrl,
        ],
    ]);
}

// string error code -> a friendly message + a machine-readable reason
$messages = [
    'throttled' => 'Too many attempts. Wait a few minutes and try again.',
    'locked'    => 'This account is locked after repeated failures. Try again in 15 minutes.',
    'bad'       => 'Wrong phone number or PIN.',
    'suspended' => 'This account has been locked by your admin.',
    'left'      => 'This account is no longer active.',
    'device'    => 'This account is already signed in on another phone. Ask your admin to reset your device.',
];
json_error($messages[$result] ?? $messages['bad'], 401, ['reason' => $result]);
