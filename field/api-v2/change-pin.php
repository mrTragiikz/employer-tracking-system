<?php
/**
 * POST field/api-v2/change-pin.php - self-service PIN change (mobile).
 *
 * Header: Authorization: Bearer <token>
 * Body (JSON): { "current_pin": "1234", "new_pin": "5678", "new_pin_confirm": "5678" }
 *
 * 200: { "ok": true }
 * 4xx: { "ok": false, "error": "...", "fields": { "new_pin": "..." } }
 *
 * Uses the SAME profile_pin_validate() the web form uses.
 * NOTE: this does NOT re-issue tokens - the app keeps working with its
 * current bearer token; only the login PIN changes.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/profile/_repo.php';
api_method('POST');

$me = api_require();
$in = body();

$hashStmt = $pdo->prepare("SELECT secret_hash FROM users WHERE id = ? AND role = 'employee'");
$hashStmt->execute([(int) $me['id']]);
$currentHash = $hashStmt->fetchColumn();
if ($currentHash === false) {
    json_error('Account not found.', 404);
}

$check = profile_pin_validate(
    (string) $currentHash,
    trim((string) ($in['current_pin'] ?? '')),
    trim((string) ($in['new_pin'] ?? '')),
    trim((string) ($in['new_pin_confirm'] ?? ''))
);

if (!$check['ok']) {
    $e = $check['errors'];
    json_error($e['current_pin'] ?? $e['new_pin'] ?? $e['new_pin_confirm'] ?? 'PIN change failed.', 422, ['fields' => $e]);
}

$pdo->prepare(
    "UPDATE users SET secret_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ? AND role = 'employee'"
)->execute([password_hash(trim((string) $in['new_pin']), PASSWORD_DEFAULT), (int) $me['id']]);

auth_event($pdo, (int) $me['id'], 'pin_changed', 'self-service change (app)');

json_out(['ok' => true]);
