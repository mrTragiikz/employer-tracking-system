<?php
/**
 * field/logout/index.php - "Are you sure?" logout confirmation.
 * URL: /track/field/logout/
 *
 * Reached from a "Log Out" button on the profile page. The actual logout
 * happens in api/logout.php (POST + CSRF), not here - this page is just
 * the confirmation step so a stray tap/link can't sign someone out by
 * accident.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_employee();

$pageTitle  = 'Log Out';
$activeTab  = '';
$sectionCss = APP_URL . '/field/logout/css/logout.css';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <a class="lo-back" href="<?= e(APP_URL) ?>/field/profile/">
      <i class="bi bi-arrow-left"></i> My Profile
    </a>
  </div>

  <section class="lo-card">
    <span class="lo-icon"><i class="bi bi-box-arrow-right"></i></span>
    <h1>Log Out?</h1>
    <p>You'll need your phone number and PIN to sign in again, <?= e(explode(' ', trim($me['name']))[0] ?? $me['name']) ?>.</p>

    <form method="post" action="<?= e(APP_URL) ?>/field/logout/api/logout.php">
      <?= csrf_field() ?>
      <button type="submit" class="lo-confirm">
        <i class="bi bi-box-arrow-right"></i> Yes, Log Out
      </button>
    </form>

    <a class="lo-cancel" href="<?= e(APP_URL) ?>/field/profile/">Cancel</a>
  </section>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
