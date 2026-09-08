<?php
/**
 * field/visit/index.php - My Visits. URL: /track/field/visit/
 *
 * This IS the bottom-tab-bar "My Visits" destination (see
 * field/components/footer/footer.php). All visit actions (log, mark Done,
 * view details) live here - the Home dashboard only links in.
 *
 *   - not checked in today: a plain note pointing back to Attendance
 *   - a visit still open (not marked Done): a banner with a "Visit Done"
 *     button - blocks logging a new visit until this one closes, since an
 *     employee is only ever at one shop at a time (server-enforced too)
 *   - otherwise: the "Log a Visit" / "Log Second Visit" / ... button
 *
 * Each visit in the list shows a "View" button (read-only details: shop,
 * area, the location grabbed once at arrival, the photo, arrival time, and -
 * once Done - the total time spent there) and, once Done, a green tick.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
$me = require_employee();
require dirname(__DIR__) . '/checkinout/_repo.php';
require __DIR__ . '/_repo.php';

$today      = server_today();
$attendance = field_today_attendance($pdo, (int) $me['id'], $today);
$checkedIn  = $attendance !== null;
$checkedOut = $checkedIn && !empty($attendance['check_out_at']);

$pageTitle  = 'My Visits';
$activeTab  = 'visits';
$sectionCss = [
    APP_URL . '/field/checkinout/css/checkinout.css',
    APP_URL . '/field/visit/css/visit.css',
];

$visitErrors = $_SESSION['visit_form_error'] ?? [];
unset($_SESSION['visit_form_error']);

$visits    = [];
$openVisit = null;
if ($checkedIn) {
    $vs = $pdo->prepare(
        "SELECT v.*, p.stored_path AS photo_path
           FROM visits v
      LEFT JOIN photos p ON p.visit_id = v.id AND p.photo_kind = 'visit'
          WHERE v.attendance_id = ?
       ORDER BY v.seq ASC"
    );
    $vs->execute([(int) $attendance['id']]);
    $visits = $vs->fetchAll();

    $openVisit = visit_open_one($pdo, (int) $attendance['id']);
}

$nextLabel = visit_next_label(count($visits));

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <h1>My Visits</h1>
    <p class="page-head__sub"><?= e((new DateTimeImmutable($today))->format('j F Y')) ?></p>
  </div>

  <?php if (($_GET['ok'] ?? '') === 'visit'): ?>
    <div class="fh-flash">
      <i class="bi bi-check-circle-fill"></i> Visit logged successfully.
    </div>
  <?php endif; ?>

  <?php if (($_GET['ok'] ?? '') === 'completed'): ?>
    <div class="fh-flash">
      <i class="bi bi-check-circle-fill"></i> Visit marked as visited. You can log your next visit now.
    </div>
  <?php endif; ?>

  <?php if (!$checkedIn): ?>

    <p class="fv-note"><i class="bi bi-info-circle-fill"></i> Check in first to log a visit.</p>
    <a class="fv-log-btn" href="<?= e(APP_URL) ?>/field/checkinout/">
      <i class="bi bi-geo-alt-fill"></i> Go to Check In/Out
    </a>

  <?php else: ?>

    <?php if (!$checkedOut && $openVisit !== null): ?>
      <!-- ===== currently at a shop - must mark Done before the next visit ===== -->
      <div class="fv-open">
        <div class="fv-open__body">
          <i class="bi bi-shop-window"></i>
          <div>
            <strong>You're at <?= e($openVisit['shop_name']) ?></strong>
            <span>Since <?= e((new DateTimeImmutable($openVisit['arrived_at']))->format('g:i A')) ?> &middot; mark it done when you leave.</span>
          </div>
        </div>
        <form method="post" action="<?= e(APP_URL) ?>/field/visit/api/complete.php">
          <?= csrf_field() ?>
          <input type="hidden" name="visit_id" value="<?= (int) $openVisit['id'] ?>">
          <button type="submit" class="fv-complete-btn">
            <i class="bi bi-check-circle-fill"></i> Visit Done
          </button>
        </form>
      </div>
    <?php elseif (!$checkedOut): ?>
      <button type="button" class="fv-log-btn" id="fh-visit-open">
        <i class="bi bi-plus-circle-fill"></i> <?= e($nextLabel) ?>
      </button>
    <?php else: ?>
      <p class="fv-note"><i class="bi bi-check-circle-fill"></i> You're checked out for today - no more visits can be logged.</p>
    <?php endif; ?>

    <?php if (!$visits): ?>
      <p class="fv-empty">No visits logged yet today.</p>
    <?php else: ?>
      <ul class="fv-list">
        <?php foreach ($visits as $v): $isOpen = $v['left_at'] === null; ?>
          <li class="fv-item<?= $isOpen ? ' fv-item--open' : ' fv-item--done' ?>">
            <?php if ($v['photo_path']): ?>
              <img class="fv-item__photo" src="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" alt="">
            <?php else: ?>
              <span class="fv-item__photo fv-item__photo--none"><i class="bi bi-shop"></i></span>
            <?php endif; ?>
            <div class="fv-item__body">
              <strong><?= e($v['shop_name']) ?></strong>
              <span><i class="bi bi-signpost-2"></i> <?= e($v['area_name']) ?></span>
              <span class="fv-item__times">
                <i class="bi bi-box-arrow-in-right"></i> <?= e((new DateTimeImmutable($v['arrived_at']))->format('g:i A')) ?>
                <?php if ($v['left_at'] !== null): ?>
                  &middot; <i class="bi bi-box-arrow-right"></i> <?= e((new DateTimeImmutable($v['left_at']))->format('g:i A')) ?>
                <?php endif; ?>
              </span>
            </div>
            <?php if ($isOpen): ?>
              <span class="fv-item__badge">In Shop</span>
            <?php else: ?>
              <span class="fv-item__tick" title="Visited"><i class="bi bi-check-circle-fill"></i></span>
            <?php endif; ?>
            <a class="fv-view-btn" href="<?= e(APP_URL) ?>/field/visit/view.php?id=<?= (int) $v['id'] ?>">
              <i class="bi bi-eye-fill"></i> View
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if (!$checkedOut && $openVisit === null): ?>
      <!-- ===== Log a Visit modal ===== -->
      <div class="fh-modal" id="fh-visit-modal" hidden>
        <div class="fh-modal__sheet" role="dialog" aria-modal="true" aria-labelledby="fh-visit-title">
          <div class="fh-modal__head">
            <h2 id="fh-visit-title"><?= e($nextLabel) ?></h2>
            <button type="button" class="fh-modal__close" id="fh-visit-close" aria-label="Close">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>

          <form class="fh-visit-form" method="post" enctype="multipart/form-data"
                action="<?= e(APP_URL) ?>/field/visit/api/save.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="lat" id="fv-lat" value="">
            <input type="hidden" name="lng" id="fv-lng" value="">
            <input type="hidden" name="accuracy" id="fv-accuracy" value="">

            <?php if (!empty($visitErrors['_'])): ?>
              <div class="fa-flash"><i class="bi bi-exclamation-circle-fill"></i> <?= e($visitErrors['_']) ?></div>
            <?php endif; ?>

            <div class="fa-field">
              <label for="fv-shop">Shop Name</label>
              <input class="fa-input" type="text" name="shop_name" id="fv-shop"
                     maxlength="160" required placeholder="e.g. Bhatbhateni Mini Mart">
              <?php if (isset($visitErrors['shop_name'])): ?>
                <span class="fa-field-err"><?= e($visitErrors['shop_name']) ?></span>
              <?php endif; ?>
            </div>

            <div class="fa-field">
              <label for="fv-area">Area Name</label>
              <input class="fa-input" type="text" name="area_name" id="fv-area"
                     maxlength="120" required placeholder="e.g. Koteshwor">
              <?php if (isset($visitErrors['area_name'])): ?>
                <span class="fa-field-err"><?= e($visitErrors['area_name']) ?></span>
              <?php endif; ?>
            </div>

            <button type="button" class="fa-locate-btn" id="fv-locate-btn">
              <i class="bi bi-geo-alt-fill"></i> Click Me
            </button>
            <div class="fa-location" id="fv-location-box" hidden></div>

            <label class="fa-shot" id="fv-shot-label">
              <input type="file" name="shop_photo" id="fv-shot-input"
                     accept="image/*" capture="environment" required hidden>
              <span class="fa-shot__empty" id="fv-shot-empty">
                <i class="bi bi-camera"></i>
                <span>Tap to open camera - shop evidence photo</span>
              </span>
              <img class="fa-shot__preview" id="fv-shot-preview" alt="" hidden>
            </label>
            <?php if (isset($visitErrors['shop_photo'])): ?>
              <span class="fa-field-err"><?= e($visitErrors['shop_photo']) ?></span>
            <?php endif; ?>

            <button type="submit" class="fa-submit" id="fv-submit" disabled>
              <span class="fa-submit__label"><i class="bi bi-check-circle-fill"></i> Confirm</span>
              <span class="fa-submit__spinner" aria-hidden="true"></span>
            </button>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <script src="<?= e(asset_url(APP_URL . '/field/components/photo-compress.js')) ?>"></script>
    <script src="<?= e(asset_url(APP_URL . '/field/visit/js/visit.js')) ?>"></script>

  <?php endif; ?>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
