<?php
/**
 * POST field/api-v2/auth/logout.php - sign the app out.
 *
 * Header: Authorization: Bearer <rowId>.<hex>
 *
 * Deletes just THIS token (other devices/sessions for the same employee are
 * untouched). Always returns 200 - a logout should never fail from the
 * client's point of view; if the token was already gone, the job is done.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/_boot.php';
api_method('POST');

$me = api_require();
api_revoke_token($pdo, (int) $me['token_row']);

json_out(['ok' => true]);
