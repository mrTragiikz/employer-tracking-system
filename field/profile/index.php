<?php
/**
 * field/profile/index.php - My Profile. URL: /track/field/profile/
 *
 * Reached by tapping the avatar in the top bar (see
 * field/components/header/header.php). Read-only bio data (the employee
 * cannot edit their own profile fields - only an admin can, on the admin
 * side) plus a self-service change-PIN form. Not a bottom-tab destination.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_employee();
require __DIR__ . '/_repo.php';

$profile = profile_find($pdo, (int) $me['id']);
if (!$profile) {
    redirect(APP_URL . '/field/home/');
}

$pageTitle  = 'My Profile';
$activeTab  = '';
$sectionCss = APP_URL . '/field/profile/css/profile.css';

$pinErrors = $_SESSION['profile_pin_error'] ?? [];
unset($_SESSION['profile_pin_error']);

/** "12 Apr 1992" from a DATE string, or a dash. */
$fmtDate = static fn(?string $d) => $d ? (new DateTimeImmutable($d))->format('j M Y') : '-';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <a class="pf-back" href="<?= e(APP_URL) ?>/field/home/">
      <i class="bi bi-arrow-left"></i> Dashboard
    </a>
  </div>

  <?php if (($_GET['ok'] ?? '') === 'pin'): ?>
    <div class="fh-flash"><i class="bi bi-check-circle-fill"></i> Your PIN has been changed.</div>
  <?php endif; ?>

  <!-- ===== identity card ===== -->
  <section class="pf-card">
    <div class="pf-identity">
      <?php if ($profile['photo_path']): ?>
        <img class="pf-avatar" src="<?= e(UPLOAD_URL . '/' . $profile['photo_path']) ?>" alt="">
      <?php else: ?>
        <span class="pf-avatar pf-avatar--initials"><?= e(mb_strtoupper(mb_substr($profile['name'], 0, 2))) ?></span>
      <?php endif; ?>
      <div class="pf-identity__body">
        <h1><?= e($profile['name']) ?></h1>
        <?php if ((int) $profile['is_active'] === 1): ?>
          <span class="pf-status pf-status--ok"><i class="bi bi-patch-check-fill"></i> Verified Account</span>
        <?php else: ?>
          <span class="pf-status pf-status--off"><i class="bi bi-slash-circle-fill"></i> Inactive Account</span>
        <?php endif; ?>
        <?php if ($profile['code']): ?>
          <span class="pf-code">Employee Code: <?= e($profile['code']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- ===== contact ===== -->
  <section class="pf-card">
    <div class="pf-card__head"><h2>Contact</h2></div>
    <div class="pf-summary">
      <div class="pf-summary__row">
        <span class="pf-summary__label"><i class="bi bi-telephone-fill"></i> Phone</span>
        <span class="pf-summary__value"><?= e($profile['phone'] ?? '-') ?></span>
      </div>
      <?php if ($profile['email']): ?>
        <div class="pf-summary__row">
          <span class="pf-summary__label"><i class="bi bi-envelope-fill"></i> Email</span>
          <span class="pf-summary__value"><?= e($profile['email']) ?></span>
        </div>
      <?php endif; ?>
      <div class="pf-summary__row">
        <span class="pf-summary__label"><i class="bi bi-geo-alt-fill"></i> Area / Region</span>
        <span class="pf-summary__value"><?= e(trim(($profile['area'] ?? '') . ($profile['area'] && $profile['region'] ? ' / ' : '') . ($profile['region'] ?? '')) ?: '-') ?></span>
      </div>
      <?php if ($profile['address']): ?>
        <div class="pf-summary__row">
          <span class="pf-summary__label"><i class="bi bi-house-fill"></i> Address</span>
          <span class="pf-summary__value"><?= e($profile['address']) ?></span>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ===== personal details ===== -->
  <section class="pf-card">
    <div class="pf-card__head"><h2>Personal Details</h2></div>
    <div class="pf-summary">
      <div class="pf-summary__row">
        <span class="pf-summary__label"><i class="bi bi-calendar-heart"></i> Date of Birth</span>
        <span class="pf-summary__value"><?= e($fmtDate($profile['dob'])) ?></span>
      </div>
      <div class="pf-summary__row">
        <span class="pf-summary__label"><i class="bi bi-person-fill"></i> Gender</span>
        <span class="pf-summary__value"><?= e($profile['gender'] ? ucfirst($profile['gender']) : '-') ?></span>
      </div>
      <?php if ($profile['document_type']): ?>
        <div class="pf-summary__row">
          <span class="pf-summary__label"><i class="bi bi-file-earmark-text-fill"></i> Document Type</span>
          <span class="pf-summary__value"><?= e(profile_document_label($profile['document_type'])) ?></span>
        </div>
      <?php endif; ?>
      <div class="pf-summary__row">
        <span class="pf-summary__label"><i class="bi bi-credit-card-2-front-fill"></i> ID No.</span>
        <span class="pf-summary__value"><?= e($profile['id_number'] ?? '-') ?></span>
      </div>
      <?php if ($profile['vehicle_type']): ?>
        <div class="pf-summary__row">
          <span class="pf-summary__label"><i class="bi bi-scooter"></i> Vehicle</span>
          <span class="pf-summary__value"><?= e(ucfirst($profile['vehicle_type'])) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($profile['emergency_name'] || $profile['emergency_contact']): ?>
        <div class="pf-summary__row">
          <span class="pf-summary__label"><i class="bi bi-telephone-plus-fill"></i> Emergency Contact</span>
          <span class="pf-summary__value">
            <?= e($profile['emergency_contact'] ?? '-') ?>
            <?php if ($profile['emergency_name']): ?><span class="pf-sub">(<?= e($profile['emergency_name']) ?>)</span><?php endif; ?>
          </span>
        </div>
      <?php endif; ?>
      <div class="pf-summary__row">
        <span class="pf-summary__label"><i class="bi bi-calendar-check"></i> Joined On</span>
        <span class="pf-summary__value"><?= e($fmtDate($profile['created_at'])) ?></span>
      </div>
    </div>
  </section>

  <!-- ===== ID photos - click a thumbnail to view full-size, close to exit ===== -->
  <?php if ($profile['id_photo_front'] || $profile['id_photo_back']): ?>
    <section class="pf-card">
      <div class="pf-card__head"><h2><?= e(profile_document_label($profile['document_type'])) ?></h2></div>
      <div class="pf-id-photos">
        <?php if ($profile['id_photo_front']): ?>
          <button type="button" class="pf-id-photo" data-view-photo="front">
            <img src="<?= e(UPLOAD_URL . '/' . $profile['id_photo_front']) ?>" alt="">
            <span>Front <i class="bi bi-arrows-fullscreen"></i></span>
          </button>
        <?php endif; ?>
        <?php if ($profile['id_photo_back']): ?>
          <button type="button" class="pf-id-photo" data-view-photo="back">
            <img src="<?= e(UPLOAD_URL . '/' . $profile['id_photo_back']) ?>" alt="">
            <span>Back <i class="bi bi-arrows-fullscreen"></i></span>
          </button>
        <?php endif; ?>
      </div>
    </section>

    <?php foreach (['front' => $profile['id_photo_front'], 'back' => $profile['id_photo_back']] as $side => $path): ?>
      <?php if ($path): ?>
        <div class="pf-photo-modal" id="pf-photo-<?= e($side) ?>" hidden>
          <button type="button" class="pf-photo-modal__close" data-close-photo aria-label="Close">
            <i class="bi bi-x-lg"></i>
          </button>
          <img src="<?= e(UPLOAD_URL . '/' . $path) ?>" alt="ID <?= e($side) ?>">
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <script>
      (function () {
        document.querySelectorAll('[data-view-photo]').forEach(function (btn) {
          var modal = document.getElementById('pf-photo-' + btn.getAttribute('data-view-photo'));
          if (!modal) return;
          btn.addEventListener('click', function () { modal.hidden = false; });
        });
        document.querySelectorAll('.pf-photo-modal').forEach(function (modal) {
          modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('[data-close-photo]')) {
              modal.hidden = true;
            }
          });
        });
      })();
    </script>
  <?php endif; ?>

  <!-- ===== change PIN ===== -->
  <section class="pf-card">
    <div class="pf-card__head"><h2>Change PIN</h2></div>
    <p class="pf-card__note">Your 4-digit PIN is used to sign in on this device.</p>

    <?php if (!empty($pinErrors['_'])): ?>
      <div class="pf-flash-err"><i class="bi bi-exclamation-circle-fill"></i> <?= e($pinErrors['_']) ?></div>
    <?php endif; ?>

    <form class="pf-pin-form" method="post" action="<?= e(APP_URL) ?>/field/profile/api/change-pin.php">
      <?= csrf_field() ?>

      <div class="pf-field">
        <label for="pf-current-pin">Current PIN</label>
        <input class="pf-input" type="password" name="current_pin" id="pf-current-pin"
               inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required>
        <?php if (isset($pinErrors['current_pin'])): ?>
          <span class="pf-field-err"><?= e($pinErrors['current_pin']) ?></span>
        <?php endif; ?>
      </div>

      <div class="pf-field">
        <label for="pf-new-pin">New PIN (4 digits)</label>
        <input class="pf-input" type="password" name="new_pin" id="pf-new-pin"
               inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required>
        <?php if (isset($pinErrors['new_pin'])): ?>
          <span class="pf-field-err"><?= e($pinErrors['new_pin']) ?></span>
        <?php endif; ?>
      </div>

      <div class="pf-field">
        <label for="pf-new-pin-confirm">Confirm New PIN</label>
        <input class="pf-input" type="password" name="new_pin_confirm" id="pf-new-pin-confirm"
               inputmode="numeric" pattern="\d{4}" maxlength="4" autocomplete="off" required>
        <?php if (isset($pinErrors['new_pin_confirm'])): ?>
          <span class="pf-field-err"><?= e($pinErrors['new_pin_confirm']) ?></span>
        <?php endif; ?>
      </div>

      <button type="submit" class="pf-submit">
        <i class="bi bi-shield-lock-fill"></i> Update PIN
      </button>
    </form>
  </section>

  <a class="pf-logout" href="<?= e(APP_URL) ?>/field/logout/">
    <i class="bi bi-box-arrow-right"></i> Log Out
  </a>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
