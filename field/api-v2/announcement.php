<?php
/**
 * GET field/api-v2/announcement.php - the live announcement for this employee.
 *
 * Header: Authorization: Bearer <token>
 *
 * 200:
 *   { "ok": true, "announcement": { "id": 12, "title": "...", "body": "..." } }
 *   or
 *   { "ok": true, "announcement": null }
 *
 * Same rule as the web popup (field/api/announcement.php): the ONE live
 * announcement, IF its audience is 'all' OR this employee is targeted, AND
 * they have not dismissed it. The app polls this and shows a modal.
 * Dismiss via POST field/api-v2/announcement-dismiss.php (Phase A3).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
api_method('GET');

$me  = api_require();
$uid = (int) $me['id'];

$stmt = $pdo->prepare(
    "SELECT a.id, a.title, a.body
       FROM announcements a
      WHERE a.is_active = 1
        AND (
              a.audience = 'all'
              OR EXISTS (
                   SELECT 1 FROM announcement_targets t
                    WHERE t.announcement_id = a.id AND t.user_id = ?
                 )
            )
        AND NOT EXISTS (
              SELECT 1 FROM announcement_dismissals d
               WHERE d.announcement_id = a.id AND d.user_id = ?
            )
      ORDER BY a.id DESC
      LIMIT 1"
);
$stmt->execute([$uid, $uid]);
$row = $stmt->fetch();

json_out([
    'ok' => true,
    'announcement' => $row ? [
        'id'    => (int) $row['id'],
        'title' => (string) $row['title'],
        'body'  => (string) $row['body'],
    ] : null,
]);
