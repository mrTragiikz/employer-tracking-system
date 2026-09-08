<?php
/**
 * admin/03-visits/visit.php - Visits section.
 * URL: /track/admin/03-visits/?scope=all|today&Employee=N&month=YYYY-MM&q=&page=&per_page=
 *
 * Every "I'm here" an Employee logged, newest first, with the shop, time, dwell,
 * hop distance and photo. The Employee IS the field person - no employee split,
 * no approval / status workflow.
 *
 * Build order step 6.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php'; // heal_missing_visit_hops()
$me = require_admin();
require __DIR__ . '/_repo.php';
require dirname(__DIR__) . '/02-employees/_repo.php';   // hm() / latlng() / map_link() / dash()

// Self-heal any visit still missing its hop distance (see the function docblock).
heal_missing_visit_hops($pdo);

$pageTitle     = 'Visits';
$activeSection  = 'visits';
$sectionCss     = APP_URL . '/admin/03-visits/css/visit.css';

$scope   = in_array($_GET['scope'] ?? 'all', ['all', 'today'], true) ? ($_GET['scope'] ?? 'all') : 'all';
$employeeF = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$monthF  = (string) ($_GET['month'] ?? '');
if ($monthF !== '' && (!preg_match('/^\d{4}-\d{2}$/', $monthF) || $monthF > substr(server_today(), 0, 7))) {
    $monthF = '';
}
$q = trim((string) ($_GET['q'] ?? ''));

$Employees = visits_employees($pdo);
$vl      = visits_list($pdo, [
    'scope'    => $scope,
    'employee'   => $employeeF,
    'month'    => $monthF,
    'q'        => $q,
    'page'     => (int) ($_GET['page'] ?? 1),
    'per_page' => (int) ($_GET['per_page'] ?? 10),
]);
$stats = visits_stats($pdo, $scope, $monthF ?: null);
$side  = visits_side($pdo, $scope, $monthF ?: null);

/** URL to this page, overriding some params, keeping the rest. */
$vUrl = static function (array $override): string {
    $keep = array_intersect_key($_GET, array_flip(['scope', 'employee', 'month', 'q', 'per_page']));
    $qsArr = array_filter(array_merge($keep, $override), static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return APP_URL . '/admin/03-visits/' . ($qsArr ? '?' . http_build_query($qsArr) : '');
};

$hasFilter = $employeeF || $q !== '' || $monthF !== '';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Visits</h1>
      <p class="section-note">Every shop visit your Employees logged, with time, distance and photo.</p>
    </div>
    <div class="v-pickers">
      <form method="get" action="<?= e(APP_URL) ?>/admin/03-visits/" class="v-pick">
        <input type="hidden" name="scope" value="<?= e($scope) ?>">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select class="select" name="employee" onchange="this.form.submit()" aria-label="Employee">
          <option value="">All Employees</option>
          <?php foreach ($Employees as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $employeeF ? 'selected' : '' ?>>
              <?= e($d['name']) ?><?= $d['code'] ? ' (' . e($d['code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <form method="get" action="<?= e(APP_URL) ?>/admin/03-visits/" class="v-pick">
        <input type="hidden" name="scope" value="all">
        <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <input class="input" type="month" name="month" value="<?= e($monthF) ?>"
               max="<?= e(substr(server_today(), 0, 7)) ?>" onchange="this.form.submit()">
      </form>
      <?php $vExpQs = e(http_build_query(array_intersect_key($_GET, array_flip(['scope', 'employee', 'month', 'q'])))); ?>
      <details class="v-export">
        <summary class="btn"><i class="bi bi-download"></i> Export <i class="bi bi-chevron-down v-export__chev"></i></summary>
        <div class="v-export__menu">
          <a href="<?= e(APP_URL) ?>/admin/03-visits/api/export.php?<?= $vExpQs ?>&format=pdf">
            <i class="bi bi-file-earmark-pdf"></i>
            <span>Export PDF <small>professional report of the filtered visits, ready to share with a client</small></span>
          </a>
          <a href="<?= e(APP_URL) ?>/admin/03-visits/api/export.php?<?= $vExpQs ?>&format=csv">
            <i class="bi bi-filetype-csv"></i>
            <span>Export CSV <small>raw row list, for spreadsheets</small></span>
          </a>
        </div>
      </details>
    </div>
  </div>

  <!-- ===== five headline numbers ===== -->
  <div class="v-stats">
    <?php
    $windowLabel = $scope === 'today' ? 'today' : ($monthF !== '' ? (new DateTimeImmutable($monthF . '-01'))->format('M Y') : 'all time');
    $cards = [
        ['Total visits',       number_format($stats['total_visits']),  $windowLabel,        'green',  'bi-geo-alt'],
        ['Active Employees',     number_format($stats['active_employees']), 'logged a visit',    'blue',   'bi-people'],
        ['KM travelled',       number_format($stats['km_est'], 1) . ' km', 'by road; open days count so far', 'purple', 'bi-signpost-split'],
        ['Avg. visit time',    hm($stats['avg_dwell_secs'], '-'),        'across completed visits', 'peach',  'bi-clock-history'],
        ['Visits today',       number_format($stats['visits_today']),    'so far',           'red',    'bi-calendar-check'],
    ];
    foreach ($cards as [$label, $value, $sub, $tone, $icon]): ?>
      <div class="v-stat">
        <span class="v-stat__ico v-stat__ico--<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div>
          <div class="v-stat__value"><?= e($value) ?></div>
          <div class="v-stat__label"><?= e($label) ?></div>
          <div class="v-stat__sub"><?= e($sub) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="v-grid">
    <!-- ===== main: tabs + filter + table ===== -->
    <section class="card v-main">
      <nav class="v-tabs">
        <a class="v-tab <?= $scope === 'all' ? 'is-active' : '' ?>" href="<?= e($vUrl(['scope' => 'all', 'page' => null])) ?>">
          <i class="bi bi-list-ul"></i> All visits
        </a>
        <a class="v-tab <?= $scope === 'today' ? 'is-active' : '' ?>" href="<?= e($vUrl(['scope' => 'today', 'month' => null, 'page' => null])) ?>">
          <i class="bi bi-calendar-day"></i> Today's visits
        </a>
      </nav>

      <form class="v-filter" method="get" action="<?= e(APP_URL) ?>/admin/03-visits/">
        <input type="hidden" name="scope" value="<?= e($scope) ?>">
        <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
        <?php if ($monthF !== ''): ?><input type="hidden" name="month" value="<?= e($monthF) ?>"><?php endif; ?>
        <div class="v-filter__search">
          <i class="bi bi-search"></i>
          <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search shop, Employee name or code...">
        </div>
        <button type="submit" class="btn btn--sm"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($hasFilter): ?>
          <a class="btn btn--sm" href="<?= e($vUrl(['employee' => null, 'q' => null, 'month' => null])) ?>">Clear</a>
        <?php endif; ?>
      </form>

      <?php if (!$vl['rows']): ?>
        <p class="section-note v-empty">
          <?= $hasFilter ? 'No visits match these filters.' : 'No visits recorded yet.' ?>
        </p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table v-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Date &amp; time</th>
                <th>Employee</th>
                <th>Shop &amp; location</th>
                <th>Time at shop</th>
                <th>Travelled (est.)</th>
                <th>Photo</th>
                <th class="ta-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $rowNo = ($vl['page'] - 1) * $vl['per_page'];
              foreach ($vl['rows'] as $v):
                $rowNo++;
                $arr    = new DateTimeImmutable($v['arrived_at']);
                $ll     = latlng((float) $v['lat'], (float) $v['lng']);
                $vMap   = map_link((float) $v['lat'], (float) $v['lng']);
                $dayUrl = APP_URL . '/admin/02-employees/day.php?employee=' . (int) $v['employee_id'] . '&date=' . $v['work_date'];
              ?>
              <tr>
                <td class="c-muted"><?= $rowNo ?></td>
                <td class="v-when">
                  <strong><?= e($arr->format('j M Y')) ?></strong>
                  <span class="c-muted"><?= e($arr->format('g:i A')) ?></span>
                </td>
                <td>
                  <a class="v-employee" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $v['employee_id'] ?>">
                    <span class="v-avatar"><?= e(mb_strtoupper(mb_substr($v['employee_name'], 0, 1))) ?></span>
                    <span class="stack">
                      <strong><?= e($v['employee_name']) ?></strong>
                      <span class="c-muted"><?= e($v['employee_code'] ?: '-') ?></span>
                    </span>
                  </a>
                </td>
                <td>
                  <div class="stack">
                    <strong><?= e($v['shop_name']) ?></strong>
                    <span class="c-muted">
                      <?= $v['region'] ? e($v['region']) . ' &middot; ' : '' ?><?= $ll !== '' ? e($ll) : '' ?>
                    </span>
                    <?php if ($v['remark']): ?>
                      <span class="v-remark"><i class="bi bi-chat-left-text"></i> <?= e($v['remark']) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?= $v['dwell_seconds'] !== null ? e(hm((int) $v['dwell_seconds'])) : '<span class="c-muted">still there</span>' ?></td>
                <td><?= $v['hop_seconds'] !== null
                      ? e(fmt_km((float) $v['hop_road_km']))
                      : '<span class="c-muted">-</span>' ?></td>
                <td>
                  <?php if ($v['photo_path']): ?>
                    <a href="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" target="_blank" rel="noopener">
                      <img class="table__thumb" src="<?= e(UPLOAD_URL . '/' . $v['photo_path']) ?>" alt="">
                    </a>
                    <?php if ((int) $v['photo_count'] > 1): ?><span class="c-muted">+<?= (int) $v['photo_count'] - 1 ?></span><?php endif; ?>
                  <?php else: ?>
                    <span class="c-muted">no photo</span>
                  <?php endif; ?>
                </td>
                <td class="ta-right">
                  <div class="v-actions">
                    <a class="v-act" title="View the day" href="<?= e($dayUrl) ?>"><i class="bi bi-eye"></i></a>
                    <a class="v-act" title="Route on map" href="<?= e(APP_URL) ?>/admin/05-routes-map/?employee=<?= (int) $v['employee_id'] ?>&date=<?= e($v['work_date']) ?>"><i class="bi bi-map"></i></a>
                    <?php if ($vMap): ?>
                      <a class="v-act" title="Open in maps" href="<?= e($vMap) ?>" target="_blank" rel="noopener"><i class="bi bi-geo-alt-fill"></i></a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="table-foot">
          <?php
            $from = $vl['total'] ? (($vl['page'] - 1) * $vl['per_page']) + 1 : 0;
            $to   = min($vl['page'] * $vl['per_page'], $vl['total']);
          ?>
          <span class="section-note">Showing <?= $from ?> to <?= $to ?> of <?= (int) $vl['total'] ?> visits</span>

          <?php if ($vl['pages'] > 1): ?>
            <div class="pager">
              <?php if ($vl['page'] > 1): ?>
                <a href="<?= e($vUrl(['page' => $vl['page'] - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
              <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-left"></i></span><?php endif; ?>
              <?php
              $p = $vl['page']; $last = $vl['pages'];
              $show = array_unique(array_filter([1, $p - 1, $p, $p + 1, $last], static fn($n) => $n >= 1 && $n <= $last));
              sort($show);
              $prev = 0;
              foreach ($show as $n):
                  if ($n - $prev > 1) echo '<span class="pager__gap">...</span>';
                  $prev = $n; ?>
                <a class="<?= $n === $p ? 'is-current' : '' ?>" href="<?= e($vUrl(['page' => $n])) ?>"><?= $n ?></a>
              <?php endforeach; ?>
              <?php if ($vl['page'] < $last): ?>
                <a href="<?= e($vUrl(['page' => $vl['page'] + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
              <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-right"></i></span><?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="get" action="<?= e(APP_URL) ?>/admin/03-visits/" class="page-size">
            <input type="hidden" name="scope" value="<?= e($scope) ?>">
            <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
            <?php if ($monthF !== ''): ?><input type="hidden" name="month" value="<?= e($monthF) ?>"><?php endif; ?>
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
            <select class="select" name="per_page" onchange="this.form.submit()">
              <?php foreach ([10, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $vl['per_page'] === $n ? 'selected' : '' ?>><?= $n ?> / page</option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <!-- ===== right rail ===== -->
    <aside class="v-rail">
      <section class="card">
        <div class="card__head"><h2>Visit Summary <span class="c-muted">(<?= e($side['label']) ?>)</span></h2></div>
        <ul class="v-sum">
          <li><span>Total visits</span><strong><?= (int) $side['total_visits'] ?></strong></li>
          <li><span>Employees active</span><strong><?= (int) $side['unique_employees'] ?></strong></li>
          <li><span>KM travelled (est.)</span><strong><?= e(number_format($side['km_est'], 1)) ?> km</strong></li>
          <li><span>Time at shops</span><strong><?= e(hm($side['total_secs'], '0m')) ?></strong></li>
          <li><span>Avg. visit time</span><strong><?= e(hm($side['avg_dwell_secs'], '-')) ?></strong></li>
        </ul>
        <a class="btn v-rail__btn" href="<?= e(APP_URL) ?>/admin/05-routes-map/">View routes on map <i class="bi bi-arrow-right"></i></a>
      </section>

      <section class="card">
        <div class="card__head"><h2>Top Employees <span class="c-muted">(by visits)</span></h2></div>
        <?php if (!$side['top_employees']): ?>
          <p class="section-note">No visits in this window.</p>
        <?php else:
          $tones = ['blue', 'green', 'peach', 'purple', 'red'];
          foreach ($side['top_employees'] as $i => $d): ?>
          <a class="v-rank" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $d['id'] ?>">
            <span class="v-rank__n"><?= $i + 1 ?></span>
            <span class="v-avatar v-avatar--<?= e($tones[$i % 5]) ?>"><?= e(mb_strtoupper(mb_substr($d['name'], 0, 1))) ?></span>
            <span class="stack">
              <strong><?= e($d['name']) ?></strong>
              <span class="c-muted"><?= e($d['code'] ?: '-') ?></span>
            </span>
            <span class="v-rank__v"><?= (int) $d['visits'] ?></span>
          </a>
        <?php endforeach; endif; ?>
        <a class="btn v-rail__btn" href="<?= e(APP_URL) ?>/admin/02-employees/">All Employees <i class="bi bi-arrow-right"></i></a>
      </section>
    </aside>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/03-visits/js/visit.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
