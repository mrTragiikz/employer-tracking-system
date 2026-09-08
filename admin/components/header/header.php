<?php
/**
 * admin/components/header/header.php
 *
 * Opens <html><body>, the shell, the sidebar (via sidebar.php), and the top bar.
 * PC only - no hamburger, no responsive collapse.
 *
 * A page uses it like:
 * require dirname(__DIR__, 2) . '/includes/bootstrap.php';
 * $me = require_admin();
 * $pageTitle = 'Dashboard';
 * $activeSection = 'dashboard';
 * require dirname(__DIR__) . '/components/header/header.php';
 * // ... page content ...
 * require dirname(__DIR__) . '/components/footer/footer.php';
 *
 * Expects: $pageTitle (string), $activeSection (string), $me (array)
 * Optional: $bodyClass (string) - extra class(es) added to <body class="admin ...">
 */

declare(strict_types=1);

if (!function_exists('e')) {
    require dirname(__DIR__, 3) . '/includes/bootstrap.php';
}

$me = $me ?? (current_user() ?? ['name' => 'Admin', 'role' => 'admin']);
$pageTitle = $pageTitle ?? 'Admin';

// Self-heal sessions started before is_super_admin existed on $_SESSION['user']
// (added 2026-09-04) - look it up once and patch the session so the topbar
// picks it up immediately, no re-login needed.
if (($me['role'] ?? '') === 'admin' && !array_key_exists('is_super_admin', $me)) {
    $flag = false;
    try {
        $stmt = $GLOBALS['pdo']->prepare('SELECT is_super_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $me['id']]);
        $flag = (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        // leave $flag false
    }
    $me['is_super_admin'] = $flag;
    if (isset($_SESSION['user'])) {
        $_SESSION['user']['is_super_admin'] = $flag;
    }
}

$unreadAlerts = 0;
try {
    $unreadAlerts = (int) $GLOBALS['pdo']
        ->query('SELECT COUNT(*) FROM alerts WHERE is_read = 0')
        ->fetchColumn();
} catch (Throwable $e) {
    // alerts table not ready - ignore
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=1280">
  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <?php /* Component CSS - header first (it carries palette tokens + shell frame). */ ?>
  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/admin/components/header/css/header.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/admin/components/sidebar/css/sidebar.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/admin/components/footer/css/footer.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/admin/components/confirm-modal/css/confirm-modal.css')) ?>">

  <?php
  /* This page's own section CSS. The page sets $sectionCss before including
     header.php, e.g. $sectionCss = APP_URL.'/admin/01-dashboard/css/dashboard.css'.
     Run through asset_url() so each file gets a ?v=<mtime> query string -
     without this, editing a CSS file has no effect until every browser's
     cache naturally expires it, which silently hid real fixes tonight. */
  if (!empty($sectionCss)):
      foreach ((array) $sectionCss as $href): ?>
    <link rel="stylesheet" href="<?= e(asset_url($href)) ?>">
  <?php endforeach; endif; ?>
</head>
<body class="admin<?= !empty($bodyClass) ? ' ' . e($bodyClass) : '' ?>">
<div class="admin-shell">

<?php require __DIR__ . '/../sidebar/sidebar.php'; ?>

  <div class="admin-content">
    <header class="topbar">
      <button type="button" class="topbar__collapse" aria-label="Collapse menu" data-sidebar-collapse>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M4 6h16M4 12h10M4 18h16"/>
        </svg>
      </button>

      <div class="topbar__search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>
        </svg>
        <input type="search" placeholder="Search anything…" aria-label="Search">
      </div>

      <div class="topbar__right">
        <button type="button" class="topbar__icon" aria-label="Toggle theme" data-theme-toggle>
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="4.5"/>
            <path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19"/>
          </svg>
        </button>

        <a class="topbar__icon topbar__bell" href="<?= e(admin_url('alerts')) ?>" aria-label="Alerts">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/>
          </svg>
          <?php if ($unreadAlerts > 0): ?>
            <span class="topbar__badge"><?= e((string) min($unreadAlerts, 99)) ?></span>
          <?php endif; ?>
        </a>

        <div class="topbar__user">
          <span class="topbar__avatar" aria-hidden="true"><?= e(mb_substr($me['name'], 0, 1)) ?></span>
          <span class="topbar__user-text">
            <strong><?= e($me['name']) ?></strong>
            <small><?= !empty($me['is_super_admin']) ? 'Super Admin' : e(ucfirst($me['role'] ?? 'admin')) ?></small>
          </span>
          <form method="post" action="<?= e(APP_URL) ?>/admin/login/api/logout.php" class="topbar__logout" id="logout-form">
            <?= csrf_field() ?>
            <button type="submit" aria-label="Log out" data-logout-trigger>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
              </svg>
            </button>
          </form>
        </div>
      </div>
    </header>

    <?php /* ---- logout confirmation modal (wired in header.js) ---- */ ?>
    <div class="logout-modal" id="logout-modal" hidden>
      <div class="logout-modal__backdrop" data-logout-cancel></div>
      <div class="logout-modal__dialog" role="dialog" aria-modal="true"
           aria-labelledby="logout-modal-title" aria-describedby="logout-modal-body">
        <div class="logout-modal__icon" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
          </svg>
        </div>
        <h2 class="logout-modal__title" id="logout-modal-title">Sign out?</h2>
        <p class="logout-modal__body" id="logout-modal-body">
          You will be returned to the sign-in page and will need your
          username and password to get back in.
        </p>
        <div class="logout-modal__actions">
          <button type="button" class="logout-modal__btn logout-modal__btn--ghost" data-logout-cancel>
            Cancel
          </button>
          <button type="button" class="logout-modal__btn logout-modal__btn--danger" data-logout-confirm>
            Sign out
          </button>
        </div>
      </div>
    </div>

    <?php require dirname(__DIR__) . '/confirm-modal/confirm-modal.php'; ?>

    <main class="admin-main">
