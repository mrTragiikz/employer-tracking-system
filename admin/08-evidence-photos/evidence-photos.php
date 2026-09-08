<?php
/**
 * admin/08-evidence-photos/evidence-photos.php - Evidence Photos section.
 * URL: /track/admin/08-evidence-photos/
 *        ?range=all|today|7d|30d|on&on=YYYY-MM-DD
 *        &Employee=N&kind=storefront|...&q=&page=&per_page=
 *
 * A gallery of the live-camera photos Employees took at shops. Browse, filter,
 * view full size, download, export. No verify / reject / approval workflow -
 * suspicious captures surface in the Alerts section.
 *
 * Build order step 6.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';
require dirname(__DIR__) . '/02-employees/_repo.php';   // hm() / latlng() / map_link() / dash()

$pageTitle     = 'Evidence Photos';
$activeSection = 'photos';
$sectionCss    = APP_URL . '/admin/08-evidence-photos/css/evidence-photos.css';

$rangeF  = in_array($_GET['range'] ?? 'all', ['all', 'today', '7d', '30d', 'on'], true) ? ($_GET['range'] ?? 'all') : 'all';
$onF     = (string) ($_GET['on'] ?? '');
if ($onF !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onF) || $onF > server_today())) {
    $onF = '';
}
if ($rangeF === 'on' && $onF === '') {
    $rangeF = 'all';
}
$employeeF = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$kindF   = array_key_exists($_GET['kind'] ?? '', photo_kinds()) ? $_GET['kind'] : '';
$q       = trim((string) ($_GET['q'] ?? ''));

$filters = [
    'range'    => $rangeF,
    'on'       => $onF,
    'employee'   => $employeeF,
    'kind'     => $kindF,
    'q'        => $q,
    'page'     => (int) ($_GET['page'] ?? 1),
    'per_page' => (int) ($_GET['per_page'] ?? 12),
];

$Employees = photos_employees($pdo);
$pl      = photos_list($pdo, $filters);
$stats   = photos_stats($pdo, $filters);
$side    = photos_side($pdo, $filters);
$kinds   = photo_kinds();

/** Kind -> colour tone + icon for the chip. */
$kindMeta = [
    'checkin'  => ['tone' => 'green', 'icon' => 'bi-box-arrow-in-right'],
    'visit'    => ['tone' => 'blue',  'icon' => 'bi-shop'],
    'checkout' => ['tone' => 'red',   'icon' => 'bi-box-arrow-right'],
];

/** URL to this page, overriding some params, keeping the rest. */
$pUrl = static function (array $override): string {
    $keep  = array_intersect_key($_GET, array_flip(['range', 'on', 'employee', 'kind', 'q', 'per_page']));
    $qsArr = array_filter(array_merge($keep, $override),
        static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return APP_URL . '/admin/08-evidence-photos/' . ($qsArr ? '?' . http_build_query($qsArr) : '');
};

/** CSV export URL: the api endpoint, carrying the current filters. */
$exportUrl = static function (): string {
    $keep = array_filter(
        array_intersect_key($_GET, array_flip(['range', 'on', 'employee', 'kind', 'q'])),
        static fn($v) => $v !== '' && $v !== null && $v !== '0'
    );
    return APP_URL . '/admin/08-evidence-photos/api/export.php' . ($keep ? '?' . http_build_query($keep) : '');
};

$hasFilter = $employeeF || $kindF !== '' || $q !== '';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Evidence Photos</h1>
      <p class="section-note">Live-camera photos from check-in, shop visits and check-out. Browse, view full size, download.</p>
    </div>
    <div class="ep-pickers">
      <form method="get" action="<?= e(APP_URL) ?>/admin/08-evidence-photos/" class="ep-pick" id="ep-range-form">
        <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
        <?php if ($kindF !== ''): ?><input type="hidden" name="kind" value="<?= e($kindF) ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <select class="ep-rangesel" name="range" onchange="epRangeChange(this)" aria-label="Date range">
          <?php
          $rangeOpts = ['all' => 'All time', 'today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'on' => 'Specific date'];
          foreach ($rangeOpts as $val => $lbl): ?>
            <option value="<?= e($val) ?>" <?= $rangeF === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <input class="input ep-ondate js-on-date" type="date" name="on" value="<?= e($onF) ?>"
               max="<?= e(server_today()) ?>" onchange="this.form.submit()"
               <?= $rangeF === 'on' ? '' : 'hidden' ?>>
      </form>

      <form method="get" action="<?= e(APP_URL) ?>/admin/08-evidence-photos/" class="ep-pick">
        <?php if ($rangeF !== 'all'): ?><input type="hidden" name="range" value="<?= e($rangeF) ?>"><?php endif; ?>
        <?php if ($onF !== ''): ?><input type="hidden" name="on" value="<?= e($onF) ?>"><?php endif; ?>
        <?php if ($kindF !== ''): ?><input type="hidden" name="kind" value="<?= e($kindF) ?>"><?php endif; ?>
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

      <a class="btn" href="<?= e($exportUrl()) ?>"><i class="bi bi-download"></i> Export</a>
    </div>
  </div>

  <!-- ===== headline numbers ===== -->
  <div class="ep-stats">
    <?php
    $cards = [
        ['Total photos',   number_format($stats['total']),         $side['label'],    'blue',   'bi-images'],
        ['Photos today',   number_format($stats['today']),         'till now',        'purple', 'bi-camera'],
        ['Shop-visit photos', number_format($stats['visit_ph']),   'in this window',  'green',  'bi-shop'],
        ['Employees',        number_format($stats['employees']),       'took photos',     'peach',  'bi-people'],
        ['Storage used',   number_format($stats['mb'], 1) . ' MB', 'in this window',  'red',    'bi-hdd'],
    ];
    foreach ($cards as [$label, $value, $sub, $tone, $icon]): ?>
      <div class="ep-stat">
        <span class="ep-stat__ico ep-stat__ico--<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div>
          <div class="ep-stat__value"><?= e($value) ?></div>
          <div class="ep-stat__label"><?= e($label) ?></div>
          <div class="ep-stat__sub"><?= e($sub) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ep-grid">
    <!-- ===== main: filter + gallery ===== -->
    <section class="card ep-main">
      <form class="ep-filter" method="get" action="<?= e(APP_URL) ?>/admin/08-evidence-photos/">
        <?php if ($rangeF !== 'all'): ?><input type="hidden" name="range" value="<?= e($rangeF) ?>"><?php endif; ?>
        <?php if ($onF !== ''): ?><input type="hidden" name="on" value="<?= e($onF) ?>"><?php endif; ?>
        <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
        <div class="ep-filter__search">
          <i class="bi bi-search"></i>
          <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search shop, Employee name or code...">
        </div>
        <select class="select" name="kind" onchange="this.form.submit()" aria-label="Photo type">
          <option value="">All types</option>
          <?php foreach ($kinds as $val => $lbl): ?>
            <option value="<?= e($val) ?>" <?= $kindF === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn--sm"><i class="bi bi-funnel"></i> Filter</button>
        <?php if ($hasFilter): ?>
          <a class="btn btn--sm" href="<?= e($pUrl(['employee' => null, 'kind' => null, 'q' => null])) ?>">Clear</a>
        <?php endif; ?>
      </form>

      <?php if (!$pl['rows']): ?>
        <p class="section-note ep-empty">
          <i class="bi bi-camera"></i>
          <?= $hasFilter || $rangeF !== 'all'
                ? 'No photos match these filters.'
                : 'No evidence photos yet. They appear here as Employees check in, visit shops and check out.' ?>
        </p>
      <?php else: ?>
        <div class="ep-gallery">
          <?php foreach ($pl['rows'] as $ph):
            $url    = UPLOAD_URL . '/' . $ph['stored_path'];
            $arr    = new DateTimeImmutable($ph['taken_at']);
            $meta   = $kindMeta[$ph['photo_kind']] ?? ['tone' => 'grey', 'icon' => 'bi-camera'];
            $kLabel = $kinds[$ph['photo_kind']] ?? 'Photo';
            $title  = $ph['photo_kind'] === 'visit'
                        ? ($ph['shop_name'] ?: 'Shop visit')
                        : $kLabel;
            $ll     = latlng(isset($ph['lat']) ? (float) $ph['lat'] : null, isset($ph['lng']) ? (float) $ph['lng'] : null);
            $gmap   = map_link(isset($ph['lat']) ? (float) $ph['lat'] : null, isset($ph['lng']) ? (float) $ph['lng'] : null);
            $dayUrl = APP_URL . '/admin/02-employees/day.php?employee=' . (int) $ph['employee_id'] . '&date=' . $ph['work_date'];
          ?>
          <figure class="ep-card">
            <button type="button" class="ep-card__img" data-lightbox
                    data-src="<?= e($url) ?>"
                    data-shop="<?= e($title) ?>"
                    data-employee="<?= e($ph['employee_name']) ?>"
                    data-when="<?= e($arr->format('j M Y, g:i A')) ?>"
                    data-kind="<?= e($kLabel) ?>">
              <img src="<?= e($url) ?>" alt="<?= e($title) ?>" loading="lazy">
              <span class="ep-card__zoom"><i class="bi bi-arrows-fullscreen"></i></span>
            </button>
            <figcaption class="ep-card__body">
              <div class="ep-card__row">
                <span class="ep-chip ep-chip--<?= e($meta['tone']) ?>"><i class="bi <?= e($meta['icon']) ?>"></i> <?= e($kLabel) ?></span>
                <span class="ep-card__time"><?= e($arr->format('j M, g:i A')) ?></span>
              </div>
              <strong class="ep-card__shop"><?= e($title) ?></strong>
              <a class="ep-card__employee" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $ph['employee_id'] ?>">
                <?php if (!empty($ph['employee_photo'])): ?>
                  <span class="ep-ava ep-ava--photo"><img src="<?= e(UPLOAD_URL . '/' . $ph['employee_photo']) ?>" alt=""></span>
                <?php else: ?>
                  <span class="ep-ava"><?= e(mb_strtoupper(mb_substr($ph['employee_name'], 0, 1))) ?></span>
                <?php endif; ?>
                <span class="stack">
                  <span class="ep-card__dname"><?= e($ph['employee_name']) ?></span>
                  <span class="c-muted"><?= e($ph['employee_code'] ?: '-') ?></span>
                </span>
              </a>
              <div class="ep-card__meta">
                <?php if ($ll !== ''): ?><span><i class="bi bi-geo-alt"></i> <?= e($ll) ?></span><?php endif; ?>
                <span class="c-muted"><?= e(number_format((int) $ph['bytes'] / 1024)) ?> KB</span>
              </div>
              <?php $photoUrl = APP_URL . '/admin/08-evidence-photos/photo.php?id=' . (int) $ph['id']; ?>
              <a class="ep-card__map" href="<?= e($photoUrl) ?>">
                <i class="bi bi-geo-alt-fill"></i> View on map
              </a>
              <div class="ep-card__actions">
                <a class="ep-act" title="Photo &amp; location" href="<?= e($photoUrl) ?>"><i class="bi bi-arrows-angle-expand"></i></a>
                <a class="ep-act" title="Open the day" href="<?= e($dayUrl) ?>"><i class="bi bi-calendar-week"></i></a>
                <a class="ep-act" title="Download" href="<?= e($url) ?>" download><i class="bi bi-download"></i></a>
                <?php if ($gmap): ?>
                  <a class="ep-act" title="Open in Google Maps" href="<?= e($gmap) ?>" target="_blank" rel="noopener"><i class="bi bi-map"></i></a>
                <?php endif; ?>
              </div>
            </figcaption>
          </figure>
          <?php endforeach; ?>
        </div>

        <div class="table-foot">
          <?php
            $from = $pl['total'] ? (($pl['page'] - 1) * $pl['per_page']) + 1 : 0;
            $to   = min($pl['page'] * $pl['per_page'], $pl['total']);
          ?>
          <span class="section-note">Showing <?= $from ?> to <?= $to ?> of <?= (int) $pl['total'] ?> photos</span>

          <?php if ($pl['pages'] > 1): ?>
            <div class="pager">
              <?php if ($pl['page'] > 1): ?>
                <a href="<?= e($pUrl(['page' => $pl['page'] - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
              <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-left"></i></span><?php endif; ?>
              <?php
              $p = $pl['page']; $last = $pl['pages'];
              $show = array_unique(array_filter([1, $p - 1, $p, $p + 1, $last], static fn($n) => $n >= 1 && $n <= $last));
              sort($show);
              $prev = 0;
              foreach ($show as $n):
                  if ($n - $prev > 1) echo '<span class="pager__gap">...</span>';
                  $prev = $n; ?>
                <a class="<?= $n === $p ? 'is-current' : '' ?>" href="<?= e($pUrl(['page' => $n])) ?>"><?= $n ?></a>
              <?php endforeach; ?>
              <?php if ($pl['page'] < $last): ?>
                <a href="<?= e($pUrl(['page' => $pl['page'] + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
              <?php else: ?><span class="is-disabled"><i class="bi bi-chevron-right"></i></span><?php endif; ?>
            </div>
          <?php endif; ?>

          <form method="get" action="<?= e(APP_URL) ?>/admin/08-evidence-photos/" class="page-size">
            <?php if ($rangeF !== 'all'): ?><input type="hidden" name="range" value="<?= e($rangeF) ?>"><?php endif; ?>
            <?php if ($onF !== ''): ?><input type="hidden" name="on" value="<?= e($onF) ?>"><?php endif; ?>
            <?php if ($employeeF): ?><input type="hidden" name="employee" value="<?= (int) $employeeF ?>"><?php endif; ?>
            <?php if ($kindF !== ''): ?><input type="hidden" name="kind" value="<?= e($kindF) ?>"><?php endif; ?>
            <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
            <select class="select" name="per_page" onchange="this.form.submit()">
              <?php foreach ([12, 24, 48, 96] as $n): ?>
                <option value="<?= $n ?>" <?= $pl['per_page'] === $n ? 'selected' : '' ?>><?= $n ?> / page</option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <!-- ===== right rail ===== -->
    <aside class="ep-rail">
      <section class="card">
        <div class="card__head"><h2>By type <span class="c-muted">(<?= e($side['label']) ?>)</span></h2></div>
        <?php if (!$side['total']): ?>
          <p class="section-note">No photos in this window.</p>
        <?php else: ?>
          <ul class="ep-breakdown">
            <?php foreach ($side['by_kind'] as $b):
              if ($b['count'] === 0) continue;
              $tone = $kindMeta[$b['key']]['tone'] ?? 'grey'; ?>
              <li>
                <div class="ep-breakdown__top">
                  <span class="ep-dot ep-dot--<?= e($tone) ?>"></span>
                  <span class="ep-breakdown__label"><?= e($b['label']) ?></span>
                  <strong><?= (int) $b['count'] ?></strong>
                  <span class="c-muted"><?= (int) $b['pct'] ?>%</span>
                </div>
                <div class="ep-bar"><span class="ep-bar__fill ep-bar__fill--<?= e($tone) ?>" style="width: <?= (int) $b['pct'] ?>%"></span></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <section class="card">
        <div class="card__head"><h2>Top Employees <span class="c-muted">(by photos)</span></h2></div>
        <?php if (!$side['top_employees']): ?>
          <p class="section-note">No photos in this window.</p>
        <?php else:
          $tones = ['blue', 'green', 'peach', 'purple', 'red'];
          foreach ($side['top_employees'] as $i => $d): ?>
          <a class="ep-rank" href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $d['id'] ?>">
            <span class="ep-rank__n"><?= $i + 1 ?></span>
            <?php if (!empty($d['employee_photo'])): ?>
              <span class="ep-ava ep-ava--photo"><img src="<?= e(UPLOAD_URL . '/' . $d['employee_photo']) ?>" alt=""></span>
            <?php else: ?>
              <span class="ep-ava ep-ava--<?= e($tones[$i % 5]) ?>"><?= e(mb_strtoupper(mb_substr($d['name'], 0, 1))) ?></span>
            <?php endif; ?>
            <span class="stack">
              <strong><?= e($d['name']) ?></strong>
              <span class="c-muted"><?= e($d['code'] ?: '-') ?></span>
            </span>
            <span class="ep-rank__v"><?= (int) $d['photos'] ?></span>
          </a>
        <?php endforeach; endif; ?>
        <a class="btn ep-rail__btn" href="<?= e(APP_URL) ?>/admin/03-visits/">All visits <i class="bi bi-arrow-right"></i></a>
      </section>
    </aside>
  </div>

  <!-- ===== lightbox ===== -->
  <div class="ep-lightbox" id="ep-lightbox" hidden>
    <button type="button" class="ep-lightbox__close" data-lightbox-close aria-label="Close"><i class="bi bi-x-lg"></i></button>
    <figure class="ep-lightbox__frame">
      <img src="" alt="" id="ep-lightbox-img">
      <figcaption class="ep-lightbox__cap">
        <strong id="ep-lightbox-shop"></strong>
        <span id="ep-lightbox-meta"></span>
      </figcaption>
    </figure>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/08-evidence-photos/js/evidence-photos.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
