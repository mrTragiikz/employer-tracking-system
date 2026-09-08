<?php
/**
 * admin/11-users-roles/form.php - add/edit an Admin, or an admin's own
 * self-edit (name + password only).
 *
 *   ?self=1     - the signed-in admin (super or regular) editing their own
 *                  account - the only mode a Normal Admin may ever reach here
 *   ?id=N       - Super Admin only: edit another regular admin
 *   (neither)   - Super Admin only: create a new admin
 *
 * POSTs to api/save.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';

$isSelf = ($_GET['self'] ?? '') === '1';
if (!$isSelf) {
    // Managing OTHER admins (create, or edit-by-id) is Super Admin only.
    // Editing your own account (?self=1) is open to every admin.
    $me = require_super_admin($me);
}

$super = super_admin_find($pdo);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$admin = null;
if ($isSelf) {
    // Any admin editing their own row - look up by session id, not $super.
    $admin = admin_find($pdo, (int) $me['id']);
    $id = (int) $me['id'];
} elseif ($id) {
    $admin = admin_find($pdo, $id);
    if ($admin && !empty($admin['is_super_admin'])) {
        // the super admin's row is only reachable via ?self=1
        redirect(APP_URL . '/admin/11-users-roles/');
    }
}

$pageTitle     = 'Users';
$activeSection = 'users';
$sectionCss    = APP_URL . '/admin/11-users-roles/css/users-roles.css';

if ($id && !$admin) {
    require dirname(__DIR__) . '/components/header/header.php';
    echo '<div class="card"><p class="section-note">That admin was not found. <a href="' . e(APP_URL) . '/admin/11-users-roles/">Back to list</a>.</p></div>';
    require dirname(__DIR__) . '/components/footer/footer.php';
    exit;
}

$isEdit = $admin !== null;

$errors = $_SESSION['admin_form_error'] ?? [];
$old    = $_SESSION['admin_form_old'] ?? [];
unset($_SESSION['admin_form_error'], $_SESSION['admin_form_old']);

/** field value: old input wins (after a failed save), then the DB row, then ''. */
function afv(string $key, ?array $old, ?array $admin, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($admin !== null && array_key_exists($key, $admin)) {
        return (string) ($admin[$key] ?? '');
    }
    return $default;
}

$activeChecked = $isEdit
    ? (array_key_exists('is_active', $old) ? !empty($old['is_active']) : (int) $admin['is_active'] === 1)
    : (array_key_exists('is_active', $old) ? !empty($old['is_active']) : true);

// A Normal Admin has no Users & Roles page to go back to - send them to the
// dashboard instead. The Super Admin keeps the normal "back to Users" link.
$selfBackUrl = !empty($me['is_super_admin'])
    ? APP_URL . '/admin/11-users-roles/'
    : APP_URL . '/admin/01-dashboard/';
$selfTierLabel = !empty($admin['is_super_admin']) ? 'Super Admin' : 'Admin';

require dirname(__DIR__) . '/components/header/header.php';
?>

<?php if ($isSelf): ?>

  <div class="page-head page-head--crumb">
    <div>
      <h1>My Account</h1>
      <p class="ur-crumb">
        <a href="<?= e($selfBackUrl) ?>"><?= !empty($me['is_super_admin']) ? 'Users' : 'Dashboard' ?></a>
        <i class="bi bi-chevron-right"></i>
        <span>Edit <?= e($selfTierLabel) ?></span>
      </p>
    </div>
  </div>

  <!-- ===== current admin ===== -->
  <section class="card ur-super">
    <div class="ur-super__badge"><i class="bi bi-shield-lock-fill"></i> Current <?= e($selfTierLabel) ?></div>
    <div class="ur-super__body">
      <?php if (!empty($admin['photo_path'])): ?>
        <span class="avatar avatar--lg avatar--photo"><img src="<?= e(UPLOAD_URL . '/' . $admin['photo_path']) ?>" alt=""></span>
      <?php else: ?>
        <span class="avatar avatar--lg"><?= e(admin_initials($admin['name'])) ?></span>
      <?php endif; ?>
      <div class="ur-super__info">
        <span class="ur-super__name">
          <strong><?= e($admin['name']) ?></strong>
          <span class="pill <?= !empty($admin['is_super_admin']) ? 'pill--super' : 'pill--admin' ?>"><?= e($selfTierLabel) ?></span>
        </span>
        <span class="c-muted ur-super__contact">
          <?php if ($admin['phone']): ?><span><i class="bi bi-telephone"></i> <?= e($admin['phone']) ?></span><?php endif; ?>
          <?php if ($admin['email']): ?><span><i class="bi bi-envelope"></i> <?= e($admin['email']) ?></span><?php endif; ?>
        </span>
        <span class="ur-super__note"><?= !empty($me['is_super_admin']) ? 'This is your primary admin account with full access to all modules and settings.' : 'Your account details. Contact the Super Admin for anything beyond your name and password.' ?></span>
      </div>
      <?php if (empty($me['is_super_admin'])): ?>
        <a class="btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/super-admin.php">
          <i class="bi bi-eye"></i> View Super Admin Details
        </a>
      <?php endif; ?>
      <a class="btn" href="<?= e($selfBackUrl) ?>">
        <i class="bi bi-arrow-left"></i> <?= !empty($me['is_super_admin']) ? 'Back to Users' : 'Back to Dashboard' ?>
      </a>
    </div>
  </section>

  <?php if (!empty($errors['_'])): ?>
    <div class="flash flash--err"><?= e($errors['_']) ?></div>
  <?php elseif (!empty($errors)): ?>
    <div class="flash flash--err">
      <?= count($errors) === 1 ? 'That was not saved - please fix the highlighted field below.' : 'That was not saved - please fix the ' . count($errors) . ' highlighted fields below.' ?>
    </div>
  <?php endif; ?>

  <!-- ===== edit form ===== -->
  <form class="card form-card ur-self-form" method="post"
        action="<?= e(APP_URL) ?>/admin/11-users-roles/api/save.php" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $admin['id'] ?>">
    <input type="hidden" name="self" value="1">

    <div class="ur-self-form__head">
      <span class="ur-self-form__icon"><i class="bi bi-pencil"></i></span>
      <div>
        <h2>Edit <?= e($selfTierLabel) ?> Account</h2>
        <p class="section-note">Update your name or password.</p>
      </div>
    </div>

    <div class="form-grid">
      <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <label for="f-name">Full Name <span class="ur-req">*</span></label>
        <input class="input" id="f-name" name="name" required maxlength="120"
               value="<?= e(afv('name', $old, $admin)) ?>">
        <?php if (isset($errors['name'])): ?><span class="field-err"><?= e($errors['name']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
        <label for="f-password">New Password <span class="c-muted">(leave blank to keep current)</span></label>
        <div class="pw-input">
          <i class="bi bi-lock"></i>
          <input class="input" id="f-password" type="password" name="password" autocomplete="new-password"
                 minlength="8" placeholder="Enter new password">
          <button type="button" class="pw-input__eye js-pw-toggle" data-target="f-password" aria-label="Show password">
            <i class="bi bi-eye"></i>
          </button>
        </div>
        <?php if (isset($errors['password'])): ?><span class="field-err"><?= e($errors['password']) ?></span><?php endif; ?>
      </div>

      <div class="field field--wide <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
        <label for="f-password-confirm">Confirm New Password</label>
        <div class="pw-input">
          <i class="bi bi-lock"></i>
          <input class="input" id="f-password-confirm" type="password" name="password_confirm" autocomplete="new-password"
                 placeholder="Confirm new password">
          <button type="button" class="pw-input__eye js-pw-toggle" data-target="f-password-confirm" aria-label="Show password">
            <i class="bi bi-eye"></i>
          </button>
        </div>
        <?php if (isset($errors['password_confirm'])): ?><span class="field-err"><?= e($errors['password_confirm']) ?></span><?php endif; ?>
        <p class="section-note" style="margin:4px 0 0;">Leave password fields blank if you don't want to change it.</p>
      </div>
    </div>

    <div class="form-actions">
      <a class="btn" href="<?= e($selfBackUrl) ?>">Cancel</a>
      <button type="submit" class="btn btn--primary" id="f-submit">
        <i class="bi bi-check-lg"></i> Save Changes
      </button>
    </div>
  </form>

<?php else: ?>

  <div class="page-head">
    <div>
      <h1><?= $isEdit ? 'Edit Admin' : 'Add Admin' ?></h1>
      <p class="section-note">
        <?= $isEdit ? e($admin['name']) : 'Create a new admin account for this panel.' ?>
      </p>
    </div>
    <div class="page-head__actions">
      <a class="btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!empty($errors['_'])): ?>
    <div class="flash flash--err"><?= e($errors['_']) ?></div>
  <?php elseif (!empty($errors)): ?>
    <div class="flash flash--err">
      <?= count($errors) === 1 ? 'That was not saved - please fix the highlighted field below.' : 'That was not saved - please fix the ' . count($errors) . ' highlighted fields below.' ?>
    </div>
  <?php endif; ?>

  <form class="card form-card" method="post" enctype="multipart/form-data"
        action="<?= e(APP_URL) ?>/admin/11-users-roles/api/save.php" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $admin['id'] ?>"><?php endif; ?>

      <!-- ===== profile photo ===== -->
      <div class="admin-photo <?= isset($errors['photo']) ? 'has-error' : '' ?>">
        <?php $curPhoto = $isEdit && !empty($admin['photo_path']) ? UPLOAD_URL . '/' . $admin['photo_path'] : ''; ?>
        <div class="admin-photo__preview" id="photo-preview"
             data-fallback="<?= e(admin_initials(afv('name', $old, $admin) ?: 'A')) ?>">
          <?php if ($curPhoto !== ''): ?>
            <img src="<?= e($curPhoto) ?>" alt="">
          <?php else: ?>
            <span class="admin-photo__ph"><?= e(admin_initials(afv('name', $old, $admin) ?: 'A')) ?></span>
          <?php endif; ?>
        </div>
        <div class="admin-photo__body">
          <label for="f-photo">Profile photo <span class="c-muted">(optional, JPEG/PNG/WebP, max 200 KB)</span></label>
          <input class="input" id="f-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
          <?php if ($isEdit && $curPhoto !== ''): ?>
            <label class="check admin-photo__remove">
              <input type="checkbox" name="photo_remove" value="1">
              <span>Remove the current photo</span>
            </label>
          <?php endif; ?>
          <p class="admin-photo__hint" id="photo-hint"></p>
          <?php if (isset($errors['photo'])): ?><span class="field-err"><?= e($errors['photo']) ?></span><?php endif; ?>
        </div>
      </div>

    <div class="form-grid">
      <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <label for="f-name">Full name</label>
        <input class="input" id="f-name" name="name" required maxlength="120"
               value="<?= e(afv('name', $old, $admin)) ?>">
        <?php if (isset($errors['name'])): ?><span class="field-err"><?= e($errors['name']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
        <label for="f-password"><?= $isEdit ? 'New password' : 'Password' ?>
          <?php if ($isEdit): ?><span class="c-muted">(leave blank to keep current)</span><?php endif; ?>
        </label>
        <div class="pw-input">
          <i class="bi bi-lock"></i>
          <input class="input" id="f-password" type="password" name="password" autocomplete="new-password"
                 <?= $isEdit ? '' : 'required' ?> minlength="8" placeholder="<?= $isEdit ? 'Enter new password' : 'Enter password' ?>">
          <button type="button" class="pw-input__eye js-pw-toggle" data-target="f-password" aria-label="Show password">
            <i class="bi bi-eye"></i>
          </button>
        </div>
        <?php if (isset($errors['password'])): ?><span class="field-err"><?= e($errors['password']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['password_confirm']) ? 'has-error' : '' ?>">
        <label for="f-password-confirm">Confirm <?= $isEdit ? 'new password' : 'password' ?></label>
        <div class="pw-input">
          <i class="bi bi-lock"></i>
          <input class="input" id="f-password-confirm" type="password" name="password_confirm" autocomplete="new-password"
                 <?= $isEdit ? '' : 'required' ?> placeholder="Confirm <?= $isEdit ? 'new ' : '' ?>password">
          <button type="button" class="pw-input__eye js-pw-toggle" data-target="f-password-confirm" aria-label="Show password">
            <i class="bi bi-eye"></i>
          </button>
        </div>
        <?php if (isset($errors['password_confirm'])): ?><span class="field-err"><?= e($errors['password_confirm']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['username']) ? 'has-error' : '' ?>">
        <label for="f-username">Username</label>
        <input class="input" id="f-username" name="username" maxlength="60"
               placeholder="letters, numbers, dot, underscore"
               value="<?= e(afv('username', $old, $admin)) ?>">
        <?php if (isset($errors['username'])): ?><span class="field-err"><?= e($errors['username']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
        <label for="f-email">Email <span class="c-muted">(optional)</span></label>
        <input class="input" id="f-email" type="email" name="email" maxlength="190"
               value="<?= e(afv('email', $old, $admin)) ?>">
        <?php if (isset($errors['email'])): ?><span class="field-err"><?= e($errors['email']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
        <label for="f-phone">Phone <span class="c-muted">(optional)</span></label>
        <input class="input" id="f-phone" name="phone" maxlength="20"
               value="<?= e(afv('phone', $old, $admin)) ?>">
        <?php if (isset($errors['phone'])): ?><span class="field-err"><?= e($errors['phone']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="f-role">Role</label>
        <select class="select" id="f-role" name="role_display" disabled>
          <option selected>Admin</option>
        </select>
        <p class="section-note" style="margin:4px 0 0;">Only Admin accounts can be created here. The Super Admin is fixed.</p>
      </div>

      <div class="field <?= isset($errors['id_number']) ? 'has-error' : '' ?>">
        <label for="f-idnum">Document number <span class="c-muted">(optional)</span></label>
        <input class="input" id="f-idnum" name="id_number" maxlength="40"
               placeholder="citizenship / national ID no."
               value="<?= e(afv('id_number', $old, $admin)) ?>">
        <?php if (isset($errors['id_number'])): ?><span class="field-err"><?= e($errors['id_number']) ?></span><?php endif; ?>
      </div>

      <div class="field field--check">
        <label class="check">
          <input type="checkbox" name="is_active" value="1" <?= $activeChecked ? 'checked' : '' ?>>
          <span>Active (can sign in)</span>
        </label>
      </div>

      <?php
      $idFront = $isEdit && !empty($admin['id_photo_front']) ? UPLOAD_URL . '/' . $admin['id_photo_front'] : '';
      $idBack  = $isEdit && !empty($admin['id_photo_back'])  ? UPLOAD_URL . '/' . $admin['id_photo_back']  : '';
      $idShots = [
          ['front', 'Document photo - front', $idFront, $errors['id_photo_front'] ?? null],
          ['back',  'Document photo - back',  $idBack,  $errors['id_photo_back'] ?? null],
      ];
      foreach ($idShots as [$side, $label, $cur, $err]): ?>
        <div class="field id-shot <?= $err ? 'has-error' : '' ?>">
          <label for="f-id-<?= $side ?>"><?= e($label) ?> <span class="c-muted">(optional, max 200 KB)</span></label>
          <div class="id-shot__row">
            <span class="id-shot__thumb" id="id-<?= $side ?>-thumb">
              <?php if ($cur !== ''): ?>
                <a href="<?= e($cur) ?>" target="_blank" rel="noopener"><img src="<?= e($cur) ?>" alt=""></a>
              <?php else: ?>
                <i class="bi bi-person-vcard"></i>
              <?php endif; ?>
            </span>
            <div class="id-shot__body">
              <input class="input js-id-file" id="f-id-<?= $side ?>" name="id_photo_<?= $side ?>" type="file"
                     accept="image/jpeg,image/png,image/webp" data-thumb="id-<?= $side ?>-thumb" data-hint="id-<?= $side ?>-hint">
              <?php if ($isEdit && $cur !== ''): ?>
                <label class="check id-shot__remove">
                  <input type="checkbox" name="id_photo_<?= $side ?>_remove" value="1">
                  <span>Remove</span>
                </label>
              <?php endif; ?>
              <p class="id-shot__hint" id="id-<?= $side ?>-hint"></p>
              <?php if ($err): ?><span class="field-err"><?= e($err) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn--primary" id="f-submit">
        <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save changes' : 'Create admin' ?>
      </button>
      <a class="btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/">Cancel</a>
    </div>
  </form>

<?php endif; ?>

  <script src="<?= e(APP_URL) ?>/admin/11-users-roles/js/users-roles.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
