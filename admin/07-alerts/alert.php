<?php
/**
 * admin/07-alerts/alert.php - Alerts = the live activity feed.
 * URL: /track/admin/07-alerts/?type=all|checkin|checkout|visit|photo|alert&employee=N&date=YYYY-MM-DD
 *
 * A running stream of what every Employee does - checked in, visited a shop,
 * added a photo, checked out - newest first, auto-refreshing. Suspicious
 * activity (rules 5/6/7/9) shows here too, as a warning/critical entry.
 *
 * Build order step 7.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';

$pageTitle     = 'Alerts';
$activeSection  = 'alerts';
$sectionCss     = APP_URL . '/admin/07-alerts/css/alert.css';

$type   = in_array($_GET['type'] ?? 'all', ['all', 'checkin', 'checkout', 'visit', 'photo', 'alert'], true) ? ($_GET['type'] ?? 'all') : 'all';
$employeeF = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$dateF  = (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date'])) ? $_GET['date'] : '';

// Partial render: the poller fetches only the feed <ul> markup.
$isPartial = ($_GET['partial'] ?? '') === 'feed';

$beforeF = (!empty($_GET['before']) && strtotime((string) $_GET['before'])) ? $_GET['before'] : null;

$employees = feed_employees($pdo);
$counts  = feed_counts($pdo, $dateF, $employeeF);
$feed    = feed_list($pdo, [
    'type'   => $type,
    'employee' => $employeeF,
    'date'   => $dateF,
    'before' => $beforeF,
    'limit'  => 40,
]);

/** icon + colour per activity kind */
function feed_meta(string $kind, string $level): array
{
    return match ($kind) {
        'checkin'  => ['bi-box-arrow-in-right', 'green'],
        'checkout' => ['bi-box-arrow-right',    'red'],
        'visit'    => ['bi-shop',               'blue'],
        'photo'    => ['bi-camera',             'purple'],
        'alert'    => [$level === 'critical' ? 'bi-exclamation-octagon-fill' : 'bi-exclamation-triangle-fill',
                       $level === 'critical' ? 'crit' : 'warn'],
        default    => ['bi-dot', 'blue'],
    };
}

/** "2 min ago" style. */
function feed_ago(string $ts): string
{
    $d = max(0, time() - strtotime($ts));
    if ($d < 45)     return 'just now';
    if ($d < 3600)   return intdiv($d, 60) . ' min ago';
    if ($d < 86400)  return intdiv($d, 3600) . ' hr ago';
    if ($d < 604800) return intdiv($d, 86400) . ' d ago';
    return date('j M', strtotime($ts));
}

/** render one <li>. */
function feed_row(array $r): string
{
    [$icon, $tone] = feed_meta($r['kind'], $r['level']);
    $when  = feed_ago($r['at']);
    $exact = (new DateTimeImmutable($r['at']))->format('j M Y, g:i A');
    $initial = mb_strtoupper(mb_substr($r['employee_name'], 0, 1));
    $employeeHref = $r['employee_id']
        ? APP_URL . '/admin/02-employees/view.php?id=' . (int) $r['employee_id']
        : null;
    $dayHref = ($r['employee_id'] && $r['ref_date'])
        ? APP_URL . '/admin/02-employees/day.php?employee=' . (int) $r['employee_id'] . '&date=' . e($r['ref_date'])
        : null;

    ob_start(); ?>
    <li class="fd-row fd-row--<?= e($tone) ?>">
      <span class="fd-ico"><i class="bi <?= e($icon) ?>"></i></span>
      <span class="fd-body">
        <span class="fd-line">
          <?php if ($employeeHref): ?>
            <a class="fd-who" href="<?= e($employeeHref) ?>"><span class="fd-av"><?= e($initial) ?></span><?= e($r['employee_name']) ?></a>
          <?php else: ?>
            <span class="fd-who"><span class="fd-av fd-av--sys">S</span><?= e($r['employee_name']) ?></span>
          <?php endif; ?>
          <span class="fd-what"><?= e($r['subject']) ?></span>
        </span>
        <?php if ($r['detail'] !== null && $r['detail'] !== ''): ?>
          <span class="fd-detail">
            <?php if ($r['kind'] === 'photo'): ?>
              <a href="<?= e(UPLOAD_URL . '/' . $r['detail']) ?>" target="_blank" rel="noopener">view photo</a>
            <?php elseif ($r['kind'] === 'alert'): ?>
              <?= e($r['detail']) ?>
            <?php else: ?>
              GPS <?= e($r['detail']) ?>
            <?php endif; ?>
          </span>
        <?php endif; ?>
      </span>
      <span class="fd-meta">
        <time title="<?= e($exact) ?>"><?= e($when) ?></time>
        <?php if ($dayHref): ?><a class="fd-open" href="<?= e($dayHref) ?>" title="Open the day"><i class="bi bi-arrow-up-right"></i></a><?php endif; ?>
      </span>
    </li>
    <?php
    return trim(ob_get_clean());
}

if ($isPartial) {
    // only the rows - used by the auto-refresh poll
    header('Content-Type: text/html; charset=utf-8');
    foreach ($feed['rows'] as $r) echo feed_row($r);
    exit;
}

$aUrl = static function (array $override): string {
    $keep  = array_intersect_key($_GET, array_flip(['type', 'employee', 'date']));
    $qsArr = array_filter(array_merge($keep, $override), static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return APP_URL . '/admin/07-alerts/' . ($qsArr ? '?' . http_build_query($qsArr) : '');
};

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Activity <span class="fd-live-dot" title="live"></span></h1>
      <p class="section-note">Every check-in, shop visit, photo and check-out - as it happens.</p>
    </div>
    <div class="fd-pickers">
      <form method="get" action="<?= e(APP_URL) ?>/admin/07-alerts/" class="fd-pick">
        <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
        <select class="select" name="employee" onchange="this.form.submit()" aria-label="Employee">
          <option value="">All Employees</option>
          <?php foreach ($employees as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $employeeF ? 'selected' : '' ?>>
              <?= e($d['name']) ?><?= $d['code'] ? ' (' . e($d['code']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <form method="get" action="<?= e(APP_URL) ?>/admin/07-alerts/" class="fd-pick">
        <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
        <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
        <input class="input" type="date" name="date" value="<?= e($dateF) ?>" max="<?= e(server_today()) ?>" onchange="this.form.submit()">
      </form>
      <?php if ($dateF !== ''): ?>
        <a class="btn btn--sm" href="<?= e($aUrl(['date' => null])) ?>">Live</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== type tabs ===== -->
  <nav class="fd-tabs">
    <?php
    $tabs = [
        'all'      => ['All activity', null],
        'checkin'  => ['Check-ins', $counts['checkin']],
        'visit'    => ['Visits', $counts['visit']],
        'photo'    => ['Photos', $counts['photo']],
        'checkout' => ['Check-outs', $counts['checkout']],
        'alert'    => ['Suspicious', $counts['alert']],
    ];
    foreach ($tabs as $k => [$lbl, $n]): ?>
      <a class="fd-tab <?= $type === $k ? 'is-active' : '' ?><?= $k === 'alert' ? ' fd-tab--alert' : '' ?>"
         href="<?= e($aUrl(['type' => $k === 'all' ? null : $k])) ?>">
        <?= e($lbl) ?>
        <?php if ($n !== null && $n > 0): ?><span class="fd-tab__n"><?= (int) $n ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <section class="card fd-card">
    <div class="card__head">
      <h2>
        <?= $dateF !== '' ? e((new DateTimeImmutable($dateF))->format('l, j F Y')) : 'Latest first' ?>
      </h2>
      <span class="section-note" id="fd-status"><?= $dateF !== '' ? 'a specific day' : 'auto-refreshing' ?></span>
    </div>

    <?php if (!$feed['rows']): ?>
      <p class="section-note fd-empty">No activity to show<?= $dateF !== '' ? ' for ' . e((new DateTimeImmutable($dateF))->format('j M Y')) : ' yet' ?>.</p>
      <?php if ($feed['window_bound'] && $dateF === ''): ?>
        <div class="fd-more">
          <a class="btn btn--sm" href="<?= e($aUrl(['type' => $type === 'all' ? null : $type, 'before' => $feed['window_from']])) ?>">
            Search further back
          </a>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <ul class="fd-list" id="fd-list"
          data-poll="<?= $dateF === '' ? e($aUrl(['type' => $type === 'all' ? null : $type, 'partial' => 'feed'])) : '' ?>">
        <?php foreach ($feed['rows'] as $r) echo feed_row($r); ?>
      </ul>
      <?php if ($feed['has_more']): ?>
        <div class="fd-more">
          <a class="btn btn--sm" href="<?= e($aUrl(['type' => $type === 'all' ? null : $type, 'date' => $dateF, 'before' => $feed['last_at']])) ?>">
            Load older activity
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <script src="<?= e(APP_URL) ?>/admin/07-alerts/js/alert.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
