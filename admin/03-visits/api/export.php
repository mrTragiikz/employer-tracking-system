<?php
/**
 * admin/03-visits/api/export.php - the current (filtered) visits list, as a
 * professional PDF (default) or a raw CSV.
 *
 * GET: same filter params as the section page - scope, employee, month, q -
 *      plus format = pdf (default) | csv.
 *
 *  - format=pdf  a client-ready report: brown letterhead, the filter it was
 *                run for, the 5 headline numbers as tinted icon cards, a
 *                "by employee" breakdown, and the full visit table (shop,
 *                time window, time at shop, drive from the previous stop,
 *                GPS). Matches the look of the Routes & Map report
 *                (admin/05-routes-map/api/export.php) and the Employee Audit
 *                PDF (admin/02-employees/api/export-pdf.php).
 *  - format=csv  the flat row list (legacy).
 *
 * The hand-written PDF class (includes/pdf.php) has text/line/rect/image plus
 * roundRect/circle - no gradients, no custom fonts, no raster icons.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate
$me = require_admin();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/02-employees/_repo.php'; // hm() / fmt_km()
require dirname(__DIR__, 3) . '/includes/distance.php';  // heal_missing_visit_hops()
require dirname(__DIR__, 3) . '/includes/pdf.php';

$monthF = (string) ($_GET['month'] ?? '');
if ($monthF !== '' && (!preg_match('/^\d{4}-\d{2}$/', $monthF) || $monthF > substr(server_today(), 0, 7))) {
    $monthF = '';
}

$scopeIn = (string) ($_GET['scope'] ?? 'all');
$filters = [
    'scope'    => in_array($scopeIn, ['all', 'today'], true) ? $scopeIn : 'all',
    'employee' => isset($_GET['employee']) ? (int) $_GET['employee'] : 0,
    'month'    => $monthF,
    'q'        => trim((string) ($_GET['q'] ?? '')),
];
$format = (($_GET['format'] ?? 'pdf') === 'csv') ? 'csv' : 'pdf';

// Self-heal so every visit's drive distance is real, not a stuck 0.
heal_missing_visit_hops($pdo, $filters['employee'] ?: null);

$rows = visits_all_for_export($pdo, $filters);

$employeeName = null;
if ($filters['employee'] > 0) {
    $st = $pdo->prepare("SELECT name, code FROM users WHERE id = ? AND role = 'employee'");
    $st->execute([$filters['employee']]);
    if ($e = $st->fetch()) {
        $employeeName = $e['name'] . ($e['code'] ? ' (' . $e['code'] . ')' : '');
    }
}

/* ===================================================================== CSV */
if ($format === 'csv') {
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="visits-' . server_today() . '.csv"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Arrived', 'Left', 'Employee', 'Code', 'Region', 'Shop', 'GPS', 'Dwell (min)', 'Distance (km)', 'Remark']);
    foreach ($rows as $r) {
        $arrived = new DateTimeImmutable($r['arrived_at']);
        $left    = $r['left_at'] !== null ? new DateTimeImmutable($r['left_at']) : null;
        fputcsv($out, [
            $r['work_date'],
            $arrived->format('H:i'),
            $left !== null ? $left->format('H:i') : '',
            $r['employee_name'],
            $r['employee_code'] ?? '',
            $r['region'] ?? '',
            $r['shop_name'],
            $r['lat'] !== null && $r['lng'] !== null ? $r['lat'] . ',' . $r['lng'] : '',
            $r['dwell_seconds'] !== null ? number_format($r['dwell_seconds'] / 60, 0) : '',
            $r['hop_seconds'] !== null ? number_format((float) $r['hop_road_km'], 2) : '',
            $r['remark'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

/* ===================================================================== PDF */

// ---- totals over exactly the rows printed below ----------------------
$totVisits   = count($rows);
$totDwell    = 0; $dwellN = 0;
$totDriveKm  = 0.0; $totDriveSec = 0;
$days        = [];
$byEmp       = []; // name => [visits, dwell_secs, drive_km, drive_secs, code]
foreach ($rows as $r) {
    if ($r['dwell_seconds'] !== null) { $totDwell += (int) $r['dwell_seconds']; $dwellN++; }
    $hopKnown = $r['hop_seconds'] !== null;
    if ($hopKnown) {
        $totDriveKm  += (float) $r['hop_road_km'];
        $totDriveSec += (int) $r['hop_seconds'];
    }
    $days[$r['work_date']] = true;

    $k = $r['employee_name'];
    if (!isset($byEmp[$k])) {
        $byEmp[$k] = ['visits' => 0, 'dwell' => 0, 'km' => 0.0, 'code' => $r['employee_code'] ?? ''];
    }
    $byEmp[$k]['visits']++;
    if ($r['dwell_seconds'] !== null) $byEmp[$k]['dwell'] += (int) $r['dwell_seconds'];
    if ($hopKnown) $byEmp[$k]['km'] += (float) $r['hop_road_km'];
}
uasort($byEmp, static fn($a, $b) => $b['visits'] <=> $a['visits']);

$avgDwell = $dwellN > 0 ? (int) round($totDwell / $dwellN) : 0;

// ---- palette (matches admin/05-routes-map/api/export.php) ------------
// This report is A4 LANDSCAPE: the "every visit" table has 7 columns
// (date, employee, shop, time window, at shop, drive, GPS) and a portrait
// page cannot give the employee/shop names and the GPS pair enough room
// without them running into each other.
const V_PAGE_W  = 841.89;
const V_PAGE_H  = 595.28;
const V_MARGIN  = 44.0;
const V_RIGHT   = V_PAGE_W - 44.0;
const V_BROWN   = [107, 66, 35];
const V_BROWN_DK = [74, 44, 22];
const V_MUTED   = [120, 114, 106];
const V_INK     = [26, 24, 22];
const V_LINE    = [228, 222, 212];
const V_CREAM   = [252, 250, 246];
const V_WHITE   = [255, 255, 255];
const V_ZEBRA   = [250, 248, 244];
const V_GOOD    = [39, 121, 74];

$VTINT = [
    'green'  => [[46, 143, 92],  [233, 244, 238]],
    'amber'  => [[201, 132, 54], [251, 241, 229]],
    'blue'   => [[52, 116, 196], [232, 240, 251]],
    'purple' => [[123, 90, 196], [239, 234, 251]],
    'pink'   => [[201, 72, 132], [251, 234, 241]],
    'brown'  => [[138, 90, 46],  [244, 237, 227]],
];

// ---- period / scope label ------------------------------------------
if ($filters['scope'] === 'today') {
    $periodLabel = (new DateTimeImmutable(server_today()))->format('j M Y');
    $scopeCap    = "Today's visits";
} elseif ($filters['month'] !== '') {
    $periodLabel = (new DateTimeImmutable($filters['month'] . '-01'))->format('F Y');
    $scopeCap    = 'One month';
} else {
    $periodLabel = 'All time';
    $scopeCap    = 'Every recorded visit';
}
$subParts = [$scopeCap];
if ($employeeName)          $subParts[] = $employeeName;
if ($filters['q'] !== '')   $subParts[] = 'Search: "' . $filters['q'] . '"';
$subtitle = implode('   .   ', $subParts);

$pdf = new TrackPdf(V_PAGE_W, V_PAGE_H);
$W = $pdf->pageWidth();
$pageBottom = $pdf->pageHeight() - 46;

$pdf->runningHeaderFooter(function (TrackPdfPageWriter $w, int $pi, int $pc): void {
    $ry = $w->pageHeight() - 34;
    $w->line(V_MARGIN, $ry, V_RIGHT, $ry, 0.8, V_BROWN);
    $w->text(V_MARGIN, $ry + 11, 'Rajdoot Visits Report', ['size' => 7, 'color' => V_MUTED]);
    $pg = 'Page ' . ($pi + 1) . ' of ' . $pc;
    $w->text(V_RIGHT - $w->textWidth($pg, 7, true), $ry + 11, $pg, ['size' => 7, 'bold' => true, 'color' => V_BROWN]);
    $credit = 'Software by Prabin Sharma  -  sharmaprabin160@gmail.com';
    $cx = (V_RIGHT + V_MARGIN) / 2 - $w->textWidth($credit, 7) / 2;
    $w->text($cx, $ry + 22, $credit, ['size' => 7, 'color' => [150, 143, 133]]);
});

$pdf->addPage();

// ---- letterhead ---------------------------------------------------
$bandH = 74.0;
$pdf->rect(0, 0, $W, 14, V_BROWN);
$pdf->roundRect(0, 0, $W, $bandH, 14, V_BROWN);
$mx = $W - 150; $my = $bandH - 8;
foreach ([[0, 34], [46, 22], [88, 40], [130, 26]] as [$dx, $peak]) {
    $pdf->line($mx + $dx, $my, $mx + $dx + 23, $my - $peak, 1.2, V_BROWN_DK);
    $pdf->line($mx + $dx + 23, $my - $peak, $mx + $dx + 46, $my, 1.2, V_BROWN_DK);
}
$lx = V_MARGIN; $ly = 22;
$pdf->line($lx, $ly + 16, $lx + 10, $ly, 1.6, V_WHITE);
$pdf->line($lx + 10, $ly, $lx + 20, $ly + 16, 1.6, V_WHITE);
$pdf->line($lx + 4, $ly + 16, $lx + 16, $ly + 16, 1.6, V_WHITE);
$pdf->text($lx + 28, $ly + 2, 'RAJDOOT', ['size' => 18, 'bold' => true, 'color' => V_WHITE]);
$pdf->text($lx + 28, $ly + 20, 'Field Visits Report', ['size' => 9, 'color' => [235, 223, 208]]);

$badgeW = 138.0; $badgeH = 30.0; $bx = V_RIGHT - $badgeW; $by = 16.0;
$pdf->roundRect($bx, $by, $badgeW, $badgeH, 7, [93, 57, 30]);
$pdf->text($bx + 12, $by + 11, $periodLabel, ['size' => 11, 'bold' => true, 'color' => V_WHITE]);
$pdf->text($bx + 12, $by + 23, $totVisits . ' visit' . ($totVisits === 1 ? '' : 's') . ' . ' . count($days) . ' day' . (count($days) === 1 ? '' : 's'),
    ['size' => 7, 'color' => [230, 216, 200]]);
$genAt = 'Generated ' . (new DateTimeImmutable(server_now()))->format('j M Y, g:i A');
$pdf->text(V_RIGHT - $pdf->textWidth($genAt, 6.5), $by + $badgeH + 10, $genAt, ['size' => 6.5, 'color' => [225, 214, 198]]);

// ---- filter card ------------------------------------------------
$cardY = $bandH + 14; $cardH = 40.0;
$pdf->roundRect(V_MARGIN, $cardY, V_RIGHT - V_MARGIN, $cardH, 9, V_CREAM, V_LINE, 0.7);
$pdf->circle(V_MARGIN + 16, $cardY + $cardH / 2, 9, V_BROWN);
$pdf->circle(V_MARGIN + 16, $cardY + $cardH / 2, 2.6, V_WHITE);
$tx = V_MARGIN + 36;
$pdf->text($tx, $cardY + 15, 'Report filter', ['size' => 7, 'bold' => true, 'color' => V_MUTED]);
$pdf->text($tx, $cardY + 29, $subtitle, ['size' => 9.5, 'bold' => true, 'color' => V_INK]);

$y = $cardY + $cardH + 22;

/** section header: brown dot + title + rule + right caption */
$section = function (float $y, string $title, string $cap = '') use ($pdf): float {
    $pdf->circle(V_MARGIN + 4, $y - 3, 4, V_BROWN);
    $pdf->text(V_MARGIN + 14, $y, $title, ['size' => 11.5, 'bold' => true, 'color' => V_INK]);
    $lineX = V_MARGIN + 14 + $pdf->textWidth($title, 11.5, true) + 10;
    $lineEnd = V_RIGHT;
    if ($cap !== '') {
        $cw = $pdf->textWidth($cap, 7);
        $lineEnd = V_RIGHT - $cw - 8;
        $pdf->text(V_RIGHT - $cw, $y - 2, $cap, ['size' => 7, 'color' => V_MUTED]);
    }
    if ($lineEnd > $lineX) $pdf->line($lineX, $y - 3, $lineEnd, $y - 3, 0.6, V_LINE);
    return $y + 20;
};

/** fact card grid, mockup style (rounded tinted cards + icon circle) */
$factGrid = function (float $y, array $facts, int $perRow = 5) use ($pdf, $VTINT): float {
    $gap = 14.0; $pad = 14.0;
    $blockW = V_RIGHT - V_MARGIN;
    $cardW = ($blockW - $gap * ($perRow - 1)) / $perRow;
    $cardH = 58.0;
    foreach ($facts as $i => $f) {
        [$label, $val, $tintKey, $hint] = array_pad($f, 4, null);
        $tint = $VTINT[$tintKey ?? 'brown'] ?? $VTINT['brown'];
        $col = $i % $perRow; $row = intdiv($i, $perRow);
        $x = V_MARGIN + $col * ($cardW + $gap);
        $cy = $y + $row * ($cardH + 12);
        $pdf->roundRect($x, $cy, $cardW, $cardH, 8, $tint[1], V_LINE, 0.6);
        $icR = 6.0;
        $pdf->circle($x + $pad + $icR, $cy + 15, $icR, $tint[0]);
        $pdf->circle($x + $pad + $icR, $cy + 15, 1.9, V_WHITE);
        $avail = $cardW - $pad * 2;
        $vSize = 12.0;
        while ($vSize > 8 && $pdf->textWidth((string) $val, $vSize, true) > $avail) { $vSize -= 0.5; }
        $pdf->text($x + $pad, $cy + 32, (string) $val, ['size' => $vSize, 'bold' => true, 'color' => V_INK]);
        $pdf->text($x + $pad, $cy + 44, strtoupper($label), ['size' => 6.0, 'bold' => true, 'color' => V_MUTED]);
        if ($hint) $pdf->text($x + $pad, $cy + 53, $hint, ['size' => 5.6, 'color' => V_MUTED]);
    }
    $rows = intdiv(count($facts) - 1, $perRow) + 1;
    return $y + $rows * ($cardH + 12) + 8;
};

/**
 * Ruled table with a rounded brown header. `cols` is a list of
 * ['label'=>, 'width'=>, 'align'=>'left'|'right']. Throws if the column
 * widths overrun the page, or a header label is wider than its own column -
 * both are real bugs that have shipped before (see the attendance PDF).
 */
$table = function (float $y, array $cols, array $rows) use ($pdf, $pageBottom): float {
    $rowH = 22.0; $headH = 24.0; $padL = 12.0; $rightPad = 12.0;
    $tableW = V_RIGHT - V_MARGIN;

    $x = V_MARGIN; $xs = [];
    foreach ($cols as $c) { $xs[] = $x; $x += $c['width']; }
    if ($x > V_RIGHT + 0.01) {
        throw new RuntimeException(sprintf('visits PDF table: columns sum to %.1fpt, overflowing the page by %.1fpt', $x - V_MARGIN, $x - V_RIGHT));
    }
    foreach ($cols as $c) {
        $need = $pdf->textWidth(strtoupper($c['label']), 7.5, true);
        if ($need > $c['width'] - $padL - 2) {
            throw new RuntimeException(sprintf('visits PDF table: header "%s" needs %.1fpt but its column is only %.1fpt', $c['label'], $need, $c['width']));
        }
    }

    $drawHead = function () use ($pdf, $cols, $xs, $padL, $rightPad, $tableW, $headH, &$y): void {
        $pdf->roundRect(V_MARGIN, $y, $tableW, $headH, 6, V_BROWN);
        $pdf->rect(V_MARGIN, $y + $headH - 6, $tableW, 6, V_BROWN);
        $ty = $y + $headH / 2 - 4;
        foreach ($cols as $i => $c) {
            $tx = $xs[$i] + $padL;
            if (($c['align'] ?? 'left') === 'right') {
                $tx = $xs[$i] + $c['width'] - $rightPad - $pdf->textWidth(strtoupper($c['label']), 7.5, true);
            }
            $pdf->text($tx, $ty, strtoupper($c['label']), ['size' => 7.5, 'bold' => true, 'color' => V_WHITE]);
        }
        $y += $headH;
    };
    if ($y + $headH + $rowH > $pageBottom) { $pdf->addPage(); $y = 46; }
    $drawHead();
    $z = 0;
    foreach ($rows as $row) {
        if ($y + $rowH > $pageBottom) { $pdf->addPage(); $y = 46; $drawHead(); $z = 0; }
        if ($z % 2 === 1) $pdf->rect(V_MARGIN, $y, $tableW, $rowH, V_ZEBRA);
        $z++;
        $ty = $y + $rowH / 2 - 3.5;
        foreach ($row as $j => $val) {
            $tx = $xs[$j] + $padL;
            if (($cols[$j]['align'] ?? 'left') === 'right') {
                $tx = $xs[$j] + $cols[$j]['width'] - $rightPad - $pdf->textWidth((string) $val, 8.5);
            }
            $pdf->text($tx, $ty, (string) $val, ['size' => 8.5, 'bold' => $j === 0, 'color' => V_INK]);
        }
        $y += $rowH;
    }
    $pdf->line(V_MARGIN, $y, V_RIGHT, $y, 0.6, V_LINE);
    return $y + 20;
};

// ---- summary cards -----------------------------------------------
$y = $section($y, 'Summary', 'All figures describe the filtered list below');
$y = $factGrid($y, [
    ['Total visits',   (string) $totVisits, 'green',  count($days) . ' working day' . (count($days) === 1 ? '' : 's')],
    ['Employees',      (string) count($byEmp), 'blue', 'covered by this list'],
    ['Time at shops',  hm($totDwell), 'purple', 'sum of measured dwell'],
    ['Avg. time / visit', hm($avgDwell, '-'), 'pink', 'across measured visits'],
    ['Drive between stops', fmt_km($totDriveKm), 'brown', hm($totDriveSec) . ' driving'],
], 5);

// ---- by employee ------------------------------------------------
if (count($byEmp) > 1) {
    $y = $section($y, 'By employee', 'Visits . time at shops . drive between stops');
    $empRows = [];
    foreach ($byEmp as $name => $agg) {
        $empRows[] = [
            $name . ($agg['code'] ? '  (' . $agg['code'] . ')' : ''),
            (string) $agg['visits'],
            hm($agg['dwell']),
            fmt_km($agg['km']),
        ];
    }
    $y = $table($y, [
        ['label' => 'Employee',            'width' => 358],
        ['label' => 'Visits',              'width' => 90, 'align' => 'right'],
        ['label' => 'Time at shops',       'width' => 148, 'align' => 'right'],
        ['label' => 'Drive between stops', 'width' => 157.89, 'align' => 'right'],
    ], $empRows);
}

// ---- full visit list ------------------------------------------
if ($rows) {
    $y = $section($y, 'Every visit', 'Newest first');
    $ll = static fn($a, $b): string => ($a === null || $b === null) ? '-' : number_format((float) $a, 5) . ', ' . number_format((float) $b, 5);
    $tableRows = [];
    foreach ($rows as $r) {
        $arrived = new DateTimeImmutable($r['arrived_at']);
        $left    = $r['left_at'] !== null ? new DateTimeImmutable($r['left_at']) : null;
        $win = $arrived->format('g:iA') . ($left ? ' - ' . $left->format('g:iA') : ' - now');
        $atShop = $r['dwell_seconds'] !== null ? hm((int) $r['dwell_seconds']) : 'in shop';
        $drive  = $r['hop_seconds'] !== null
            ? fmt_km((float) $r['hop_road_km']) . ((int) $r['hop_seconds'] > 0 ? ', ' . hm((int) $r['hop_seconds']) : '')
            : 'pending';
        $tableRows[] = [
            $arrived->format('j M'),
            $r['employee_name'] . ($r['employee_code'] ? ' (' . $r['employee_code'] . ')' : ''),
            $r['shop_name'],
            $win,
            $atShop,
            $drive,
            $ll($r['lat'], $r['lng']),
        ];
    }
    $y = $table($y, [
        ['label' => 'Date',        'width' => 54],
        ['label' => 'Employee',    'width' => 148],
        ['label' => 'Shop',        'width' => 150],
        ['label' => 'Time window', 'width' => 118],
        ['label' => 'At shop',     'width' => 66, 'align' => 'right'],
        ['label' => 'Drive here',  'width' => 108, 'align' => 'right'],
        ['label' => 'Coordinates', 'width' => 109.89],
    ], $tableRows);

    $pdf->text(V_MARGIN, $y - 4,
        '"Drive here" is the road distance and driving time from the previous stop; "pending" means it is still being computed.',
        ['size' => 7, 'color' => V_MUTED]);
} else {
    $pdf->text(V_MARGIN, $y, 'No visits match this filter.', ['size' => 10, 'color' => V_MUTED]);
}

$fname = 'Visits-' . ($filters['scope'] === 'today' ? server_today()
        : ($filters['month'] !== '' ? $filters['month'] : 'all-time')) . '.pdf';
$pdf->output($fname);
