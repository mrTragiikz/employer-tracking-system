<?php
/**
 * admin/08-evidence-photos/api/export.php - CSV of the current (filtered) photo list.
 *
 * GET: same filter params as the section page
 *      (range, on, Employee, kind, q).
 * Streams a text/csv download.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate
$me = require_admin();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/02-employees/_repo.php';   // latlng()

$rangeIn = (string) ($_GET['range'] ?? 'all');
$kindIn  = (string) ($_GET['kind'] ?? '');
$filters = [
    'range'  => in_array($rangeIn, ['all', 'today', '7d', '30d', 'on'], true) ? $rangeIn : 'all',
    'on'     => (string) ($_GET['on'] ?? ''),
    'employee' => isset($_GET['employee']) ? (int) $_GET['employee'] : 0,
    'kind'   => array_key_exists($kindIn, photo_kinds()) ? $kindIn : '',
    'q'      => trim((string) ($_GET['q'] ?? '')),
];

$rows  = photos_all_for_export($pdo, $filters);
$kinds = photo_kinds();

// Override the JSON headers api.php already sent.
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="evidence-photos-' . server_today() . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, ['Photo ID', 'Date', 'Time', 'Employee', 'Code', 'Region', 'Kind', 'Shop', 'GPS', 'Size (KB)']);

foreach ($rows as $r) {
    $taken = new DateTimeImmutable($r['taken_at']);
    fputcsv($out, [
        $r['id'],
        $r['work_date'],
        $taken->format('H:i'),
        $r['employee_name'],
        $r['employee_code'] ?? '',
        $r['region'] ?? '',
        $kinds[$r['photo_kind']] ?? $r['photo_kind'],
        $r['photo_kind'] === 'visit' ? ($r['shop_name'] ?? '') : '',
        latlng(isset($r['lat']) ? (float) $r['lat'] : null, isset($r['lng']) ? (float) $r['lng'] : null),
        number_format((int) $r['bytes'] / 1024, 0, '.', ''),
    ]);
}
fclose($out);
exit;
