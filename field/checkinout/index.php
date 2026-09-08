<?php
/**
 * field/checkinout/index.php - Check In/Out. URL: /track/field/checkinout/
 *
 * This IS the bottom-tab-bar "Check In/Out" destination (see
 * field/components/footer/footer.php). This tab owns the actual check-in
 * and check-out ACTIONS - the Attendance tab is a read-only history/
 * overview and no longer has a form on it.
 *
 *   - not checked in yet: the check-in form (location + live odometer
 *     photo + KM), same as before
 *   - checked in, not checked out: today's check-in summary + a
 *     "Check Out" button - blocked (with a clear reason) while a visit is
 *     still open, since an employee is only ever at one shop at a time
 *   - check-out in progress (button tapped): the check-out form, same
 *     shape as check-in
 *   - already checked out: today's full check-in + check-out summary
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_employee();
require __DIR__ . '/_repo.php';
require dirname(__DIR__) . '/visit/_repo.php';

$today      = server_today();
$attendance = field_today_attendance($pdo, (int) $me['id'], $today);
$checkedIn  = $attendance !== null;
$checkedOut = $checkedIn && !empty($attendance['check_out_at']);

$pageTitle  = 'Check In/Out';
$activeTab  = 'checkinout';
$sectionCss = APP_URL . '/field/checkinout/css/checkinout.css';

$errors = $_SESSION['checkinout_form_error'] ?? [];
unset($_SESSION['checkinout_form_error']);

$checkinPhoto  = null;
$checkoutPhoto = null;
$openVisit     = null;
if ($checkedIn) {
    $ps = $pdo->prepare(
        "SELECT photo_kind, stored_path FROM photos WHERE attendance_id = ? AND photo_kind IN ('checkin','checkout')"
    );
    $ps->execute([(int) $attendance['id']]);
    foreach ($ps->fetchAll() as $p) {
        if ($p['photo_kind'] === 'checkin')  $checkinPhoto  = $p['stored_path'];
        if ($p['photo_kind'] === 'checkout') $checkoutPhoto = $p['stored_path'];
    }
    $openVisit = field_visit_open_one($pdo, (int) $attendance['id']);
}

$wantsCheckout = $checkedIn && !$checkedOut && $openVisit === null && ($_GET['action'] ?? '') === 'checkout';

// Check-in window policy: once blocked, the check-in form itself is
// replaced with a plain "too late" message - there is nothing to fill in,
// checkin.php also refuses the POST for real (see its own comment), this is
// just the friendly version of the same rule for someone loading the page.
// Only relevant when the Employee hasn't already checked in today.
$checkinBlocked = !$checkedIn && attendance_checkin_blocked($today);

// Live-lock poll: while the check-in form is showing (not yet checked in,
// not yet blocked), checkinout-lock.js asks this same URL every few seconds
// whether the window has JUST closed, so the form can lock itself live in
// that same session - no refresh, no button tap needed (see the field
// employee's own explicit requirement). Deliberately re-checks against the
// SERVER clock via attendance_checkin_blocked() every poll, not a
// client-side countdown against a phone clock - this app's fraud rules
// already treat phone clocks as untrustworthy (server time only), and the
// same reasoning applies here: a phone with a wrong/tampered clock must
// never be the thing deciding whether the window is still open.
if (($_GET['partial'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['blocked' => $checkinBlocked]);
    exit;
}

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <h1>Check In/Out</h1>
    <p class="page-head__sub"><i class="bi bi-calendar3"></i> <?= e((new DateTimeImmutable($today))->format('j F Y')) ?></p>
  </div>

  <?php if (($_GET['ok'] ?? '') === 'checkin'): ?>
    <div class="fa-flash fa-flash--ok"><i class="bi bi-check-circle-fill"></i> You're checked in. Have a great day!</div>
  <?php elseif (($_GET['ok'] ?? '') === 'checkout'): ?>
    <div class="fa-flash fa-flash--ok"><i class="bi bi-check-circle-fill"></i> You're checked out. Nice work today!</div>
  <?php endif; ?>

  <?php if (!empty($errors['_']) && !$wantsCheckout && $checkedIn): ?>
    <div class="fa-flash"><i class="bi bi-exclamation-circle-fill"></i> <?= e($errors['_']) ?></div>
  <?php endif; ?>

  <?php if ($checkedOut): ?>

    <!-- ===== day complete: check-in + check-out summary ===== -->
    <section class="fa-card fa-card--done">
      <div class="fa-card__head">
        <span class="fa-card__icon fa-card__icon--ok"><i class="bi bi-check-circle-fill"></i></span>
        <div>
          <h2>Day Complete</h2>
          <p>You're checked out for today.</p>
        </div>
        <button type="button" class="fa-view-btn" id="fa-day-view">
          <i class="bi bi-eye-fill"></i> View
        </button>
      </div>

      <!-- ===== always-visible highlights ===== -->
      <div class="fa-highlights">
        <div class="fa-highlight">
          <span class="fa-highlight__label"><i class="bi bi-box-arrow-in-right"></i> Check-in</span>
          <span class="fa-highlight__value"><?= e((new DateTimeImmutable($attendance['check_in_at']))->format('g:i A')) ?></span>
          <span class="fa-highlight__sub"><?= e(number_format((float) $attendance['check_in_lat'], 5)) ?>, <?= e(number_format((float) $attendance['check_in_lng'], 5)) ?></span>
        </div>
        <div class="fa-highlight">
          <span class="fa-highlight__label"><i class="bi bi-speedometer2"></i> Check-in KM</span>
          <span class="fa-highlight__value"><?= e(number_format((float) $attendance['check_in_odometer_km'], 1)) ?> km</span>
        </div>
        <div class="fa-highlight">
          <span class="fa-highlight__label"><i class="bi bi-speedometer2"></i> Check-out KM</span>
          <span class="fa-highlight__value"><?= e(number_format((float) $attendance['check_out_odometer_km'], 1)) ?> km</span>
        </div>
      </div>

      <div id="fa-day-details" hidden>
        <div class="fa-summary">
          <div class="fa-summary__row">
            <span class="fa-summary__label"><i class="bi bi-box-arrow-in-right"></i> Check-in</span>
            <span class="fa-summary__value"><?= e((new DateTimeImmutable($attendance['check_in_at']))->format('g:i A')) ?></span>
          </div>
          <div class="fa-summary__row">
            <span class="fa-summary__label"><i class="bi bi-speedometer2"></i> Check-in KM</span>
            <span class="fa-summary__value"><?= e(number_format((float) $attendance['check_in_odometer_km'], 1)) ?> km</span>
          </div>
          <div class="fa-summary__row">
            <span class="fa-summary__label"><i class="bi bi-box-arrow-right"></i> Check-out</span>
            <span class="fa-summary__value"><?= e((new DateTimeImmutable($attendance['check_out_at']))->format('g:i A')) ?></span>
          </div>
          <div class="fa-summary__row">
            <span class="fa-summary__label"><i class="bi bi-speedometer2"></i> Check-out KM</span>
            <span class="fa-summary__value"><?= e(number_format((float) $attendance['check_out_odometer_km'], 1)) ?> km</span>
          </div>
        </div>
        <?php if ($checkinPhoto): ?>
          <p class="fa-sub" style="margin:14px 0 6px;">Check-in odometer</p>
          <img class="fa-summary__photo" src="<?= e(UPLOAD_URL . '/' . $checkinPhoto) ?>" alt="Odometer at check-in">
        <?php endif; ?>
        <?php if ($checkoutPhoto): ?>
          <p class="fa-sub" style="margin:14px 0 6px;">Check-out odometer</p>
          <img class="fa-summary__photo" src="<?= e(UPLOAD_URL . '/' . $checkoutPhoto) ?>" alt="Odometer at check-out">
        <?php endif; ?>
      </div>

      <script>
        (function () {
          var btn = document.getElementById('fa-day-view');
          var box = document.getElementById('fa-day-details');
          if (!btn || !box) return;
          btn.addEventListener('click', function () {
            box.hidden = !box.hidden;
            btn.innerHTML = box.hidden
              ? '<i class="bi bi-eye-fill"></i> View'
              : '<i class="bi bi-eye-slash-fill"></i> Hide';
          });
        })();
      </script>
    </section>

  <?php elseif ($wantsCheckout): ?>

    <!-- ===== check-out form ===== -->
    <?php if (!empty($errors['_'])): ?>
      <div class="fa-flash"><i class="bi bi-exclamation-circle-fill"></i> <?= e($errors['_']) ?></div>
    <?php endif; ?>

    <form class="fa-form" method="post" enctype="multipart/form-data"
          action="<?= e(APP_URL) ?>/field/checkinout/api/checkout.php" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="lat" id="fa-lat" value="">
      <input type="hidden" name="lng" id="fa-lng" value="">
      <input type="hidden" name="accuracy" id="fa-accuracy" value="">
      <input type="hidden" name="location_denied" id="fa-location-denied" value="0">

      <section class="fa-card">
        <div class="fa-card__head">
          <span class="fa-card__icon"><i class="bi bi-geo-alt-fill"></i></span>
          <div>
            <h2>Your Location</h2>
            <p>Tap the button to capture your location - this cannot be typed or edited.</p>
          </div>
        </div>
        <button type="button" class="fa-locate-btn" id="fa-locate-btn">
          <i class="bi bi-geo-alt-fill"></i> Click Me
        </button>
        <div class="fa-location" id="fa-location-box" hidden></div>
      </section>

      <section class="fa-card">
        <div class="fa-card__head">
          <span class="fa-card__icon"><i class="bi bi-camera-fill"></i></span>
          <div>
            <h2>Bike current meter photo</h2>
            <p>आफ्नु बाइक या स्क्याडन को मिटर रिडिङ को फोटो हाल्नुस्।</p>
          </div>
        </div>

        <label class="fa-shot" id="fa-shot-label">
          <input type="file" name="odometer_photo" id="fa-shot-input"
                 accept="image/*" capture="environment" required hidden>
          <span class="fa-shot__empty" id="fa-shot-empty">
            <i class="bi bi-camera"></i>
            <span>Tap to open camera</span>
          </span>
          <img class="fa-shot__preview" id="fa-shot-preview" alt="" hidden>
        </label>
        <?php if (isset($errors['odometer_photo'])): ?>
          <span class="fa-field-err"><?= e($errors['odometer_photo']) ?></span>
        <?php endif; ?>

        <div class="fa-field">
          <label for="fa-km">Bike KM (from the odometer)</label>
          <input class="fa-input" type="number" name="odometer_km" id="fa-km"
                 inputmode="decimal" step="0.1" min="0" max="999999" required
                 placeholder="e.g. 24583.5">
          <?php if (isset($errors['odometer_km'])): ?>
            <span class="fa-field-err"><?= e($errors['odometer_km']) ?></span>
          <?php endif; ?>
        </div>
      </section>

      <button type="submit" class="fa-submit" id="fa-submit" disabled>
        <span class="fa-submit__label"><i class="bi bi-check-circle-fill"></i> Check Out</span>
        <span class="fa-submit__spinner" aria-hidden="true"></span>
      </button>
    </form>

    <script src="<?= e(asset_url(APP_URL . '/field/components/photo-compress.js')) ?>"></script>
    <script src="<?= e(asset_url(APP_URL . '/field/checkinout/js/checkinout.js')) ?>"></script>

  <?php elseif ($checkedIn): ?>

    <!-- ===== checked in today - summary + Check Out ===== -->
    <section class="fa-card fa-card--done">
      <div class="fa-card__head">
        <span class="fa-card__icon fa-card__icon--ok"><i class="bi bi-check-circle-fill"></i></span>
        <div>
          <h2>Checked In</h2>
          <p>You're marked present for today.</p>
        </div>
        <button type="button" class="fa-view-btn" id="fa-checkin-view">
          <i class="bi bi-eye-fill"></i> View
        </button>
      </div>

      <!-- ===== always-visible highlights ===== -->
      <div class="fa-highlights">
        <div class="fa-highlight">
          <span class="fa-highlight__label"><i class="bi bi-box-arrow-in-right"></i> Check-in</span>
          <span class="fa-highlight__value"><?= e((new DateTimeImmutable($attendance['check_in_at']))->format('g:i A')) ?></span>
          <span class="fa-highlight__sub"><?= e(number_format((float) $attendance['check_in_lat'], 5)) ?>, <?= e(number_format((float) $attendance['check_in_lng'], 5)) ?></span>
        </div>
        <div class="fa-highlight">
          <span class="fa-highlight__label"><i class="bi bi-speedometer2"></i> Check-in KM</span>
          <span class="fa-highlight__value"><?= e(number_format((float) $attendance['check_in_odometer_km'], 1)) ?> km</span>
        </div>
      </div>

      <div class="fa-summary" id="fa-checkin-details" hidden>
        <div class="fa-summary__row">
          <span class="fa-summary__label"><i class="bi bi-clock"></i> Check-in time</span>
          <span class="fa-summary__value"><?= e((new DateTimeImmutable($attendance['check_in_at']))->format('g:i A')) ?></span>
        </div>
        <div class="fa-summary__row">
          <span class="fa-summary__label"><i class="bi bi-speedometer2"></i> Bike KM</span>
          <span class="fa-summary__value"><?= e(number_format((float) $attendance['check_in_odometer_km'], 1)) ?> km</span>
        </div>
        <?php if ($checkinPhoto): ?>
          <div class="fa-summary__row">
            <span class="fa-summary__label"><i class="bi bi-camera"></i> Odometer photo</span>
          </div>
          <img class="fa-summary__photo" src="<?= e(UPLOAD_URL . '/' . $checkinPhoto) ?>" alt="Odometer at check-in">
        <?php endif; ?>
      </div>

      <script>
        (function () {
          var btn = document.getElementById('fa-checkin-view');
          var box = document.getElementById('fa-checkin-details');
          if (!btn || !box) return;
          btn.addEventListener('click', function () {
            box.hidden = !box.hidden;
            btn.innerHTML = box.hidden
              ? '<i class="bi bi-eye-fill"></i> View'
              : '<i class="bi bi-eye-slash-fill"></i> Hide';
          });
        })();
      </script>

      <?php if ($openVisit !== null): ?>
        <p class="fa-checkout-note">
          <i class="bi bi-exclamation-circle-fill"></i>
          Mark your visit to "<?= e($openVisit['shop_name']) ?>" Done (on My Visits) before you can check out.
        </p>
      <?php else: ?>
        <a class="fa-checkout-btn" href="<?= e(APP_URL) ?>/field/checkinout/?action=checkout">
          <i class="bi bi-box-arrow-right"></i> Check Out
        </a>
      <?php endif; ?>
    </section>

  <?php elseif ($checkinBlocked): ?>

    <!-- ===== check-in window closed - no form, just the reason ===== -->
    <section class="fa-card fa-card--blocked">
      <div class="fa-card__head">
        <span class="fa-card__icon fa-card__icon--bad"><i class="bi bi-x-circle-fill"></i></span>
        <div>
          <h2>Too Late to Check In</h2>
          <p>You are too late for today's attendance. Sorry, you have been marked absent.</p>
        </div>
      </div>
    </section>

  <?php else: ?>

    <!-- ===== check-in form - id'd so checkinout-lock.js can replace the
         whole thing live if the window closes while this is on screen,
         with no reload (see that script's own docblock) ===== -->
    <div id="fa-checkin-live">
      <?php if (!empty($errors['_'])): ?>
        <div class="fa-flash"><i class="bi bi-exclamation-circle-fill"></i> <?= e($errors['_']) ?></div>
      <?php endif; ?>

      <form class="fa-form" method="post" enctype="multipart/form-data"
            action="<?= e(APP_URL) ?>/field/checkinout/api/checkin.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="lat" id="fa-lat" value="">
        <input type="hidden" name="lng" id="fa-lng" value="">
        <input type="hidden" name="accuracy" id="fa-accuracy" value="">
        <input type="hidden" name="location_denied" id="fa-location-denied" value="0">

        <section class="fa-card">
          <div class="fa-card__head">
            <span class="fa-card__icon"><i class="bi bi-geo-alt-fill"></i></span>
            <div>
              <h2>Your Location</h2>
              <p>Tap the button to capture your location - this cannot be typed or edited.</p>
            </div>
          </div>
          <button type="button" class="fa-locate-btn" id="fa-locate-btn">
            <i class="bi bi-geo-alt-fill"></i> Click Me
          </button>
          <div class="fa-location" id="fa-location-box" hidden></div>
        </section>

        <section class="fa-card">
          <div class="fa-card__head">
            <span class="fa-card__icon"><i class="bi bi-camera-fill"></i></span>
            <div>
              <h2>Bike current meter photo</h2>
              <p>आफ्नु बाइक या स्क्याडन को मिटर रिडिङ को फोटो हाल्नुस्।</p>
            </div>
          </div>

          <label class="fa-shot" id="fa-shot-label">
            <input type="file" name="odometer_photo" id="fa-shot-input"
                   accept="image/*" capture="environment" required hidden>
            <span class="fa-shot__empty" id="fa-shot-empty">
              <i class="bi bi-camera"></i>
              <span>Tap to open camera</span>
            </span>
            <img class="fa-shot__preview" id="fa-shot-preview" alt="" hidden>
          </label>
          <?php if (isset($errors['odometer_photo'])): ?>
            <span class="fa-field-err"><?= e($errors['odometer_photo']) ?></span>
          <?php endif; ?>

          <div class="fa-field">
            <label for="fa-km">Bike KM (from the odometer)</label>
            <input class="fa-input" type="number" name="odometer_km" id="fa-km"
                   inputmode="decimal" step="0.1" min="0" max="999999" required
                   placeholder="e.g. 24583.5">
            <?php if (isset($errors['odometer_km'])): ?>
              <span class="fa-field-err"><?= e($errors['odometer_km']) ?></span>
            <?php endif; ?>
          </div>
        </section>

        <button type="submit" class="fa-submit" id="fa-submit" disabled>
          <span class="fa-submit__label"><i class="bi bi-check-circle-fill"></i> Check In</span>
          <span class="fa-submit__spinner" aria-hidden="true"></span>
        </button>
      </form>
    </div>

    <script src="<?= e(asset_url(APP_URL . '/field/components/photo-compress.js')) ?>"></script>
    <script src="<?= e(asset_url(APP_URL . '/field/checkinout/js/checkinout.js')) ?>"></script>
    <?php if (attendance_cutoff_enabled()): ?>
      <script src="<?= e(asset_url(APP_URL . '/field/checkinout/js/checkinout-lock.js')) ?>"></script>
    <?php endif; ?>

  <?php endif; ?>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
