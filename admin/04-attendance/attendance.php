<?php
/**
 * admin/04-attendance/attendance.php - Attendance section.
 * URL: /track/admin/04-attendance/?month=YYYY-MM&Employee=N&status=&q=&page=&per_page=
 *
 * One row per Employee per working day: check-in / check-out, working hours,
 * distance, day status. The Employee IS the field person - no employee split,
 * no "Office / Field" location type.
 *
 * Build order step 6.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';
require dirname(__DIR__) . '/02-employees/_repo.php';   // hm() / dash()

$pageTitle     = 'Attendance';
$activeSection  = 'attendance';
$sectionCss     = APP_URL . '/admin/04-attendance/css/attendance.css';

$m       = att_month($_GET['month'] ?? null);
$employeeF = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$statusF = in_array($_GET['status'] ?? '', ['open', 'closed', 'incomplete'], true) ? $_GET['status'] : '';
$q       = trim((string) ($_GET['q'] ?? ''));

// Daily-table date window: a preset range, or a specific date, else the month.
$rangeOpts = ['month' => 'This month', 'today' => 'Today', 'yesterday' => 'Yesterday',
              'week' => 'Last 7 days', '30d' => 'Last 30 days', 'on' => 'Specific date'];
$rangeF = in_array($_GET['range'] ?? 'month', array_keys($rangeOpts), true) ? ($_GET['range'] ?? 'month') : 'month';
$onF    = (string) ($_GET['on'] ?? '');
if ($onF !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onF) || $onF > server_today())) $onF = '';
if ($rangeF === 'on' && $onF === '') $rangeF = 'month';
[$listFrom, $listTo, $rangeLabel] = att_range($m, $rangeF === 'on' ? null : $rangeF, $rangeF === 'on' ? $onF : null);

$Employees  = att_employees($pdo);
$stats    = att_stats($pdo, $m, $employeeF);
$top      = att_top($pdo, $m);
$trend    = att_trend($pdo, 14);
$al       = att_list($pdo, [
    'from'     => $listFrom,
    'to'       => $listTo,
    'employee'   => $employeeF,
    'status'   => $statusF,
    'q'        => $q,
    'page'     => (int) ($_GET['page'] ?? 1),
    'per_page' => (int) ($_GET['per_page'] ?? 10),
]);

$aUrl = static function (array $override): string {
    $keep  = array_intersect_key($_GET, array_flip(['month', 'employee', 'status', 'q', 'per_page', 'range', 'on']));
    $qsArr = array_filter(array_merge($keep, $override), static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return APP_URL . '/admin/04-attendance/' . ($qsArr ? '?' . http_build_query($qsArr) : '');
};
$hasFilter = $employeeF || $statusF !== '' || $q !== '' || $rangeF !== 'month';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Attendance</h1>
      <p class="section-note">Track Employee attendance, working hours and field activity.</p>
    </div>
    <div class="a-pickers">
      <form method="get" action="<?= e(APP_URL) ?>/admin/04-attendance/" class="a-pick">
        <input type="hidden" name="month" value="<?= e($m['month']) ?>">
        <select class="select" name="employee" onchange="this.form.submit()" aria-label="Employee">
          <option value="">All Employees</option>
          <?php foreach ($Employees as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $employeeF ? 'selected' : '' ?>>
              <?= e($d['name']) ?><?= $d['code'] ? ' (' . e($d['code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <form method="get" action="<?= e(APP_URL) ?>/admin/04-attendance/" class="a-pick">
        <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
        <input class="input" type="month" name="month" value="<?= e($m['month']) ?>"
               max="<?= e(substr(server_today(), 0, 7)) ?>" onchange="this.form.submit()">
      </form>
      <?php
        $exportQs = e(http_build_query(array_intersect_key($_GET, array_flip(['month', 'employee', 'status', 'q', 'range', 'on']))));
      ?>
      <details class="a-export">
        <summary class="btn"><i class="bi bi-download"></i> Export <i class="bi bi-chevron-down a-export__chev"></i></summary>
        <div class="a-export__menu">
          <a href="<?= e(APP_URL) ?>/admin/04-attendance/api/export-pdf.php?<?= $exportQs ?>">
            <i class="bi bi-file-earmark-pdf"></i>
            <span>Export PDF <small>professional report, ready to print/share</small></span>
          </a>
          <a href="<?= e(APP_URL) ?>/admin/04-attendance/api/export.php?<?= $exportQs ?>">
            <i class="bi bi-filetype-csv"></i>
            <span>Export CSV <small>raw data, for spreadsheets</small></span>
          </a>
        </div>
      </details>
    </div>
  </div>

  <!-- ===== five headline numbers ===== -->
  <div class="a-stats">
    <?php
    $cards = [
        ['Working days', $stats['present'] . ' / ' . $stats['expected'],
            $stats['rate'] . '% attendance rate', 'green', 'bi-calendar-check'],
        ['Present',      number_format($stats['present']),
            $stats['expected'] ? $stats['rate'] . '%' : '-', 'blue', 'bi-person-check'],
        ['Absent',       number_format($stats['absent']),
            $stats['expected'] ? round(100 - $stats['rate'], 1) . '%' : '-', 'red', 'bi-person-x'],
        ['Avg. working hours', hm($stats['avg_secs'], '-'),
            'per closed day', 'purple', 'bi-hourglass-split'],
        ['Field days', number_format($stats['field_days']),
            'days with Employee visits', 'peach', 'bi-geo-alt'],
    ];
    foreach ($cards as [$label, $value, $sub, $tone, $icon]): ?>
      <div class="a-stat">
        <span class="a-stat__ico a-stat__ico--<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div>
          <div class="a-stat__value"><?= e($value) ?></div>
          <div class="a-stat__label"><?= e($label) ?></div>
          <div class="a-stat__sub"><?= e($sub) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="a-grid">

  <!-- ===== daily attendance table ===== -->
  <section class="card a-table-card">
    <div class="card__head">
      <h2>Daily Attendance</h2>
      <span class="section-note"><?= e($rangeLabel) ?></span>
    </div>

    <form class="a-filter" method="get" action="<?= e(APP_URL) ?>/admin/04-attendance/">
      <input type="hidden" name="month" value="<?= e($m['month']) ?>">
      <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>

      <select class="select" name="range" onchange="
        var d = this.form.querySelector('.js-on-date');
        if (this.value === 'on') { d.hidden = false; d.focus(); } else { d.value = ''; this.form.submit(); }">
        <?php foreach ($rangeOpts as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $rangeF === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
      <input class="input js-on-date" type="date" name="on" value="<?= e($onF) ?>"
             max="<?= e(server_today()) ?>" <?= $rangeF === 'on' ? '' : 'hidden' ?>
             onchange="this.form.submit()">

      <div class="a-filter__search">
        <i class="bi bi-search"></i>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search Employee name or code...">
      </div>
      <select class="select" name="status" onchange="this.form.submit()">
        <option value="">All status</option>
        <?php foreach (['closed' => 'Closed', 'incomplete' => 'No check-out', 'open' => 'Open'] as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn--sm"><i class="bi bi-funnel"></i> Filter</button>
      <?php if ($hasFilter): ?>
        <a class="btn btn--sm" href="<?= e($aUrl(['employee' => null, 'status' => null, 'q' => null, 'range' => null, 'on' => null])) ?>">Clear</a>
      <?php endif; ?>
    </form>

    <?php if (!$al['rows']): ?>
      <p class="section-note a-empty"><?= $hasFilter ? 'No records match these filters.' : 'No attendance recorded in ' . e($m['label']) . '.' ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table a-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Employee</th>
              <th>Check-in</th>
              <th>Check-out</th>
              <th>Working hours</th>
              <th>Shop / road</th>
              <th>Distance</th>
              <th>Visits</th>
              <th>Status</th>
              <th class="ta-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($al['rows'] as $r):
              $dObj = new DateTimeImmutable($r['work_date']);
              $ci   = new DateTimeImmutable($r['check_in_at']);
              $co   = $r['check_out_at'] ? new DateTimeImmutable($r['check_out_at']) : null;
              $dayUrl = APP_URL . '/admin/02-employees/day.php?employee=' . (int) $r['employee_id'] . '&date=' . $r['work_date'];
              $stMap  = ['closed' => ['Present', 'ok'], 'incomplete' => ['No check-out', 'warn'], 'open' => ['Open', 'open']];
              [$stLabel, $stTone] = $stMap[$r['status']] ?? [ucfirst($r['status']), 'open'];
              // shop_seconds / road_seconds / road_km on `attendance` are the
              // day's audit totals - computed only at check-out (see
              // field/checkinout/api/checkout.php -> compute_day()). On a
              // still-open day they sit at 0, which reads as "did nothing"
              // rather than "not finished" - show "-" until check-out, like
              // the Working hours column already does with "open".
              $dayClosed = $r['check_out_at'] !== null;
            ?>
            <tr>
              <td class="a-when">
                <strong><?= e($dObj->format('j M Y')) ?></strong>
                <span class="c-muted"><?= e($dObj->format('l')) ?></span>
              </td>
              <td>
                <a class="a-employee" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $r['employee_id'] ?>">
                  <span class="a-avatar"><?= e(mb_strtoupper(mb_substr($r['employee_name'], 0, 1))) ?></span>
                  <span class="stack">
                    <strong><?= e($r['employee_name']) ?></strong>
                    <span class="c-muted"><?= e($r['employee_code'] ?: '-') ?></span>
                  </span>
                </a>
              </td>
              <td><?= e($ci->format('g:i A')) ?></td>
              <td><?= $co ? e($co->format('g:i A')) : '<span class="c-muted">-</span>' ?></td>
              <td><?= $r['total_seconds'] !== null ? e(hm((int) $r['total_seconds'])) : '<span class="c-muted">open</span>' ?></td>
              <td class="c-muted"><?= $dayClosed
                    ? e(hm((int) $r['shop_seconds'], '0m')) . ' / ' . e(hm((int) $r['road_seconds'], '0m'))
                    : '<span class="c-muted">-</span>' ?></td>
              <td><?= $dayClosed
                    ? e(number_format((float) $r['road_km'], 1)) . ' km'
                    : '<span class="c-muted">-</span>' ?></td>
              <td><?= (int) $r['visit_count'] ?></td>
              <td><span class="a-pill a-pill--<?= e($stTone) ?>"><?= e($stLabel) ?></span></td>
              <td class="ta-right">
                <a class="a-act" title="View the day" href="<?= e($dayUrl) ?>"><i class="bi bi-eye"></i></a>
                <a class="a-act" title="Route on map" href="<?= e(APP_URL) ?>/admin/05-routes-map/?employee=<?= (int) $r['employee_id'] ?>&date=<?= e($r['work_date']) ?>"><i class="bi bi-map"></i></a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="table-foot">
        <?php
          $from = $al['total'] ? (($al['page'] - 1) * $al['per_page']) + 1 : 0;
          $to   = min($al['page'] * $al['per_page'], $al['total']);
        ?>
        <span class="section-note">Showing <?= $from ?> to <?= $to ?> of <?= (int) $al['total'] ?> days</span>

        <?php if ($al['pages'] > 1): ?>
          <div class="pager">
            <?php if ($al['page'] > 1): ?>
              <a href="<?= e($aUrl(['page' => $al['page'] - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
            <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-left"></i></span><?php endif; ?>
            <?php
            $p = $al['page']; $last = $al['pages'];
            $show = array_unique(array_filter([1, $p - 1, $p, $p + 1, $last], static fn($n) => $n >= 1 && $n <= $last));
            sort($show); $prevN = 0;
            foreach ($show as $n):
                if ($n - $prevN > 1) echo '<span class="pager__gap">...</span>';
                $prevN = $n; ?>
              <a class="<?= $n === $p ? 'is-current' : '' ?>" href="<?= e($aUrl(['page' => $n])) ?>"><?= $n ?></a>
            <?php endforeach; ?>
            <?php if ($al['page'] < $last): ?>
              <a href="<?= e($aUrl(['page' => $al['page'] + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
            <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-right"></i></span><?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="get" action="<?= e(APP_URL) ?>/admin/04-attendance/" class="page-size">
          <input type="hidden" name="month" value="<?= e($m['month']) ?>">
          <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
          <?php if ($statusF !== ''): ?><input type="hidden" name="status" value="<?= e($statusF) ?>"><?php endif; ?>
          <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
          <?php if ($rangeF !== 'month'): ?><input type="hidden" name="range" value="<?= e($rangeF) ?>"><?php endif; ?>
          <?php if ($onF !== ''): ?><input type="hidden" name="on" value="<?= e($onF) ?>"><?php endif; ?>
          <select class="select" name="per_page" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50, 100] as $n): ?>
              <option value="<?= $n ?>" <?= $al['per_page'] === $n ? 'selected' : '' ?>><?= $n ?> / page</option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    <?php endif; ?>
  </section>

    <!-- ===== right rail: attendance overview ===== -->
    <aside class="a-rail">
      <section class="card">
        <div class="card__head"><h2>Attendance Overview <span class="c-muted">(<?= e($m['label']) ?>)</span></h2></div>
        <?php $rate = $stats['rate']; $deg = round($rate * 3.6); ?>
        <div class="a-donut" style="--deg: <?= $deg ?>deg">
          <div class="a-donut__hole">
            <strong><?= e(number_format($rate, 1)) ?>%</strong>
            <span>present rate</span>
          </div>
        </div>
        <div class="a-donut-key">
          <span><i class="a-dot a-dot--present"></i> Present <b><?= (int) $stats['present'] ?></b></span>
          <span><i class="a-dot a-dot--absent"></i> Absent <b><?= (int) $stats['absent'] ?></b></span>
        </div>
        <ul class="a-sum a-mt">
          <li><span>Working days (Sun-Fri)</span><strong><?= (int) $stats['working_days'] ?></strong></li>
          <li><span>No check-out</span><strong><?= (int) $stats['incomplete'] ?></strong></li>
          <li><span>Field days</span><strong><?= (int) $stats['field_days'] ?></strong></li>
          <li><span>Total visits</span><strong><?= (int) $stats['visits'] ?></strong></li>
        </ul>
      </section>

      <section class="card">
        <div class="card__head"><h2>Top Attendees <span class="c-muted">(<?= e($m['label']) ?>)</span></h2></div>
        <?php if (!$top): ?>
          <p class="section-note">No attendance in <?= e($m['label']) ?>.</p>
        <?php else:
          $tones = ['blue', 'green', 'peach', 'purple', 'red'];
          foreach ($top as $i => $d): ?>
          <a class="a-rank" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $d['id'] ?>&tab=attendance">
            <span class="a-rank__n"><?= $i + 1 ?></span>
            <span class="a-avatar a-avatar--<?= e($tones[$i % 5]) ?>"><?= e(mb_strtoupper(mb_substr($d['name'], 0, 1))) ?></span>
            <span class="stack">
              <strong><?= e($d['name']) ?></strong>
              <span class="c-muted"><?= e($d['code'] ?: '-') ?></span>
            </span>
            <span class="a-rank__v"><?= (int) $d['days'] ?>d<br><span class="c-muted"><?= e(number_format($d['rate'], 0)) ?>%</span></span>
          </a>
        <?php endforeach; endif; ?>
      </section>

      <section class="card">
        <div class="card__head"><h2>Attendance Trend <span class="c-muted">(14 days)</span></h2></div>
        <?php $peak = max(1, max(array_column($trend, 'n'))); ?>
        <div class="a-trend">
          <?php foreach ($trend as $t): ?>
            <span class="a-trend__bar" style="height: <?= max(6, round($t['n'] / $peak * 100)) ?>%"
                  title="<?= e($t['label']) ?>: <?= (int) $t['n'] ?> present"></span>
          <?php endforeach; ?>
        </div>
      </section>
    </aside>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/04-attendance/js/attendance.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
