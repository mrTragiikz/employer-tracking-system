<?php
/**
 * field/home/index.php - Employee dashboard. URL: /track/field/home/
 *
 * Greeting + attendance CTA + 4 stat tiles + Recent Visits, per the supplied
 * mockup - "Today's Plan" (a pre-scheduled shop list) is left out: this app
 * has no route/beat-planning feature or table behind it, Employees log a
 * visit by typing a shop name when they arrive, so a "planned" list would
 * be decorative, not real. Every tile here is a real query.
 *
 * Build order: first field/ page after login.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
$me = require_employee();
require __DIR__ . '/_repo.php';

$pageTitle  = 'Dashboard';
$activeTab  = 'dashboard';
$sectionCss = [
    APP_URL . '/field/home/css/home.css',
    APP_URL . '/field/visit/css/visit.css',
];

$today      = server_today();
$attendance = field_today_attendance($pdo, (int) $me['id'], $today);

$todayVisits = [];
if ($attendance !== null) {
    $vs = $pdo->prepare('SELECT * FROM visits WHERE attendance_id = ? ORDER BY seq ASC');
    $vs->execute([(int) $attendance['id']]);
    $todayVisits = $vs->fetchAll();
}

$stats = field_home_stats($pdo, (int) $me['id'], $today, $attendance, $todayVisits);

$checkedIn  = $attendance !== null;
$checkedOut = $checkedIn && !empty($attendance['check_out_at']);

/** "9:24 AM" from a DATETIME string. */
$fmtTime = static fn(?string $ts) => $ts ? (new DateTimeImmutable($ts))->format('g:i A') : null;

require dirname(__DIR__) . '/components/header/header.php';
?>

  <?php if (($_GET['ok'] ?? '') === 'checkin'): ?>
    <div class="fh-flash">
      <i class="bi bi-check-circle-fill"></i> You're checked in. Have a great day!
    </div>
  <?php endif; ?>

  <div class="fh-greeting">
    <div>
      <h1><?= e(field_greeting()) ?>, <?= e(explode(' ', trim($me['name']))[0] ?? $me['name']) ?> <span aria-hidden="true">&#128075;</span></h1>
      <p class="fh-greeting__sub">
        <?= $checkedOut ? "You're checked out for today. Nice work!" : ($checkedIn ? "You're checked in - keep logging your visits." : "Let's mark your first attendance and start your day.") ?>
      </p>
    </div>
    <span class="fh-date-pill"><i class="bi bi-calendar3"></i> <?= e((new DateTimeImmutable($today))->format('j M Y')) ?></span>
  </div>

  <!-- ===== attendance CTA ===== -->
  <?php if (!$checkedIn): ?>
    <section class="fh-cta">
      <div class="fh-cta__body">
        <span class="fh-cta__icon"><i class="bi bi-fingerprint"></i></span>
        <h2>Mark Your First Attendance</h2>
        <p class="fh-cta__np-text">शुभ प्रभात! हजुरको आजको attendance अझै मार्क भएको छैन। यहाँ क्लिक गरेर आफ्नो attendance गर्नुहोला।</p>
        <a class="fh-cta__btn" href="<?= e(APP_URL) ?>/field/checkinout/">
          <i class="bi bi-geo-alt-fill"></i> Mark Attendance Now
        </a>
        <p class="fh-cta__note"><i class="bi bi-info-circle"></i> This will mark your check-in time and location.</p>
      </div>
    </section>
  <?php else: ?>
    <section class="fh-cta fh-cta--done">
      <div class="fh-cta__body">
        <span class="fh-cta__icon fh-cta__icon--ok"><i class="bi bi-check-circle-fill"></i></span>
        <h2><?= $checkedOut ? 'Day Complete' : 'Checked In' ?></h2>
        <p>
          Checked in
          (<?= e($fmtTime($attendance['check_in_at'])) ?>, <?= e(number_format((float) $attendance['check_in_lat'], 6)) ?>, <?= e(number_format((float) $attendance['check_in_lng'], 6)) ?>)
          <?php if ($checkedOut): ?>
            &middot; checked out at <strong><?= e($fmtTime($attendance['check_out_at'])) ?></strong>
          <?php endif; ?>
        </p>
        <?php if (!$checkedOut): ?>
          <p class="fh-cta__np">
            <span>हजुरको आजको attendance सफल भइसक्यो।</span>
            <span>अब आफ्नो visiting data हरू My Visit बाट गर्नुहोला। धन्यवाद।</span>
          </p>
          <a class="fh-cta__btn fh-cta__btn--ghost" href="<?= e(APP_URL) ?>/field/visit/">
            <i class="bi bi-shop"></i> Go to My Visits
          </a>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>

  <!-- ===== stat tiles ===== -->
  <div class="fh-stat-row">
    <div class="fh-stat">
      <span class="fh-stat__icon fh-stat__icon--green"><i class="bi bi-check2-circle"></i></span>
      <span class="fh-stat__label">Status</span>
      <span class="fh-stat__value fh-stat__value--sm">
        <?php if ($checkedIn): ?>
          <i class="bi bi-check-circle-fill fh-stat__tick"></i>
        <?php endif; ?>
        <?= $checkedOut ? 'Checked Out' : ($checkedIn ? 'Checked In' : 'Not Marked') ?>
      </span>
      <span class="fh-stat__foot">
        <?php if ($checkedIn): ?>
          <?= e($fmtTime($attendance['check_in_at'])) ?> &middot; <?= e(number_format((float) $attendance['check_in_odometer_km'], 1)) ?> km
        <?php else: ?>
          You haven't checked in today
        <?php endif; ?>
      </span>
    </div>

    <div class="fh-stat">
      <span class="fh-stat__icon fh-stat__icon--blue"><i class="bi bi-geo-alt"></i></span>
      <span class="fh-stat__label">Today's Visits</span>
      <span class="fh-stat__value fh-stat__value--center"><?= (int) $stats['visits_today'] ?></span>
      <span class="fh-stat__foot">Shop visited today</span>
    </div>

    <div class="fh-stat">
      <span class="fh-stat__icon fh-stat__icon--peach"><i class="bi bi-map"></i></span>
      <span class="fh-stat__label">Productive Work KM</span>
      <span class="fh-stat__value"><?= e(number_format((float) $stats['km_today'], 1)) ?> km</span>
      <span class="fh-stat__foot">travelled so far</span>
    </div>

    <div class="fh-stat">
      <span class="fh-stat__icon fh-stat__icon--red"><i class="bi bi-box-arrow-right"></i></span>
      <span class="fh-stat__label">Check-out KM</span>
      <?php if ($checkedOut): ?>
        <span class="fh-stat__value"><?= e(number_format((float) $attendance['check_out_odometer_km'], 1)) ?> km</span>
        <span class="fh-stat__foot"><?= e($fmtTime($attendance['check_out_at'])) ?></span>
      <?php else: ?>
        <span class="fh-stat__value fh-stat__value--sm">-</span>
        <span class="fh-stat__foot">Not checked out yet</span>
      <?php endif; ?>
    </div>
  </div>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
