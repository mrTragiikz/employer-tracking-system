<?php
/**
 * admin/02-employees/day.php  -  one Employee, one working day, full detail.
 *   /track/admin/02-employees/day.php?employee=N&date=YYYY-MM-DD
 *
 * Reached from the "View" button on each row of view.php's days table.
 * Shows: the day's check-in / check-out with map links, day totals and the
 * 3-way time split, then every shop visit that day in full detail
 * (arrive/leave/dwell, hop distance & time, GPS accuracy, photo). This is the
 * honest route - suspicious activity is reviewed in the Alerts section only.
 *
 * Build order step 3.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';

$pageTitle     = 'Employees';
$activeSection = 'employees';
$sectionCss    = [
    APP_URL . '/admin/02-employees/css/employee.css',
    APP_URL . '/admin/components/mapbox/css/mapbox-route.css',
];

$employeeId = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$date     = (string) ($_GET['date'] ?? '');
$employee   = $employeeId ? employee_find($pdo, $employeeId) : null;

$validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date);

if (!$employee || !$validDate) {
    require dirname(__DIR__) . '/components/header/header.php';
    echo '<div class="card"><p class="section-note">'
       . 'Day not found. <a href="' . e(APP_URL) . '/admin/02-employees/">Back to Employees</a>.'
       . '</p></div>';
    require dirname(__DIR__) . '/components/footer/footer.php';
    exit;
}

$dObj    = new DateTimeImmutable($date);
$day     = employee_day($pdo, $employeeId, $date);
$visits  = $day ? employee_day_visits($pdo, (int) $day['id']) : [];

/* First check-in / check-out photo for this day, for the timeline table's
 * top and bottom rows (same table shape as the PDF's Visit Timeline). */
$endpointPhoto = ['checkin' => null, 'checkout' => null];
if ($day) {
    $ph = $pdo->prepare(
        "SELECT photo_kind, stored_path FROM photos
          WHERE attendance_id = ? AND photo_kind IN ('checkin','checkout') AND visit_id IS NULL
          ORDER BY id"
    );
    $ph->execute([(int) $day['id']]);
    foreach ($ph as $row) {
        if ($endpointPhoto[$row['photo_kind']] === null) {
            $endpointPhoto[$row['photo_kind']] = $row['stored_path'];
        }
    }
}
$backUrl = APP_URL . '/admin/02-employees/view.php?id=' . $employeeId
         . '&mode=month&month=' . $dObj->format('Y-m');

/* ---- ordered route points for the "View on map" panel ------------------
 * check-in -> visit 1 -> visit 2 -> ... -> check-out, each with real GPS.
 * The lat/lng feed both the SVG sketch now and the real map API later. */
$mapPoints = [];
if ($day) {
    $mapPoints[] = [
        'kind' => 'checkin', 'no' => null, 'label' => 'Check-in',
        'at' => $day['check_in_at'],
        'lat' => (float) $day['check_in_lat'], 'lng' => (float) $day['check_in_lng'],
    ];
    foreach ($visits as $i => $v) {
        $mapPoints[] = [
            'kind' => 'visit', 'no' => $i + 1, 'label' => $v['shop_name'],
            'at' => $v['arrived_at'],
            'lat' => (float) $v['lat'], 'lng' => (float) $v['lng'],
        ];
    }
    if ($day['check_out_at'] && $day['check_out_lat'] !== null) {
        $mapPoints[] = [
            'kind' => 'checkout', 'no' => null, 'label' => 'Check-out',
            'at' => $day['check_out_at'],
            'lat' => (float) $day['check_out_lat'], 'lng' => (float) $day['check_out_lng'],
        ];
    }
}

require dirname(__DIR__) . '/components/header/header.php';
require dirname(__DIR__) . '/components/mapbox/mapbox.php';
?>

  <div class="page-head">
    <div>
      <h1><?= e($dObj->format('l, j F Y')) ?></h1>
      <p class="section-note">
        <a href="<?= e($backUrl) ?>"><?= e($employee['name']) ?></a>
        <?php if ($employee['code']): ?> &nbsp;&middot;&nbsp; <?= e($employee['code']) ?><?php endif; ?>
      </p>
    </div>
    <div class="page-head__actions">
      <a class="btn" href="<?= e($backUrl) ?>"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!$day): ?>
    <div class="card">
      <div class="day-absent">
        <i class="bi bi-x-circle"></i>
        <div>
          <strong>No attendance on this day.</strong>
          <p class="section-note">The Employee did not check in on <?= e($dObj->format('j F Y')) ?>.</p>
        </div>
      </div>
    </div>
  <?php else:
    $ci = new DateTimeImmutable($day['check_in_at']);
    $co = $day['check_out_at'] ? new DateTimeImmutable($day['check_out_at']) : null;
    $ciMap = map_link((float) $day['check_in_lat'], (float) $day['check_in_lng']);
    $coMap = ($co && $day['check_out_lat'] !== null)
        ? map_link((float) $day['check_out_lat'], (float) $day['check_out_lng']) : null;
    $totalSecs = $day['total_seconds'] !== null ? (int) $day['total_seconds'] : null;
  ?>

    <!-- ===== check-in / check-out ===== -->
    <div class="day-endpoints">
      <div class="card endpoint">
        <span class="endpoint__tag">Check-in</span>
        <div class="endpoint__time"><?= e($ci->format('g:i A')) ?></div>
        <div class="endpoint__meta">
          <?php if ($day['check_in_accuracy_m'] !== null): ?>
            GPS accuracy <?= e(number_format((float) $day['check_in_accuracy_m'], 0)) ?> m
          <?php endif; ?>
        </div>
        <?php if ($ciMap): ?>
          <a class="pt-link" href="<?= e($ciMap) ?>" target="_blank" rel="noopener">
            <i class="bi bi-geo-alt"></i> <?= e(latlng((float) $day['check_in_lat'], (float) $day['check_in_lng'])) ?>
          </a>
        <?php endif; ?>
      </div>

      <div class="card endpoint">
        <span class="endpoint__tag">Check-out</span>
        <?php if ($co): ?>
          <div class="endpoint__time"><?= e($co->format('g:i A')) ?></div>
          <div class="endpoint__meta">
            <?php if ($day['check_out_accuracy_m'] !== null): ?>
              GPS accuracy <?= e(number_format((float) $day['check_out_accuracy_m'], 0)) ?> m
            <?php endif; ?>
          </div>
          <?php if ($coMap): ?>
            <a class="pt-link" href="<?= e($coMap) ?>" target="_blank" rel="noopener">
              <i class="bi bi-geo-alt"></i> <?= e(latlng((float) $day['check_out_lat'], (float) $day['check_out_lng'])) ?>
            </a>
          <?php endif; ?>
        <?php else: ?>
          <div class="endpoint__time endpoint__time--none">Not checked out</div>
          <div class="endpoint__meta">
            <?php if ($day['status'] === 'incomplete'): ?>
              Marked incomplete (no check-out by end of day)
            <?php else: ?>
              Day still open
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card endpoint">
        <span class="endpoint__tag">Day status</span>
        <div class="endpoint__time">
          <span class="badge badge--<?= $day['status'] === 'closed' ? 'approved' : ($day['status'] === 'incomplete' ? 'rejected' : 'open') ?>">
            <?= e(ucfirst($day['status'])) ?>
          </span>
        </div>
        <div class="endpoint__meta">
          <?= (int) $day['visit_count'] ?> visit<?= (int) $day['visit_count'] === 1 ? '' : 's' ?>
        </div>
      </div>
    </div>

    <?php if (count($mapPoints) >= 2):
      $mapPanelId    = 'day-map';
      $mapPersistKey = 'trk.daymap.' . (int) $employeeId . '.' . $date;
      $mapFoot       = count($mapPoints) . ' points · ' . count($visits) . ' shop' . (count($visits) === 1 ? '' : 's')
                     . ($co ? ' · ' . number_format((float) $day['road_km'], 1) . ' km by road (estimate)' : '');
      require __DIR__ . '/_route_map.php';
    endif; ?>

    <!-- ===== day totals + time split ===== -->
    <div class="summary-row summary-row--4">
      <?php
      // Productive hours / road time / distance are the day's audit totals -
      // computed only at check-out (checkout.php -> compute_day()). For a
      // still-open day they are 0; show a dash instead of a misleading zero.
      $dayClosed = $co !== null;
      $prodPct = ($totalSecs && $totalSecs > 0)
          ? round(((int) $day['shop_seconds']) / $totalSecs * 100) : 0;
      $cards = [
          ['Time from check-in to check-out', $totalSecs !== null ? hm($totalSecs) : 'Open',
              $totalSecs !== null ? 'the full working day on the clock' : 'no checkout yet', 'bi-clock'],
          ['Productive hours', $dayClosed ? hm((int) $day['shop_seconds']) : '-',
              $dayClosed ? 'at shops, ' . $prodPct . '% of day' : 'worked out at check-out', 'bi-check2-circle'],
          ['Road time',        $dayClosed ? hm((int) $day['road_seconds']) : '-',
              $dayClosed ? 'travelling between stops' : 'worked out at check-out', 'bi-signpost-2'],
          ['Full productive km of today', $dayClosed ? number_format((float) $day['road_km'], 1) . ' km' : '-',
              $dayClosed ? 'road distance across the day, audited at check-out' : 'worked out at check-out', 'bi-signpost-split'],
      ];
      foreach ($cards as [$label, $value, $sub, $icon]): ?>
        <div class="sum-card">
          <span class="sum-card__icon"><i class="bi <?= e($icon) ?>"></i></span>
          <div>
            <div class="sum-card__value"><?= e($value) ?></div>
            <div class="sum-card__label"><?= e($label) ?></div>
            <div class="sum-card__sub"><?= e($sub) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ===== day timeline (check-in, shop visits, check-out) ===== -->
    <div class="card">
      <div class="card__head">
        <h2>Day timeline</h2>
        <span class="section-note">Check-in, <?= count($visits) ?> shop<?= count($visits) === 1 ? '' : 's' ?>, check-out</span>
      </div>

      <?php if (!$visits): ?>
        <p class="section-note">No shop visits were recorded on this day.</p>
      <?php else:
        $ciLL   = latlng((float) $day['check_in_lat'], (float) $day['check_in_lng']);
        $ciMapL = map_link((float) $day['check_in_lat'], (float) $day['check_in_lng']);
        $coHas  = $day['check_out_at'] && $day['check_out_lat'] !== null;
        $coLL   = $coHas ? latlng((float) $day['check_out_lat'], (float) $day['check_out_lng']) : '';
        $coMapL = $coHas ? map_link((float) $day['check_out_lat'], (float) $day['check_out_lng']) : null;
      ?>
        <div class="table-wrap">
          <table class="table visits-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Point</th>
                <th>Arrived</th>
                <th>Left</th>
                <th>Shop time</th>
                <th>Travel distance</th>
                <th>Travel time</th>
                <th>GPS accuracy</th>
                <th>Photo</th>
              </tr>
            </thead>
            <tbody>
              <tr class="visits-table__endpoint">
                <td class="c-muted">A</td>
                <td>
                  <div class="stack">
                    <strong>Check-in<?= $day['check_in_odometer_km'] !== null ? ' &middot; odo ' . e(number_format((float) $day['check_in_odometer_km'], 1)) . ' km' : '' ?></strong>
                    <?php if ($ciMapL && $ciLL !== ''): ?>
                      <a class="pt-link" href="<?= e($ciMapL) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-geo-alt"></i> <?= e($ciLL) ?>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= e((new DateTimeImmutable($day['check_in_at']))->format('g:i A')) ?></td>
                <td><?= dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= $day['check_in_accuracy_m'] !== null ? e(number_format((float) $day['check_in_accuracy_m'], 0)) . ' m' : dash() ?></td>
                <td>
                  <?php if ($endpointPhoto['checkin']): ?>
                    <a href="<?= e(UPLOAD_URL . '/' . $endpointPhoto['checkin']) ?>" target="_blank" rel="noopener">
                      <img class="table__thumb" src="<?= e(UPLOAD_URL . '/' . $endpointPhoto['checkin']) ?>" alt="">
                    </a>
                  <?php else: ?>
                    <?= dash() ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php foreach ($visits as $v):
                $arr  = new DateTimeImmutable($v['arrived_at']);
                $lft  = $v['left_at'] ? new DateTimeImmutable($v['left_at']) : null;
                $vMap = map_link((float) $v['lat'], (float) $v['lng']);
                $vLL  = latlng((float) $v['lat'], (float) $v['lng']);
              ?>
              <tr>
                <td class="c-muted"><?= (int) $v['seq'] ?></td>
                <td>
                  <div class="stack">
                    <strong><?= e($v['shop_name']) ?></strong>
                    <?php if ($vMap && $vLL !== ''): ?>
                      <a class="pt-link" href="<?= e($vMap) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-geo-alt"></i> <?= e($vLL) ?>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= e($arr->format('g:i A')) ?></td>
                <td><?= $lft ? e($lft->format('g:i A')) : dash() ?></td>
                <td><?= $v['dwell_seconds'] !== null ? e(hm((int) $v['dwell_seconds'])) : dash() ?></td>
                <td><?= $v['hop_seconds'] !== null ? e(number_format((float) $v['hop_road_km'], 2)) . ' km' : dash() ?></td>
                <td><?= $v['hop_seconds'] !== null ? e(hm((int) $v['hop_seconds'])) : dash() ?></td>
                <td><?= $v['accuracy_m'] !== null ? e(number_format((float) $v['accuracy_m'], 0)) . ' m' : dash() ?></td>
                <td>
                  <?php if ($v['photo_path']): ?>
                    <a href="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" target="_blank" rel="noopener">
                      <img class="table__thumb" src="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" alt="">
                    </a>
                    <?php if ((int) $v['photo_count'] > 1): ?><span class="c-muted">+<?= (int) $v['photo_count'] - 1 ?></span><?php endif; ?>
                  <?php else: ?>
                    <?= dash() ?>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <tr class="visits-table__endpoint">
                <td class="c-muted">Z</td>
                <td>
                  <div class="stack">
                    <strong>Check-out<?= $day['check_out_odometer_km'] !== null ? ' &middot; odo ' . e(number_format((float) $day['check_out_odometer_km'], 1)) . ' km' : '' ?></strong>
                    <?php if ($coMapL && $coLL !== ''): ?>
                      <a class="pt-link" href="<?= e($coMapL) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-geo-alt"></i> <?= e($coLL) ?>
                      </a>
                    <?php elseif (!$coHas): ?>
                      <span class="c-muted">not checked out yet</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= $day['check_out_at'] ? e((new DateTimeImmutable($day['check_out_at']))->format('g:i A')) : dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= dash() ?></td>
                <td><?= $day['check_out_accuracy_m'] !== null ? e(number_format((float) $day['check_out_accuracy_m'], 0)) . ' m' : dash() ?></td>
                <td>
                  <?php if ($endpointPhoto['checkout']): ?>
                    <a href="<?= e(UPLOAD_URL . '/' . $endpointPhoto['checkout']) ?>" target="_blank" rel="noopener">
                      <img class="table__thumb" src="<?= e(UPLOAD_URL . '/' . $endpointPhoto['checkout']) ?>" alt="">
                    </a>
                  <?php else: ?>
                    <?= dash() ?>
                  <?php endif; ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';