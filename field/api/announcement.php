<?php
/**
 * field/api/announcement.php - "is there an announcement to show me right now?"
 *
 * GET, employee session required.
 * Returns the ONE live announcement IF it is for this employee AND they have
 * not closed it:
 *   {"announcement": {"id": 12, "title": "...", "body": "..."}}
 * or, when there is nothing to show:
 *   {"announcement": null}
 *
 * "For this employee" = the announcement's audience is 'all', OR the employee
 * is in announcement_targets for it.
 *
 * The field pages poll this every ~8s (see
 * field/components/announcement/announcement.php) so a just-posted
 * announcement appears on a working employee's screen without a reload.
 *
 * Read-only GET: no CSRF. Employee session IS required - an announcement can
 * carry operational instructions, so it is not shown to an unauthenticated
 * caller.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_employee();

// This endpoint only READS the session (require_employee), never writes it.
// Every field page hits it every ~8s, so release the session lock right away
// - otherwise two requests from the same employee (e.g. the poll and a page
// load) would serialise on PHP's per-session file lock. Nothing below needs
// $_SESSION.
session_write_close();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

echo json_encode([
    'announcement' => $row ? [
        'id'    => (int) $row['id'],
        'title' => (string) $row['title'],
        'body'  => (string) $row['body'],
    ] : null,
], JSON_UNESCAPED_UNICODE);
