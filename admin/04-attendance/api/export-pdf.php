<?php
/**
 * admin/04-attendance/api/export-pdf.php - the "Export PDF" option on the
 * Attendance section's Export button. Streams a real .pdf report covering
 * whatever the admin currently has filtered on that page - same
 * month/employee/status/q/range/on query params the page itself and its
 * existing CSV export already use, so the PDF always matches exactly what
 * is on screen (a specific date, a specific month, a specific Employee, or
 * the whole system across every Employee) with nothing extra to configure.
 *
 * GET: month, employee, status, q, range, on - identical set/validation to
 * attendance.php and api/export.php (the CSV export).
 *
 * See admin/02-employees/api/export-pdf.php for the sibling "one Employee's
 * own statement" PDF and includes/pdf.php for why this is a hand-written
 * PDF (no Composer/extension/external binary in this project).
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
$me = require_admin();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/02-employees/_repo.php'; // hm()
require dirname(__DIR__, 3) . '/includes/pdf.php';

$m         = att_month($_GET['month'] ?? null);
$employeeF = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$statusF   = in_array($_GET['status'] ?? '', ['open', 'closed', 'incomplete'], true) ? $_GET['status'] : '';
$q         = trim((string) ($_GET['q'] ?? ''));

$rangeF = in_array($_GET['range'] ?? 'month', ['month', 'today', 'yesterday', 'week', '30d', 'on'], true)
    ? ($_GET['range'] ?? 'month') : 'month';
$onF = (string) ($_GET['on'] ?? '');
if ($onF !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onF) || $onF > server_today())) {
    $onF = '';
}
if ($rangeF === 'on' && $onF === '') {
    $rangeF = 'month';
}
[$listFrom, $listTo, $rangeLabel] = att_range($m, $rangeF === 'on' ? null : $rangeF, $rangeF === 'on' ? $onF : null);

$employeeName = null;
if ($employeeF) {
    $employeeName = employee_find($pdo, $employeeF)['name'] ?? null;
}

$rows = att_all_for_export($pdo, [
    'from'     => $listFrom,
    'to'       => $listTo,
    'employee' => $employeeF,
    'status'   => $statusF,
    'q'        => $q,
]);

/**
 * Aggregate the SAME rows the report table lists, rather than re-querying
 * att_stats() (which is scoped to the whole selected MONTH regardless of
 * the range/status/search filters) - the summary numbers at the top of the
 * PDF must describe exactly the rows printed below them, whatever the
 * admin actually filtered to (a single day, a status, a name search), not
 * a different, wider window than what's on the page.
 */
function attendance_export_totals(array $rows): array
{
    $present = count($rows);
    $incomplete = 0;
    $totalSecs = 0;
    $closedCount = 0;
    $roadKm = 0.0;
    $visits = 0;
    $employees = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'incomplete') {
            $incomplete++;
        }
        if ($r['total_seconds'] !== null) {
            $totalSecs += (int) $r['total_seconds'];
            $closedCount++;
        }
        $roadKm += (float) ($r['road_km'] ?? 0);
        $visits += (int) $r['visit_count'];
        $employees[$r['employee_name']] = true;
    }
    return [
        'days'        => $present,
        'incomplete'  => $incomplete,
        'employees'   => count($employees),
        'visits'      => $visits,
        'road_km'     => $roadKm,
        'avg_secs'    => $closedCount > 0 ? (int) round($totalSecs / $closedCount) : 0,
    ];
}

// ---- Layout constants (points; A4 = 595.28 x 841.89) --------------------
const PDF_MARGIN   = 42.0;
const PDF_RIGHT    = 595.28 - 42.0;
const PDF_BROWN    = [138, 90, 46];
const PDF_BROWN_DK = [96, 62, 30];
const PDF_MUTED    = [122, 116, 108];
const PDF_INK      = [28, 26, 24];
const PDF_LINE     = [223, 216, 204];
const PDF_CARD_BG  = [250, 247, 242];
const PDF_ZEBRA    = [247, 244, 238];
const PDF_WHITE    = [255, 255, 255];

/** Same letterhead treatment as the Employee statement PDF - see that file's pdf_header() for the design rationale. */
function pdf_header(TrackPdf $pdf, string $subtitle, string $periodLabel): float
{
    $bandH = 54.0;
    $pdf->rect(0, 0, $pdf->pageWidth(), $bandH, PDF_BROWN);
    $pdf->rect(0, $bandH - 3, $pdf->pageWidth(), 3, PDF_BROWN_DK);

    $pdf->text(PDF_MARGIN, 16, 'RAJDOOT', ['size' => 17, 'bold' => true, 'color' => PDF_WHITE]);
    $pdf->text(PDF_MARGIN, 36, 'Attendance Report', ['size' => 9.5, 'color' => [232, 220, 205]]);

    $pdf->text(PDF_RIGHT - $pdf->textWidth($periodLabel, 13, true), 16, $periodLabel, ['size' => 13, 'bold' => true, 'color' => PDF_WHITE]);
    $genAt = 'Generated ' . (new DateTimeImmutable(server_now()))->format('j M Y, g:i A');
    $pdf->text(PDF_RIGHT - $pdf->textWidth($genAt, 8), 36, $genAt, ['size' => 8, 'color' => [232, 220, 205]]);

    $y = $bandH + 20;
    $pdf->text(PDF_MARGIN, $y, $subtitle, ['size' => 11, 'color' => PDF_MUTED]);
    $y += 16;
    $pdf->line(PDF_MARGIN, $y, PDF_RIGHT, $y, 1, PDF_LINE);
    return $y + 20;
}

function pdf_section(TrackPdf $pdf, float $y, string $title): float
{
    $pdf->rect(PDF_MARGIN, $y - 9, 3, 12, PDF_BROWN);
    $pdf->text(PDF_MARGIN + 9, $y, strtoupper($title), ['size' => 10.5, 'bold' => true, 'color' => PDF_INK]);
    return $y + 20;
}

function pdf_fact(TrackPdf $pdf, float $x, float $y, float $w, string $label, string $value): void
{
    $h = 40.0;
    $pdf->rect($x, $y, $w, $h, PDF_CARD_BG);
    $pdf->text($x + 10, $y + 11, strtoupper($label), ['size' => 7.5, 'bold' => true, 'color' => PDF_MUTED]);
    $pdf->text($x + 10, $y + 27, $value, ['size' => 13, 'bold' => true, 'color' => PDF_INK]);
}

function pdf_fact_grid(TrackPdf $pdf, float $y, array $facts, int $perRow = 3): float
{
    $gap = 10.0;
    $totalW = PDF_RIGHT - PDF_MARGIN;
    $cardW = ($totalW - $gap * ($perRow - 1)) / $perRow;
    $cardH = 40.0;
    $rowGap = 10.0;

    foreach ($facts as $i => [$label, $val]) {
        $col = $i % $perRow;
        $row = intdiv($i, $perRow);
        $x = PDF_MARGIN + $col * ($cardW + $gap);
        pdf_fact($pdf, $x, $y + $row * ($cardH + $rowGap), $cardW, $label, $val);
    }
    $rows = intdiv(count($facts) - 1, $perRow) + 1;
    return $y + $rows * ($cardH + $rowGap) + 6;
}

/** Same ruled/zebra table as the Employee statement PDF - see that file's pdf_table() for the design rationale. */
function pdf_table(TrackPdf $pdf, float $y, array $cols, array $rows, float $pageBottom): float
{
    $rowH = 18.0;
    $headH = 20.0;
    $rightPad = 10.0;
    $tableW = PDF_RIGHT - PDF_MARGIN;

    $x = PDF_MARGIN;
    $xs = [];
    foreach ($cols as $c) {
        $xs[] = $x;
        $x += $c['width'];
    }
    if ($x > PDF_RIGHT + 0.01) {
        throw new RuntimeException(sprintf(
            'pdf_table(): column widths sum to %.2fpt, which overflows the page by %.2fpt (right margin is at %.2fpt)',
            $x - PDF_MARGIN, $x - PDF_RIGHT, PDF_RIGHT
        ));
    }
    // Second, DIFFERENT overflow to guard against: a column's own HEADER
    // LABEL text can be wider than that column, even when every column's
    // width sums correctly - a real bug hit twice (this file's "Status"
    // column, and the Employee statement PDF's "Distance"/"Coordinates"
    // headers) where the total table width was exactly right but one
    // header's own text still ran past ITS column (and in this case past
    // the page's own right margin, since it was the last column). Checking
    // total width alone does not catch this - each column's own text width
    // must be checked against its own available space.
    foreach ($cols as $c) {
        $avail = $c['width'] - $rightPad;
        $labelW = $pdf->textWidth(strtoupper($c['label']), 8, true);
        if ($labelW > $avail + 0.01) {
            throw new RuntimeException(sprintf(
                'pdf_table(): header label "%s" needs %.2fpt but its column is only %.2fpt wide (after padding)',
                $c['label'], $labelW, $avail
            ));
        }
    }

    $drawHeader = function () use ($pdf, $cols, $xs, $rightPad, $tableW, $headH, &$y): void {
        $pdf->rect(PDF_MARGIN, $y, $tableW, $headH, PDF_BROWN);
        $textY = $y + $headH / 2 - 4;
        foreach ($cols as $i => $c) {
            $tx = $xs[$i] + 8;
            if (($c['align'] ?? 'left') === 'right') {
                $tx = $xs[$i] + $c['width'] - $rightPad - $pdf->textWidth($c['label'], 8, true);
            }
            $pdf->text($tx, $textY, strtoupper($c['label']), ['size' => 8, 'bold' => true, 'color' => PDF_WHITE]);
        }
        $y += $headH;
    };

    if ($y + $headH + $rowH > $pageBottom) {
        $pdf->addPage();
        $y = 40;
    }
    $drawHeader();

    $zebra = 0;
    foreach ($rows as $row) {
        if ($y + $rowH > $pageBottom) {
            $pdf->addPage();
            $y = 40;
            $drawHeader();
            $zebra = 0;
        }
        if ($zebra % 2 === 1) {
            $pdf->rect(PDF_MARGIN, $y, $tableW, $rowH, PDF_ZEBRA);
        }
        $zebra++;
        $textY = $y + $rowH / 2 - 3.5;
        foreach ($row as $j => $val) {
            $tx = $xs[$j] + 8;
            if (($cols[$j]['align'] ?? 'left') === 'right') {
                $tx = $xs[$j] + $cols[$j]['width'] - $rightPad - $pdf->textWidth($val, 9);
            }
            $pdf->text($tx, $textY, $val, ['size' => 9, 'color' => PDF_INK]);
        }
        $y += $rowH;
    }
    $pdf->line(PDF_MARGIN, $y, PDF_RIGHT, $y, 0.75, PDF_LINE);
    return $y + 18;
}

$pdf = new TrackPdf();
$pageBottom = $pdf->pageHeight() - 40;

$pdf->runningHeaderFooter(function (TrackPdfPageWriter $w, int $pageIndex, int $pageCount): void {
    $ruleY = $w->pageHeight() - 40;
    $w->line(PDF_MARGIN, $ruleY, PDF_RIGHT, $ruleY, 1, PDF_BROWN);

    $lineY1 = $ruleY + 11;
    $w->text(PDF_MARGIN, $lineY1, 'Rajdoot - Attendance Report', ['size' => 7.5, 'color' => PDF_MUTED]);
    $page = 'Page ' . ($pageIndex + 1) . ' of ' . $pageCount;
    $w->text(PDF_RIGHT - $w->textWidth($page, 7.5, true), $lineY1, $page, ['size' => 7.5, 'bold' => true, 'color' => PDF_BROWN]);

    $lineY2 = $lineY1 + 13;
    $credit = 'Software by Prabin Sharma  -  sharmaprabin160@gmail.com';
    $centerX = (PDF_RIGHT + PDF_MARGIN) / 2 - $w->textWidth($credit, 7.5) / 2;
    $w->text($centerX, $lineY2, $credit, ['size' => 7.5, 'color' => [155, 148, 138]]);
});

$pdf->addPage();

$periodLabel = (new DateTimeImmutable($listFrom))->format('j M Y') === (new DateTimeImmutable($listTo))->format('j M Y')
    ? (new DateTimeImmutable($listFrom))->format('j F Y')
    : (new DateTimeImmutable($listFrom))->format('j M Y') . ' - ' . (new DateTimeImmutable($listTo))->format('j M Y');

$subtitleParts = [$rangeLabel];
if ($employeeName) {
    $subtitleParts[] = $employeeName;
}
if ($statusF !== '') {
    $subtitleParts[] = ucfirst($statusF) . ' only';
}
if ($q !== '') {
    $subtitleParts[] = 'Search: "' . $q . '"';
}
$subtitle = implode('  |  ', $subtitleParts);

$y = pdf_header($pdf, $subtitle, $periodLabel);

$totals = attendance_export_totals($rows);
$y = pdf_section($pdf, $y, 'Summary');
$facts = [
    ['Attendance days', (string) $totals['days']],
    ['Incomplete days', (string) $totals['incomplete']],
    ['Employees covered', (string) $totals['employees']],
    ['Total visits', (string) $totals['visits']],
    ['Distance by road', number_format($totals['road_km'], 1) . ' km'],
    ['Avg. working time', hm($totals['avg_secs'], '-')],
];
$y = pdf_fact_grid($pdf, $y, $facts);

if ($rows) {
    $y = pdf_section($pdf, $y, 'Daily attendance');
    $tableRows = array_map(static function (array $r): array {
        $checkIn  = new DateTimeImmutable($r['check_in_at']);
        $checkOut = $r['check_out_at'] !== null ? new DateTimeImmutable($r['check_out_at']) : null;
        // road_km is a check-out-time audit figure; for an open day it is 0
        // by column default, not a measurement - show "-" until check-out.
        return [
            (new DateTimeImmutable($r['work_date']))->format('j M Y'),
            $r['employee_name'] . ($r['employee_code'] ? ' (' . $r['employee_code'] . ')' : ''),
            $checkIn->format('g:i A'),
            $checkOut !== null ? $checkOut->format('g:i A') : 'open',
            $r['total_seconds'] !== null ? hm((int) $r['total_seconds']) : '-',
            (string) (int) $r['visit_count'],
            $checkOut !== null ? number_format((float) ($r['road_km'] ?? 0), 1) . ' km' : '-',
            ucfirst($r['status']),
        ];
    }, $rows);
    $y = pdf_table($pdf, $y, [
        ['label' => 'Date', 'width' => 68],
        ['label' => 'Employee', 'width' => 125],
        ['label' => 'Check-in', 'width' => 55],
        ['label' => 'Check-out', 'width' => 55],
        ['label' => 'Hours', 'width' => 55, 'align' => 'right'],
        ['label' => 'Visits', 'width' => 42, 'align' => 'right'],
        ['label' => 'Distance', 'width' => 56.28, 'align' => 'right'],
        ['label' => 'Status', 'width' => 55],
    ], $tableRows, $pageBottom);
} else {
    $pdf->text(PDF_MARGIN, $y, 'No attendance records match this filter.', ['size' => 10, 'color' => PDF_MUTED]);
}

$fname = 'Attendance-' . $listFrom . '-to-' . $listTo . '.pdf';
$pdf->output($fname);
