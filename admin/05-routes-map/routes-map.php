<?php
/**
 * admin/05-routes-map/routes-map.php - Routes & Map section.
 * URL: /track/admin/05-routes-map/?employee=N&date=YYYY-MM-DD
 *
 * One Employee, one day: the route that connects their visit points
 * (check-in -> shop 1 -> ... -> check-out). The map is the shared
 * _route_map.php placeholder; a real map API drops in later.
 *
 * Build order step 7. Connected visit points, NOT a live trail.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require dirname(__DIR__) . '/02-employees/_repo.php';   // employee_day / employee_timeline / employee_statement / hm / latlng / map_link

$pageTitle     = 'Routes & Map';
$activeSection  = 'routes';
$sectionCss     = [
    APP_URL . '/admin/05-routes-map/css/routes-map.css',
    APP_URL . '/admin/components/mapbox/css/mapbox-route.css',
];

/* ---- pick an Employee + a day ------------------------------------------- */
$Employees = [];
try {
    $Employees = $pdo->query(
        "SELECT id, name, code, region, area, is_active
           FROM users
          WHERE role = 'employee' AND deleted_at IS NULL
          ORDER BY is_active DESC, name"
    )->fetchAll();
} catch (Throwable $e) { /* ignore */ }

$employeeId = isset($_GET['employee']) ? (int) $_GET['employee'] : (int) ($Employees[0]['id'] ?? 0);
$employee   = null;
foreach ($Employees as $d) {
    if ((int) $d['id'] === $employeeId) { $employee = $d; break; }
}

$date = (string) ($_GET['date'] ?? server_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > server_today()) {
    $date = server_today();
}
$dObj    = new DateTimeImmutable($date);
$isToday = $date === server_today();

/* ---- the day's data ------------------------------------------------- */
// Self-heal any visit still missing its hop distance before we read the day
// (see heal_missing_visit_hops() in includes/distance.php). distance.php is
// required just below for road_km_real() etc. anyway; load it here first.
require dirname(__DIR__, 2) . '/includes/distance.php';
if ($employee) {
    heal_missing_visit_hops($pdo, $employeeId);
}

$day       = $employee ? employee_day($pdo, $employeeId, $date) : null;
$statement = $employee ? employee_statement($pdo, $employeeId, $date) : ['has_attendance' => false];
$timeline  = $employee ? employee_timeline($pdo, $employeeId, $date) : [];

// Ordered GPS points for the map partial + the "Visit Points" list.
$mapPoints = [];
$shopNo = 0;
foreach ($timeline as $t) {
    if ($t['lat'] === null || $t['lng'] === null) continue;
    if ($t['kind'] === 'visit') $shopNo++;
    $mapPoints[] = [
        'kind'     => $t['kind'],
        'no'       => $t['kind'] === 'visit' ? $shopNo : null,
        'label'    => $t['label'],
        'at'       => $t['at']->format('Y-m-d H:i:s'),
        'lat'      => (float) $t['lat'],
        'lng'      => (float) $t['lng'],
        // hop INTO this point from the previous one - only meaningful on
        // visit rows, and only once it's been computed (hop_secs !== null).
        'hop_km'   => $t['kind'] === 'visit' ? (float) ($t['hop_km'] ?? 0) : null,
        'hop_secs' => $t['kind'] === 'visit' ? ($t['hop_secs'] ?? null) : null,
        'dwell'    => $t['kind'] === 'visit' ? ($t['dwell_secs'] ?? null) : null,
    ];
}
$hasRoute = count($mapPoints) >= 2;
$hasOut   = (bool) array_filter($mapPoints, static fn($p) => $p['kind'] === 'checkout');

/* ---- the five top numbers ----------------------------------------- */
$dayClosed = $day && $day['check_out_at'] !== null;

// road km: a CLOSED day uses attendance.road_km (the full-day audit figure,
// incl. the trip back to check-out). A STILL-OPEN day's attendance.road_km is
// 0, so sum each visit's own hop instead (visits.hop_road_km, filled at visit
// save time) - the route so far. road_seconds is the same story.
$roadKm  = 0.0;
$roadSec = 0;
if ($day) {
    if ($dayClosed) {
        $roadKm  = (float) $day['road_km'];
        $roadSec = (int) $day['road_seconds'];
    } else {
        $hopAgg = $pdo->prepare(
            'SELECT COALESCE(SUM(hop_road_km), 0) AS km,
                    COALESCE(SUM(hop_seconds), 0) AS secs
               FROM visits WHERE attendance_id = ?'
        );
        $hopAgg->execute([(int) $day['id']]);
        $agg     = $hopAgg->fetch();
        $roadKm  = (float) $agg['km'];
        $roadSec = (int) $agg['secs'];
    }
}
$totalSec = $day && $day['total_seconds'] !== null ? (int) $day['total_seconds'] : null;
$visitN   = $day ? (int) $day['visit_count'] : 0;
$ci       = $statement['first_check_in'] ?? null;
$co       = $statement['last_check_out'] ?? null;
$avgKmh   = $roadSec > 0 ? $roadKm / ($roadSec / 3600) : null;

/* ---- URL helper -------------------------------------------------------- */
$rmUrl = static function (int $employeeId, string $date): string {
    return APP_URL . '/admin/05-routes-map/?' . http_build_query(['employee' => $employeeId, 'date' => $date]);
};

require dirname(__DIR__) . '/components/header/header.php';
require dirname(__DIR__) . '/components/mapbox/mapbox.php';
?>

  <div class="page-head">
    <div>
      <h1>Routes &amp; Map</h1>
      <p class="section-note">View routes, visited locations and travel summary.</p>
    </div>
    <div class="rm-pickers">
      <form method="get" action="<?= e(APP_URL) ?>/admin/05-routes-map/" class="rm-pick">
        <input type="hidden" name="date" value="<?= e($date) ?>">
        <select class="select" name="employee" onchange="this.form.submit()" aria-label="Employee">
          <?php foreach ($Employees as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $employeeId ? 'selected' : '' ?>>
              <?= e($d['name']) ?><?= $d['code'] ? ' (' . e($d['code']) . ')' : '' ?><?= $d['is_active'] ? '' : ' - inactive' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <form method="get" action="<?= e(APP_URL) ?>/admin/05-routes-map/" class="rm-pick">
        <input type="hidden" name="employee" value="<?= (int) $employeeId ?>">
        <input class="input" type="date" name="date" value="<?= e($date) ?>"
               max="<?= e(server_today()) ?>" onchange="this.form.submit()">
      </form>
      <?php $rmQs = e(http_build_query(['employee' => $employeeId, 'date' => $date])); ?>
      <details class="rm-export">
        <summary class="btn"><i class="bi bi-download"></i> Export Route <i class="bi bi-chevron-down rm-export__chev"></i></summary>
        <div class="rm-export__menu">
          <a href="<?= e(APP_URL) ?>/admin/05-routes-map/api/export.php?<?= $rmQs ?>&format=pdf">
            <i class="bi bi-file-earmark-pdf"></i>
            <span>Export PDF <small>full route report with map, ready to share with a client</small></span>
          </a>
          <a href="<?= e(APP_URL) ?>/admin/05-routes-map/api/export.php?<?= $rmQs ?>&format=csv">
            <i class="bi bi-filetype-csv"></i>
            <span>Export CSV <small>raw stop list, for spreadsheets</small></span>
          </a>
        </div>
      </details>
    </div>
  </div>

  <?php if (!$employee): ?>
    <div class="card"><p class="section-note">No Employees found. Add them in Users &amp; Roles.</p></div>
  <?php else: ?>

  <!-- ===== five top numbers ===== -->
  <div class="rm-stats">
    <?php
    $tFmt = static fn(?DateTimeImmutable $t) => $t ? $t->format('g:iA') : null;
    $startEnd = ($tFmt($ci) ?? '-') . ' to ' . ($tFmt($co) ?? ($ci ? 'now' : '-'));
    $kmSub  = $dayClosed ? 'estimated, by road' : 'by road; route so far';
    $spdSub = $dayClosed ? 'between stops' : 'between stops, so far';
    $stats = [
        ['Total distance', number_format($roadKm, 1) . ' km', $kmSub, 'green', 'bi-signpost-split'],
        ['Total time',     $totalSec !== null ? hm($totalSec) : ($ci ? 'open' : '-'), 'check-in to check-out', 'blue', 'bi-stopwatch'],
        ['Total visits',   number_format($visitN), 'shops visited', 'purple', 'bi-geo-alt'],
        ['Avg. speed',     $avgKmh !== null ? number_format($avgKmh, 0) . ' km/h' : '-', $spdSub, 'peach', 'bi-speedometer2'],
        ['Start / End',    $startEnd, 'check-in / check-out', 'red', 'bi-map'],
    ];
    foreach ($stats as $si => [$label, $value, $sub, $tone, $icon]): ?>
      <div class="rm-stat">
        <span class="rm-stat__ico rm-stat__ico--<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div>
          <div class="rm-stat__value<?= $si === 4 ? ' rm-stat__value--sm' : '' ?>"><?= e($value) ?></div>
          <div class="rm-stat__label"><?= e($label) ?></div>
          <div class="rm-stat__sub"><?= e($sub) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ===== map | visit points ===== -->
  <div class="rm-grid">

    <section class="card rm-map-card">
      <div class="card__head">
        <h2>
          <?= $isToday ? "Today's" : "Day's" ?> Route
          <?php if ($isToday && $statement['has_attendance'] && !$hasOut): ?>
            <span class="rm-live">Live</span>
          <?php endif; ?>
        </h2>
        <span class="section-note"><?= e($dObj->format('l, j F Y')) ?></span>
      </div>

      <?php if (!$statement['has_attendance']): ?>
        <div class="rm-empty">
          <i class="bi bi-geo"></i>
          <p><strong><?= e($employee['name']) ?></strong> did not check in on <?= e($dObj->format('j F Y')) ?>.</p>
        </div>
      <?php elseif (!$hasRoute): ?>
        <div class="rm-empty">
          <i class="bi bi-pin-map"></i>
          <p>Checked in, but no shop visits recorded yet - no route to draw.</p>
        </div>
      <?php else:
        // hand the shared map partial its inputs
        $mapPanelId    = 'rm-map';
        $mapPersistKey = 'trk.rmmap.' . (int) $employeeId . '.' . $date;
        $mapOpen       = true;
        $dot           = " \xc2\xb7 ";
        $mapFoot       = count($mapPoints) . ' point' . (count($mapPoints) === 1 ? '' : 's')
                       . $dot . $shopNo . ' shop' . ($shopNo === 1 ? '' : 's') . ' visited'
                       . $dot . number_format($roadKm, 1) . ' km by road'
                       . ($dayClosed ? ' (estimate)' : ' so far')
                       . ($hasOut ? '' : $dot . 'not checked out yet');
        $mapTrailUrl   = APP_URL . '/admin/components/mapbox/api/pings.php?employee=' . (int) $employeeId . '&date=' . rawurlencode($date);
        require dirname(__DIR__) . '/02-employees/_route_map.php';
      ?>

        <div class="rm-map-foot">
          <div><span class="rm-map-foot__k">Start point</span><span class="rm-map-foot__v"><?= $ci ? e($ci->format('g:i A')) : '-' ?></span><span class="rm-map-foot__s">check-in</span></div>
          <div><span class="rm-map-foot__k">End point</span><span class="rm-map-foot__v"><?= $co ? e($co->format('g:i A')) : ($ci ? 'still out' : '-') ?></span><span class="rm-map-foot__s">check-out</span></div>
          <div><span class="rm-map-foot__k">Total stops</span><span class="rm-map-foot__v"><?= (int) $shopNo ?></span><span class="rm-map-foot__s">shops</span></div>
          <div><span class="rm-map-foot__k">Total distance</span><span class="rm-map-foot__v"><?= e(number_format($roadKm, 1)) ?> km</span><span class="rm-map-foot__s"><?= $dayClosed ? 'estimated' : 'route so far' ?></span></div>
        </div>
      <?php endif; ?>
    </section>

    <section class="card rm-points-card">
      <div class="card__head">
        <h2>Visit Points <span class="c-muted">(<?= count($mapPoints) ?>)</span></h2>
      </div>

      <?php if (!$mapPoints): ?>
        <p class="section-note">No points to show for this day.</p>
      <?php else: ?>
        <ol class="rm-points">
          <?php foreach ($mapPoints as $i => $p):
            $pAt   = new DateTimeImmutable($p['at']);
            $pMap  = map_link($p['lat'], $p['lng']);
            $isIn  = $p['kind'] === 'checkin';
            $isOut = $p['kind'] === 'checkout';
            $mark  = $isIn ? 'A' : ($isOut ? 'Z' : (string) (int) $p['no']);
          ?>
          <li class="rm-point rm-point--<?= e($p['kind']) ?>">
            <span class="rm-point__dot"><?= $mark ?></span>
            <span class="rm-point__time"><?= e($pAt->format('g:i A')) ?></span>
            <span class="rm-point__body">
              <strong><?= e($isIn ? 'Start point' : ($isOut ? 'End point' : $p['label'])) ?></strong>
              <span class="rm-point__sub">
                <?php if ($isIn || $isOut): ?>
                  <?= e($employee['name']) ?><?= $employee['region'] ? ' - ' . e($employee['region']) : '' ?>
                <?php else: ?>
                  <?= e(latlng($p['lat'], $p['lng'])) ?>
                <?php endif; ?>
              </span>
              <?php if (!$isIn && !$isOut): ?>
                <span class="rm-point__hop">
                  <i class="bi bi-signpost-2"></i>
                  <?php if ($p['hop_secs'] !== null): ?>
                    <?= e(fmt_km((float) $p['hop_km'])) ?> drive<?php if ((int) $p['hop_secs'] > 0): ?>, <?= e(hm((int) $p['hop_secs'])) ?> driving time<?php endif; ?>
                  <?php else: ?>
                    distance pending
                  <?php endif; ?>
                  <span class="rm-point__hop-dot">&middot;</span>
                  <i class="bi bi-hourglass-split"></i>
                  <?= $p['dwell'] !== null ? e(hm((int) $p['dwell'])) . ' at shop' : 'still here' ?>
                </span>
              <?php endif; ?>
            </span>
            <span class="rm-point__tag rm-point__tag--<?= $isIn ? 'start' : ($isOut ? 'end' : 'visit') ?>">
              <?= $isIn ? 'Start' : ($isOut ? 'End' : 'Visit #' . (int) $p['no']) ?>
            </span>
            <?php if ($pMap): ?>
              <a class="rm-point__pin" href="<?= e($pMap) ?>" target="_blank" rel="noopener" title="Open in maps"><i class="bi bi-geo-alt-fill"></i></a>
            <?php else: ?>
              <span class="rm-point__pin is-off"><i class="bi bi-geo-alt"></i></span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>

        <a class="btn rm-summary-btn" href="<?= e(APP_URL) ?>/admin/02-employees/day.php?employee=<?= (int) $employeeId ?>&date=<?= e($date) ?>">
          View full day detail <i class="bi bi-arrow-right"></i>
        </a>
      <?php endif; ?>
    </section>
  </div>

  <?php endif; /* $employee */ ?>

  <script src="<?= e(APP_URL) ?>/admin/05-routes-map/js/routes-map.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
