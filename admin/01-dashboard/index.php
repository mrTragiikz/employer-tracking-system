<?php
/**
 * admin/01-dashboard/index.php - /track/admin/01-dashboard/ (Build order step 4)
 *
 * PC-only admin dashboard, cream/brown design.
 * greeting + date | 4 stat cards | chart + route + activities |
 * top Employees + latest visits + quick actions
 *
 * Model: an Employee is the field salesperson. They visit shops they type by name.
 * Stat cards run real queries and degrade to 0 with no data.
 * Chart and route map are styled placeholders - wired in a later step.
 * Trend %s are static placeholders (need historical aggregation, later).
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php'; // heal_missing_visit_hops()
$me = require_admin();

// Self-heal: close any gap where a visit's hop distance never got written at
// save time (stale OpCache after a deploy, a transient Mapbox timeout, an
// old row). Cheap no-op when there's nothing to fix - see the function.
heal_missing_visit_hops($pdo);

$pageTitle = 'Dashboard';
$activeSection = 'dashboard';
$sectionCss = [
    APP_URL . '/admin/01-dashboard/css/dashboard.css',
    APP_URL . '/admin/components/mapbox/css/mapbox-route.css',
];
$bodyClass = 'dash-bg';   // -> plain white page ground (see dashboard.css)

$today = server_today();

// Partial: the dashboard's live-refresh poll re-fetches everything that can
// change while the page sits open (stat counts, the activity feed, the
// selected Employee's route-so-far) as one small JSON payload, and
// dashboard.js patches the existing DOM with it - no full page reload, same
// approach as admin/07-alerts' feed poll (see alert.js), just carrying more
// than one fragment since the dashboard has more than one live area.
$isPartial = ($_GET['partial'] ?? '') === 'live';

function scalar(PDO $pdo, string $sql, array $args = []): float
{
    try {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return (float) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/* ---- stat cards ------------------------------------------------------- */
$totalEmployees = (int) scalar($pdo,
    "SELECT COUNT(*) FROM users WHERE role = 'employee' AND deleted_at IS NULL");

$visitsToday = (int) scalar($pdo,
    "SELECT COUNT(*) FROM visits WHERE work_date = ?", [$today]);

// Employees who have checked in today (any attendance row = attended)
$attendedToday = (int) scalar($pdo,
    "SELECT COUNT(*) FROM attendance WHERE work_date = ?", [$today]);

/* ---- top Employees by visit count (all-time) -------------------------- */
$topEmployees = [];
try {
    $st = $pdo->query(
        "SELECT u.id, u.name, COUNT(v.id) AS visits
           FROM users u
           LEFT JOIN visits v ON v.employee_id = u.id
          WHERE u.role = 'employee' AND u.deleted_at IS NULL
          GROUP BY u.id, u.name
          ORDER BY visits DESC, u.name ASC
          LIMIT 5"
    );
    $topEmployees = $st->fetchAll();
} catch (Throwable $e) { /* ignore */ }

/* ---- latest visits ----------------------------------------------- */
$latestVisits = [];
try {
    $st = $pdo->query(
        "SELECT v.id, v.arrived_at, v.hop_road_km, v.hop_seconds, v.shop_name,
                u.name AS employee
           FROM visits v
           JOIN users u ON u.id = v.employee_id
          ORDER BY v.arrived_at DESC
          LIMIT 5"
    );
    $latestVisits = $st->fetchAll();
} catch (Throwable $e) { /* ignore */ }

/* ---- Today's Route: Employee picker + the chosen Employee's route today ---- */
$routeEmployees = [];
try {
    $st = $pdo->query(
        "SELECT id, name, code, is_active
           FROM users
          WHERE role = 'employee' AND deleted_at IS NULL
          ORDER BY is_active DESC, name ASC"
    );
    $routeEmployees = $st->fetchAll();
} catch (Throwable $e) { /* ignore */ }

$routeEmployeeId = isset($_GET['route_employee']) ? (int) $_GET['route_employee'] : 0;
$routeEmployee   = null;
$routePoints   = [];      // ordered: check-in, visit, visit, ..., check-out
$routeInfo     = null;    // 'inactive' | 'no_attendance' | 'ok'

if ($routeEmployeeId) {
    foreach ($routeEmployees as $rd) {
        if ((int) $rd['id'] === $routeEmployeeId) { $routeEmployee = $rd; break; }
    }
    if (!$routeEmployee) {
        $routeInfo = 'no_attendance';
    } elseif (!$routeEmployee['is_active']) {
        $routeInfo = 'inactive';
    } else {
        try {
            $st = $pdo->prepare(
                "SELECT id, check_in_at, check_in_lat, check_in_lng,
                        check_out_at, check_out_lat, check_out_lng, status
                   FROM attendance
                  WHERE employee_id = ? AND work_date = ?
                  LIMIT 1"
            );
            $st->execute([$routeEmployeeId, $today]);
            $att = $st->fetch();
        } catch (Throwable $e) { $att = null; }

        if (!$att) {
            $routeInfo = 'no_attendance';
        } else {
            $routeInfo = 'ok';
            $routePoints[] = [
                'kind'  => 'checkin',
                'label' => 'Check-in',
                'at'    => $att['check_in_at'],
                'lat'   => (float) $att['check_in_lat'],
                'lng'   => (float) $att['check_in_lng'],
            ];
            try {
                $vs = $pdo->prepare(
                    "SELECT shop_name, arrived_at, lat, lng
                       FROM visits
                      WHERE attendance_id = ?
                      ORDER BY seq ASC, arrived_at ASC"
                );
                $vs->execute([(int) $att['id']]);
                foreach ($vs as $i => $v) {
                    $routePoints[] = [
                        'kind'    => 'visit',
                        'label'   => $v['shop_name'],
                        'at'      => $v['arrived_at'],
                        'lat'     => (float) $v['lat'],
                        'lng'     => (float) $v['lng'],
                        'n'       => $i + 1,
                    ];
                }
            } catch (Throwable $e) { /* ignore */ }
            if ($att['check_out_at'] && $att['check_out_lat'] !== null) {
                $routePoints[] = [
                    'kind'  => 'checkout',
                    'label' => 'Check-out',
                    'at'    => $att['check_out_at'],
                    'lat'   => (float) $att['check_out_lat'],
                    'lng'   => (float) $att['check_out_lng'],
                ];
            }
        }
    }
}

/* ---- recent activity feed --------------------------------------- */
$activities = [];
try {
    $st = $pdo->query(
        "SELECT * FROM (
            SELECT v.arrived_at AS at, u.name AS who, CONCAT('visited ', v.shop_name) AS what
              FROM visits v JOIN users u ON u.id = v.employee_id
            UNION ALL
            SELECT a.check_in_at AS at, u.name AS who, 'checked in' AS what
              FROM attendance a JOIN users u ON u.id = a.employee_id
            UNION ALL
            SELECT a.check_out_at AS at, u.name AS who, 'checked out' AS what
              FROM attendance a JOIN users u ON u.id = a.employee_id WHERE a.check_out_at IS NOT NULL
         ) feed
         ORDER BY at DESC
         LIMIT 5"
    );
    $activities = $st->fetchAll();
} catch (Throwable $e) { /* ignore */ }

function ago(string $ts): string
{
    $diff = max(0, time() - strtotime($ts));
    if ($diff < 60) return $diff . ' sec ago';
    if ($diff < 3600) return intdiv($diff, 60) . ' min ago';
    if ($diff < 86400) return intdiv($diff, 3600) . ' hr ago';
    return intdiv($diff, 86400) . ' d ago';
}

/**
 * Format a hop distance for display. Real field hops are very often under a
 * km (shops clustered a few doors apart) - number_format(x, 1) rounds all
 * of those down to a flat, misleading "0.0" even though the real recorded
 * distance is accurate (see includes/distance.php road_km_real()). Below
 * 1 km, show metres instead so a genuinely tiny hop still reads as a real
 * number ("3 m") rather than looking like missing/broken data; at 1 km and
 * above, km with one decimal reads better than an m figure with 3+ digits.
 *
 * $computed = whether this hop's distance has actually been worked out yet
 * (visit save fills it in; checkout recomputes it). When it hasn't, show a
 * plain dash instead of a fake "0 m" - the number is pending, not zero.
 */
function fmt_hop_km(float $km, bool $computed = true): string
{
    if (!$computed) {
        return '-';
    }
    if ($km < 1.0) {
        return round($km * 1000) . ' m';
    }
    return number_format($km, 1) . ' km';
}

/** tone + icon for one activity row - shared by the initial render and the live-poll partial. */
function activity_meta(string $what): array
{
    if (str_starts_with($what, 'checked in')) return ['bi-box-arrow-in-right', 'green'];
    if (str_starts_with($what, 'checked out')) return ['bi-box-arrow-right', 'red'];
    return ['bi-shop', 'blue'];
}

/** render one activity <div> - shared by the initial render and the live-poll partial. */
function activity_row(array $a): string
{
    [$actIcon, $actTone] = activity_meta($a['what']);
    ob_start(); ?>
        <div class="activity" data-key="<?= e($a['at'] . '|' . $a['who'] . '|' . $a['what']) ?>">
          <span class="activity__icon activity__icon--<?= e($actTone) ?>">
            <i class="bi <?= e($actIcon) ?>"></i>
          </span>
          <div class="activity__body">
            <span class="activity__who"><?= e($a['who']) ?></span>
            <span class="activity__what"><?= e($a['what']) ?></span>
          </div>
          <span class="activity__time"><?= e(ago($a['at'])) ?></span>
        </div>
    <?php
    return trim(ob_get_clean());
}

/** render the whole "Top Employees" ranked list body - shared by the initial render and the live-poll partial. */
function top_employees_html(array $topEmployees): string
{
    if (!$topEmployees) {
        return '<p class="stat-card__foot">No Employees yet. Add them in Users &amp; Roles.</p>';
    }
    $avatarTones = ['blue', 'green', 'peach', 'purple', 'red'];
    $html = '';
    foreach ($topEmployees as $i => $d) {
        ob_start(); ?>
        <div class="rank">
          <span class="rank__n"><?= $i + 1 ?></span>
          <span class="rank__avatar rank__avatar--<?= e($avatarTones[$i % count($avatarTones)]) ?>"><?= e(mb_strtoupper(mb_substr($d['name'], 0, 1))) ?></span>
          <span class="rank__name"><?= e($d['name']) ?></span>
          <span class="rank__meta"><?= (int) $d['visits'] ?> visits</span>
        </div>
        <?php
        $html .= trim(ob_get_clean());
    }
    return $html;
}

/** render the whole "Latest Visits" table body - shared by the initial render and the live-poll partial. */
function latest_visits_html(array $latestVisits): string
{
    if (!$latestVisits) {
        return '<tr><td colspan="4" class="stat-card__foot">No visits recorded yet.</td></tr>';
    }
    $html = '';
    foreach ($latestVisits as $v) {
        $km = (float) $v['hop_road_km'];
        // hop_seconds is NULL until the hop distance/time is actually
        // computed (at visit save, then again at checkout). Until then the
        // "0" in hop_road_km is just the column default, not a real 0 m.
        $computed = $v['hop_seconds'] !== null;
        ob_start(); ?>
        <tr>
          <td class="lv-employee"><?= e($v['employee']) ?></td>
          <td class="lv-shop"><?= e($v['shop_name']) ?></td>
          <td class="lv-time"><?= e((new DateTimeImmutable($v['arrived_at']))->format('h:i A')) ?></td>
          <td class="lv-km"><span class="lv-km__badge<?= ($computed && $km < 1.0) ? ' lv-km__badge--near' : '' ?>"><?= fmt_hop_km($km, $computed) ?></span></td>
        </tr>
        <?php
        $html .= trim(ob_get_clean());
    }
    return $html;
}

if ($isPartial) {
    // Live-refresh payload: stat counts + the activity feed HTML + (if an
    // Employee is selected) their route-so-far points. dashboard.js patches
    // the DOM with this every few seconds - the route map itself is re-drawn
    // by TrackMapboxRoute.render() from the fresh points, not patched here.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'stats' => [
            'total_employees' => $totalEmployees,
            'attended_today'  => $attendedToday,
            'visits_today'    => $visitsToday,
        ],
        'activities_html' => implode('', array_map('activity_row', $activities)),
        'top_employees_html' => top_employees_html($topEmployees),
        'latest_visits_html' => latest_visits_html($latestVisits),
        'route' => [
            'info'   => $routeInfo,
            'points' => array_map(static function (array $p): array {
                return [
                    'kind' => $p['kind'], 'label' => $p['label'], 'at' => $p['at'],
                    'lat' => $p['lat'], 'lng' => $p['lng'], 'no' => $p['n'] ?? null,
                ];
            }, $routePoints),
        ],
    ]);
    exit;
}

require dirname(__DIR__) . '/components/header/header.php';
require dirname(__DIR__) . '/components/mapbox/mapbox.php';
?>

  <?php if (($_GET['err'] ?? '') === 'forbidden'): ?>
    <div class="dash-flash dash-flash--err">
      <i class="bi bi-shield-lock"></i> That section is only available to the Super Admin.
    </div>
  <?php elseif (($_GET['ok'] ?? '') === 'updated'): ?>
    <div class="dash-flash dash-flash--ok">
      <i class="bi bi-check-circle"></i> Your account was updated.
    </div>
  <?php endif; ?>

  <div class="dash-greeting">
    <div>
      <h1>Dashboard <span aria-hidden="true">&#128075;</span></h1>
      <p class="dash-greeting__sub">Welcome back, <?= e($me['name']) ?>! Here's what's happening today.</p>
    </div>
    <div class="date-pill">
      <i class="bi bi-calendar3"></i>
      <?= e((new DateTimeImmutable($today))->format('j F Y')) ?>
    </div>
  </div>

  <!-- ===== stat cards ===== -->
  <div class="stat-row stat-row--3">
    <?php
    $cards = [
        ['total_employees', 'Total Employees', number_format($totalEmployees), 'registered',           'peach',  'bi-people'],
        ['attended_today',  'Attended Today',  number_format($attendedToday),  'Employees checked in',   'purple', 'bi-person-check'],
        ['visits_today',    'Visits Today',    number_format($visitsToday),    'shop visits logged',   'green',  'bi-geo-alt'],
    ];
    foreach ($cards as [$key, $label, $value, $foot, $tile, $icon]): ?>
      <div class="stat-card" data-stat="<?= e($key) ?>">
        <span class="stat-card__tile stat-card__tile--<?= e($tile) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div class="stat-card__label"><?= e($label) ?></div>
        <div class="stat-card__value" data-stat-value><?= e($value) ?></div>
        <div class="stat-card__foot"><?= e($foot) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ===== today's route | activities ===== -->
  <div class="dash-row-2">
    <section class="card" id="todays-route" data-route-employee="<?= (int) $routeEmployeeId ?>">
      <div class="card__head">
        <h2>Today's Route <span class="pill-live" id="dash-live-dot" title="live"></span></h2>
        <form method="get" action="<?= e(APP_URL) ?>/admin/01-dashboard/" class="route-picker">
          <select class="select-pill" name="route_employee" onchange="this.form.submit()" aria-label="Employee">
            <option value="">Choose an Employee...</option>
            <?php foreach ($routeEmployees as $rd): ?>
              <option value="<?= (int) $rd['id'] ?>" <?= $routeEmployeeId === (int) $rd['id'] ? 'selected' : '' ?>>
                <?= e($rd['name']) ?><?= $rd['is_active'] ? '' : ' (inactive)' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if (!$routeEmployeeId): ?>
        <div class="route-empty">
          <i class="bi bi-signpost-split"></i>
          <p>Pick an Employee to see their route so far today.</p>
        </div>

      <?php elseif ($routeInfo === 'inactive'): ?>
        <div class="route-empty">
          <i class="bi bi-person-x"></i>
          <p><strong><?= e($routeEmployee['name']) ?></strong> is inactive - no route.</p>
        </div>

      <?php elseif ($routeInfo === 'no_attendance' || !$routePoints): ?>
        <div class="route-empty">
          <i class="bi bi-geo"></i>
          <p><strong><?= e($routeEmployee['name'] ?? 'That Employee') ?></strong> has not checked in today - no route yet.</p>
        </div>

      <?php else: ?>
        <?php
          // Real Mapbox map, fed by $routePoints. TrackMapboxRoute.render()
          // only clears/replaces this div's fallback content if Mapbox's
          // script loaded AND a real token is configured (see
          // mapbox-route.js) - until then the plain list below stays
          // visible exactly as before.
          $routePointsJson = json_encode(array_map(static function (array $p): array {
              return [
                  'kind' => $p['kind'], 'label' => $p['label'], 'at' => $p['at'],
                  'lat' => $p['lat'], 'lng' => $p['lng'], 'no' => $p['n'] ?? null,
              ];
          }, $routePoints));
        ?>
        <div class="route-map-wrap">
          <div class="route-map mb-route-map" id="mb-dashboard-route"
               data-map-token="<?= e(defined('MAPBOX_ACCESS_TOKEN') ? MAPBOX_ACCESS_TOKEN : '') ?>"
               data-map-points='<?= e($routePointsJson) ?>'
               <?php // For a tracked Android worker this returns their real ridden
                     // path and the map draws THAT; empty for web/iOS -> unchanged. ?>
               data-ping-trail-url="<?= e(APP_URL) ?>/admin/components/mapbox/api/pings.php?employee=<?= (int) $routeEmployeeId ?>&amp;date=<?= e($today) ?>">
            <div class="route-map__bg">Map - integrated in a later step</div>
            <ol class="route-line">
              <?php foreach ($routePoints as $p): ?>
                <li class="route-pt route-pt--<?= e($p['kind']) ?>">
                  <span class="route-pt__dot">
                    <?php if ($p['kind'] === 'checkin'): ?><i class="bi bi-box-arrow-in-right"></i>
                    <?php elseif ($p['kind'] === 'checkout'): ?><i class="bi bi-box-arrow-right"></i>
                    <?php else: ?><?= (int) $p['n'] ?><?php endif; ?>
                  </span>
                  <span class="route-pt__body">
                    <strong><?= e($p['label']) ?></strong>
                    <span class="route-pt__meta">
                      <?= e((new DateTimeImmutable($p['at']))->format('g:i A')) ?>
                      &nbsp;&middot;&nbsp; <?= e(number_format($p['lat'], 5)) ?>, <?= e(number_format($p['lng'], 5)) ?>
                    </span>
                  </span>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
          <button type="button" class="route-map__expand" title="Expand map to inspect"
                  onclick="if (typeof TrackMapboxRoute !== 'undefined') { TrackMapboxRoute.openRoute('mb-dashboard-route'); }">
            <i class="bi bi-arrows-fullscreen"></i>
          </button>
        </div>
        <script>if (typeof TrackMapboxRoute !== 'undefined') { TrackMapboxRoute.render('mb-dashboard-route'); }</script>
        <?php
          $lastAt   = end($routePoints)['at'];
          $stillOut = !array_filter($routePoints, static fn($p) => $p['kind'] === 'checkout');
        ?>
        <p class="route-foot">
          <?= count($routePoints) ?> points &middot; last update <?= e(ago($lastAt)) ?>
          <?php if ($stillOut): ?><span class="pill-live">still out</span><?php endif; ?>
        </p>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card__head">
        <h2>Recent Activities <span class="pill-live" title="live"></span></h2>
        <a class="card__link" href="<?= e(admin_url('visits')) ?>">View All</a>
      </div>
      <div id="dash-activities">
        <?php if (!$activities): ?>
          <p class="stat-card__foot" id="dash-activities-empty">No activity yet today.</p>
        <?php else: echo implode('', array_map('activity_row', $activities));
        endif; ?>
      </div>
    </section>
  </div>

  <!-- ===== top Employees | latest visits | quick actions ===== -->
  <div class="dash-row-3">
    <section class="card">
      <div class="card__head">
        <h2>Top Employees <span class="stat-card__foot">(By Visits)</span></h2>
        <a class="card__link" href="<?= e(admin_url('employees')) ?>">View All</a>
      </div>
      <div id="dash-top-employees"><?= top_employees_html($topEmployees) ?></div>
    </section>

    <section class="card">
      <div class="card__head">
        <h2>Latest Visits</h2>
        <a class="card__link" href="<?= e(admin_url('visits')) ?>">View All</a>
      </div>
      <table class="table">
        <thead>
          <tr><th>Employee</th><th>Shop</th><th>Time</th><th class="lv-km">Distance</th></tr>
        </thead>
        <tbody id="dash-latest-visits"><?= latest_visits_html($latestVisits) ?></tbody>
      </table>
    </section>

    <section class="card">
      <div class="card__head"><h2>Quick Actions</h2></div>
      <div class="qa-grid">
        <?php
        $qa = [
            ['Add Employee',    'employees',    'bi-person-plus',    'blue'],
            ['View Visits',   'visits',     'bi-geo-alt',        'green'],
            ['Attendance',    'attendance', 'bi-calendar-check', 'peach'],
            ['Routes & Map',  'routes',     'bi-map',            'purple'],
            ['Evidence',      'photos',     'bi-camera',         'blue'],
            ['Alerts',        'alerts',     'bi-bell',           'red'],
        ];
        foreach ($qa as [$label, $slug, $icon, $tone]): ?>
          <a class="qa" href="<?= e(admin_url($slug)) ?>">
            <span class="qa__icon qa__icon--<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span>
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/01-dashboard/js/dashboard.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
