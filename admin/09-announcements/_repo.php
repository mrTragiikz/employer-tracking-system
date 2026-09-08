<?php
/**
 * admin/09-announcements/_repo.php - read + write the `announcements` table.
 *
 * A Super Admin posts a short title + message. Every logged-in employee's
 * field screen shows the ONE active announcement as a popup (the field pages
 * poll field/api/announcement.php ~every 30s). Once an employee closes it,
 * a row lands in `announcement_dismissals` and it stays closed for them.
 *
 * Rules baked in here:
 *   - Every announcement you post is LIVE immediately (no drafts). Only one
 *     is is_active = 1 at a time - posting or editing one deactivates the
 *     rest (they stay in the list as history).
 *   - audience = 'all' shows it to every employee; audience = 'selected'
 *     shows it only to the employees in announcement_targets.
 *   - Saving (create OR edit) clears the announcement's dismissals, so a
 *     revised message re-shows to everyone who had already closed the old one.
 *   - "Push again" (announcement_push_again) re-fires an announcement without
 *     changing its text: it becomes the live one and its dismissals are
 *     cleared, so every targeted employee sees the popup again.
 *   - Nothing is destructive except announcement_delete() (an explicit
 *     "remove this" - cascades its dismissals AND targets). Every write is
 *     audit-logged.
 */

declare(strict_types=1);

/**
 * All announcements, newest first, with the author's name, the dismissed
 * count, and (for 'selected' ones) how many employees it targets.
 *
 * @return array<int,array<string,mixed>>
 */
function announcements_list(PDO $pdo): array
{
    return $pdo->query(
        "SELECT a.*, u.name AS author_name,
                (SELECT COUNT(*) FROM announcement_dismissals d WHERE d.announcement_id = a.id) AS dismissed_count,
                (SELECT COUNT(*) FROM announcement_targets t WHERE t.announcement_id = a.id) AS target_count
           FROM announcements a
           LEFT JOIN users u ON u.id = a.created_by
          ORDER BY a.id DESC"
    )->fetchAll();
}

/**
 * Active field employees for the "specific employees" picker: id, name, code.
 * Excludes locked, deleted and former employees - you cannot usefully send a
 * live popup to someone who cannot log in.
 *
 * @return array<int,array{id:int,name:string,code:?string}>
 */
function announcement_employee_choices(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name, code
           FROM users
          WHERE role = 'employee'
            AND deleted_at IS NULL
            AND left_job_at IS NULL
            AND is_active = 1
          ORDER BY name ASC"
    )->fetchAll();
}

/**
 * The user ids this announcement targets (empty for an 'all' announcement).
 *
 * @return int[]
 */
function announcement_target_ids(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM announcement_targets WHERE announcement_id = ?');
    $stmt->execute([$id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** One announcement by id, or null. */
function announcement_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * The single announcement currently shown to employees, or null. Carries the
 * author name and dismissed count for the admin "Showing now" card (the
 * lightweight field/api/announcement.php endpoint uses its own leaner query).
 * (If more than one is somehow is_active = 1, the newest wins - the
 * ix_ann_active index orders this.)
 */
function announcement_active(PDO $pdo): ?array
{
    $row = $pdo->query(
        "SELECT a.*, u.name AS author_name,
                (SELECT COUNT(*) FROM announcement_dismissals d WHERE d.announcement_id = a.id) AS dismissed_count,
                (SELECT COUNT(*) FROM announcement_targets t WHERE t.announcement_id = a.id) AS target_count
           FROM announcements a
           LEFT JOIN users u ON u.id = a.created_by
          WHERE a.is_active = 1
          ORDER BY a.id DESC LIMIT 1"
    )->fetch();
    return $row ?: null;
}

/**
 * Validate a submitted title + body + audience + target list.
 *
 * @return array{0:array<string,string>,1:array{title:string,body:string,audience:string,targets:int[]}}
 *         [errors(map field=>msg), normalised]
 */
function announcement_validate(PDO $pdo, array $post): array
{
    $errors = [];

    $title = trim((string) ($post['title'] ?? ''));
    $body  = trim((string) ($post['body'] ?? ''));

    // Normalise newlines so the stored length check matches what the field
    // modal will render, and \r\n doesn't secretly eat into the 2000 cap.
    $body = str_replace(["\r\n", "\r"], "\n", $body);

    if ($title === '') {
        $errors['title'] = 'Title is required.';
    } elseif (mb_strlen($title) < 3) {
        $errors['title'] = 'Title is too short (at least 3 characters).';
    } elseif (mb_strlen($title) > 120) {
        $errors['title'] = 'Title is too long (120 characters maximum).';
    }

    if ($body === '') {
        $errors['body'] = 'Message is required.';
    } elseif (mb_strlen($body) < 3) {
        $errors['body'] = 'Message is too short (at least 3 characters).';
    } elseif (mb_strlen($body) > 2000) {
        $errors['body'] = 'Message is too long (2000 characters maximum).';
    }

    $audience = ($post['audience'] ?? 'all') === 'selected' ? 'selected' : 'all';

    // Targets: only meaningful for 'selected'. Filter to real, eligible
    // employee ids so a stale/forged id can never end up in the table.
    $targets = [];
    if ($audience === 'selected') {
        $raw = $post['targets'] ?? [];
        $raw = is_array($raw) ? array_map('intval', $raw) : [];
        $raw = array_values(array_unique(array_filter($raw, static fn($n) => $n > 0)));

        if ($raw) {
            $in   = implode(',', array_fill(0, count($raw), '?'));
            $stmt = $pdo->prepare(
                "SELECT id FROM users
                  WHERE role = 'employee' AND deleted_at IS NULL AND left_job_at IS NULL
                    AND is_active = 1 AND id IN ($in)"
            );
            $stmt->execute($raw);
            $targets = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (!$targets) {
            $errors['targets'] = 'Pick at least one employee, or choose "Everyone".';
        }
    }

    return [$errors, [
        'title'    => $title,
        'body'     => $body,
        'audience' => $audience,
        'targets'  => $targets,
    ]];
}

/**
 * Create or update an announcement. It always goes LIVE (no drafts) - posting
 * or editing one makes it the single active announcement and deactivates the
 * rest.
 *
 * @param int|null $id        null = create, int = update that row
 * @param string   $audience  'all' | 'selected'
 * @param int[]    $targetIds  employee ids for 'selected' (ignored for 'all')
 * @return int  the announcement id
 */
function announcement_save(PDO $pdo, int $actorId, ?int $id, string $title, string $body, string $audience, array $targetIds): int
{
    $audience = $audience === 'selected' ? 'selected' : 'all';
    if ($audience === 'all') {
        $targetIds = [];
    }

    $pdo->beginTransaction();
    try {
        if ($id === null) {
            $pdo->prepare(
                'INSERT INTO announcements (title, body, is_active, audience, created_by)
                 VALUES (?, ?, 1, ?, ?)'
            )->execute([$title, $body, $audience, $actorId]);
            $id = (int) $pdo->lastInsertId();
            $before = null;
        } else {
            $before = announcement_find($pdo, $id);
            $pdo->prepare(
                'UPDATE announcements SET title = ?, body = ?, is_active = 1, audience = ? WHERE id = ?'
            )->execute([$title, $body, $audience, $id]);
        }

        // Only one live at a time.
        $pdo->prepare('UPDATE announcements SET is_active = 0 WHERE id <> ?')->execute([$id]);

        // Replace the target list wholesale (a handful of rows).
        $pdo->prepare('DELETE FROM announcement_targets WHERE announcement_id = ?')->execute([$id]);
        if ($audience === 'selected' && $targetIds) {
            $ins = $pdo->prepare('INSERT INTO announcement_targets (announcement_id, user_id) VALUES (?, ?)');
            foreach ($targetIds as $uid) {
                $ins->execute([$id, (int) $uid]);
            }
        }

        // A create or an edit re-shows: drop this announcement's dismissals so
        // a returning "closed it already" employee sees it again.
        $pdo->prepare('DELETE FROM announcement_dismissals WHERE announcement_id = ?')->execute([$id]);

        announcement_audit($pdo, $actorId, $before === null ? 'announcement.create' : 'announcement.update', $id,
            $before === null ? null : [
                'title'    => $before['title'],
                'body'     => $before['body'],
                'audience' => $before['audience'] ?? 'all',
            ],
            [
                'title'    => $title,
                'body'     => $body,
                'audience' => $audience,
                'targets'  => $targetIds,
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $id;
}

/**
 * "Push again" - re-fire an announcement without changing its text. It becomes
 * the single live one and its dismissals are cleared, so every targeted
 * employee sees the popup again on their next check (within ~8 seconds).
 */
function announcement_push_again(PDO $pdo, int $actorId, int $id): bool
{
    $row = announcement_find($pdo, $id);
    if ($row === null) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE announcements SET is_active = 1 WHERE id = ?')->execute([$id]);
        $pdo->prepare('UPDATE announcements SET is_active = 0 WHERE id <> ?')->execute([$id]);
        $pdo->prepare('DELETE FROM announcement_dismissals WHERE announcement_id = ?')->execute([$id]);

        announcement_audit($pdo, $actorId, 'announcement.push_again', $id,
            ['is_active' => (int) $row['is_active']],
            ['is_active' => 1, 'dismissals_cleared' => true]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}

/** Permanently delete an announcement (its dismissals + targets cascade away). */
function announcement_delete(PDO $pdo, int $actorId, int $id): bool
{
    $row = announcement_find($pdo, $id);
    if ($row === null) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        announcement_audit($pdo, $actorId, 'announcement.delete', $id,
            ['title' => $row['title'], 'is_active' => (int) $row['is_active'], 'audience' => $row['audience'] ?? 'all'], null);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return true;
}

/**
 * Audit-log an announcement action. Mirrors employee_audit() in
 * admin/02-employees/_repo.php - same audit_log table, entity = 'announcement'.
 */
function announcement_audit(PDO $pdo, int $actorId, string $action, int $id, ?array $before, ?array $after): void
{
    $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, entity, entity_id, before_json, after_json, ip)
         VALUES (?, ?, 'announcement', ?, ?, ?, ?)"
    )->execute([
        $actorId, $action, $id,
        $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        client_ip_bin(),
    ]);
}
