<?php
/**
 * field/visit/view.php - read-only detail page for one visit.
 * URL: /track/field/visit/view.php?id=123
 *
 * Own page rather than a modal - simpler, no JS needed, and a proper back
 * button/URL an employee can actually navigate with. Only shows a visit
 * that belongs to the logged-in employee.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
$me = require_employee();

$visitId = (int) ($_GET['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT v.*, p.stored_path AS photo_path
       FROM visits v
  LEFT JOIN photos p ON p.visit_id = v.id AND p.photo_kind = 'visit'
      WHERE v.id = ? AND v.employee_id = ?
      LIMIT 1"
);
$st->execute([$visitId, (int) $me['id']]);
$v = $st->fetch();

if (!$v) {
    redirect(APP_URL . '/field/visit/');
}

$pageTitle  = $v['shop_name'];
$activeTab  = 'visits';
$sectionCss = [
    APP_URL . '/field/checkinout/css/checkinout.css',
    APP_URL . '/field/visit/css/visit.css',
];

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <a class="fv-back" href="<?= e(APP_URL) ?>/field/visit/">
      <i class="bi bi-arrow-left"></i> My Visits
    </a>
  </div>

  <section class="fv-detail">
    <div class="fv-detail__head">
      <h1><?= e($v['shop_name']) ?></h1>
      <?php if ($v['left_at'] !== null): ?>
        <span class="fv-detail__status fv-detail__status--done"><i class="bi bi-check-circle-fill"></i> Visited</span>
      <?php else: ?>
        <span class="fv-detail__status fv-detail__status--open"><i class="bi bi-shop-window"></i> In Shop</span>
      <?php endif; ?>
    </div>

    <?php if ($v['photo_path']): ?>
      <img class="fv-detail__photo" src="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" alt="<?= e($v['shop_name']) ?> evidence photo">
    <?php endif; ?>

    <div class="fa-summary fv-detail-summary">
      <div class="fa-summary__row">
        <span class="fa-summary__label"><i class="bi bi-signpost-2 fv-ic fv-ic--purple"></i> Area</span>
        <span class="fa-summary__value"><?= e($v['area_name']) ?></span>
      </div>
      <div class="fa-summary__row">
        <span class="fa-summary__label"><i class="bi bi-geo-alt-fill fv-ic fv-ic--blue"></i> Location</span>
        <span class="fa-summary__value"><?= e(number_format((float) $v['lat'], 6)) ?>, <?= e(number_format((float) $v['lng'], 6)) ?></span>
      </div>
      <a class="fv-map-btn"
         href="https://www.google.com/maps/search/?api=1&query=<?= e((string) $v['lat']) ?>,<?= e((string) $v['lng']) ?>"
         target="_blank" rel="noopener noreferrer">
        <i class="bi bi-map-fill"></i> Open in Google Maps
      </a>
      <div class="fa-summary__row">
        <span class="fa-summary__label"><i class="bi bi-clock fv-ic fv-ic--green"></i> Arrived</span>
        <span class="fa-summary__value"><?= e((new DateTimeImmutable($v['arrived_at']))->format('g:i A')) ?></span>
      </div>
      <?php if ($v['left_at'] !== null): ?>
        <div class="fa-summary__row">
          <span class="fa-summary__label"><i class="bi bi-box-arrow-right fv-ic fv-ic--peach"></i> Left</span>
          <span class="fa-summary__value"><?= e((new DateTimeImmutable($v['left_at']))->format('g:i A')) ?></span>
        </div>
        <div class="fa-summary__row">
          <span class="fa-summary__label"><i class="bi bi-hourglass-split fv-ic fv-ic--brown"></i> Total Shop Time</span>
          <span class="fa-summary__value"><?= e(fmt_hm((int) $v['dwell_seconds'])) ?></span>
        </div>
      <?php else: ?>
        <div class="fa-summary__row">
          <span class="fa-summary__label"><i class="bi bi-hourglass-split fv-ic fv-ic--brown"></i> Total Shop Time</span>
          <span class="fa-summary__value">Still in shop</span>
        </div>
      <?php endif; ?>
    </div>
  </section>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
