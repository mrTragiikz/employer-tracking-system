<?php
/**
 * field/components/header/header.php
 *
 * Opens <html><body>, the mobile app shell, and the top brand bar. Mirrors
 * admin/components/header/header.php's pattern (a page requires this, then
 * its content, then footer.php) but for the field app: mobile-only, no
 * sidebar - navigation is the bottom tab bar in footer.php instead.
 *
 * A page uses it like:
 *   require dirname(__DIR__, 2) . '/includes/bootstrap.php';
 *   $me = require_employee();
 *   $pageTitle = 'Dashboard';
 *   $activeTab = 'dashboard';
 *   require dirname(__DIR__) . '/components/header/header.php';
 *   // ... page content ...
 *   require dirname(__DIR__) . '/components/footer/footer.php';
 *
 * Expects: $pageTitle (string), $activeTab (string), $me (array)
 */

declare(strict_types=1);

if (!function_exists('e')) {
    require dirname(__DIR__, 3) . '/includes/bootstrap.php';
}

$me = $me ?? (current_user() ?? ['name' => 'Employee', 'role' => 'employee']);
$pageTitle = $pageTitle ?? 'Track';

// $_SESSION['user'] doesn't carry photo_path - a cheap single-column lookup
// so the header avatar can show the real profile photo when there is one.
$headerPhoto = null;
if (!empty($me['id'])) {
    $hpStmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
    $hpStmt->execute([$me['id']]);
    $headerPhoto = $hpStmt->fetchColumn() ?: null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">

  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>

  <?php /* PWA: makes the field app installable + gives it the offline page.
           Scope is <APP_URL>/field/ only - the admin panel is unaffected.
           manifest.php (not a static .webmanifest) so its URLs are correct
           on both the live domain root and the local /try/ subfolder. */ ?>
  <link rel="manifest" href="<?= e(APP_URL) ?>/field/manifest.php">
  <meta name="theme-color" content="#6b4423">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="Rajdoot">
  <link rel="apple-touch-icon" href="<?= e(APP_URL) ?>/field/icons/icon-192.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/field/components/header/css/header.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/field/components/footer/css/footer.css')) ?>">

  <?php
  /* This page's own section CSS. The page sets $sectionCss before including
     header.php, e.g. $sectionCss = APP_URL.'/field/home/css/home.css'; -
     asset_url() appends ?v=<mtime> so an edited file is never served stale
     from the browser cache. */
  if (!empty($sectionCss)):
      foreach ((array) $sectionCss as $href): ?>
    <link rel="stylesheet" href="<?= e(asset_url($href)) ?>">
  <?php endforeach; endif; ?>
</head>
<body class="field<?= !empty($bodyClass) ? ' ' . e($bodyClass) : '' ?>">
<div class="field-shell">

  <header class="field-topbar">
    <a class="field-topbar__brand" href="<?= e(APP_URL) ?>/field/home/">
      <img class="field-topbar__logo"
           src="<?= e(asset_url(APP_URL . '/assets/img/rajdoot-logo.jpg')) ?>"
           alt="<?= e(APP_NAME) ?>">
    </a>

    <div class="field-topbar__right">
      <a class="field-topbar__avatar" href="<?= e(APP_URL) ?>/field/profile/" aria-label="My Profile">
        <?php if ($headerPhoto): ?>
          <img src="<?= e(UPLOAD_URL . '/' . $headerPhoto) ?>" alt="">
        <?php else: ?>
          <?= e(mb_strtoupper(mb_substr($me['name'] ?? 'E', 0, 2))) ?>
        <?php endif; ?>
      </a>
    </div>
  </header>

  <main class="field-main">
