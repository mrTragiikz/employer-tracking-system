<?php
/**
 * admin/09-announcements/api/push.php - "Push again".
 *
 * POST: id
 *
 * Re-fires an announcement without changing its text: it becomes the single
 * live one and its dismissals are cleared, so every targeted employee sees
 * the popup again on their next check (within ~8 seconds).
 *
 * Super Admin only. CSRF enforced by includes/api.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me);
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id      = (int) ($_POST['id'] ?? 0);
$listUrl = APP_URL . '/admin/09-announcements/';

if ($id <= 0) {
    redirect($listUrl);
}

try {
    if (!announcement_push_again($pdo, (int) $me['id'], $id)) {
        redirect($listUrl); // gone already
    }
} catch (Throwable $e) {
    redirect($listUrl . '?err=push');
}

redirect($listUrl . '?ok=pushed');
