<?php
/**
 * admin/02-employees/view.php  -  Employee / Field Employee Details.
 *   /track/admin/02-employees/view.php?id=N[&tab=overview|attendance][&on=YYYY-MM-DD]
 *
 * Header (Edit / Reset Password / Delete) + profile card + 5 all-time stat
 * cards + tabbed body:
 *   Overview   : Today's Statement (date-jumpable) + attendance/location timeline
 *   Attendance : day/month picker + days table (each row -> day.php)
 *   Visits History / Today's Route / Evidence Photos / Documents : placeholders
 *
 * Build order step 3.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require dirname(__DIR__, 2) . '/includes/distance.php'; // heal_missing_visit_hops()
$me = require_admin();
require __DIR__ . '/_repo.php';

$pageTitle     = 'Employees';
$activeSection = 'employees';
$sectionCss    = [
    APP_URL . '/admin/02-employees/css/employee.css',
    APP_URL . '/admin/components/mapbox/css/mapbox-route.css',
];
$bodyClass     = 'employee-detail';   // -> plain white page ground (see Employee.css)

$id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$employee = $id ? employee_find($pdo, $id) : null;

if (!$employee) {
    require dirname(__DIR__) . '/components/header/header.php';
    echo '<div class="card"><p class="section-note">Employee not found. <a href="' . e(APP_URL) . '/admin/02-employees/">Back to list</a>.</p></div>';
    require dirname(__DIR__) . '/components/footer/footer.php';
    exit;
}

$tabs = ['overview' => 'Overview', 'visits' => 'Visits History', 'attendance' => 'Attendance',
         'route' => "Today's Route", 'photos' => 'Evidence Photos', 'documents' => 'Documents'];
$tab  = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';

$flash = $_GET['ok'] ?? '';
$err   = $_GET['err'] ?? '';

// Self-heal any of this employee's visits still missing their hop distance so
// every figure below (all-time Productive KM, Today's Statement, timeline)
// reads a real number, not 0 - see heal_missing_visit_hops() in distance.php.
heal_missing_visit_hops($pdo, $id);

$stats = employee_alltime_stats($pdo, $id);

/* ---------- Overview tab: Day / Month / All time selector ---------- */
$ovMode = in_array($_GET['view'] ?? '', ['day', 'month', 'alltime'], true) ? $_GET['view'] : 'day';

// day mode target
$onDate = (string) ($_GET['on'] ?? server_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) || !strtotime($onDate) || $onDate > server_today()) {
    $onDate = server_today();
}
$isToday = $onDate === server_today();

// month mode target
$onMonth = (string) ($_GET['month'] ?? substr(server_today(), 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $onMonth) || !strtotime($onMonth . '-01') || $onMonth > substr(server_today(), 0, 7)) {
    $onMonth = substr(server_today(), 0, 7);
}

$statement    = null;   // day mode: employee_statement()
$timeline     = [];      // day mode: employee_timeline()
$periodTotals = null;    // month / alltime mode: employee_period_totals()
$periodLabel  = '';
$monthDays    = [];      // month mode: employee_calendar() rows

if ($tab === 'overview') {
    if ($ovMode === 'day') {
        $statement   = employee_statement($pdo, $id, $onDate);
        $timeline    = employee_timeline($pdo, $id, $onDate);
        $periodLabel = $isToday ? 'Today' : (new DateTimeImmutable($onDate))->format('l, j F Y');
    } elseif ($ovMode === 'month') {
        $mFrom        = $onMonth . '-01';
        $mTo          = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
        $periodTotals = employee_period_totals($pdo, $id, $mFrom, $mTo);
        $periodLabel  = (new DateTimeImmutable($mFrom))->format('F Y');
        $monthDays    = employee_calendar($pdo, $id, $mFrom, $mTo);
    } else { // alltime
        $periodTotals = employee_period_totals($pdo, $id, null, null);
        $periodLabel  = 'All time';
    }
}

// Attendance tab data
if ($tab === 'attendance') {
    [$spanMode, $from, $to, $spanLabel] = employee_span($_GET);
    $calendar   = employee_calendar($pdo, $id, $from, $to);
    $workedDays = array_filter($calendar, static fn($r) => $r['has_attendance']);
    $summary    = employee_range_summary($pdo, $id, $from, $to);
    $pickerDay   = $spanMode === 'day'   ? $from : server_today();
    $pickerMonth = $spanMode === 'month' ? substr($from, 0, 7) : substr(server_today(), 0, 7);
}

// Visits History tab data
$vh = null;
if ($tab === 'visits') {
    $vh = employee_visits_history($pdo, $id, [
        'month'    => $_GET['month'] ?? null,
        'q'        => trim((string) ($_GET['q'] ?? '')),
        'page'     => (int) ($_GET['page'] ?? 1),
        'per_page' => (int) ($_GET['per_page'] ?? 25),
    ]);
}

/**
 * URL to this page keeping id, switching tab. The #tabs fragment scrolls the
 * browser back to the tab bar after navigation, so switching tab / mode does
 * not throw the reader back to the top of the page.
 */
function tab_url(int $id, string $tab, array $extra = []): string
{
    return APP_URL . '/admin/02-employees/view.php?'
        . http_build_query(['id' => $id, 'tab' => $tab] + $extra) . '#tabs';
}

$fmtDate = static fn(?string $d) => $d ? (new DateTimeImmutable($d))->format('j M Y') : dash();

require dirname(__DIR__) . '/components/header/header.php';
require dirname(__DIR__) . '/components/mapbox/mapbox.php';
?>

  <div class="page-head">
    <div>
      <h1>Employee / Field Employee Details</h1>
      <p class="crumbs">
        <a href="<?= e(APP_URL) ?>/admin/02-employees/">Employees</a>
        <i class="bi bi-chevron-right"></i> Employee Details
      </p>
    </div>
    <div class="page-head__actions">
      <?php $isFormer = $employee['left_job_at'] !== null; ?>
      <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/<?= $isFormer ? '?status=former' : '' ?>">
        <i class="bi bi-arrow-left"></i> Back to <?= $isFormer ? 'Former Employees' : 'Employees' ?>
      </a>
      <?php if (!empty($me['is_super_admin']) && $isFormer): ?>
        <?php /* Former employee: the only action is Rejoin. Everything else
                 (edit, PIN, device, lock, delete) is hidden - the record is
                 read-only history now. */ ?>
        <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/rejoin.php" class="js-confirm" style="margin:0"
              data-confirm-title="Reinstate employee?" data-confirm-label="Rejoin" data-confirm-tone="primary"
              data-confirm-body="Bring <?= e($employee['name']) ?> back as an active employee? After this, reset their PIN so they can sign in, and they will pair a phone on their next login.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <button type="submit" class="btn btn--primary"><i class="bi bi-arrow-counterclockwise"></i> Rejoin</button>
        </form>
      <?php elseif (!empty($me['is_super_admin'])): ?>
        <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/form.php?id=<?= (int) $id ?>">
          <i class="bi bi-pencil"></i> Edit Employee
        </a>
        <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/reset-pin.php" class="js-prompt" style="margin:0"
              data-confirm-title="Reset PIN" data-confirm-body="Set a new 4-digit PIN for <?= e($employee['name']) ?>."
              data-confirm-label="Save PIN" data-confirm-tone="primary"
              data-prompt-label="New 4-digit PIN" data-prompt-name="pin" data-prompt-pattern="\d{4}"
              data-prompt-maxlength="4" data-prompt-error="The PIN must be exactly 4 digits.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <button type="submit" class="btn"><i class="bi bi-key"></i> Reset Password</button>
        </form>
        <?php if (!empty($employee['device_id'])): ?>
          <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/reset-device.php" class="js-confirm" style="margin:0"
                data-confirm-title="Reset device?" data-confirm-label="Reset Device"
                data-confirm-body="Unbind <?= e($employee['name']) ?> from their current phone? They will be signed out on that phone, and the next phone they sign in from becomes their new bound device.">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <button type="submit" class="btn"><i class="bi bi-phone"></i> Reset Device</button>
          </form>
        <?php endif; ?>
        <?php if ((int) $employee['is_active'] === 1): ?>
          <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/toggle-lock.php" class="js-confirm" style="margin:0"
                data-confirm-title="Lock employee?" data-confirm-label="Lock Employee"
                data-confirm-body="Lock <?= e($employee['name']) ?>? They will be signed out immediately and cannot log in until unlocked.">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="hidden" name="action" value="lock">
            <button type="submit" class="btn btn--danger"><i class="bi bi-lock-fill"></i> Lock Employee</button>
          </form>
        <?php else: ?>
          <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/toggle-lock.php" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <input type="hidden" name="action" value="unlock">
            <button type="submit" class="btn btn--primary"><i class="bi bi-unlock-fill"></i> Unlock Employee</button>
          </form>
        <?php endif; ?>
        <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/leave-job.php" class="js-confirm" style="margin:0"
              data-confirm-title="Mark as left the job?" data-confirm-label="Left the Job"
              data-confirm-body="Mark <?= e($employee['name']) ?> as having left the job? They will be signed out and can no longer log in. Their full history (attendance, visits, routes and evidence photos) stays on file under Former Employees, and you can Rejoin them later if they come back.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <button type="submit" class="btn btn--danger"><i class="bi bi-box-arrow-right"></i> Left the Job</button>
        </form>
        <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/delete.php" class="js-confirm" style="margin:0"
              data-confirm-title="Delete employee?" data-confirm-label="Delete Employee"
              data-confirm-body="Delete <?= e($employee['name']) ?>? Past records stay on file. Prefer &quot;Left the Job&quot; unless you really want them gone from every list.">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $id ?>">
          <button type="submit" class="btn btn--danger"><i class="bi bi-trash3"></i> Delete Employee</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash === 'updated'): ?><div class="flash flash--ok">Employee updated.</div>
  <?php elseif ($flash === 'pin'): ?><div class="flash flash--ok">Password reset.</div>
  <?php elseif ($flash === 'device'): ?><div class="flash flash--ok">Device binding cleared.</div>
  <?php elseif ($flash === 'notes'): ?><div class="flash flash--ok">Notes saved.</div>
  <?php elseif ($flash === 'locked'): ?><div class="flash flash--ok">Employee locked - they have been signed out and cannot log in until unlocked.</div>
  <?php elseif ($flash === 'unlocked'): ?><div class="flash flash--ok">Employee unlocked - they can log in again.</div>
  <?php elseif ($flash === 'unlocked_throttle'): ?><div class="flash flash--ok">Login lockout cleared - they can try signing in again now.</div>
  <?php elseif ($flash === 'leftjob'): ?><div class="flash flash--ok">Marked as having left the job. Their full history is kept and stays visible here and under Former Employees.</div>
  <?php elseif ($flash === 'rejoined'): ?><div class="flash flash--ok">Employee reinstated. Reset their PIN so they can sign in again.</div>
  <?php endif; ?>
  <?php if ($err === 'leavejob'): ?><div class="flash flash--err">Could not update. Please try again.</div>
  <?php elseif ($err === 'rejoin'): ?><div class="flash flash--err">Could not reinstate. Please try again.</div>
  <?php endif; ?>

  <?php if ($employee['left_job_at'] !== null): ?>
    <div class="flash flash--err throttle-banner">
      <div>
        <strong><i class="bi bi-box-arrow-right"></i>
          Former employee. Left the job on
          <?= e((new DateTimeImmutable($employee['left_job_at']))->format('j F Y')) ?>.
        </strong>
        <p class="section-note">
          This account can no longer log in. Everything below stays on file,
          read only: attendance, visits, routes, evidence photos and PDF
          reports. If this employee comes back, use the
          <strong>Rejoin</strong> button.
        </p>
      </div>
    </div>
  <?php endif; ?>

  <?php
    $throttleLockedUntil = $employee['locked_until'] ?? null;
    $isThrottleLocked = $throttleLockedUntil !== null && strtotime($throttleLockedUntil) > time();
  ?>
  <?php if ($isThrottleLocked): ?>
    <div class="flash flash--err throttle-banner">
      <div>
        <strong><i class="bi bi-shield-lock-fill"></i> Locked out from too many wrong PIN attempts</strong>
        <p class="section-note">
          <?= (int) $employee['failed_logins'] ?> failed attempts in a row. Automatically unlocks at
          <?= e((new DateTimeImmutable($throttleLockedUntil))->format('g:i:s A')) ?>.
        </p>
      </div>
      <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/clear-throttle.php" class="js-confirm"
            data-confirm-title="Unlock now?" data-confirm-label="Unlock Now" data-confirm-tone="primary"
            data-confirm-body="Let <?= e($employee['name']) ?> try signing in again right away, instead of waiting for the 15-minute lock to expire on its own?">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <button type="submit" class="btn btn--primary"><i class="bi bi-unlock-fill"></i> Unlock Now</button>
      </form>
    </div>
  <?php endif; ?>
  <?php if ($err === 'pin'): ?><div class="flash flash--err">Password must be 4 digits.</div>
  <?php elseif ($err === 'lock'): ?><div class="flash flash--err">Could not update the lock status. Please try again.</div>
  <?php elseif ($err === 'device'): ?><div class="flash flash--err">Could not reset the device. Please try again.</div>
  <?php endif; ?>

  <!-- ===== profile card ===== -->
  <div class="card profile-card">
    <div class="profile-col profile-col--id">
      <?php if (!empty($employee['photo_path'])): ?>
        <span class="profile-avatar profile-avatar--photo">
          <img src="<?= e(UPLOAD_URL . '/' . $employee['photo_path']) ?>" alt="">
        </span>
      <?php else: ?>
        <span class="profile-avatar"><?= e(employee_initials($employee['name'])) ?></span>
      <?php endif; ?>
      <div class="profile-id">
        <div class="profile-id__name">
          <?= e($employee['name']) ?>
          <?php if ($employee['left_job_at'] !== null): ?>
            <span class="badge badge--rejected">Former</span>
          <?php else: ?>
            <span class="badge badge--<?= $employee['is_active'] ? 'approved' : 'rejected' ?>">
              <?= $employee['is_active'] ? 'Active' : 'Inactive' ?>
            </span>
          <?php endif; ?>
        </div>
        <div class="profile-id__code">Employee Code: <?= e($employee['code'] ?: '-') ?></div>
        <div class="profile-id__line"><i class="bi bi-telephone"></i> <?= e($employee['phone']) ?></div>
        <?php if ($employee['email']): ?><div class="profile-id__line"><i class="bi bi-envelope"></i> <?= e($employee['email']) ?></div><?php endif; ?>
        <?php $ra = trim(($employee['region'] ?? '') . ' / ' . ($employee['area'] ?? ''), ' /'); ?>
        <?php if ($ra !== ''): ?><div class="profile-id__line"><i class="bi bi-geo-alt"></i> <?= e($ra) ?></div><?php endif; ?>
        <?php if (!empty($employee['vehicle_type'])):
          $vIcon = ['bike' => 'bi-bicycle', 'car' => 'bi-car-front', 'auto' => 'bi-truck'][$employee['vehicle_type']] ?? 'bi-truck';
        ?>
          <div class="profile-id__line"><i class="bi <?= e($vIcon) ?>"></i> <?= e(ucfirst($employee['vehicle_type'])) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <dl class="profile-col profile-kv">
      <div><dt><i class="bi bi-calendar3"></i> Joined On</dt><dd><?= $fmtDate($employee['created_at']) ?></dd></div>
      <div><dt><i class="bi bi-cake2"></i> Date of Birth</dt><dd><?= $fmtDate($employee['dob']) ?></dd></div>
      <div><dt><i class="bi bi-person"></i> Gender</dt><dd><?= $employee['gender'] ? e(ucfirst($employee['gender'])) : dash() ?></dd></div>
      <div><dt><i class="bi bi-phone"></i> Bound Device</dt><dd>
        <?php if (!empty($employee['device_id'])): ?>
          Paired <?= $employee['device_bound_at'] ? e((new DateTimeImmutable($employee['device_bound_at']))->format('j M Y')) : '' ?>
          <span class="c-muted">(&hellip;<?= e(substr($employee['device_id'], -6)) ?>)</span>
        <?php else: ?>
          <span class="c-muted">Not paired - next sign-in binds a phone</span>
        <?php endif; ?>
      </dd></div>
    </dl>

    <dl class="profile-col profile-kv">
      <?php if ($employee['document_type']): ?>
        <div><dt><i class="bi bi-file-earmark-text"></i> Document Type</dt><dd><?= e(employee_document_label($employee['document_type'])) ?></dd></div>
      <?php endif; ?>
      <div><dt><i class="bi bi-person-vcard"></i> ID No.</dt><dd><?= $employee['id_number'] ? e($employee['id_number']) : dash() ?></dd></div>
      <div><dt><i class="bi bi-telephone-plus"></i> Emergency Contact</dt>
        <dd><?= $employee['emergency_contact'] ? e($employee['emergency_contact']) : dash() ?>
          <?php if ($employee['emergency_name']): ?><span class="c-muted">(<?= e($employee['emergency_name']) ?>)</span><?php endif; ?>
        </dd></div>
      <div><dt><i class="bi bi-house"></i> Address</dt><dd><?= $employee['address'] ? e($employee['address']) : dash() ?></dd></div>
    </dl>

    <?php
    $idCards = [
        ['Front', $employee['id_photo_front'] ?? null],
        ['Back',  $employee['id_photo_back'] ?? null],
    ];
    if (!empty($employee['id_photo_front']) || !empty($employee['id_photo_back'])): ?>
      <div class="profile-col id-cards">
        <div class="id-cards__head"><i class="bi bi-person-vcard"></i> <?= e(employee_document_label($employee['document_type'])) ?> photos</div>
        <div class="id-cards__row">
          <?php foreach ($idCards as [$side, $path]): ?>
            <figure class="id-card">
              <?php if (!empty($path)):
                $url = UPLOAD_URL . '/' . $path; ?>
                <a href="<?= e($url) ?>" target="_blank" rel="noopener"><img src="<?= e($url) ?>" alt="ID card <?= e($side) ?>"></a>
              <?php else: ?>
                <span class="id-card__empty"><i class="bi bi-image"></i></span>
              <?php endif; ?>
              <figcaption><?= e($side) ?></figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== all-time stat cards ===== -->
  <div class="summary-row summary-row--4">
    <?php
    $longestMin = $stats['longest_dwell_secs'] > 0
        ? number_format(round($stats['longest_dwell_secs'] / 60)) . ' min'
        : '-';
    $topCards = [
        ['Total Visits',          number_format($stats['total_visits']),                  'All time', 'peach',  'bi-clipboard-check'],
        ['Productive KM',         number_format($stats['productive_km'], 1) . ' km',       'All time; open days count so far', 'purple', 'bi-geo'],
        ['Longest Shop Visit',    $longestMin,                                             'All-time best', 'peach', 'bi-clock-history'],
        ['Visits This Month',     number_format($stats['visits_month']),                   (new DateTimeImmutable())->format('F Y'), 'blue', 'bi-calendar-week'],
    ];
    foreach ($topCards as [$label, $value, $sub, $tile, $icon]): ?>
      <div class="sum-card">
        <span class="sum-card__icon sum-card__icon--<?= e($tile) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div>
          <div class="sum-card__value"><?= e($value) ?></div>
          <div class="sum-card__label"><?= e($label) ?></div>
          <div class="sum-card__sub"><?= e($sub) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ===== tabs ===== -->
  <nav class="tabs" id="tabs">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="tab <?= $tab === $key ? 'is-active' : '' ?>" href="<?= e(tab_url($id, $key)) ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'overview'): ?>
    <?php require __DIR__ . '/_view_overview.php'; ?>
  <?php elseif ($tab === 'attendance'): ?>
    <?php require __DIR__ . '/_view_attendance.php'; ?>
  <?php elseif ($tab === 'visits'): ?>
    <?php require __DIR__ . '/_view_visits.php'; ?>
  <?php else: ?>
    <div class="card tab-stub">
      <i class="bi bi-hourglass-split"></i>
      <p><strong><?= e($tabs[$tab]) ?></strong> is coming in a later build step.</p>
    </div>
  <?php endif; ?>

  <script>
    // Keep the viewport at the tab bar when a tab / Overview mode / date /
    // month is switched, instead of the browser jumping back to the page top.
    // The mode links carry #tabs; GET forms strip it on submit, so any
    // navigation that added a query param past ?id= also counts as a switch.
    (function () {
      var el = document.getElementById('tabs');
      if (!el) return;
      var ignore = { id: 1, ok: 1, err: 1 };   // bare load + post-save flashes
      var switched = false;
      new URLSearchParams(location.search).forEach(function (_, k) {
        if (!ignore[k]) switched = true;
      });
      if (location.hash === '#tabs' || switched) {
        el.scrollIntoView({ block: 'start' });
      }
    })();
  </script>
  <script src="<?= e(APP_URL) ?>/admin/02-employees/js/employee.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
