<?php
/**
 * admin/02-employees/api/export-pdf.php - the "Export PDF" button on the
 * Employee Overview tab. Streams a real .pdf statement covering whichever
 * period (Day / Month / All time) the admin currently has selected on that
 * tab - reuses the exact same $ovMode/on/month query params view.php's own
 * Day/Month/All time picker already uses, so the export always matches
 * whatever period is on screen (no separate date-range UI to keep in sync).
 *
 * GET: id (employee), view (day|month|alltime), on (YYYY-MM-DD, day mode),
 * month (YYYY-MM, month mode) - same params/validation as view.php.
 *
 * Built for handing to a manager as salary/payroll-supporting evidence:
 * attendance summary, visit list, distance/odometer, and a route map
 * snapshot - see includes/pdf.php for why this is a hand-written PDF (no
 * Composer/extension/external binary in this project) and
 * includes/static_map.php for the embedded map image.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
$me = require_admin();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 3) . '/includes/pdf.php';
require dirname(__DIR__, 3) . '/includes/static_map.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$employee = $id ? employee_find($pdo, $id) : null;
if (!$employee) {
    http_response_code(404);
    exit('Employee not found.');
}

$ovMode = in_array($_GET['view'] ?? '', ['day', 'month', 'alltime'], true) ? $_GET['view'] : 'day';

$onDate = (string) ($_GET['on'] ?? server_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) || !strtotime($onDate) || $onDate > server_today()) {
    $onDate = server_today();
}

$onMonth = (string) ($_GET['month'] ?? substr(server_today(), 0, 7));
if (!preg_match('/^\d{4}-\d{2}$/', $onMonth) || !strtotime($onMonth . '-01') || $onMonth > substr(server_today(), 0, 7)) {
    $onMonth = substr(server_today(), 0, 7);
}

// ---- Layout constants (points; A4 = 595.28 x 841.89) --------------------
const PDF_MARGIN   = 40.0;
const PDF_RIGHT    = 595.28 - 40.0;
const PDF_BROWN    = [107, 66, 35];    // brand brown - header band, section accents
const PDF_BROWN_DK = [74, 44, 22];     // deep brown
const PDF_MUTED    = [120, 114, 106];  // secondary label / hint text
const PDF_INK      = [26, 24, 22];     // primary value text - near-black
const PDF_LINE     = [228, 222, 212];  // hairline rules / card borders
const PDF_ZEBRA    = [250, 248, 244];  // alternate table row
const PDF_WHITE    = [255, 255, 255];
const PDF_CREAM    = [252, 250, 246];  // page-section wash behind blocks
const PDF_GOOD     = [39, 121, 74];    // "audited / on time" green
const PDF_PENDING  = [193, 122, 39];   // "still open / pending" amber

// Icon-circle fills for the summary cards (soft, tinted) + matching pale card
// grounds. Index them by role so the layout code stays readable.
const PDF_TINT = [
    'green'  => [['46,143,92'],  '#e9f4ee'],
    'amber'  => [['201,132,54'], '#fbf1e5'],
    'blue'   => [['52,116,196'], '#e8f0fb'],
    'purple' => [['123,90,196'], '#efeafb'],
    'pink'   => [['201,72,132'], '#fbeaf1'],
    'brown'  => [['138,90,46'],  '#f4ede3'],
];
/** '#rrggbb' -> [r,g,b] */
function hx(string $h): array {
    $h = ltrim($h, '#');
    return [hexdec(substr($h,0,2)), hexdec(substr($h,2,2)), hexdec(substr($h,4,2))];
}
/** 'r,g,b' -> [r,g,b] */
function rgb(string $s): array { return array_map('intval', explode(',', $s)); }

$pdf = new TrackPdf();

/**
 * Shared page header: a solid brown letterhead band (company identity +
 * period, in white text) followed by the employee's own identity line -
 * reads as a real letterhead/masthead rather than plain text sitting on
 * the page, which is what made the first pass look more like a debug dump
 * than a document meant to be handed to a manager.
 */
/**
 * Rounded brown letterhead band + a rounded "identity" card below it with a
 * circle avatar. Returns the y where page content should start.
 */
function pdf_header(TrackPdf $pdf, array $employee, string $periodLabel, string $periodSub = ''): float
{
    $W = $pdf->pageWidth();

    // ----- brown band (rounded bottom corners; full-bleed top) -----
    $bandH = 74.0;
    $pdf->rect(0, 0, $W, 14, PDF_BROWN);                                  // square top
    $pdf->roundRect(0, 0, $W, $bandH, 14, PDF_BROWN);                     // rounded body
    // subtle darker mountain silhouette on the right, drawn as two triangles
    $mx = $W - 150; $my = $bandH - 8;
    foreach ([[0, 34], [46, 22], [88, 40], [130, 26]] as [$dx, $peak]) {
        $pdf->line($mx + $dx, $my, $mx + $dx + 23, $my - $peak, 1.2, PDF_BROWN_DK);
        $pdf->line($mx + $dx + 23, $my - $peak, $mx + $dx + 46, $my, 1.2, PDF_BROWN_DK);
    }

    // logo mark: a small white triangle "peak"
    $lx = PDF_MARGIN; $ly = 22;
    $pdf->line($lx, $ly + 16, $lx + 10, $ly, 1.6, PDF_WHITE);
    $pdf->line($lx + 10, $ly, $lx + 20, $ly + 16, 1.6, PDF_WHITE);
    $pdf->line($lx + 4, $ly + 16, $lx + 16, $ly + 16, 1.6, PDF_WHITE);

    $pdf->text($lx + 28, $ly + 2, 'RAJDOOT', ['size' => 18, 'bold' => true, 'color' => PDF_WHITE]);
    $pdf->text($lx + 28, $ly + 20, 'Employee Audit', ['size' => 9, 'color' => [235, 223, 208]]);

    // date "badge" on the right - a rounded pill
    $badgeW = 118.0; $badgeH = 30.0;
    $bx = PDF_RIGHT - $badgeW; $by = 16.0;
    $pdf->roundRect($bx, $by, $badgeW, $badgeH, 7, [93, 57, 30]);
    $pdf->text($bx + 12, $by + 10, $periodLabel, ['size' => 11, 'bold' => true, 'color' => PDF_WHITE]);
    if ($periodSub !== '') {
        $pdf->text($bx + 12, $by + 22, $periodSub, ['size' => 7, 'color' => [230, 216, 200]]);
    }
    $genAt = 'Generated ' . (new DateTimeImmutable(server_now()))->format('j M Y, g:i A');
    $pdf->text(PDF_RIGHT - $pdf->textWidth($genAt, 6.5), $by + $badgeH + 10, $genAt, ['size' => 6.5, 'color' => [225, 214, 198]]);

    // ----- identity card -----
    $cardY = $bandH + 14; $cardH = 46.0;
    $pdf->roundRect(PDF_MARGIN, $cardY, PDF_RIGHT - PDF_MARGIN, $cardH, 9, PDF_CREAM, PDF_LINE, 0.7);

    // circle avatar with initials
    $avR = 15.0; $avCx = PDF_MARGIN + 12 + $avR; $avCy = $cardY + $cardH / 2;
    $pdf->circle($avCx, $avCy, $avR, PDF_BROWN);
    $ini = mb_strtoupper(mb_substr($employee['name'], 0, 1));
    $pdf->text($avCx - $pdf->textWidth($ini, 12, true) / 2, $avCy - 6, $ini, ['size' => 12, 'bold' => true, 'color' => PDF_WHITE]);

    $tx = $avCx + $avR + 12;
    $pdf->text($tx, $cardY + 15, $employee['name'], ['size' => 13, 'bold' => true, 'color' => PDF_INK]);
    if ($employee['code']) {
        $nw = $pdf->textWidth($employee['name'], 13, true);
        $pdf->text($tx + $nw + 10, $cardY + 15, $employee['code'], ['size' => 9, 'color' => PDF_MUTED]);
    }
    $sub = trim(($employee['region'] ?? '') . (($employee['region'] ?? '') && ($employee['area'] ?? '') ? ' / ' : '') . ($employee['area'] ?? ''));
    $pdf->text($tx, $cardY + 30, $sub !== '' ? $sub : 'Field employee', ['size' => 8.5, 'color' => PDF_MUTED]);
    $pdf->text(PDF_RIGHT - $pdf->textWidth('Employee ID', 7) - 4, $cardY + 30, 'Employee ID', ['size' => 7, 'color' => PDF_MUTED]);

    return $cardY + $cardH + 22;
}

/**
 * Section header: a small filled circle to the left (brand brown), the title
 * next to it, a hairline rule filling the rest of the width, and an optional
 * right-aligned caption.
 */
function pdf_section(TrackPdf $pdf, float $y, string $title, string $rightCaption = ''): float
{
    $pdf->circle(PDF_MARGIN + 4, $y - 3, 4, PDF_BROWN);
    $pdf->text(PDF_MARGIN + 14, $y, $title, ['size' => 11.5, 'bold' => true, 'color' => PDF_INK]);
    $titleW = $pdf->textWidth($title, 11.5, true);
    $lineX  = PDF_MARGIN + 14 + $titleW + 10;
    $lineEnd = PDF_RIGHT;
    if ($rightCaption !== '') {
        $capW = $pdf->textWidth($rightCaption, 7);
        $lineEnd = PDF_RIGHT - $capW - 8;
        $pdf->text(PDF_RIGHT - $capW, $y - 2, $rightCaption, ['size' => 7, 'color' => PDF_MUTED]);
    }
    if ($lineEnd > $lineX) {
        $pdf->line($lineX, $y - 3, $lineEnd, $y - 3, 0.6, PDF_LINE);
    }
    return $y + 20;
}

/**
 * The summary block, mockup style: a grid of ROUNDED cards, each with a soft
 * tinted ground, a coloured icon circle top-left, an uppercase label, a big
 * black value, and an optional hint line.
 *
 * $facts entries: [label, value, tintKey?, hint?]  where tintKey is one of
 * the keys of PDF_TINT ('green'|'amber'|'blue'|'purple'|'pink'|'brown').
 * Returns the y after the block.
 */
function pdf_fact_grid(TrackPdf $pdf, float $y, array $facts, int $perRow = 3): float
{
    $gap    = 12.0;
    $blockW = PDF_RIGHT - PDF_MARGIN;
    $cardW  = ($blockW - $gap * ($perRow - 1)) / $perRow;
    $anyHint = false;
    foreach ($facts as $f) { if (!empty($f[3])) { $anyHint = true; break; } }
    $cardH  = $anyHint ? 62.0 : 54.0;
    $rowGap = 12.0;

    foreach ($facts as $i => $f) {
        [$label, $val] = $f;
        $tintKey = $f[2] ?? 'brown';
        $hint    = $f[3] ?? null;
        $tint    = PDF_TINT[$tintKey] ?? PDF_TINT['brown'];
        $iconRgb = rgb($tint[0][0]);
        $groundRgb = hx($tint[1]);

        $col = $i % $perRow;
        $row = intdiv($i, $perRow);
        $x = PDF_MARGIN + $col * ($cardW + $gap);
        $cy = $y + $row * ($cardH + $rowGap);

        $pdf->roundRect($x, $cy, $cardW, $cardH, 8, $groundRgb, PDF_LINE, 0.6);

        // icon circle
        $icR = 8.5;
        $pdf->circle($x + 14 + $icR, $cy + 15, $icR, $iconRgb);
        // a tiny white dot inside so the circle reads as an "icon", not a blob
        $pdf->circle($x + 14 + $icR, $cy + 15, 2.4, PDF_WHITE);

        $tx = $x + 14;
        $pdf->text($tx + $icR * 2 + 6, $cy + 12, strtoupper($label), ['size' => 6.3, 'bold' => true, 'color' => PDF_MUTED]);
        // value: auto-shrink 11 -> 8 so a wide string never overruns the card
        $avail = $cardW - 28;
        $vSize = 11.0;
        while ($vSize > 8 && $pdf->textWidth($val, $vSize, true) > $avail) { $vSize -= 0.5; }
        $pdf->text($tx, $cy + 32, $val, ['size' => $vSize, 'bold' => true, 'color' => PDF_INK]);
        if ($hint !== null) {
            $pdf->text($tx, $cy + 46, $hint, ['size' => 6.0, 'color' => PDF_MUTED]);
        }
    }

    $rows = intdiv(count($facts) - 1, $perRow) + 1;
    return $y + $rows * ($cardH + $rowGap) + 8;
}

/**
 * A ruled table with a solid tinted header band and alternating (zebra)
 * row shading - reads as a real report table at a glance, not a plain list
 * of text separated by thin lines. $cols: array<{label, width, align?}>;
 * $rows: array of arrays of pre-formatted strings, same order as $cols.
 * Returns the y position right after the table. Starts a NEW PAGE if there
 * is not enough room left on the current one for at least the header + one
 * row (a table that starts 4pt from the bottom of the page is worse than
 * starting it cleanly on the next page).
 */
function pdf_table(TrackPdf $pdf, float $y, array $cols, array $rows, float $pageBottom): float
{
    $rowH = 21.0;   // roomier rows - the tight 18pt felt cramped
    $headH = 22.0;
    // A right-aligned column's text must never sit flush against its own
    // column boundary - with zero gap, a right-aligned header/value ends up
    // touching (visually running into) whatever starts at that same x in
    // the NEXT column (caught by rendering a real test PDF: "Distance" and
    // "Coordinates" headers collided into "DistanceCoordinates" with no
    // padding here).
    $rightPad = 10.0;
    $tableW = PDF_RIGHT - PDF_MARGIN;

    $x = PDF_MARGIN;
    $xs = [];
    foreach ($cols as $c) {
        $xs[] = $x;
        $x += $c['width'];
    }
    // Guard against the exact bug a real rendered test PDF caught: column
    // widths that sum past the page's right margin, so the header/zebra
    // background bands (drawn full table width) visibly overflow the page
    // edge and the last column's text gets clipped. A silent visual defect
    // like that could easily ship again if a future column set is added by
    // hand without re-checking the arithmetic - failing loudly here instead
    // catches it the moment this runs, not only when someone happens to
    // notice a cut-off table in a rendered PDF.
    if ($x > PDF_RIGHT + 0.01) {
        throw new RuntimeException(sprintf(
            'pdf_table(): column widths sum to %.2fpt, which overflows the page by %.2fpt (right margin is at %.2fpt)',
            $x - PDF_MARGIN, $x - PDF_RIGHT, PDF_RIGHT
        ));
    }
    // Second, DIFFERENT overflow to guard against: a column's own HEADER
    // LABEL text can be wider than that column, even when every column's
    // width sums correctly - this is exactly the bug the "Distance"/
    // "Coordinates" collision above was, and it recurred in the sibling
    // Attendance report PDF's "Status" column even after this file's fix,
    // because checking total width alone does not catch it - each column's
    // own text width must be checked against its own available space too.
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
        // rounded top, square bottom so rows butt cleanly against it
        $pdf->roundRect(PDF_MARGIN, $y, $tableW, $headH, 6, PDF_BROWN);
        $pdf->rect(PDF_MARGIN, $y + $headH - 6, $tableW, 6, PDF_BROWN);
        $textY = $y + $headH / 2 - 4;
        foreach ($cols as $i => $c) {
            $tx = $xs[$i] + 10;
            if (($c['align'] ?? 'left') === 'right') {
                $tx = $xs[$i] + $c['width'] - $rightPad - $pdf->textWidth($c['label'], 7.5, true);
            }
            $pdf->text($tx, $textY, strtoupper($c['label']), ['size' => 7.5, 'bold' => true, 'color' => PDF_WHITE]);
        }
        $y += $headH;
    };

    if ($y + $headH + $rowH > $pageBottom) {
        $pdf->addPage();
        $y = 40;
    }
    $drawHeader();

    $zebra = 0; // own counter, not the row's array key - reset to 0 on each new page so the stripe pattern always starts the same way under a fresh header
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
            $tx = $xs[$j] + 10;
            if (($cols[$j]['align'] ?? 'left') === 'right') {
                $tx = $xs[$j] + $cols[$j]['width'] - $rightPad - $pdf->textWidth($val, 8.5);
            }
            // First column is the row's "name" - a touch bolder so the eye
            // can run down the left edge; the rest is plain black.
            $pdf->text($tx, $textY, $val, ['size' => 8.5, 'bold' => $j === 0, 'color' => PDF_INK]);
        }
        $y += $rowH;
    }
    $pdf->line(PDF_MARGIN, $y, PDF_RIGHT, $y, 0.6, PDF_LINE);
    return $y + 22;
}

$pageBottom = $pdf->pageHeight() - 40;

// Running footer on EVERY page (registered once, applied by TrackPdf::build()
// after all real content is drawn and the true page count is known - see
// TrackPdf::runningHeaderFooter()'s own docblock for why "Page X of Y" can't
// be written page-by-page as content is drawn). A compact identity strip,
// not a repeat of the big page-1 header block - standard practice for a
// multi-page document meant to be printed/handed around: a loose page 3
// should still say whose statement it is and which page it is, without
// visually competing with the real page-1 header.
$pdf->runningHeaderFooter(function (TrackPdfPageWriter $w, int $pageIndex, int $pageCount) use ($employee): void {
    $ruleY = $w->pageHeight() - 40;
    $w->line(PDF_MARGIN, $ruleY, PDF_RIGHT, $ruleY, 1, PDF_BROWN);

    // Line 1: document identity (left) + page number (right).
    $lineY1 = $ruleY + 11;
    $left = $employee['name'] . ($employee['code'] ? ' (' . $employee['code'] . ')' : '') . ' - Rajdoot Employee Audit';
    $w->text(PDF_MARGIN, $lineY1, $left, ['size' => 7.5, 'color' => PDF_MUTED]);
    $page = 'Page ' . ($pageIndex + 1) . ' of ' . $pageCount;
    $w->text(PDF_RIGHT - $w->textWidth($page, 7.5, true), $lineY1, $page, ['size' => 7.5, 'bold' => true, 'color' => PDF_BROWN]);

    // Line 2: a distinct, centered signature/credit line - deliberately its
    // own line (not crammed alongside the page number) so it reads as a
    // real software-attribution credit, not incidental fine print.
    $lineY2 = $lineY1 + 13;
    $credit = 'Software by Prabin Sharma  -  sharmaprabin160@gmail.com';
    $centerX = (PDF_RIGHT + PDF_MARGIN) / 2 - $w->textWidth($credit, 7.5) / 2;
    $w->text($centerX, $lineY2, $credit, ['size' => 7.5, 'color' => [155, 148, 138]]);
});

$pdf->addPage();

if ($ovMode === 'day') {
    $dObjSel     = new DateTimeImmutable($onDate);
    $periodLabel = $dObjSel->format('j M Y');
    $periodSub   = $dObjSel->format('l');
    $y = pdf_header($pdf, $employee, $periodLabel, $periodSub);

    $statement = employee_statement($pdo, $id, $onDate);
    $timeline  = employee_timeline($pdo, $id, $onDate);
    $dayClosed = $statement['last_check_out'] !== null;

    if (!$statement['has_attendance']) {
        $pdf->text(PDF_MARGIN, $y, 'No attendance recorded for this day.', ['size' => 10, 'color' => PDF_MUTED]);
    } else {
        // ---- running (live) totals from the timeline, so an OPEN day still
        // shows the route so far. Each visit carries hop_km = the road
        // distance of the ONE leg that arrives at it (check-in -> visit 1,
        // then visit 1 -> visit 2, ...). Productive distance for the day is
        // the SUM of those legs; shop time is the sum of completed dwells.
        // The final audited figures (attendance.road_km etc.) land at
        // check-out and this recomputes then.
        $liveKm    = 0.0;
        $liveShop  = 0;
        foreach ($timeline as $r) {
            if ($r['kind'] !== 'visit') continue;
            $liveKm += (float) $r['hop_km'];
            if ($r['dwell_secs'] !== null) $liveShop += (int) $r['dwell_secs'];
        }

        $y = pdf_section($pdf, $y, 'Attendance Summary', 'Track . Verify . Stay Accurate');
        $facts = [
            ['Check-in', $statement['first_check_in'] ? $statement['first_check_in']->format('g:i A') : '-',
                'green', 'On time'],
            ['Check-out', $dayClosed ? $statement['last_check_out']->format('g:i A') : 'still open',
                $dayClosed ? 'green' : 'amber', $dayClosed ? 'Day closed' : 'Not yet checked out'],
            ['Total visits', (string) $statement['visits'], 'blue', 'Shops logged today'],
            ['Productive working distance',
                $dayClosed ? fmt_km($statement['km']) : fmt_km($liveKm),
                $dayClosed ? 'purple' : 'amber',
                $dayClosed ? 'Audited at check-out' : 'Route so far - not final'],
            ['Total working time',
                $statement['active_secs'] !== null ? hm($statement['active_secs']) : '-',
                'blue',
                $dayClosed ? 'Check-in to check-out' : 'So far - still on the clock'],
            [$dayClosed ? 'Shop time (total)' : 'Shop time (total so far)', hm($liveShop),
                'pink', $dayClosed ? 'Time spent inside shops' : 'Across completed visits so far'],
        ];
        $y = pdf_fact_grid($pdf, $y, $facts);

        if ($statement['odo_check_in'] !== null) {
            // Odometer is recorded as two plain readings for the record. The
            // paid distance is the system's GPS "Productive working distance"
            // above - NOT a check-out-minus-check-in subtraction, which mixes
            // in parking loops, detours and unlogged errands the bike's meter
            // can't tell from work. So no "distance ridden" figure here.
            $y = pdf_section($pdf, $y, 'Bike Odometer', 'Readings recorded by the employee');
            $odoFacts = [
                ['Odometer at check-in', number_format($statement['odo_check_in'], 1) . ' km', 'brown',
                    'Employee-entered reading'],
                ['Odometer at check-out',
                    $statement['odo_check_out'] !== null ? number_format($statement['odo_check_out'], 1) . ' km' : 'not recorded yet',
                    $statement['odo_check_out'] !== null ? 'green' : 'amber',
                    $statement['odo_check_out'] !== null ? 'Employee-entered reading' : 'Entered at check-out'],
            ];
            $y = pdf_fact_grid($pdf, $y, $odoFacts);
        }

        if ($timeline) {
            $y = pdf_section($pdf, $y, 'Visit Timeline', 'Each visit with time, shop and distance');
            $odoIn = $statement['odo_check_in'];
            // 4 dp (~11 m) keeps the coordinate cell inside its column while
            // staying precise enough for an audit trail.
            $ll4 = static fn(?float $la, ?float $lo): string =>
                ($la === null || $lo === null) ? '-'
                : number_format((float) $la, 4) . ', ' . number_format((float) $lo, 4);

            // "Productive KM" per row = the road distance of the ONE leg that
            // ends at that point: check-in -> visit 1, then visit 1 -> visit
            // 2, ... , then (last visit) -> check-out. This IS visits.hop_km
            // for each visit; the check-out leg is the day total minus the
            // sum of the visit hops. NOT cumulative - each row shows only its
            // own leg, and they sum to the day's productive distance.
            $rows = [];
            foreach ($timeline as $ti => $r) {
                if ($r['kind'] === 'checkin') {
                    $rows[] = [
                        'Check-in' . ($odoIn !== null ? '  (odo ' . number_format((float) $odoIn, 1) . ' km)' : ''),
                        $r['at']->format('g:i A'),
                        '-',
                        '-',
                        $ll4($r['lat'], $r['lng']),
                    ];
                } elseif ($r['kind'] === 'checkout') {
                    $odoOut = $statement['odo_check_out'] !== null
                        ? '  (odo ' . number_format((float) $statement['odo_check_out'], 1) . ' km)' : '';
                    // last-visit -> check-out leg = day total - sum of visit hops
                    $lastLeg = $dayClosed ? max(0.0, (float) $statement['km'] - $liveKm) : null;
                    $rows[] = [
                        'Check-out' . $odoOut,
                        $r['at']->format('g:i A'),
                        '-',
                        $lastLeg !== null ? fmt_km($lastLeg) : '-',
                        $ll4($r['lat'], $r['lng']),
                    ];
                } else {
                    // Shop visit: "Shop: <name>", arrived-to-left window,
                    // minutes at shop, and THIS leg's own productive km
                    // (distance from the previous stop to this shop).
                    $win = $r['at']->format('g:iA');
                    $win .= $r['left_at'] ? ' - ' . $r['left_at']->format('g:iA') : ' - now';
                    $atShop = $r['dwell_secs'] !== null ? hm($r['dwell_secs']) : 'in shop';
                    $legKm  = $r['hop_secs'] !== null ? fmt_km((float) $r['hop_km']) : 'pending';
                    $rows[] = [
                        'Shop: ' . $r['label'],
                        $win,
                        $atShop,
                        $legKm,
                        $ll4($r['lat'], $r['lng']),
                    ];
                }
            }
            $y = pdf_table($pdf, $y, [
                ['label' => 'Point',        'width' => 174],
                ['label' => 'Time window',  'width' => 104],
                ['label' => 'At shop',      'width' => 50, 'align' => 'right'],
                ['label' => 'Productive km', 'width' => 82, 'align' => 'right'],
                ['label' => 'Coordinates',  'width' => 97.28],
            ], $rows, $pageBottom);

            // A small "adds up to" line under the table so the manager sees
            // the per-leg figures are the day's productive distance broken out.
            $sumLabel = $dayClosed
                ? 'Legs above sum to the audited productive distance: ' . fmt_km((float) $statement['km'])
                : 'Legs so far sum to: ' . fmt_km($liveKm) . '  (not final - the trip after the last visit is added at check-out)';
            $pdf->text(PDF_MARGIN, $y - 6, $sumLabel, ['size' => 7.5, 'color' => PDF_MUTED]);
            $y += 12;
        }

        // ---- the day's two pay numbers, as plain bold lines right under
        // the visit timeline (no card, no big type - a quiet final tally) ----
        $prodKm   = $dayClosed ? fmt_km((float) $statement['km']) : fmt_km($liveKm) . ' (so far)';
        $workTime = $statement['active_secs'] !== null
            ? hm((int) $statement['active_secs']) . ($dayClosed ? '' : ' (so far)')
            : '-';
        $labelW = $pdf->textWidth('Total working hours', 9, true) + 14;
        $pdf->text(PDF_MARGIN, $y,      'Total productive km', ['size' => 9, 'bold' => true, 'color' => PDF_INK]);
        $pdf->text(PDF_MARGIN + $labelW, $y, $prodKm,          ['size' => 9, 'bold' => true, 'color' => PDF_INK]);
        $y += 14;
        $pdf->text(PDF_MARGIN, $y,      'Total working hours', ['size' => 9, 'bold' => true, 'color' => PDF_INK]);
        $pdf->text(PDF_MARGIN + $labelW, $y, $workTime,        ['size' => 9, 'bold' => true, 'color' => PDF_INK]);
        $y += 18;

        $mapPoints = array_map(static fn(array $r): array => ['lat' => $r['lat'], 'lng' => $r['lng'], 'kind' => $r['kind']], $timeline);
        $png = count($mapPoints) >= 1 ? static_route_map_png($mapPoints) : null;
        if ($png !== null) {
            // Cap the map's width so it (plus its "Route map" heading) is
            // GUARANTEED to fit above pageBottom on a fresh page even in the
            // worst case (nothing above it) - sizing to the full content
            // width first and checking against a made-up constant was the
            // bug caught by an actual rendered PDF: the real image (511pt
            // wide, ~320pt tall at its 640:400 aspect ratio) didn't fit the
            // hardcoded "needs 220pt" guess, so it silently ran into the
            // page-footer text.
            $maxImgW = PDF_RIGHT - PDF_MARGIN;
            $maxImgH = ($pageBottom - 40 - 16); // room left on a FRESH page below a section heading
            $imgW = min($maxImgW, $maxImgH * (640 / 400));
            $imgH = $imgW * (400 / 640);

            if ($y + 16 + $imgH > $pageBottom) {
                $pdf->addPage();
                $y = 40;
            }
            $y = pdf_section($pdf, $y, 'Route map');
            $pdf->image($png, PDF_MARGIN, $y, $imgW, $imgH);
        }
    }
} else {
    // month / alltime
    if ($ovMode === 'month') {
        $mFrom = $onMonth . '-01';
        $mTo   = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
        $mObj  = new DateTimeImmutable($mFrom);
        $periodLabel = $mObj->format('M Y');
        $periodSub   = $mObj->format('F Y');
    } else {
        $mFrom = null;
        $mTo   = null;
        $periodLabel = 'All time';
        $periodSub   = 'every recorded day';
    }

    $y = pdf_header($pdf, $employee, $periodLabel, $periodSub);
    $totals = employee_period_totals($pdo, $id, $mFrom, $mTo);

    $y = pdf_section($pdf, $y, 'Summary', 'All figures from checked-out days');
    // Every figure here is a sum over CHECKED-OUT days only (open days have
    // no audited totals yet), so it is accurate by construction.
    $facts = [
        ['Days worked', (string) $totals['days'], 'green'],
        ['Incomplete days', (string) $totals['incomplete'], $totals['incomplete'] > 0 ? 'amber' : 'green'],
        ['Total visits', (string) $totals['visits'], 'blue'],
        ['Distinct shops', (string) $totals['shops'], 'blue'],
        ['Productive working distance', number_format($totals['productive_km'], 1) . ' km', 'purple', 'Audited, checked-out days'],
        ['Total working time', hm($totals['total_secs']), 'green', 'Check-in to check-out'],
        ['Shop time', hm($totals['shop_secs']), 'pink', 'Total time at shops'],
        ['Road time', hm($totals['road_secs']), 'brown', 'Travelling between stops'],
        ['Avg. time per visit', hm($totals['avg_dwell_secs']), 'pink', 'Across completed visits'],
    ];
    $y = pdf_fact_grid($pdf, $y, $facts);

    $days = employee_days($pdo, $id, $mFrom ?? '2000-01-01', $mTo ?? server_today());
    if ($days) {
        $y = pdf_section($pdf, $y, 'Day-by-day', 'Audited figures shown for checked-out days');
        $rows = array_map(static function (array $d): array {
            $ci = new DateTimeImmutable($d['check_in_at']);
            $co = $d['check_out_at'] ? new DateTimeImmutable($d['check_out_at']) : null;
            // A day with no check-out has no audited totals yet - "-", not 0.
            $closed = $co !== null;
            return [
                (new DateTimeImmutable($d['work_date']))->format('D, j M'),
                $ci->format('g:i A'),
                $co ? $co->format('g:i A') : 'open',
                (string) (int) $d['visit_count'],
                $closed ? hm((int) $d['total_seconds']) : '-',   // working time (check-in -> check-out)
                $closed ? hm((int) $d['shop_seconds'])  : '-',   // time at shops
                $closed ? hm((int) $d['road_seconds'])  : '-',   // travel time
                $closed ? fmt_km((float) $d['road_km']) : '-',   // productive distance
            ];
        }, $days);
        $y = pdf_table($pdf, $y, [
            ['label' => 'Date',        'width' => 88],
            ['label' => 'Check-in',    'width' => 66],
            ['label' => 'Check-out',   'width' => 66],
            ['label' => 'Visits',      'width' => 44, 'align' => 'right'],
            ['label' => 'Working',     'width' => 56, 'align' => 'right'],
            ['label' => 'Shop time',   'width' => 58, 'align' => 'right'],
            ['label' => 'Road time',   'width' => 58, 'align' => 'right'],
            ['label' => 'Distance',    'width' => 77.28, 'align' => 'right'],
        ], $rows, $pageBottom);

        // Column totals for the whole period, under the table.
        $tSecs = 0; $shSecs = 0; $rdSecs = 0; $km = 0.0; $closedN = 0;
        foreach ($days as $d) {
            if ($d['check_out_at'] === null) continue;
            $closedN++;
            $tSecs  += (int) $d['total_seconds'];
            $shSecs += (int) $d['shop_seconds'];
            $rdSecs += (int) $d['road_seconds'];
            $km     += (float) $d['road_km'];
        }
        $sum = $closedN . ' checked-out day' . ($closedN === 1 ? '' : 's') . '  .  '
             . 'working ' . hm($tSecs) . '  .  shop ' . hm($shSecs)
             . '  .  road ' . hm($rdSecs) . '  .  ' . fmt_km($km) . ' total';
        $pdf->text(PDF_MARGIN, $y - 6, $sum, ['size' => 7.5, 'color' => PDF_MUTED]);
        $y += 8;
    }
}

$fname = 'Statement-' . preg_replace('/[^A-Za-z0-9]+/', '-', $employee['name']) . '-' . $ovMode . '-' . server_today() . '.pdf';
$pdf->output($fname);
