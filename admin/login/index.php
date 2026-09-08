<?php
/**
 * admin/login/index.php - admin sign-in page.
 * URL: /track/admin/login/
 *
 * Standalone split-screen layout (no sidebar / topbar). Already-authenticated
 * admins are bounced to the dashboard. The form posts to api/authenticate.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (is_logged_in() && current_user()['role'] === 'admin') {
    redirect(admin_url('dashboard'));
}

// One-shot message bag set by api/authenticate.php on a failed attempt.
$err = $_SESSION['login_error'] ?? null;
$old = $_SESSION['login_username'] ?? '';
unset($_SESSION['login_error'], $_SESSION['login_username']);

$notice = null;
if (($_GET['bye'] ?? '') === '1') {
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
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in &middot; <?= e($appName) ?></title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&family=Sora:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= e(APP_URL) ?>/admin/login/css/login.css">
</head>
<body class="lg">

  <div class="lg__grid">

    <!-- ===== brand panel ===== -->
    <aside class="lg-brandpanel" aria-hidden="true">
      <div class="lg-brandpanel__inner">
        <div class="lg-logo">
          <span class="lg-logo__mark"><i class="bi bi-signpost-split-fill"></i></span>
          <span class="lg-logo__word"><?= e($appName) ?></span>
        </div>

        <div class="lg-brandpanel__body">
          <h2 class="lg-brandpanel__headline">Field sales,<br>tracked end to end.</h2>
          <ul class="lg-points">
            <li><i class="bi bi-geo-alt-fill"></i><span>Live routes, visits and attendance on one map</span></li>
            <li><i class="bi bi-shield-fill-check"></i><span>Photo-verified check-ins with anti-fraud checks</span></li>
            <li><i class="bi bi-bar-chart-fill"></i><span>Daily productivity and distance, per Employee</span></li>
          </ul>
        </div>

        <p class="lg-brandpanel__foot">
          &copy; <?= e($year) ?> <?= e($appName) ?> &middot; Admin console<br>
          <span class="lg-brandpanel__by">Software by Prabin Sharma</span>
        </p>
      </div>
      <span class="lg-brandpanel__glow lg-brandpanel__glow--a"></span>
      <span class="lg-brandpanel__glow lg-brandpanel__glow--b"></span>
    </aside>

    <!-- ===== form column ===== -->
    <main class="lg-formcol">
      <div class="lg-form">

        <div class="lg-logo lg-logo--compact">
          <span class="lg-logo__mark"><i class="bi bi-signpost-split-fill"></i></span>
          <span class="lg-logo__word"><?= e($appName) ?></span>
        </div>

        <header class="lg-form__head">
          <h1 class="lg-form__title">Sign in</h1>
          <p class="lg-form__sub">Use your admin username and password.</p>
        </header>

        <div class="lg-flashes" aria-live="polite">
          <?php if ($notice): ?>
            <div class="lg-flash lg-flash--ok" role="status">
              <i class="bi bi-check-circle-fill"></i>
              <span><?= e($notice) ?></span>
            </div>
          <?php endif; ?>
          <?php if ($err): ?>
            <div class="lg-flash lg-flash--bad" role="alert">
              <i class="bi bi-exclamation-circle-fill"></i>
              <span><?= e($err) ?></span>
            </div>
          <?php endif; ?>
        </div>

        <form class="lg-fields" method="post"
              action="<?= e(APP_URL) ?>/admin/login/api/authenticate.php" novalidate>
          <?= csrf_field() ?>

          <div class="lg-field">
            <label for="lg-username" class="lg-field__label">Username</label>
            <div class="lg-input">
              <i class="bi bi-person lg-input__icon"></i>
              <input type="text" id="lg-username" name="username"
                     value="<?= e($old) ?>" required autofocus
                     autocomplete="username" spellcheck="false"
                     autocapitalize="none" placeholder="e.g. prabin_dev">
            </div>
          </div>

          <div class="lg-field">
            <label for="lg-password" class="lg-field__label">Password</label>
            <div class="lg-input">
              <i class="bi bi-lock lg-input__icon"></i>
              <input type="password" id="lg-password" name="password" required
                     autocomplete="current-password" placeholder="Your password">
              <button type="button" class="lg-input__eye" data-toggle-password
                      aria-label="Show password" tabindex="-1">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="lg-submit">
            <span class="lg-submit__label">Sign in</span>
            <span class="lg-submit__spinner" aria-hidden="true"></span>
          </button>
        </form>

      </div>

      <p class="lg-formcol__foot">
        &copy; <?= e($year) ?> <?= e($appName) ?>
        <span class="lg-formcol__by">Software by Prabin Sharma</span>
      </p>
    </main>

  </div>

  <script src="<?= e(APP_URL) ?>/admin/login/js/login.js"></script>
</body>
</html>
