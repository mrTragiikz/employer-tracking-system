<?php
/**
 * field/login/api/status.php - "is this phone's account still suspended?"
 *
 * GET: p = phone number
 * Returns: {"suspended": true|false}
 *
 * Used ONLY by the suspended login screen (field/login/index.php) to poll,
 * so it can auto-reload the moment an admin unlocks the account - the
 * employee's phone is otherwise stuck on a dead screen until they manually
 * refresh.
 *
 * Deliberately gives back nothing but the one boolean:
 *  - an unknown / deleted phone reports suspended = true (indistinguishable
 *    from a real lock), so this can't be used to probe which numbers exist
 *  - no name, no code, no reason - nothing an unauthenticated caller could
 *    mine
 * No auth (the whole point is the session was just killed), no CSRF (it's a
 * read-only GET).
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$phone = trim((string) ($_GET['p'] ?? ''));

// Malformed / missing phone -> treat as "still suspended" (nothing to reload to).
if ($phone === '' || !preg_match('/^[0-9+\- ]{7,20}$/', $phone)) {
    echo json_encode(['suspended' => true]);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT is_active, deleted_at
       FROM users
      WHERE role = 'employee' AND phone = ?
      LIMIT 1"
);
$stmt->execute([$phone]);
$row = $stmt->fetch();

$suspended = ($row === false)
    || $row['deleted_at'] !== null
    || (int) $row['is_active'] !== 1;

echo json_encode(['suspended' => $suspended]);
