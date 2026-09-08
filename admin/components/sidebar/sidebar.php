<?php
/**
 * admin/components/sidebar/sidebar.php
 *
 * Fixed left rail (PC only). Included BY header.php - never directly.
 * Logo block, nav (11 items), and a "Need Help?" card.
 * Icons are Bootstrap Icons (web font - the CDN <link> is in header.php).
 *
 * Active item: set $activeSection = '<slug>' in the page before header.php
 * (slug = short key: dashboard, employees, visits, attendance, routes, reports,
 * alerts, photos, settings, users).
 *
 * Folders are numbered so the file tree lists them in sidebar order; the
 * slug -> real folder mapping lives in includes/helpers.php::admin_url().
 */

declare(strict_types=1);

$activeSection = $activeSection ?? '';

/** slug => [label, bootstrap-icon class] - order = sidebar order */
$nav = [
    'dashboard' => ['Dashboard', 'bi-grid-1x2'],
    'employees' => ['Employees', 'bi-people'],
    'visits' => ['Visits', 'bi-geo-alt'],
    'attendance' => ['Attendance', 'bi-clock-history'],
    'routes' => ['Routes & Map', 'bi-map'],
    'alerts' => ['Alerts', 'bi-bell'],
    'photos' => ['Evidence Photos', 'bi-camera'],
    'settings' => ['Settings', 'bi-gear'],
    'users' => ['Users', 'bi-person-badge'],
];

// Settings is Super Admin only - a Normal Admin never sees it here (the page
// itself also refuses the request - see require_super_admin() in
// includes/auth.php - this hides the link, that enforces the boundary).
//
// The Users & Roles MANAGEMENT page is Super Admin only too, but every admin
// (including Normal Admin) needs a way to reach their own account - so for a
// Normal Admin this nav item is repointed from the management page straight
// to their own self-edit form instead of being removed outright.
$usersHref = admin_url('users');
if (empty($me['is_super_admin'])) {
    unset($nav['settings']);
    $nav['users'][0] = 'My Account';
    $usersHref = admin_url('users') . 'form.php?self=1';
}
?>
<aside class="sidebar" data-sidebar>
  <a class="sidebar__logo" href="<?= e(admin_url('dashboard')) ?>">
    <span class="sidebar__logo-mark" aria-hidden="true"><i class="bi bi-geo-alt-fill"></i></span>
    <span class="sidebar__logo-text">
      <strong><?= e(APP_NAME) ?></strong>
      <small>TRACK · MANAGE · GROW</small>
    </span>
  </a>

  <nav class="sidebar__nav">
    <ul>
      <?php foreach ($nav as $slug => [$label, $icon]): ?>
        <li>
          <a class="sidebar__link<?= $activeSection === $slug ? ' is-active' : '' ?>"
             href="<?= e($slug === 'users' ? $usersHref : admin_url($slug)) ?>" data-label="<?= e($label) ?>">
            <i class="bi <?= e($icon) ?> sidebar__icon" aria-hidden="true"></i>
            <span><?= e($label) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <a class="sidebar__help" href="<?= e(admin_url('reports')) ?>">
    <span class="sidebar__help-icon" aria-hidden="true"><i class="bi bi-headset"></i></span>
    <span class="sidebar__help-text">
      <strong>Need Help?</strong>
      <small>Visit Help Center →</small>
    </span>
  </a>
</aside>
