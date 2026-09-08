<?php
/**
 * admin/09-announcements/announcements.php - Announcements list.
 * URL: /track/admin/09-announcements/
 *
 * Super Admin only (same gate as Settings). Shows the ONE currently-live
 * announcement at the top with Edit + "Push again", then a table of every
 * announcement ever posted with Edit + Delete. "New announcement" -> form.php.
 *
 * Every announcement goes live on save (no drafts). "Push again" re-fires the
 * live one (clears its dismissals) so everyone sees the popup again.
 *
 * The employee-facing popup lives in field/components/announcement/ and reads
 * announcement_active() via field/api/announcement.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$me = require_super_admin($me); // Announcements is Super Admin only

require __DIR__ . '/_repo.php';

$pageTitle     = 'Announcements';
$activeSection  = 'announcements';
$sectionCss     = APP_URL . '/admin/09-announcements/css/announcements.css';

$all    = announcements_list($pdo);
$active = announcement_active($pdo);
$flash  = $_GET['ok']  ?? '';
$err    = $_GET['err'] ?? '';

$fmtWhen = static fn(?string $d) => $d ? (new DateTimeImmutable($d))->format('j M Y, g:i A') : '-';

/** "Everyone" or "3 employees" - who an announcement row targets. */
$audienceLabel = static function (array $a): string {
    if (($a['audience'] ?? 'all') !== 'selected') {
        return 'Everyone';
    }
    $n = (int) ($a['target_count'] ?? 0);
    return $n . ' employee' . ($n === 1 ? '' : 's');
};

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Announcements</h1>
      <p class="section-note">
        Post a short notice. It pops up on the field screens of everyone (or a
        chosen few) within a few seconds, even while they are working. Only one
        announcement is live at a time. Use <strong>Push again</strong> to
        re-show it to everyone.
      </p>
    </div>
    <div class="page-head__actions">
      <a class="btn btn--primary" href="<?= e(APP_URL) ?>/admin/09-announcements/form.php">
        <i class="bi bi-plus-lg"></i> New announcement
      </a>
    </div>
  </div>

  <?php if ($flash === 'posted'): ?>
    <div class="flash flash--ok">Announcement posted. The chosen employees see it within a few seconds.</div>
  <?php elseif ($flash === 'updated'): ?>
    <div class="flash flash--ok">Announcement updated. Everyone sees the new version again, even if they had closed the old one.</div>
  <?php elseif ($flash === 'pushed'): ?>
    <div class="flash flash--ok">Pushed again. Every chosen employee sees the popup again within a few seconds.</div>
  <?php elseif ($flash === 'deleted'): ?>
    <div class="flash flash--ok">Announcement deleted.</div>
  <?php endif; ?>
  <?php if ($err === 'save'): ?>
    <div class="flash flash--err">Could not save the announcement. Please try again.</div>
  <?php elseif ($err === 'push' || $err === 'delete'): ?>
    <div class="flash flash--err">Could not do that. Please try again.</div>
  <?php endif; ?>

  <?php /* ---- what's live right now ---- */ ?>
  <section class="card an-live">
    <div class="card__head"><h2>Showing now</h2></div>
    <?php if ($active === null): ?>
      <div class="an-live__none">
        <i class="bi bi-megaphone"></i>
        <p>No announcement is showing. Post one and it appears on the chosen field screens.</p>
      </div>
    <?php else: ?>
      <div class="an-live__card">
        <div class="an-live__badge"><span class="an-dot"></span> Live</div>
        <h3 class="an-live__title"><?= e($active['title']) ?></h3>
        <p class="an-live__body"><?= nl2br(e($active['body'])) ?></p>
        <div class="an-live__meta">
          <i class="bi bi-people"></i> <?= e($audienceLabel($active)) ?>
          &middot; posted by <?= e($active['author_name'] ?: 'an admin') ?>
          &middot; <?= e($fmtWhen($active['created_at'])) ?>
          <?php if ((int) $active['dismissed_count'] > 0): ?>
            &middot; closed by <?= (int) $active['dismissed_count'] ?> employee<?= (int) $active['dismissed_count'] === 1 ? '' : 's' ?>
          <?php endif; ?>
        </div>
        <div class="an-live__actions">
          <a class="btn" href="<?= e(APP_URL) ?>/admin/09-announcements/form.php?id=<?= (int) $active['id'] ?>">
            <i class="bi bi-pencil"></i> Edit
          </a>
          <form method="post" action="<?= e(APP_URL) ?>/admin/09-announcements/api/push.php" class="js-confirm" style="margin:0"
                data-confirm-title="Push this announcement again?" data-confirm-label="Push again" data-confirm-tone="primary"
                data-confirm-body="Every chosen employee sees the popup again within a few seconds, even the ones who had closed it. The message text does not change.">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $active['id'] ?>">
            <button type="submit" class="btn"><i class="bi bi-broadcast"></i> Push again</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <?php /* ---- every announcement ---- */ ?>
  <section class="card an-table-card">
    <div class="card__head"><h2>All announcements</h2></div>
    <?php if (!$all): ?>
      <p class="section-note">Nothing posted yet.</p>
    <?php else: ?>
      <div class="an-table-wrap">
        <table class="an-table">
          <thead>
            <tr>
              <th>Title</th>
              <th>Message</th>
              <th>Who</th>
              <th>Posted</th>
              <th class="an-table__actions-h">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($all as $a): ?>
              <?php $isLive = (int) $a['is_active'] === 1; ?>
              <tr class="<?= $isLive ? 'is-live' : '' ?>">
                <td class="an-table__title">
                  <?= e($a['title']) ?>
                  <?php if ($isLive): ?><span class="badge badge--approved an-livetag"><span class="an-dot"></span> Live</span><?php endif; ?>
                </td>
                <td class="an-table__msg"><?= e(mb_strimwidth($a['body'], 0, 90, '...')) ?></td>
                <td class="an-table__who"><?= e($audienceLabel($a)) ?></td>
                <td class="an-table__when">
                  <?= e($fmtWhen($a['created_at'])) ?><br>
                  <span class="c-muted"><?= e($a['author_name'] ?: 'admin') ?></span>
                </td>
                <td class="an-table__actions">
                  <a class="an-iconbtn" href="<?= e(APP_URL) ?>/admin/09-announcements/form.php?id=<?= (int) $a['id'] ?>"
                     aria-label="Edit" title="Edit"><i class="bi bi-pencil"></i></a>

                  <form method="post" action="<?= e(APP_URL) ?>/admin/09-announcements/api/delete.php" class="js-confirm an-inline"
                        data-confirm-title="Delete this announcement?" data-confirm-label="Delete"
                        data-confirm-body="This removes it for good. If it is live, it stops showing immediately.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button type="submit" class="an-iconbtn an-iconbtn--danger" aria-label="Delete" title="Delete"><i class="bi bi-trash3"></i></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
