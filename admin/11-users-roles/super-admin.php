<?php
/**
 * admin/11-users-roles/super-admin.php - "View Super Admin Details" - a
 * READ-ONLY profile page any signed-in admin may open (Normal Admins reach
 * the Users section only through this one page; there is no edit/delete
 * control anywhere on it - management stays on users-roles.php, Super Admin
 * only).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';

$pageTitle     = 'Users';
$activeSection = 'users';
$sectionCss    = APP_URL . '/admin/11-users-roles/css/users-roles.css';

$super = super_admin_find($pdo);

$backUrl = !empty($me['is_super_admin'])
    ? APP_URL . '/admin/11-users-roles/'
    : APP_URL . '/admin/01-dashboard/';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head page-head--crumb">
    <div>
      <h1>Super Admin Details</h1>
      <p class="ur-crumb">
        <a href="<?= e($backUrl) ?>"><?= !empty($me['is_super_admin']) ? 'Users' : 'Dashboard' ?></a>
        <i class="bi bi-chevron-right"></i>
        <span>Super Admin</span>
      </p>
    </div>
    <div class="page-head__actions">
      <a class="btn" href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!$super): ?>
    <div class="card"><p class="section-note">No Super Admin is set up yet.</p></div>
  <?php else: ?>

    <section class="card ur-super">
      <div class="ur-super__badge"><i class="bi bi-shield-lock-fill"></i> Super Admin</div>
      <div class="ur-super__body">
        <?php if (!empty($super['photo_path'])): ?>
          <span class="avatar avatar--lg avatar--photo"><img src="<?= e(UPLOAD_URL . '/' . $super['photo_path']) ?>" alt=""></span>
        <?php else: ?>
          <span class="avatar avatar--lg"><?= e(admin_initials($super['name'])) ?></span>
        <?php endif; ?>
        <div class="ur-super__info">
          <span class="ur-super__name">
            <strong><?= e($super['name']) ?></strong>
            <span class="pill pill--super">Super Admin</span>
          </span>
          <span class="c-muted ur-super__contact">
            <?php if ($super['phone']): ?><span><i class="bi bi-telephone"></i> <?= e($super['phone']) ?></span><?php endif; ?>
            <?php if ($super['email']): ?><span><i class="bi bi-envelope"></i> <?= e($super['email']) ?></span><?php endif; ?>
          </span>
          <span class="ur-super__note">View only - the Super Admin's own details can only be changed by the Super Admin.</span>
        </div>
      </div>
    </section>

    <section class="card form-card">
      <div class="card__head">
        <h2>Profile Details</h2>
      </div>
      <div class="form-grid">
        <div class="field">
          <label>Full Name</label>
          <p class="ur-view-value"><?= e($super['name']) ?></p>
        </div>
        <div class="field">
          <label>Phone</label>
          <p class="ur-view-value"><?= e($super['phone'] ?: '-') ?></p>
        </div>
        <div class="field">
          <label>Email</label>
          <p class="ur-view-value"><?= e($super['email'] ?: '-') ?></p>
        </div>
        <div class="field">
          <label>Document Number</label>
          <p class="ur-view-value"><?= e($super['id_number'] ?: '-') ?></p>
        </div>

        <?php
        $idFront = !empty($super['id_photo_front']) ? UPLOAD_URL . '/' . $super['id_photo_front'] : '';
        $idBack  = !empty($super['id_photo_back'])  ? UPLOAD_URL . '/' . $super['id_photo_back']  : '';
        $idShots = [
            ['Document photo - front', $idFront],
            ['Document photo - back',  $idBack],
        ];
        foreach ($idShots as [$label, $cur]): ?>
          <div class="field id-shot">
            <label><?= e($label) ?></label>
            <div class="id-shot__row">
              <span class="id-shot__thumb">
                <?php if ($cur !== ''): ?>
                  <button type="button" class="js-photo-view" data-src="<?= e($cur) ?>" data-label="<?= e($label) ?>">
                    <img src="<?= e($cur) ?>" alt="">
                  </button>
                <?php else: ?>
                  <i class="bi bi-person-vcard"></i>
                <?php endif; ?>
              </span>
              <p class="section-note" style="margin:0;"><?= $cur !== '' ? 'On file.' : 'Not on file.' ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

  <?php endif; ?>

  <!-- ===== document photo viewer modal ===== -->
  <div class="photo-modal" id="photo-modal" hidden>
    <div class="photo-modal__backdrop" data-photo-modal-close></div>
    <div class="photo-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="photo-modal-title">
      <div class="photo-modal__head">
        <span id="photo-modal-title"></span>
        <button type="button" class="photo-modal__close" data-photo-modal-close aria-label="Close">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
      <img id="photo-modal-img" src="" alt="">
    </div>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/11-users-roles/js/users-roles.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
