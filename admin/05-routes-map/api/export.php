<?php
/**
 * admin/05-routes-map/api/export.php - one Employee's route for one day.
 *
 * GET: employee, date (same as the section page), format = pdf (default) | csv
 *
 *  - format=pdf  a professional single-page route report: letterhead, a real
 *                Mapbox static route map, the 5 headline numbers, and the full
 *                stop-by-stop timeline (drive distance/time, time at shop,
 *                GPS). Built for handing to a client as evidence of a day's
 *                fieldwork.
 *  - format=csv  the raw point list (legacy).
 *
 * The hand-written PDF class (includes/pdf.php) has text/line/rect/image plus
 * roundRect/circle - no gradients, no custom fonts. The look here matches the
 * Employee Audit PDF (admin/02-employees/api/export-pdf.php).
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate
$me = require_admin();
require dirname(__DIR__, 2) . '/02-employees/_repo.php'; // employee_day / employee_timeline / employee_statement / hm / fmt_km
require dirname(__DIR__, 3) . '/includes/distance.php';  // heal_missing_visit_hops()
require dirname(__DIR__, 3) . '/includes/pdf.php';
require dirname(__DIR__, 3) . '/includes/static_map.php';

$employeeId = isset($_GET['employee']) ? (int) $_GET['employee'] : 0;
$date = (string) ($_GET['date'] ?? server_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > server_today()) {
    $date = server_today();
}
$format = (($_GET['format'] ?? 'pdf') === 'csv') ? 'csv' : 'pdf';

$employee = null;
if ($employeeId > 0) {
    $st = $pdo->prepare(
        "SELECT id, name, code, region, area FROM users
          WHERE id = ? AND role = 'employee' AND deleted_at IS NULL"
    );
    $st->execute([$employeeId]);
    $employee = $st->fetch() ?: null;
}
if (!$employee) {
    header_remove('Content-Type');
    http_response_code(404);
    exit('Employee not found.');
}

// self-heal so drive distances are real, not 0
heal_missing_visit_hops($pdo, $employeeId);

$day       = employee_day($pdo, $employeeId, $date);
$statement = employee_statement($pdo, $employeeId, $date);
$timeline  = employee_timeline($pdo, $employeeId, $date);

// ordered points
$points = [];
$shopNo = 0;
foreach ($timeline as $t) {
    if ($t['lat'] === null || $t['lng'] === null) continue;
    if ($t['kind'] === 'visit') $shopNo++;
    $points[] = [
        'kind'     => $t['kind'],
        'no'       => $t['kind'] === 'visit' ? $shopNo : null,
        'label'    => $t['label'],
        'at'       => $t['at'],
        'left_at'  => $t['left_at'] ?? null,
        'lat'      => (float) $t['lat'],
        'lng'      => (float) $t['lng'],
        'acc'      => $t['accuracy'] ?? null,
        'hop_km'   => $t['kind'] === 'visit' ? (float) ($t['hop_km'] ?? 0) : null,
        'hop_secs' => $t['kind'] === 'visit' ? ($t['hop_secs'] ?? null) : null,
        'dwell'    => $t['kind'] === 'visit' ? ($t['dwell_secs'] ?? null) : null,
    ];
}

$dayClosed = $day && $day['check_out_at'] !== null;

// road km / time: closed day -> attendance audit; open day -> sum of hops
$roadKm = 0.0; $roadSec = 0;
if ($day) {
    if ($dayClosed) {
        $roadKm = (float) $day['road_km'];
        $roadSec = (int) $day['road_seconds'];
    } else {
        $agg = $pdo->prepare(
            'SELECT COALESCE(SUM(hop_road_km),0) km, COALESCE(SUM(hop_seconds),0) secs FROM visits WHERE attendance_id = ?'
        );
        $agg->execute([(int) $day['id']]);
        $r = $agg->fetch();
        $roadKm = (float) $r['km'];
        $roadSec = (int) $r['secs'];
    }
}
$totalSec = $day && $day['total_seconds'] !== null ? (int) $day['total_seconds'] : null;
$ci = $statement['first_check_in'] ?? null;
$co = $statement['last_check_out'] ?? null;
$avgKmh = $roadSec > 0 ? $roadKm / ($roadSec / 3600) : null;

/* ===================================================================== CSV */
if ($format === 'csv') {
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="route-' . ($employee['code'] ?? $employeeId) . '-' . $date . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee', 'Date', 'Seq', 'Point', 'Arrived', 'Left', 'Minutes at shop',
                   'Drive km (from prev stop)', 'Driving minutes', 'GPS']);
    foreach ($points as $i => $p) {
        $hopKnown = $p['hop_secs'] !== null;
        fputcsv($out, [
            $employee['name'],
            $date,
            $i + 1,
            $p['no'] !== null ? 'Shop #' . $p['no'] . ': ' . $p['label'] : $p['label'],
            $p['at']->format('H:i'),
            $p['left_at'] ? $p['left_at']->format('H:i') : '',
            $p['dwell'] !== null ? number_format((int) $p['dwell'] / 60, 0) : '',
            $hopKnown ? number_format((float) $p['hop_km'], 2) : '',
            $hopKnown ? number_format((int) $p['hop_secs'] / 60, 0) : '',
            $p['lat'] . ',' . $p['lng'],
        ]);
    }
    fclose($out);
    exit;
}

/* ===================================================================== PDF */
const R_MARGIN = 40.0;
const R_RIGHT  = 595.28 - 40.0;
const R_BROWN  = [107, 66, 35];
const R_BROWN_DK = [74, 44, 22];
const R_MUTED  = [120, 114, 106];
const R_INK    = [26, 24, 22];
const R_LINE   = [228, 222, 212];
const R_CREAM  = [252, 250, 246];
const R_WHITE  = [255, 255, 255];
const R_ZEBRA  = [250, 248, 244];
const R_GOOD   = [39, 121, 74];
const R_PENDING= [193, 122, 39];

$RTINT = [
    'green'  => [[46,143,92],  [233,244,238]],
    'amber'  => [[201,132,54], [251,241,229]],
    'blue'   => [[52,116,196], [232,240,251]],
    'purple' => [[123,90,196], [239,234,251]],
    'pink'   => [[201,72,132], [251,234,241]],
    'brown'  => [[138,90,46],  [244,237,227]],
];

$pdf = new TrackPdf();
$W = $pdf->pageWidth();

$pdf->runningHeaderFooter(function (TrackPdfPageWriter $w, int $pi, int $pc) use ($employee): void {
    $ry = $w->pageHeight() - 34;
    $w->line(R_MARGIN, $ry, R_RIGHT, $ry, 0.8, R_BROWN);
    $left = $employee['name'] . ($employee['code'] ? ' (' . $employee['code'] . ')' : '') . '  -  Rajdoot Route Report';
    $w->text(R_MARGIN, $ry + 11, $left, ['size' => 7, 'color' => R_MUTED]);
    $pg = 'Page ' . ($pi + 1) . ' of ' . $pc;
    $w->text(R_RIGHT - $w->textWidth($pg, 7, true), $ry + 11, $pg, ['size' => 7, 'bold' => true, 'color' => R_BROWN]);
    $credit = 'Software by Prabin Sharma  -  sharmaprabin160@gmail.com';
    $cx = (R_RIGHT + R_MARGIN) / 2 - $w->textWidth($credit, 7) / 2;
    $w->text($cx, $ry + 22, $credit, ['size' => 7, 'color' => [150, 143, 133]]);
});

$pdf->addPage();

// ---- letterhead --------------------------------------------------------
$bandH = 74.0;
$pdf->rect(0, 0, $W, 14, R_BROWN);
$pdf->roundRect(0, 0, $W, $bandH, 14, R_BROWN);
$mx = $W - 150; $my = $bandH - 8;
foreach ([[0,34],[46,22],[88,40],[130,26]] as [$dx,$peak]) {
    $pdf->line($mx+$dx, $my, $mx+$dx+23, $my-$peak, 1.2, R_BROWN_DK);
    $pdf->line($mx+$dx+23, $my-$peak, $mx+$dx+46, $my, 1.2, R_BROWN_DK);
}
$lx = R_MARGIN; $ly = 22;
$pdf->line($lx, $ly+16, $lx+10, $ly, 1.6, R_WHITE);
$pdf->line($lx+10, $ly, $lx+20, $ly+16, 1.6, R_WHITE);
$pdf->line($lx+4, $ly+16, $lx+16, $ly+16, 1.6, R_WHITE);
$pdf->text($lx+28, $ly+2, 'RAJDOOT', ['size' => 18, 'bold' => true, 'color' => R_WHITE]);
$pdf->text($lx+28, $ly+20, 'Field Route Report', ['size' => 9, 'color' => [235,223,208]]);

$dObj = new DateTimeImmutable($date);
$badgeW = 128.0; $badgeH = 30.0; $bx = R_RIGHT - $badgeW; $by = 16.0;
$pdf->roundRect($bx, $by, $badgeW, $badgeH, 7, [93,57,30]);
$pdf->text($bx+12, $by+11, $dObj->format('j M Y'), ['size' => 11, 'bold' => true, 'color' => R_WHITE]);
$pdf->text($bx+12, $by+23, $dObj->format('l'), ['size' => 7, 'color' => [230,216,200]]);
$genAt = 'Generated ' . (new DateTimeImmutable(server_now()))->format('j M Y, g:i A');
$pdf->text(R_RIGHT - $pdf->textWidth($genAt, 6.5), $by+$badgeH+10, $genAt, ['size' => 6.5, 'color' => [225,214,198]]);

// ---- identity card ----------------------------------------------------
$cardY = $bandH + 14; $cardH = 46.0;
$pdf->roundRect(R_MARGIN, $cardY, R_RIGHT - R_MARGIN, $cardH, 9, R_CREAM, R_LINE, 0.7);
$avR = 15.0; $avCx = R_MARGIN + 12 + $avR; $avCy = $cardY + $cardH/2;
$pdf->circle($avCx, $avCy, $avR, R_BROWN);
$ini = mb_strtoupper(mb_substr($employee['name'], 0, 1));
$pdf->text($avCx - $pdf->textWidth($ini, 12, true)/2, $avCy - 6, $ini, ['size' => 12, 'bold' => true, 'color' => R_WHITE]);
$tx = $avCx + $avR + 12;
$pdf->text($tx, $cardY + 15, $employee['name'], ['size' => 13, 'bold' => true, 'color' => R_INK]);
if ($employee['code']) {
    $pdf->text($tx + $pdf->textWidth($employee['name'], 13, true) + 10, $cardY + 15, $employee['code'], ['size' => 9, 'color' => R_MUTED]);
}
$sub = trim(($employee['region'] ?? '') . (($employee['region'] ?? '') && ($employee['area'] ?? '') ? ' / ' : '') . ($employee['area'] ?? ''));
$pdf->text($tx, $cardY + 30, $sub !== '' ? $sub : 'Field employee', ['size' => 8.5, 'color' => R_MUTED]);
$dayState = $dayClosed ? 'Day complete' : ($ci ? 'Still in the field' : 'No check-in');
$pdf->text(R_RIGHT - $pdf->textWidth($dayState, 7.5) - 4, $cardY + 30, $dayState, ['size' => 7.5, 'color' => $dayClosed ? R_GOOD : R_PENDING]);

$y = $cardY + $cardH + 22;

/** section header: brown dot + title + rule + right caption */
$section = function (float $y, string $title, string $cap = '') use ($pdf): float {
    $pdf->circle(R_MARGIN + 4, $y - 3, 4, R_BROWN);
    $pdf->text(R_MARGIN + 14, $y, $title, ['size' => 11.5, 'bold' => true, 'color' => R_INK]);
    $lineX = R_MARGIN + 14 + $pdf->textWidth($title, 11.5, true) + 10;
    $lineEnd = R_RIGHT;
    if ($cap !== '') {
        $cw = $pdf->textWidth($cap, 7);
        $lineEnd = R_RIGHT - $cw - 8;
        $pdf->text(R_RIGHT - $cw, $y - 2, $cap, ['size' => 7, 'color' => R_MUTED]);
    }
    if ($lineEnd > $lineX) $pdf->line($lineX, $y - 3, $lineEnd, $y - 3, 0.6, R_LINE);
    return $y + 20;
};

/** fact card grid, mockup style */
$factGrid = function (float $y, array $facts, int $perRow = 5) use ($pdf, $RTINT): float {
    $gap = 9.0;
    $pad = 10.0;
    $blockW = R_RIGHT - R_MARGIN;
    $cardW = ($blockW - $gap * ($perRow - 1)) / $perRow;
    $cardH = 52.0;
    foreach ($facts as $i => $f) {
        [$label, $val, $tintKey, $hint] = array_pad($f, 4, null);
        $tint = $RTINT[$tintKey ?? 'brown'] ?? $RTINT['brown'];
        $col = $i % $perRow; $row = intdiv($i, $perRow);
        $x = R_MARGIN + $col * ($cardW + $gap);
        $cy = $y + $row * ($cardH + 9);
        $pdf->roundRect($x, $cy, $cardW, $cardH, 7, $tint[1], R_LINE, 0.6);
        $icR = 5.5;
        $pdf->circle($x + $pad + $icR, $cy + 13, $icR, $tint[0]);
        $pdf->circle($x + $pad + $icR, $cy + 13, 1.7, R_WHITE);
        // value: auto-shrink 9.5 -> 7 so a wide string ("9:57AM - 10:51AM")
        // never overruns the card
        $avail = $cardW - $pad * 2;
        $vSize = 9.5;
        while ($vSize > 7 && $pdf->textWidth($val, $vSize, true) > $avail) { $vSize -= 0.5; }
        $pdf->text($x + $pad, $cy + 27, $val, ['size' => $vSize, 'bold' => true, 'color' => R_INK]);
        $pdf->text($x + $pad, $cy + 38, strtoupper($label), ['size' => 5.3, 'bold' => true, 'color' => R_MUTED]);
        if ($hint) $pdf->text($x + $pad, $cy + 47, $hint, ['size' => 5.1, 'color' => R_MUTED]);
    }
    $rows = intdiv(count($facts) - 1, $perRow) + 1;
    return $y + $rows * ($cardH + 9) + 6;
};

/** ruled table with rounded brown header */
$table = function (float $y, array $cols, array $rows) use ($pdf): float {
    $rowH = 20.0; $headH = 22.0; $rightPad = 9.0;
    $tableW = R_RIGHT - R_MARGIN;
    $x = R_MARGIN; $xs = [];
    foreach ($cols as $c) { $xs[] = $x; $x += $c['width']; }

    $drawHead = function () use ($pdf, $cols, $xs, $rightPad, $tableW, $headH, &$y): void {
        $pdf->roundRect(R_MARGIN, $y, $tableW, $headH, 6, R_BROWN);
        $pdf->rect(R_MARGIN, $y + $headH - 6, $tableW, 6, R_BROWN);
        $ty = $y + $headH/2 - 4;
        foreach ($cols as $i => $c) {
            $tx = $xs[$i] + 9;
            if (($c['align'] ?? 'left') === 'right') {
                $tx = $xs[$i] + $c['width'] - $rightPad - $pdf->textWidth($c['label'], 7, true);
            }
            $pdf->text($tx, $ty, strtoupper($c['label']), ['size' => 7, 'bold' => true, 'color' => R_WHITE]);
        }
        $y += $headH;
    };
    $pageBottom = $pdf->pageHeight() - 44;
    if ($y + $headH + $rowH > $pageBottom) { $pdf->addPage(); $y = 44; }
    $drawHead();
    $z = 0;
    foreach ($rows as $row) {
        if ($y + $rowH > $pageBottom) { $pdf->addPage(); $y = 44; $drawHead(); $z = 0; }
        if ($z % 2 === 1) $pdf->rect(R_MARGIN, $y, $tableW, $rowH, R_ZEBRA);
        $z++;
        $ty = $y + $rowH/2 - 3.5;
        foreach ($row as $j => $val) {
            $tx = $xs[$j] + 9;
            if (($cols[$j]['align'] ?? 'left') === 'right') {
                $tx = $xs[$j] + $cols[$j]['width'] - $rightPad - $pdf->textWidth($val, 8);
            }
            $pdf->text($tx, $ty, $val, ['size' => 8, 'bold' => $j === 0, 'color' => R_INK]);
        }
        $y += $rowH;
    }
    $pdf->line(R_MARGIN, $y, R_RIGHT, $y, 0.6, R_LINE);
    return $y + 18;
};

// ---- 5 headline numbers --------------------------------------------
$y = $section($y, 'Route Summary', $dayClosed ? 'Audited at check-out' : 'Route so far - not final');
$startEnd = ($ci ? $ci->format('g:iA') : '-') . ' - ' . ($co ? $co->format('g:iA') : ($ci ? 'now' : '-'));
$y = $factGrid($y, [
    ['Total distance', fmt_km($roadKm), $dayClosed ? 'green' : 'amber', 'By road'],
    ['Total time', $totalSec !== null ? hm($totalSec) : ($ci ? 'open' : '-'), 'blue', 'Check-in to check-out'],
    ['Total visits', (string) $shopNo, 'purple', 'Shops visited'],
    ['Avg. driving speed', $avgKmh !== null ? number_format($avgKmh, 0) . ' km/h' : '-', 'brown', 'Between stops'],
    ['Start / End', $startEnd, 'pink', 'Check-in / check-out'],
]);

// ---- static route map ---------------------------------------------
if (count($points) >= 1) {
    $mapPts = array_map(static fn(array $p): array => [
        'kind' => $p['kind'], 'no' => $p['no'], 'lat' => $p['lat'], 'lng' => $p['lng'],
    ], $points);
    $png = static_route_map_png($mapPts, 900, 460);
    if ($png !== null) {
        $y = $section($y, 'Route on Map', count($points) . ' points  .  ' . $shopNo . ' shop' . ($shopNo === 1 ? '' : 's'));
        $imgW = R_RIGHT - R_MARGIN;
        $imgH = $imgW * (460 / 900);
        $pageBottom = $pdf->pageHeight() - 44;
        if ($y + $imgH > $pageBottom) { $pdf->addPage(); $y = 44; }
        // rounded white frame behind the image
        $pdf->roundRect(R_MARGIN - 3, $y - 3, $imgW + 6, $imgH + 6, 8, R_WHITE, R_LINE, 0.7);
        $pdf->image($png, R_MARGIN, $y, $imgW, $imgH);
        $y += $imgH + 16;
    }
}

// ---- stop-by-stop timeline ---------------------------------------
if ($points) {
    $y = $section($y, 'Stop-by-stop Timeline', 'Drive from previous stop . time at shop . GPS');
    $ll = static fn(float $a, float $b): string => number_format($a, 5) . ', ' . number_format($b, 5);
    $rows = [];
    foreach ($points as $i => $p) {
        if ($p['kind'] === 'checkin') {
            $rows[] = ['Check-in (start)', $p['at']->format('g:i A'), '-', '-', $ll($p['lat'], $p['lng'])];
        } elseif ($p['kind'] === 'checkout') {
            $rows[] = ['Check-out (end)', $p['at']->format('g:i A'), '-', '-', $ll($p['lat'], $p['lng'])];
        } else {
            $win = $p['at']->format('g:iA') . ($p['left_at'] ? ' - ' . $p['left_at']->format('g:iA') : ' - now');
            $atShop = $p['dwell'] !== null ? hm((int) $p['dwell']) : 'in shop';
            $drive  = $p['hop_secs'] !== null
                ? fmt_km((float) $p['hop_km']) . ($p['hop_secs'] > 0 ? ', ' . hm((int) $p['hop_secs']) : '')
                : 'pending';
            $rows[] = ['Shop ' . $p['no'] . ': ' . $p['label'], $win, $atShop, $drive, $ll($p['lat'], $p['lng'])];
        }
    }
    $y = $table($y, [
        ['label' => 'Point',        'width' => 150],
        ['label' => 'Time window',  'width' => 106],
        ['label' => 'At shop',      'width' => 52, 'align' => 'right'],
        ['label' => 'Drive here',   'width' => 108, 'align' => 'right'],
        ['label' => 'Coordinates',  'width' => 99.28],
    ], $rows);

    $note = $dayClosed
        ? 'All figures audited at check-out (includes the return trip after the last visit).'
        : 'Distances are the route so far - the trip after the last visit is added when the employee checks out.';
    $pdf->text(R_MARGIN, $y - 4, $note, ['size' => 7, 'color' => R_MUTED]);
}

$fname = 'Route-' . preg_replace('/[^A-Za-z0-9]+/', '-', $employee['name']) . '-' . $date . '.pdf';
$pdf->output($fname);
