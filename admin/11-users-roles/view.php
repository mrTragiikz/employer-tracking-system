<?php
/**
 * admin/11-users-roles/view.php - read-only profile for one regular Admin.
 * URL: /track/admin/11-users-roles/view.php?id=N
 *
 * Super Admin only (same tier as the management list itself). Mirrors
 * super-admin.php's read-only layout, but for a regular admin row - no
 * edit/delete controls live here, those stay on the Edit page.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$me = require_super_admin($me); // Users is Super Admin only
require __DIR__ . '/_repo.php';

$pageTitle     = 'Users';
$activeSection = 'users';
$sectionCss    = APP_URL . '/admin/11-users-roles/css/users-roles.css';

$id = (int) ($_GET['id'] ?? 0);
$admin = $id ? admin_find($pdo, $id) : null;
if ($admin && !empty($admin['is_super_admin'])) {
    // the super admin's own profile is viewed via super-admin.php instead
    redirect(APP_URL . '/admin/11-users-roles/super-admin.php');
}

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head page-head--crumb">
    <div>
      <h1>Admin Details</h1>
      <p class="ur-crumb">
        <a href="<?= e(APP_URL) ?>/admin/11-users-roles/">Users</a>
        <i class="bi bi-chevron-right"></i>
        <span><?= $admin ? e($admin['name']) : 'Not found' ?></span>
      </p>
    </div>
    <div class="page-head__actions">
      <?php if ($admin): ?>
        <a class="btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/form.php?id=<?= (int) $admin['id'] ?>">
          <i class="bi bi-pencil"></i> Edit
        </a>
      <?php endif; ?>
      <a class="btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/"><i class="bi bi-arrow-left"></i> Back to Users</a>
    </div>
  </div>

  <?php if (!$admin): ?>
    <div class="card"><p class="section-note">That admin was not found.</p></div>
  <?php else: ?>

    <section class="card ur-super">
      <div class="ur-super__badge"><i class="bi bi-person-badge-fill"></i> Admin</div>
      <div class="ur-super__body">
        <?php if (!empty($admin['photo_path'])): ?>
          <span class="avatar avatar--lg avatar--photo"><img src="<?= e(UPLOAD_URL . '/' . $admin['photo_path']) ?>" alt=""></span>
        <?php else: ?>
          <span class="avatar avatar--lg"><?= e(admin_initials($admin['name'])) ?></span>
        <?php endif; ?>
        <div class="ur-super__info">
          <span class="ur-super__name">
            <strong><?= e($admin['name']) ?></strong>
            <span class="pill pill--admin">Admin</span>
            <span class="ur-status ur-status--<?= $admin['is_active'] ? 'active' : 'inactive' ?>">
              <i class="bi bi-circle-fill"></i> <?= $admin['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          </span>
          <span class="c-muted ur-super__contact">
            <?php if ($admin['phone']): ?><span><i class="bi bi-telephone"></i> <?= e($admin['phone']) ?></span><?php endif; ?>
            <?php if ($admin['email']): ?><span><i class="bi bi-envelope"></i> <?= e($admin['email']) ?></span><?php endif; ?>
          </span>
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
          <p class="ur-view-value"><?= e($admin['name']) ?></p>
        </div>
        <div class="field">
          <label>Username</label>
          <p class="ur-view-value"><?= e($admin['username'] ?: '-') ?></p>
        </div>
        <div class="field">
          <label>Phone</label>
          <p class="ur-view-value"><?= e($admin['phone'] ?: '-') ?></p>
        </div>
        <div class="field">
          <label>Email</label>
          <p class="ur-view-value"><?= e($admin['email'] ?: '-') ?></p>
        </div>
        <div class="field">
          <label>Document Number</label>
          <p class="ur-view-value"><?= e($admin['id_number'] ?: '-') ?></p>
        </div>
        <div class="field">
          <label>Joined</label>
          <p class="ur-view-value"><?= e((new DateTimeImmutable($admin['created_at']))->format('j M Y')) ?></p>
        </div>

        <?php
        $idFront = !empty($admin['id_photo_front']) ? UPLOAD_URL . '/' . $admin['id_photo_front'] : '';
        $idBack  = !empty($admin['id_photo_back'])  ? UPLOAD_URL . '/' . $admin['id_photo_back']  : '';
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
