<?php
/**
 * field/attendance/index.php - Attendance. URL: /track/field/attendance/
 *
 * This IS the bottom-tab-bar "Attendance" destination (see
 * field/components/footer/footer.php), not a one-off form that bounces
 * away - it always shows something relevant to attendance for today:
 *
 *   - not checked in yet: the check-in form (deliberately simple) -
 *     auto-captured GPS location (never typed/edited by hand), a live
 *     camera photo of the bike's odometer/speedometer, a manual KM number
 *     input, and one "Check In" button
 *   - already checked in today: today's check-in summary (time, KM, photo)
 *     instead of an empty/pointless re-submitted form
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
$me = require_employee();
require __DIR__ . '/_repo.php';

$today = server_today();

$pageTitle  = 'Attendance';
$activeTab  = 'attendance';
$sectionCss = APP_URL . '/field/attendance/css/attendance.css';

/* ---- My Overview: Day / Month / All time (same concept as the admin's
   Employee Detail Overview tab, scoped to "myself") ---- */
$ovMode = in_array($_GET['view'] ?? '', ['day', 'month', 'alltime'], true) ? $_GET['view'] : 'day';

$onDate = (string) ($_GET['on'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) || !strtotime($onDate) || $onDate > $today) {
    $onDate = $today;
}
$isToday = $onDate === $today;

$onMonth = (string) ($_GET['month'] ?? substr($today, 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $onMonth) || !strtotime($onMonth . '-01') || $onMonth > substr($today, 0, 7)) {
    $onMonth = substr($today, 0, 7);
}

$statement    = null;
$timeline     = [];
$periodTotals = null;
$periodLabel  = '';
$monthDays    = [];

if ($ovMode === 'day') {
    $statement   = field_statement($pdo, (int) $me['id'], $onDate, server_now());
    $timeline    = field_timeline($pdo, (int) $me['id'], $onDate);
    $periodLabel = $isToday ? 'Today' : (new DateTimeImmutable($onDate))->format('l, j F Y');
} elseif ($ovMode === 'month') {
    $mFrom        = $onMonth . '-01';
    $mTo          = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
    $periodTotals = field_period_totals($pdo, (int) $me['id'], $mFrom, $mTo);
    $periodLabel  = (new DateTimeImmutable($mFrom))->format('F Y');
    $monthDays    = field_calendar($pdo, (int) $me['id'], $mFrom, $mTo, $today);
} else {
    $periodTotals = field_period_totals($pdo, (int) $me['id'], null, null);
    $periodLabel  = 'All time';
}

/** URL to this page in a given overview mode, keeping #tabs-style anchor out (single page, no tabs). */
$ovUrl = static fn(string $mode, array $extra = []): string =>
    APP_URL . '/field/attendance/?' . http_build_query(['view' => $mode] + $extra);

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <h1>Attendance</h1>
    <p class="page-head__sub"><?= e((new DateTimeImmutable($today))->format('j F Y')) ?></p>
  </div>

  <!-- ===== My Overview: Day / Month / All time ===== -->
  <div class="fa-ov">
    <div class="fa-ov__head">
      <h2>My Overview</h2>
      <div class="fa-ov__modes">
        <a class="fa-ov-seg <?= $ovMode === 'day'     ? 'is-active' : '' ?>" href="<?= e($ovUrl('day')) ?>">Day</a>
        <a class="fa-ov-seg <?= $ovMode === 'month'   ? 'is-active' : '' ?>" href="<?= e($ovUrl('month')) ?>">Month</a>
        <a class="fa-ov-seg <?= $ovMode === 'alltime' ? 'is-active' : '' ?>" href="<?= e($ovUrl('alltime')) ?>">All time</a>
      </div>
    </div>

    <?php if ($ovMode === 'day'): ?>
      <form method="get" action="<?= e(APP_URL) ?>/field/attendance/" class="fa-ov__picker">
        <input type="hidden" name="view" value="day">
        <input class="fa-input fa-input--sm" type="date" name="on" value="<?= e($onDate) ?>"
               max="<?= e($today) ?>" onchange="this.form.submit()">
        <?php if (!$isToday): ?>
          <a class="fa-ov__today" href="<?= e($ovUrl('day')) ?>"><i class="bi bi-arrow-counterclockwise"></i> Today</a>
        <?php endif; ?>
      </form>
    <?php elseif ($ovMode === 'month'): ?>
      <form method="get" action="<?= e(APP_URL) ?>/field/attendance/" class="fa-ov__picker">
        <input type="hidden" name="view" value="month">
        <input class="fa-input fa-input--sm" type="month" name="month" value="<?= e($onMonth) ?>"
               max="<?= e(substr($today, 0, 7)) ?>" onchange="this.form.submit()">
      </form>
    <?php else: ?>
      <p class="fa-ov__note">Your lifetime figures.</p>
    <?php endif; ?>

    <!-- ===== statement ===== -->
    <section class="fa-card">
      <div class="fa-card__head">
        <h2><?= e($periodLabel) ?> statement</h2>
      </div>

      <?php if ($ovMode === 'day'): ?>
        <?php if (!$statement['has_attendance']): ?>
          <p class="fa-empty">You did not check in on <?= e((new DateTimeImmutable($onDate))->format('j F Y')) ?>.</p>
        <?php else: ?>
          <div class="fa-summary">
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-box-arrow-in-right"></i> Attendance time (check-in)</span>
              <span class="fa-summary__value">
                <?= $statement['first_check_in'] ? e($statement['first_check_in']->format('g:i A')) : fa_dash() ?>
                <?php if ($statement['check_in_km'] !== null): ?>
                  <span class="fa-sub fa-sub--km"><i class="bi bi-speedometer2"></i> <?= e(number_format($statement['check_in_km'], 1)) ?> km</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-clipboard-check"></i> Total visits</span>
              <span class="fa-summary__value"><?= (int) $statement['visits'] ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-geo"></i> Productive KM travelled (estimate)</span>
              <span class="fa-summary__value"><?= e(number_format($statement['km'], 1)) ?> km</span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-check2-circle"></i> Productive working time (at shops)</span>
              <span class="fa-summary__value"><?= e(fmt_hm($statement['shop_secs'])) ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-clock"></i> Full day time (check-in to check-out)</span>
              <span class="fa-summary__value">
                <?= $statement['active_secs'] !== null ? e(fmt_hm($statement['active_secs'])) : fa_dash() ?>
                <?php if ($statement['last_check_out'] === null && $statement['first_check_in'] !== null): ?>
                  <span class="fa-sub fa-sub--warn">still open</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-box-arrow-right"></i> Last check-out</span>
              <span class="fa-summary__value">
                <?= $statement['last_check_out'] ? e($statement['last_check_out']->format('g:i A')) : fa_dash() ?>
                <?php if ($statement['check_out_km'] !== null): ?>
                  <span class="fa-sub fa-sub--km"><i class="bi bi-speedometer2"></i> <?= e(number_format($statement['check_out_km'], 1)) ?> km</span>
                <?php endif; ?>
              </span>
            </div>
          </div>
        <?php endif; ?>

      <?php else: /* month or alltime */ ?>
        <?php if ($periodTotals['days'] === 0): ?>
          <p class="fa-empty">
            <?= $ovMode === 'month' ? 'No working days recorded in ' . e($periodLabel) . '.' : 'No recorded activity yet.' ?>
          </p>
        <?php else: ?>
          <div class="fa-summary">
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-clipboard-check"></i> Visits</span>
              <span class="fa-summary__value"><?= (int) $periodTotals['visits'] ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-shop"></i> Shops visited</span>
              <span class="fa-summary__value"><?= (int) $periodTotals['shops'] ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-geo"></i> Productive KM (estimate)</span>
              <span class="fa-summary__value"><?= e(number_format($periodTotals['productive_km'], 1)) ?> km</span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-check2-circle"></i> Productive time (at shops)</span>
              <span class="fa-summary__value"><?= e(fmt_hm($periodTotals['shop_secs'])) ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-calendar-check"></i> Attendance (days worked)</span>
              <span class="fa-summary__value">
                <?= (int) $periodTotals['days'] ?>
                <?php if ($periodTotals['incomplete'] > 0): ?><span class="fa-sub fa-sub--warn"><?= (int) $periodTotals['incomplete'] ?> incomplete</span><?php endif; ?>
              </span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-signpost-2"></i> Travel time (between shops)</span>
              <span class="fa-summary__value"><?= e(fmt_hm($periodTotals['road_secs'])) ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-clock-history"></i> Avg. visit duration</span>
              <span class="fa-summary__value"><?= $periodTotals['avg_dwell_secs'] !== null ? e(fmt_hm($periodTotals['avg_dwell_secs'])) : fa_dash() ?></span>
            </div>
            <div class="fa-summary__row">
              <span class="fa-summary__label"><i class="bi bi-clock"></i> Total time (check-in to check-out)</span>
              <span class="fa-summary__value">
                <?= e(fmt_hm($periodTotals['total_secs'])) ?>
                <?php if ($periodTotals['incomplete'] > 0): ?><span class="fa-sub">closed days only</span><?php endif; ?>
              </span>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- ===== day: location timeline / month: per-day list / alltime: pointer ===== -->
    <?php if ($ovMode === 'day'): ?>
      <section class="fa-card">
        <div class="fa-card__head">
          <h2><?= $isToday ? "Today's" : "Day's" ?> Attendance &amp; Location Timeline</h2>
        </div>
        <?php if (!$timeline): ?>
          <p class="fa-empty">No check-in recorded for this day.</p>
        <?php else: ?>
          <ul class="fa-tl">
            <?php foreach ($timeline as $row):
              $isVisit = $row['kind'] === 'visit';
              $tlIcon  = $row['kind'] === 'checkin' ? 'bi-box-arrow-in-right' : ($row['kind'] === 'checkout' ? 'bi-box-arrow-right' : 'bi-shop');
              $tlPhotoId = $isVisit ? 'fa-tl-photo-' . (int) $row['visit_no'] : null;
            ?>
              <li class="fa-tl__row fa-tl__row--<?= e($row['kind']) ?>">
                <span class="fa-tl__icon">
                  <i class="bi <?= e($tlIcon) ?>"></i>
                  <?php if ($isVisit): ?><span class="fa-tl__no"><?= (int) $row['visit_no'] ?></span><?php endif; ?>
                </span>
                <div class="fa-tl__body">
                  <div class="fa-tl__top">
                    <strong><?= e($row['label']) ?></strong>
                    <span class="fa-tl__time">
                      <?= e($row['at']->format('g:i A')) ?>
                      <?php if ($isVisit && $row['left_at']): ?> &ndash; <?= e($row['left_at']->format('g:i A')) ?><?php endif; ?>
                    </span>
                  </div>
                  <?php if ($row['lat'] !== null): ?>
                    <span class="fa-tl__coord"><i class="bi bi-geo-alt"></i> <?= e(number_format($row['lat'], 6)) ?>, <?= e(number_format($row['lng'], 6)) ?></span>
                  <?php endif; ?>
                  <?php if ($isVisit): ?>
                    <span class="fa-tl__meta">
                      <?= e($row['area']) ?>
                      &middot; <?= $row['dwell_secs'] !== null ? e(fmt_hm($row['dwell_secs'])) : 'still there' ?>
                    </span>
                    <?php if ($row['photo_path']): ?>
                      <button type="button" class="fa-tl__photo-btn" data-open-photo="<?= e($tlPhotoId) ?>">
                        <i class="bi bi-image-fill"></i> View Photo
                      </button>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="fa-tl__meta"><?= e($row['remark']) ?></span>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>

          <!-- ===== view/close photo modals, one per visit with a photo ===== -->
          <?php foreach ($timeline as $row): if ($row['kind'] === 'visit' && $row['photo_path']): ?>
            <div class="fa-photo-modal" id="fa-tl-photo-<?= (int) $row['visit_no'] ?>" hidden>
              <button type="button" class="fa-photo-modal__close" data-close-photo aria-label="Close">
                <i class="bi bi-x-lg"></i>
              </button>
              <img src="<?= e(UPLOAD_URL . '/' . $row['photo_path']) ?>" alt="<?= e($row['label']) ?> evidence photo">
            </div>
          <?php endif; endforeach; ?>

          <script>
            (function () {
              document.querySelectorAll('[data-open-photo]').forEach(function (btn) {
                var modal = document.getElementById(btn.getAttribute('data-open-photo'));
                if (!modal) return;
                btn.addEventListener('click', function () { modal.hidden = false; });
              });
              document.querySelectorAll('.fa-photo-modal').forEach(function (modal) {
                modal.addEventListener('click', function (e) {
                  if (e.target === modal || e.target.closest('[data-close-photo]')) {
                    modal.hidden = true;
                  }
                });
              });
            })();
          </script>
        <?php endif; ?>
      </section>

    <?php elseif ($ovMode === 'month'): ?>
      <section class="fa-card">
        <div class="fa-card__head">
          <h2>Days in <?= e($periodLabel) ?></h2>
          <span class="fa-sub"><?= count(array_filter($monthDays, static fn($r) => $r['has_attendance'])) ?> attended of <?= count($monthDays) ?> days</span>
        </div>
        <?php if (!$monthDays): ?>
          <p class="fa-empty">Nothing to show for <?= e($periodLabel) ?>.</p>
        <?php else: ?>
          <ul class="fa-mdays">
            <?php foreach ($monthDays as $row):
              $dObj   = new DateTimeImmutable($row['date']);
              $dayUrl = APP_URL . '/field/attendance/?view=day&on=' . $row['date'];
            ?>
              <?php if (!$row['has_attendance']): ?>
                <li class="fa-mdays__row fa-mdays__row--absent">
                  <span class="fa-mdays__icon"><i class="bi bi-x-circle-fill"></i></span>
                  <span class="fa-mdays__date"><?= e($dObj->format('D, j M')) ?></span>
                  <span class="fa-sub">Not attended</span>
                </li>
              <?php else: ?>
                <li class="fa-mdays__row fa-mdays__row--ok">
                  <a class="fa-mdays__link" href="<?= e($dayUrl) ?>">
                    <span class="fa-mdays__icon"><i class="bi bi-check-circle-fill"></i></span>
                    <span class="fa-mdays__body">
                      <span class="fa-mdays__date"><?= e($dObj->format('D, j M')) ?></span>
                      <span class="fa-mdays__stats">
                        <?= e((new DateTimeImmutable($row['check_in_at']))->format('g:i A')) ?>
                        &middot; <?= (int) $row['visit_count'] ?> visits
                        &middot; <?= e(number_format((float) $row['road_km'], 1)) ?> km
                      </span>
                    </span>
                    <span class="fa-mdays__view"><i class="bi bi-eye-fill"></i> View</span>
                  </a>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    <?php else: /* alltime */ ?>
      <section class="fa-card">
        <p class="fa-empty">See Month view for a day-by-day breakdown.</p>
      </section>
    <?php endif; ?>
  </div>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
