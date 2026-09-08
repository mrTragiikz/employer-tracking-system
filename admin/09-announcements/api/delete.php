<?php
/**
 * admin/09-announcements/api/delete.php - permanently delete an announcement.
 *
 * POST: id
 *
 * Removes the row for good; its announcement_dismissals rows cascade away.
 * If it was live, it stops showing on field screens on their next poll.
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
    announcement_delete($pdo, (int) $me['id'], $id);
} catch (Throwable $e) {
    redirect($listUrl . '?err=delete');
}

redirect($listUrl . '?ok=deleted');
