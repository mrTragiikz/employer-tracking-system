<?php
/**
 * field/api/announcement-dismiss.php - "the employee closed the popup".
 *
 * POST: csrf_token, id
 * Employee session required. Records a row in announcement_dismissals so this
 * employee stops seeing this announcement (an admin EDIT clears these rows,
 * so a revised message re-shows).
 *
 * Returns {"ok": true} on success, or on any problem still {"ok": true} with
 * a note - the popup should close either way; a failed dismiss just means it
 * pops again on the next poll, which is harmless.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/api.php'; // bootstrap + JSON + CSRF (POST)
$me = require_employee();
api_method('POST');

$id = (int) ($_POST['id'] ?? 0);

if ($id > 0) {
    try {
        // INSERT IGNORE: closing twice (double tap, a poll racing the dismiss)
        // is a no-op, not an error. FK also guarantees the announcement and
        // the user both still exist.
        $pdo->prepare(
            'INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id) VALUES (?, ?)'
        )->execute([$id, (int) $me['id']]);
    } catch (Throwable $e) {
        // A missing announcement (deleted between poll and dismiss) throws on
        // the FK - fine, there is nothing left to dismiss.
    }
}

echo json_encode(['ok' => true]);
