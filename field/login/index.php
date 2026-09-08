<?php
/**
 * field/login/index.php - Employee sign-in page.
 * URL: /track/field/login/
 *
 * Mobile-first, single column (Employees use this from a phone in the
 * field). Already-authenticated Employees are bounced to field home. The
 * form posts to api/authenticate.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (is_logged_in() && current_user()['role'] === 'employee') {
    redirect(APP_URL . '/field/home/');
}

// One-shot message bag set by api/authenticate.php on a failed attempt.
$err = $_SESSION['login_error'] ?? null;
$old = $_SESSION['login_phone'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_phone']);

$notice = null;
$suspended = ($_GET['suspended'] ?? '') === '1';
// phone passed by require_employee() when it bounced a suspended session here -
// lets the suspended screen poll status.php and auto-reload on unlock.
$suspendedPhone = $suspended ? trim((string) ($_GET['p'] ?? '')) : '';
if (!preg_match('/^[0-9+\- ]{7,20}$/', $suspendedPhone)) {
    $suspendedPhone = '';
}
if ($suspended) {
    // handled as its own block below - distinct look from a plain notice
} elseif (($_GET['bye'] ?? '') === '1') {
    $notice = 'You have been signed out.';
} elseif (($_GET['timeout'] ?? '') === '1') {
    $notice = 'Your session expired. Please sign in again.';
}

$appName = defined('APP_NAME') ? APP_NAME : 'Track';
$year    = date('Y');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in &middot; <?= e($appName) ?></title>

  <?php /* PWA - same manifest as the rest of the field app, so installing
           from the login screen (before first sign-in) works too. */ ?>
  <link rel="manifest" href="<?= e(APP_URL) ?>/field/manifest.php">
  <meta name="theme-color" content="#6b4423">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="Rajdoot">
  <link rel="apple-touch-icon" href="<?= e(APP_URL) ?>/field/icons/icon-192.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/field/login/css/login.css')) ?>">
</head>
<body class="fl">

  <main class="fl-page">
    <div class="fl-card">

      <div class="fl-logo">
        <img class="fl-logo__img"
             src="<?= e(asset_url(APP_URL . '/assets/img/rajdoot-logo.jpg')) ?>"
             alt="<?= e($appName) ?>">
      </div>

      <?php if ($suspended): ?>

        <!-- ===== account suspended - blocks the form entirely, nothing to type ===== -->
        <div class="fl-suspended"<?= $suspendedPhone !== '' ? ' data-poll-phone="' . e($suspendedPhone) . '"' : '' ?>>
          <span class="fl-suspended__icon"><i class="bi bi-slash-circle-fill"></i></span>
          <h1 class="fl-suspended__title">You Have Been Suspended</h1>
          <p class="fl-suspended__sub">by the Super Admin</p>
          <p class="fl-suspended__note">Please contact the administrator to restore access to your account.</p>
          <?php if ($suspendedPhone !== ''): ?>
            <p class="fl-suspended__watch" data-poll-idle hidden>
              <i class="bi bi-arrow-repeat"></i>
              Waiting for the admin to restore access - this screen will open on its own.
            </p>
          <?php endif; ?>
        </div>

      <?php else: ?>

        <header class="fl-head">
          <h1 class="fl-head__title">Employee Sign In</h1>
          <p class="fl-head__sub">Use your registered phone number and PIN.</p>
        </header>

        <div class="fl-flashes" aria-live="polite">
          <?php if ($notice): ?>
            <div class="fl-flash fl-flash--ok" role="status">
              <i class="bi bi-check-circle-fill"></i>
              <span><?= e($notice) ?></span>
            </div>
          <?php endif; ?>
          <?php if ($err): ?>
            <div class="fl-flash fl-flash--bad" role="alert">
              <i class="bi bi-exclamation-circle-fill"></i>
              <span><?= e($err) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <form class="fl-fields" method="post"
              action="<?= e(APP_URL) ?>/field/login/api/authenticate.php" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="device_id" id="fl-device-id" value="">

          <div class="fl-field">
            <label for="fl-phone" class="fl-field__label">Phone number</label>
            <div class="fl-input">
              <i class="bi bi-phone fl-input__icon"></i>
              <input type="tel" id="fl-phone" name="phone"
                     value="<?= e($old) ?>" required autofocus
                     inputmode="numeric" autocomplete="username"
                     placeholder="e.g. 9841000001">
            </div>
          </div>

          <div class="fl-field">
            <label for="fl-pin" class="fl-field__label">4-digit PIN</label>
            <div class="fl-input">
              <i class="bi bi-lock fl-input__icon"></i>
              <input type="password" id="fl-pin" name="pin" required
                     inputmode="numeric" pattern="\d{4}" maxlength="4"
                     autocomplete="current-password" placeholder="&bull;&bull;&bull;&bull;">
              <button type="button" class="fl-input__eye" data-toggle-pin
                      aria-label="Show PIN" tabindex="-1">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="fl-submit">
            <span class="fl-submit__label">Sign in</span>
            <span class="fl-submit__spinner" aria-hidden="true"></span>
          </button>
        </form>

        <p class="fl-note">
          <i class="bi bi-info-circle"></i>
          Your account is bound to this phone after your first sign in. Contact
          your admin if you get a new phone.
        </p>

      <?php endif; ?>
    </div>

    <p class="fl-foot">&copy; <?= e($year) ?> <?= e($appName) ?></p>
  </main>

  <script src="<?= e(asset_url(APP_URL . '/field/login/js/login.js')) ?>"></script>
  <script src="<?= e(asset_url(APP_URL . '/field/pwa.js')) ?>" defer></script>
</body>
</html>
