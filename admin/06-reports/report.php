<?php
/**
 * admin/06-reports/report.php - Reports section.
 * URL: /track/admin/06-reports/
 *
 * STUB. Build order step 7. Date-range summaries: visits, distance, time split. Export. Build AFTER one full working day tests green.
 *
 * Wiring only: bootstrap + admin guard + shared header/sidebar/footer +
 * section-local css/ js/. Real content built per the build order.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();

$pageTitle = 'Reports';
$activeSection = 'reports';
$sectionCss = APP_URL . '/admin/06-reports/css/report.css';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <h1>Reports</h1>
  <div class="card">
    <p class="section-note">
      Section scaffold OK - signed in as <?= e($me['name']) ?>.<br>
      Build order step 7. Date-range summaries: visits, distance, time split. Export. Build AFTER one full working day tests green.
    </p>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/06-reports/js/report.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
