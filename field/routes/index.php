<?php
/**
 * field/routes/index.php - Routes. URL: /track/field/routes/
 *
 * This IS the bottom-tab-bar "Routes" destination (see
 * field/components/footer/footer.php). Deliberately a plain numbered list
 * of the day's stops (Start -> Visit 1 -> Visit 2 -> ... -> End), NOT a
 * live map - a real map (Mapbox etc.) costs per view/load, and for one
 * employee's own day this list is just as clear. A single-day picker only
 * (a route is a one-day concept - no month/all-time rollup).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_employee();
require __DIR__ . '/_repo.php';

$today = server_today();

$onDate = (string) ($_GET['on'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) || !strtotime($onDate) || $onDate > $today) {
    $onDate = $today;
}
$isToday = $onDate === $today;

$points  = routes_points($pdo, (int) $me['id'], $onDate);
$visits  = array_values(array_filter($points, static fn($p) => $p['kind'] === 'visit'));

$pageTitle  = 'Routes';
$activeTab  = 'routes';
$sectionCss = APP_URL . '/field/routes/css/routes.css';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <h1>Routes</h1>
    <p class="page-head__sub"><i class="bi bi-calendar3"></i> <?= e((new DateTimeImmutable($onDate))->format('j F Y')) ?></p>
  </div>

  <form method="get" action="<?= e(APP_URL) ?>/field/routes/" class="rt-picker">
    <input class="rt-input" type="date" name="on" value="<?= e($onDate) ?>"
           max="<?= e($today) ?>" onchange="this.form.submit()">
    <?php if (!$isToday): ?>
      <a class="rt-today" href="<?= e(APP_URL) ?>/field/routes/"><i class="bi bi-arrow-counterclockwise"></i> Today</a>
    <?php endif; ?>
  </form>

  <section class="rt-card">
    <div class="rt-card__head">
      <h2>Visit Points<?= $visits ? ' (' . count($visits) . ')' : '' ?></h2>
    </div>

    <?php if (!$points): ?>
      <p class="rt-empty">No check-in recorded on <?= e((new DateTimeImmutable($onDate))->format('j F Y')) ?>.</p>
    <?php else: ?>
      <ul class="rt-list">
        <?php foreach ($points as $p):
          $badge = $p['kind'] === 'start' ? 'A' : ($p['kind'] === 'end' ? 'B' : (string) $p['no']);
        ?>
          <li class="rt-item rt-item--<?= e($p['kind']) ?>">
            <span class="rt-item__badge"><?= e($badge) ?></span>
            <div class="rt-item__body">
              <span class="rt-item__time"><?= e($p['at']->format('g:i A')) ?></span>
              <strong class="rt-item__label"><?= e($p['label']) ?></strong>
              <?php if ($p['sub']): ?><span class="rt-item__sub"><?= e($p['sub']) ?></span><?php endif; ?>
              <?php if ($p['lat'] !== null): ?>
                <span class="rt-item__coord"><?= e(number_format($p['lat'], 5)) ?>, <?= e(number_format($p['lng'], 5)) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($p['kind'] === 'start'): ?>
              <span class="rt-item__tag rt-item__tag--start">Start</span>
            <?php elseif ($p['kind'] === 'end'): ?>
              <span class="rt-item__tag rt-item__tag--end">End</span>
            <?php else: ?>
              <span class="rt-item__tag rt-item__tag--visit">Visit #<?= (int) $p['no'] ?></span>
            <?php endif; ?>
            <i class="bi bi-geo-alt-fill rt-item__pin"></i>
          </li>
        <?php endforeach; ?>
      </ul>

      <a class="rt-detail-link" href="<?= e(APP_URL) ?>/field/attendance/?view=day&on=<?= e($onDate) ?>">
        View full day detail <i class="bi bi-arrow-right"></i>
      </a>
    <?php endif; ?>
  </section>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
