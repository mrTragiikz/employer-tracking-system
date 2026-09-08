<?php
/**
 * admin/02-employees/api/export.php - CSV of the current (filtered) Employee list.
 *
 * GET: same filter params as the list page (q, region, area, status).
 * Streams a text/csv download.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate
$me = require_admin();
require dirname(__DIR__) . '/_repo.php';

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'region' => trim((string) ($_GET['region'] ?? '')),
    'area' => trim((string) ($_GET['area'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '',
];

$rows = employees_all_for_export($pdo, $filters);

// Override the JSON headers api.php already sent.
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="Employees-' . server_today() . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, ['Code', 'Name', 'Phone', 'Email', 'Region', 'Area', 'Status', 'Visits', 'Joined', 'Last login']);

foreach ($rows as $d) {
    fputcsv($out, [
        $d['code'] ?? '',
        $d['name'],
        $d['phone'],
        $d['email'] ?? '',
        $d['region'] ?? '',
        $d['area'] ?? '',
        $d['is_active'] ? 'Active' : 'Inactive',
        (int) $d['visit_count'],
        $d['created_at'],
        $d['last_login_at'] ?? '',
    ]);
}
fclose($out);
exit;
