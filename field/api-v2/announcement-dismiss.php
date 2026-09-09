<?php
/**
 * POST field/api-v2/announcement-dismiss.php - the employee closed the popup.
 *
 * Header: Authorization: Bearer <token>
 * Body (JSON or form): { "id": 12 }
 *
 * 200: { "ok": true }   (always, even if the announcement was already gone -
 *        the popup should close regardless; a failed dismiss just means it
 *        pops again on the next poll, which is harmless.)
 *
 * Records a row in announcement_dismissals so this employee stops seeing this
 * announcement. An admin EDIT / Push again clears these rows.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
api_method('POST');

$me = api_require();
$in = body();
$id = (int) ($in['id'] ?? 0);

if ($id > 0) {
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id) VALUES (?, ?)'
        )->execute([$id, (int) $me['id']]);
    } catch (Throwable $e) {
        // a deleted announcement -> FK error -> nothing left to dismiss
    }
}

json_out(['ok' => true]);
