<?php
/**
 * field/profile/api/change-pin.php - self-service PIN change.
 *
 * POST: csrf_token, current_pin, new_pin, new_pin_confirm
 * Browser form endpoint (not a JSON api/) - redirects rather than
 * returning JSON, same pattern as the rest of the field app. Requires the
 * correct current PIN before accepting a new one.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
$me = require_employee();
require dirname(__DIR__) . '/_repo.php';

$formUrl = APP_URL . '/field/profile/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($formUrl);
}
if (!csrf_check()) {
    $_SESSION['profile_pin_error'] = ['_' => 'Your session expired. Please try again.'];
    redirect($formUrl);
}

$hashStmt = $pdo->prepare("SELECT secret_hash FROM users WHERE id = ? AND role = 'employee'");
$hashStmt->execute([$me['id']]);
$currentHash = $hashStmt->fetchColumn();

if ($currentHash === false) {
    redirect($formUrl);
}

$currentPin    = trim((string) ($_POST['current_pin'] ?? ''));
$newPin        = trim((string) ($_POST['new_pin'] ?? ''));
$newPinConfirm = trim((string) ($_POST['new_pin_confirm'] ?? ''));

$check = profile_pin_validate((string) $currentHash, $currentPin, $newPin, $newPinConfirm);

if (!$check['ok']) {
    $_SESSION['profile_pin_error'] = $check['errors'];
    redirect($formUrl);
}

$pdo->prepare(
    "UPDATE users SET secret_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ? AND role = 'employee'"
)->execute([password_hash($newPin, PASSWORD_DEFAULT), $me['id']]);

auth_event($pdo, (int) $me['id'], 'pin_changed', 'self-service change');

redirect($formUrl . '?ok=pin');
