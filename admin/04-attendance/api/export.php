<?php
/**
 * admin/04-attendance/api/export.php - CSV of the current (filtered) attendance list.
 *
 * GET: same filter params as the section page (month, employee, status, q, range, on).
 * Streams a text/csv download.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate
$me = require_admin();
require dirname(__DIR__) . '/_repo.php';

$m       = att_month($_GET['month'] ?? null);
$employeeF = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$statusF = in_array($_GET['status'] ?? '', ['open', 'closed', 'incomplete'], true) ? $_GET['status'] : '';
$q       = trim((string) ($_GET['q'] ?? ''));

$rangeF = in_array($_GET['range'] ?? 'month', ['month', 'today', 'yesterday', 'week', '30d', 'on'], true)
    ? ($_GET['range'] ?? 'month') : 'month';
$onF = (string) ($_GET['on'] ?? '');
if ($onF !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onF) || $onF > server_today())) {
    $onF = '';
}
if ($rangeF === 'on' && $onF === '') {
    $rangeF = 'month';
}
[$listFrom, $listTo] = att_range($m, $rangeF === 'on' ? null : $rangeF, $rangeF === 'on' ? $onF : null);

$rows = att_all_for_export($pdo, [
    'from'     => $listFrom,
    'to'       => $listTo,
    'employee' => $employeeF,
    'status'   => $statusF,
    'q'        => $q,
]);

// Override the JSON headers api.php already sent.
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="attendance-' . server_today() . '.csv"');
header('X-Content-Type-Options: nosniff');

$out = fopen('php://output', 'w');
fputcsv($out, ['Date', 'Employee', 'Code', 'Check-in', 'Check-out', 'Total (hrs)', 'Shop time (hrs)', 'Road time (hrs)', 'Road KM', 'Visits', 'Status']);

foreach ($rows as $r) {
    $checkIn  = new DateTimeImmutable($r['check_in_at']);
    $checkOut = $r['check_out_at'] !== null ? new DateTimeImmutable($r['check_out_at']) : null;
    // The day's audit totals (shop/road time, road km) are computed only at
    // check-out; for a still-open day they are 0 by column default, not a
    // real measurement - leave those cells blank rather than exporting "0.00".
    $dayClosed = $checkOut !== null;
    fputcsv($out, [
        $r['work_date'],
        $r['employee_name'],
        $r['employee_code'] ?? '',
        $checkIn->format('H:i'),
        $dayClosed ? $checkOut->format('H:i') : '',
        $dayClosed && $r['total_seconds'] !== null ? number_format($r['total_seconds'] / 3600, 2) : '',
        $dayClosed ? number_format($r['shop_seconds'] / 3600, 2) : '',
        $dayClosed ? number_format($r['road_seconds'] / 3600, 2) : '',
        $dayClosed ? number_format((float) $r['road_km'], 2) : '',
        $r['visit_count'],
        ucfirst($r['status']),
    ]);
}
fclose($out);
exit;
