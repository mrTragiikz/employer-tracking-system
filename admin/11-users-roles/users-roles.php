<?php
/**
 * admin/11-users-roles/users-roles.php - Users section.
 * URL: /track/admin/11-users-roles/?q=&status=&sort=
 *
 * Admin accounts only (the field employee roster is 02-employees/). A
 * "Current Admin" card for the viewer's own account, then a card grid of
 * every manageable admin (Super Admin excluded - see admins_all()) plus a
 * trailing "Add New Admin" tile. Card-grid UI per supplied design
 * (2026-09-05) - see css/users-roles.css.
 *
 * Build order step 7.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$me = require_super_admin($me); // Users is Super Admin only
require __DIR__ . '/_repo.php';

$pageTitle     = 'Users';
$activeSection = 'users';
$sectionCss    = APP_URL . '/admin/11-users-roles/css/users-roles.css';

$q      = trim((string) ($_GET['q'] ?? ''));
$status = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$sort   = in_array($_GET['sort'] ?? '', ['newest', 'oldest', 'name'], true) ? $_GET['sort'] : 'newest';
$super  = super_admin_find($pdo);
$admins = admins_all($pdo, $q, $status, $sort);
$flash  = $_GET['ok'] ?? '';

$sortLabels = ['newest' => 'Newest First', 'oldest' => 'Oldest First', 'name' => 'Name (A-Z)'];

/** Build a URL to this page with some GET params changed. */
function users_url(array $override = []): string
{
    $q = array_merge($_GET, $override);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return APP_URL . '/admin/11-users-roles/' . ($q ? '?' . http_build_query($q) : '');
}

require dirname(__DIR__) . '/components/header/header.php';
?>

<div class="users-page">

  <div class="users-header">
    <div>
      <h1>Users</h1>
      <p>Manage admin and employer accounts.</p>
    </div>
    <a class="btn-brown" href="<?= e(APP_URL) ?>/admin/11-users-roles/form.php">
      <i class="bi bi-person-plus-fill"></i> Add Admin Account
    </a>
  </div>

  <?php if ($flash === 'created'): ?><div class="flash flash--ok">Admin added.</div>
  <?php elseif ($flash === 'updated'): ?><div class="flash flash--ok">Saved.</div>
  <?php elseif ($flash === 'deleted'): ?><div class="flash flash--ok">Admin removed.</div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['admin_form_error']['_'])):
    $topErr = $_SESSION['admin_form_error']['_']; unset($_SESSION['admin_form_error']); ?>
    <div class="flash flash--err"><?= e($topErr) ?></div>
  <?php endif; ?>

  <!-- ===== current admin ===== -->
  <?php if ($super): ?>
    <section class="current-admin">
      <div class="current-admin-title"><i class="bi bi-shield-lock-fill"></i> Current Admin</div>
      <div class="current-admin-content">
        <div class="admin-profile">
          <span class="admin-avatar">
            <?php if (!empty($super['photo_path'])): ?>
              <img src="<?= e(UPLOAD_URL . '/' . $super['photo_path']) ?>" alt="">
            <?php else: ?>
              <?= e(admin_initials($super['name'])) ?>
            <?php endif; ?>
          </span>
          <div>
            <span class="admin-name"><?= e($super['name']) ?></span>
            <span class="admin-role">Super Admin</span>
            <p class="admin-description">This is your primary admin account with full access to all modules and settings.</p>
          </div>
        </div>
        <?php if ($me['id'] === (int) $super['id']): ?>
          <a class="btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/form.php?self=1">
            <i class="bi bi-pencil"></i> Edit Admin Account
          </a>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ===== all admins ===== -->
  <section class="admin-accounts">
    <div class="accounts-header">
      <div class="accounts-title">
        <h2>Admin Accounts <span><?= count($admins) ?></span></h2>
        <p>All admin accounts in the system.</p>
      </div>

      <form method="get" action="<?= e(APP_URL) ?>/admin/11-users-roles/" class="accounts-tools">
        <div class="search-box">
          <i class="bi bi-search"></i>
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or phone...">
        </div>
        <?php if ($status !== ''): ?>
          <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <?php if ($sort !== 'newest'): ?>
          <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <?php endif; ?>

        <div class="tool-menu">
          <button type="button" class="tool-btn js-menu-toggle" aria-haspopup="true" aria-expanded="false">
            <i class="bi bi-funnel"></i> Filters<?= $status !== '' ? ' (1)' : '' ?>
          </button>
          <div class="tool-panel" hidden>
            <p class="tool-panel-label">Status</p>
            <a href="<?= e(users_url(['status' => null])) ?>" class="<?= $status === '' ? 'is-active' : '' ?>">
              <i class="bi bi-check-lg"></i> All
            </a>
            <a href="<?= e(users_url(['status' => 'active'])) ?>" class="<?= $status === 'active' ? 'is-active' : '' ?>">
              <i class="bi bi-check-lg"></i> Active
            </a>
            <a href="<?= e(users_url(['status' => 'inactive'])) ?>" class="<?= $status === 'inactive' ? 'is-active' : '' ?>">
              <i class="bi bi-check-lg"></i> Inactive
            </a>
          </div>
        </div>

        <div class="tool-menu">
          <button type="button" class="tool-btn js-menu-toggle" aria-haspopup="true" aria-expanded="false">
            <?= e($sortLabels[$sort]) ?> <i class="bi bi-chevron-down"></i>
          </button>
          <div class="tool-panel" hidden>
            <?php foreach ($sortLabels as $key => $label): ?>
              <a href="<?= e(users_url(['sort' => $key === 'newest' ? null : $key])) ?>" class="<?= $sort === $key ? 'is-active' : '' ?>">
                <i class="bi bi-check-lg"></i> <?= e($label) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </form>
    </div>

    <?php if (!$admins && $q === '' && $status === ''): ?>
      <p class="accounts-empty">No admins yet.</p>
    <?php else: ?>
      <div class="admin-grid">
        <?php foreach ($admins as $a):
          $isMe = (int) $a['id'] === (int) $me['id'];
        ?>
          <div class="admin-card">
            <div class="admin-card-top">
              <span class="card-avatar">
                <?php if (!empty($a['photo_path'])): ?>
                  <img src="<?= e(UPLOAD_URL . '/' . $a['photo_path']) ?>" alt="">
                <?php else: ?>
                  <?= e(admin_initials($a['name'])) ?>
                <?php endif; ?>
                <span class="online-dot <?= $a['is_active'] ? '' : 'offline-dot' ?>"></span>
              </span>
              <p class="card-name"><?= e($a['name']) ?></p>
              <div class="card-role-wrap">
                <span class="card-role">Admin</span>
                <?php if ($isMe): ?><span class="card-role card-role--you">You</span><?php endif; ?>
              </div>
            </div>

            <div class="card-details">
              <div class="card-detail"><i class="bi bi-telephone"></i> <span><?= e($a['phone'] ?: 'No phone on file') ?></span></div>
              <div class="card-detail"><i class="bi bi-envelope"></i> <span><?= e($a['email'] ?: 'No email on file') ?></span></div>
              <div class="card-detail"><i class="bi bi-person-badge"></i> <span><?= e($a['username'] ?: 'No username') ?></span></div>
              <div class="card-detail"><i class="bi bi-calendar3"></i> <span><?= e((new DateTimeImmutable($a['created_at']))->format('j M Y')) ?></span></div>
            </div>

            <div class="card-actions">
              <a class="action-btn view-btn" href="<?= e(APP_URL) ?>/admin/11-users-roles/view.php?id=<?= (int) $a['id'] ?>">
                <i class="bi bi-eye"></i> View
              </a>
              <a class="action-btn edit-btn" title="Edit"
                 href="<?= e(APP_URL) ?>/admin/11-users-roles/form.php?id=<?= (int) $a['id'] ?>">
                <i class="bi bi-pencil"></i>
              </a>
              <?php if (!$isMe): ?>
                <form method="post" action="<?= e(APP_URL) ?>/admin/11-users-roles/api/toggle-active.php" class="js-confirm"
                      data-confirm-title="<?= $a['is_active'] ? 'Deactivate admin?' : 'Activate admin?' ?>"
                      data-confirm-label="<?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>"
                      data-confirm-tone="<?= $a['is_active'] ? 'danger' : 'primary' ?>"
                      data-confirm-body="<?= $a['is_active']
                          ? e($a['name']) . ' will no longer be able to log in until reactivated.'
                          : e($a['name']) . ' will be able to log in again.' ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="action-btn <?= $a['is_active'] ? 'deactivate-btn' : 'activate-btn' ?>"
                          title="<?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>">
                    <i class="bi bi-<?= $a['is_active'] ? 'lock-fill' : 'unlock-fill' ?>"></i>
                  </button>
                </form>
              <?php endif; ?>
              <form method="post" action="<?= e(APP_URL) ?>/admin/11-users-roles/api/delete.php" class="js-delete" data-name="<?= e($a['name']) ?>"
                    data-confirm-title="Remove admin?" data-confirm-label="Remove">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button type="submit" class="action-btn delete-btn" title="Delete">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>

        <!-- ===== add-new tile ===== -->
        <div class="add-admin-card">
          <span class="add-admin-icon"><i class="bi bi-person-plus"></i></span>
          <h3>Add New Admin</h3>
          <p>Create a new admin account and manage system access.</p>
          <a class="btn-brown" href="<?= e(APP_URL) ?>/admin/11-users-roles/form.php">
            <i class="bi bi-person-plus-fill"></i> Add Admin
          </a>
        </div>
      </div>

      <?php if (!$admins): ?>
        <p class="accounts-empty">No admins match your search or filters.</p>
      <?php endif; ?>

      <div class="accounts-footer">
        <span>Showing 1 to <?= count($admins) ?> of <?= count($admins) ?> admin<?= count($admins) === 1 ? '' : 's' ?></span>
        <div class="pagination">
          <span class="page-btn is-disabled"><i class="bi bi-chevron-left"></i></span>
          <a class="page-btn active" href="#">1</a>
          <span class="page-btn is-disabled"><i class="bi bi-chevron-right"></i></span>
        </div>
      </div>
    <?php endif; ?>
  </section>

</div>

  <script src="<?= e(APP_URL) ?>/admin/11-users-roles/js/users-roles.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
