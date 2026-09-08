<?php
/**
 * admin/02-employees/Employee.php - Employees list. URL: /track/admin/02-employees/
 *
 * Stat cards + filter bar + table + pagination, matching the approved mockup.
 * Server-rendered: filters and paging are GET params that reload the page.
 * Row actions: View (eye) -> view.php, Edit -> form.php, Delete -> api/delete.php.
 *
 * Build order step 3.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
require __DIR__ . '/_repo.php';

$pageTitle = 'Employees';
$activeSection = 'employees';
$sectionCss = APP_URL . '/admin/02-employees/css/employee.css';

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'region' => trim((string) ($_GET['region'] ?? '')),
    'area' => trim((string) ($_GET['area'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', ['active', 'inactive', 'former'], true) ? $_GET['status'] : '',
    'page' => (int) ($_GET['page'] ?? 1),
    'per_page' => (int) ($_GET['per_page'] ?? 10),
];
$viewingFormer = $filters['status'] === 'former';

$stats = employee_stats($pdo);
$list = employees_list($pdo, $filters);
$regions = employee_regions($pdo);
$areas = employee_areas($pdo);
$formerCount = former_employees_count($pdo);

/** Build a URL to this page with some GET params changed. */
function employees_url(array $override = []): string
{
    $q = array_merge($_GET, $override);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null);
    return APP_URL . '/admin/02-employees/' . ($q ? '?' . http_build_query($q) : '');
}

$flash = $_GET['ok'] ?? ''; // set after a redirect from save/delete

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1><?= $viewingFormer ? 'Former Employees' : 'Employees' ?>
        <span class="page-head__tag">(Field Employees)</span></h1>
      <p class="section-note">
        <?= $viewingFormer
          ? 'Employees who have left the job. Their full history stays on file. Open one to view it.'
          : 'Manage and view all field employees (Employees).' ?>
      </p>
    </div>
    <div class="page-head__actions">
      <?php $qs = $_SERVER['QUERY_STRING'] ?? ''; ?>
      <?php if ($viewingFormer): ?>
        <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/">
          <i class="bi bi-arrow-left"></i> Back to Employees
        </a>
      <?php else: ?>
        <?php if ($formerCount > 0): ?>
          <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/?status=former">
            <i class="bi bi-person-dash"></i> Former Employees (<?= (int) $formerCount ?>)
          </a>
        <?php endif; ?>
        <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/api/export.php<?= $qs !== '' ? '?' . e($qs) : '' ?>">
          <i class="bi bi-download"></i> Export
        </a>
        <?php if (!empty($me['is_super_admin'])): ?>
          <a class="btn btn--primary" href="<?= e(APP_URL) ?>/admin/02-employees/form.php">
            <i class="bi bi-plus-lg"></i> Add Employee
          </a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash === 'created'): ?><div class="flash flash--ok">Employee added.</div>
  <?php elseif ($flash === 'updated'): ?><div class="flash flash--ok">Employee updated.</div>
  <?php elseif ($flash === 'deleted'): ?><div class="flash flash--ok">Employee removed.</div>
  <?php elseif ($flash === 'pin'): ?><div class="flash flash--ok">PIN reset.</div>
  <?php elseif ($flash === 'device'): ?><div class="flash flash--ok">Device binding cleared - the Employee can pair a new phone on next login.</div>
  <?php elseif ($flash === 'leftjob'): ?><div class="flash flash--ok">Employee marked as having left the job.</div>
  <?php elseif ($flash === 'rejoined'): ?><div class="flash flash--ok">Employee reinstated. Reset their PIN so they can sign in again.</div>
  <?php endif; ?>

  <!-- ===== stat cards ===== -->
  <div class="stat-row">
    <?php
    $cards = [
        ['Total Employees', number_format($stats['total']), 'All registered Employees', 'peach', 'bi-people'],
        ['Active Today', number_format($stats['active_today']), 'Checked in today', 'green', 'bi-person-check'],
        ['Visits Today', number_format($stats['visits_today']), 'Total visits by Employees', 'blue', 'bi-geo-alt'],
        ['KM Travelled Today', number_format($stats['km_today'], 1) . ' km', 'By road; open days count the route so far', 'purple', 'bi-map'],
    ];
    foreach ($cards as [$label, $value, $foot, $tile, $icon]): ?>
      <div class="stat-card">
        <span class="stat-card__tile stat-card__tile--<?= e($tile) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <div class="stat-card__label"><?= e($label) ?></div>
        <div class="stat-card__value"><?= e($value) ?></div>
        <div class="stat-card__foot"><?= e($foot) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ===== table card ===== -->
  <div class="card">
    <form class="filter-bar" method="get" action="<?= e(APP_URL) ?>/admin/02-employees/">
      <div class="filter-bar__search">
        <i class="bi bi-search"></i>
        <input class="input" type="search" name="q" value="<?= e($filters['q']) ?>"
               placeholder="Search Employees by name, phone, email…">
      </div>

      <select class="select" name="region" onchange="this.form.submit()">
        <option value="">All Regions</option>
        <?php foreach ($regions as $r): ?>
          <option value="<?= e($r) ?>" <?= $filters['region'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="select" name="area" onchange="this.form.submit()">
        <option value="">All Areas</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= e($a) ?>" <?= $filters['area'] === $a ? 'selected' : '' ?>><?= e($a) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="select" name="status" onchange="this.form.submit()">
        <?php if ($viewingFormer): ?>
          <option value="former" selected>Former Employees</option>
        <?php else: ?>
          <option value="">All Status</option>
          <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <?php if ($formerCount > 0): ?>
            <option value="former">Former Employees (<?= (int) $formerCount ?>)</option>
          <?php endif; ?>
        <?php endif; ?>
      </select>

      <button type="submit" class="btn btn--sm"><i class="bi bi-funnel"></i> Filter</button>
      <a class="btn btn--sm btn--icon" href="<?= e(APP_URL) ?>/admin/02-employees/" title="Reset"><i class="bi bi-arrow-clockwise"></i></a>
    </form>

    <div class="table-wrap">
      <table class="table employees-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Employee Info</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Region / Area</th>
            <th>Status</th>
            <th>Joined On</th>
            <th class="ta-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$list['rows']): ?>
            <tr><td colspan="8" class="table__empty">No Employees match these filters.</td></tr>
          <?php else:
            $rowNo = ($list['page'] - 1) * $list['per_page'];
            foreach ($list['rows'] as $d):
              $rowNo++; ?>
            <tr>
              <td class="c-muted"><?= $rowNo ?></td>
              <td>
                <div class="employee-cell">
                  <?php if (!empty($d['photo_path'])): ?>
                    <span class="avatar avatar--photo"><img src="<?= e(UPLOAD_URL . '/' . $d['photo_path']) ?>" alt=""></span>
                  <?php else: ?>
                    <span class="avatar"><?= e(employee_initials($d['name'])) ?></span>
                  <?php endif; ?>
                  <span class="employee-cell__text">
                    <strong><?= e($d['name']) ?></strong>
                    <small><?= e($d['code'] ?: ' - ') ?><?= $d['vehicle_type'] ? ' &middot; ' . e(ucfirst($d['vehicle_type'])) : '' ?></small>
                  </span>
                </div>
              </td>
              <td><?= e($d['phone']) ?></td>
              <td class="c-muted"><?= e($d['email'] ?: ' - ') ?></td>
              <?php $regionArea = trim(($d['region'] ?? '') . ' / ' . ($d['area'] ?? ''), ' /'); ?>
              <td><?= $regionArea !== '' ? e($regionArea) : '<span class="c-muted"> - </span>' ?></td>
              <td>
                <?php if ($d['left_job_at'] !== null): ?>
                  <span class="badge badge--rejected" title="Left the job">
                    Left <?= e((new DateTimeImmutable($d['left_job_at']))->format('j M Y')) ?>
                  </span>
                <?php else: ?>
                  <span class="badge badge--<?= $d['is_active'] ? 'approved' : 'rejected' ?>">
                    <?= $d['is_active'] ? 'Active' : 'Inactive' ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="c-muted"><?= e((new DateTimeImmutable($d['created_at']))->format('j M Y')) ?></td>
              <td class="ta-right">
                <div class="row-actions">
                  <a class="row-btn row-btn--view"
                     href="<?= e(APP_URL) ?>/admin/02-employees/view.php?id=<?= (int) $d['id'] ?>">
                    <i class="bi bi-eye"></i> View
                  </a>
                  <?php if (!empty($me['is_super_admin']) && $d['left_job_at'] === null): ?>
                    <a class="row-btn row-btn--edit" title="Edit"
                       href="<?= e(APP_URL) ?>/admin/02-employees/form.php?id=<?= (int) $d['id'] ?>">
                      <i class="bi bi-pencil"></i>
                    </a>
                    <form method="post" action="<?= e(APP_URL) ?>/admin/02-employees/api/delete.php"
                          class="js-delete" data-name="<?= e($d['name']) ?>"
                          data-confirm-title="Remove employee?"
                          data-confirm-body="Remove <?= e($d['name']) ?>? Their past visits and attendance stay on record.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                      <button type="submit" class="row-btn row-btn--del" title="Delete">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ===== footer: count + pager + page size ===== -->
    <div class="table-foot">
      <span class="section-note">
        <?php
        $from = $list['total'] ? (($list['page'] - 1) * $list['per_page']) + 1 : 0;
        $to = min($list['page'] * $list['per_page'], $list['total']);
        ?>
        Showing <?= $from ?> to <?= $to ?> of <?= $list['total'] ?> Employees
      </span>

      <div class="pager">
        <?php if ($list['page'] > 1): ?>
          <a href="<?= e(employees_url(['page' => $list['page'] - 1])) ?>"><i class="bi bi-chevron-left"></i></a>
        <?php else: ?>
          <span class="is-disabled"><i class="bi bi-chevron-left"></i></span>
        <?php endif; ?>

        <?php
        // compact page list: 1 … around-current … last
        $p = $list['page']; $last = $list['pages'];
        $show = array_unique(array_filter([1, $p - 1, $p, $p + 1, $last],
            static fn($n) => $n >= 1 && $n <= $last));
        sort($show);
        $prev = 0;
        foreach ($show as $n):
            if ($n - $prev > 1) echo '<span class="pager__gap">…</span>';
            $prev = $n; ?>
          <a class="<?= $n === $p ? 'is-current' : '' ?>" href="<?= e(employees_url(['page' => $n])) ?>"><?= $n ?></a>
        <?php endforeach; ?>

        <?php if ($list['page'] < $last): ?>
          <a href="<?= e(employees_url(['page' => $list['page'] + 1])) ?>"><i class="bi bi-chevron-right"></i></a>
        <?php else: ?>
          <span class="is-disabled"><i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
      </div>

      <form method="get" action="<?= e(APP_URL) ?>/admin/02-employees/" class="page-size">
        <?php foreach (['q', 'region', 'area', 'status'] as $k):
          if ($filters[$k] !== ''): ?>
            <input type="hidden" name="<?= e($k) ?>" value="<?= e($filters[$k]) ?>">
        <?php endif; endforeach; ?>
        <select class="select" name="per_page" onchange="this.form.submit()">
          <?php foreach ([10, 25, 50, 100] as $n): ?>
            <option value="<?= $n ?>" <?= $list['per_page'] === $n ? 'selected' : '' ?>><?= $n ?> / page</option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/02-employees/js/employee.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
