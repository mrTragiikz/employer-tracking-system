<?php
/**
 * includes/pdf.php - a small, dependency-free PDF writer.
 *
 * No Composer, no PDF PHP extension, no external binary (this project has
 * none of those - see MEMORY/deploy notes) - just hand-written PDF syntax,
 * so it works identically in dev and on the live cPanel host with zero
 * setup. Deliberately NOT a general-purpose library: it supports exactly
 * the primitives Track's exported statements need (text runs at a position,
 * simple ruled tables, section headers/dividers, one embedded image) and
 * nothing more - keeping the format-writing surface small keeps it
 * correctly matched to the one real PDF reader that matters (browsers'
 * built-in PDF viewers, tested against this file).
 *
 * PDF core-14 fonts (Helvetica) are used throughout - no font file to
 * embed, every PDF reader ships these built in.
 *
 * Usage:
 *   $pdf = new TrackPdf();
 *   $pdf->addPage();
 *   $pdf->text(40, 40, 'Hello', ['size' => 16, 'bold' => true]);
 *   $pdf->line(40, 60, 555, 60);
 *   $pdf->image($pngBytes, 40, 70, 300, 187.5); // raw PNG file bytes
 *   $pdf->output('statement.pdf'); // streams the download and exits
 *
 * Coordinate system matches print/PDF convention: origin bottom-left,
 * y increases UPWARD. A page is 595 x 842 pt (A4) unless constructed with
 * different dimensions. All helper methods above (text/line/image) take
 * TOP-DOWN y (origin top-left, y increases downward) for author
 * convenience - matching how a report is naturally laid out - and this
 * class flips it internally before writing the raw PDF content stream.
 */

declare(strict_types=1);

final class TrackPdf
{
    private float $pageW;
    private float $pageH;
    /** @var array<int,string> raw content-stream text accumulated for the CURRENT page */
    private array $curOps = [];
    /** @var array<int,string> finished pages' content streams */
    private array $pages = [];
    /** @var array<string,true> font resource names actually used (Helvetica / Helvetica-Bold) */
    private array $fontsUsed = [];
    /** @var array<int,array{width:int,height:int,bitDepth:int,palette:string,idat:string,hasAlpha:bool}> images embedded, indexed 0.. */
    private array $images = [];
    /** @var array<int,int> which image index is used on which page index */
    private array $pageImages = [];
    /** @var ?callable(TrackPdfPageWriter $w, int $pageIndex, int $pageCount): void */
    private $runningHeaderFooter = null;

    public function __construct(float $pageWidth = 595.28, float $pageHeight = 841.89)
    {
        $this->pageW = $pageWidth;
        $this->pageH = $pageHeight;
    }

    private bool $pageOpen = false;

    /**
     * Register content to draw on EVERY page (a running header/footer -
     * employee name, page numbers, a credit line) once the total page count
     * is known. Runs once per finished page during build(), AFTER all of
     * this document's own content is already drawn - necessary because
     * "Page 2 of 5" can't be written onto page 2 back when page 2 was
     * originally being drawn, since page 5 doesn't exist yet at that point.
     * The callback receives a small writer exposing just text()/line() (see
     * TrackPdfPageWriter below), the 0-based page index, and the total page
     * count.
     *
     * @param callable(TrackPdfPageWriter $w, int $pageIndex, int $pageCount): void $fn
     */
    public function runningHeaderFooter(callable $fn): void
    {
        $this->runningHeaderFooter = $fn;
    }

    /**
     * Start a new page. The FIRST call just opens page 1 (nothing to flush
     * yet); every call after that flushes whatever was drawn on the
     * previous page as its own finished page first. Without distinguishing
     * "first call" from "later call", the first addPage() has nothing real
     * to flush but flushPage()'s own "always push at least one page" guard
     * (needed so build() never runs with zero pages if addPage() is only
     * ever called once) would push an empty page BEFORE the real one,
     * producing a blank leading page in the output - a real bug caught by
     * rendering an actual test PDF, not just by this class's unit checks.
     */
    public function addPage(): void
    {
        if ($this->pageOpen) {
            $this->flushPage();
        }
        $this->pageOpen = true;
    }

    private function flushPage(): void
    {
        $this->pages[] = implode("\n", $this->curOps);
        $this->curOps = [];
    }

    public function pageWidth(): float { return $this->pageW; }
    public function pageHeight(): float { return $this->pageH; }

    /** Escape a string for a PDF literal string "(...)" - backslash, parens. */
    private static function esc(string $s): string
    {
        // PDF's core-14 fonts are WinAnsiEncoding (~Latin-1), not UTF-8 - a
        // name/label with e.g. a curly quote or non-Latin character would
        // otherwise come out as garbage bytes. mb_convert_encoding degrades
        // anything outside that range to '?' rather than corrupting the
        // stream - acceptable for a report whose content is names/numbers/
        // dates, not a substitute for real Unicode font embedding.
        $s = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8') ?: $s;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /**
     * Draw a text run. $x/$y are TOP-DOWN (y=0 at the top of the page).
     * Options: size (pt, default 10), bold (bool), color ([r,g,b] 0-255).
     */
    public function text(float $x, float $y, string $s, array $opts = []): void
    {
        $this->curOps[] = $this->textOp($x, $y, $s, $opts);
    }

    /** Builds the raw content-stream op-string for a text run (shared by text() and the running header/footer pass). */
    public function textOp(float $x, float $y, string $s, array $opts = []): string
    {
        $size = (float) ($opts['size'] ?? 10);
        $bold = !empty($opts['bold']);
        $font = $bold ? 'F2' : 'F1';
        $this->fontsUsed[$font] = true;
        $yFlipped = $this->pageH - $y - $size * 0.8; // baseline sits ~0.8x size below the top of the glyph box

        $ops = ['BT', "/$font $size Tf"];
        if (!empty($opts['color'])) {
            [$r, $g, $b] = $opts['color'];
            $ops[] = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }
        $ops[] = sprintf('%.2F %.2F Td', $x, $yFlipped);
        $ops[] = '(' . self::esc($s) . ') Tj';
        $ops[] = 'ET';
        return implode(' ', $ops);
    }

    /** Text width in points for Helvetica core-14 metrics - used to right-align/wrap without a font file. */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        // Core-14 Helvetica average glyph width approximation: real PDF
        // viewers use the font's actual AFM metrics, which vary per
        // character - this project doesn't embed those tables (would be a
        // few KB of per-glyph width data for no real layout benefit at
        // report-table scale), so a well-tuned average is used instead.
        // Tuned against Helvetica's real metrics: ~0.5em regular, ~0.53em
        // bold, close enough that right-aligned numbers/labels in a table
        // don't visibly drift over the string lengths a report deals with.
        $avg = $bold ? 0.53 : 0.50;
        return mb_strlen($s) * $size * $avg;
    }

    /** A straight line, TOP-DOWN coordinates, in points. */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $color = [200, 200, 200]): void
    {
        $this->curOps[] = $this->lineOp($x1, $y1, $x2, $y2, $width, $color);
    }

    /** Builds the raw content-stream op-string for a line (shared by line() and the running header/footer pass). */
    public function lineOp(float $x1, float $y1, float $x2, float $y2, float $width, array $color): string
    {
        [$r, $g, $b] = $color;
        $y1f = $this->pageH - $y1;
        $y2f = $this->pageH - $y2;
        return sprintf(
            '%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S',
            $width, $r / 255, $g / 255, $b / 255, $x1, $y1f, $x2, $y2f
        );
    }

    /** A filled rectangle, TOP-DOWN coordinates (x,y = top-left corner). */
    public function rect(float $x, float $y, float $w, float $h, array $fill): void
    {
        [$r, $g, $b] = $fill;
        $yf = $this->pageH - $y - $h;
        $this->curOps[] = sprintf(
            '%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f',
            $r / 255, $g / 255, $b / 255, $x, $yf, $w, $h
        );
    }

    /**
     * A rounded rectangle, TOP-DOWN coords (x,y = top-left). Filled when
     * $fill is a colour; optionally stroked with $stroke colour + $strokeW
     * width. Corners are quarter-circle Bezier arcs (0.5523 kappa). Radius is
     * clamped to half the shorter side.
     */
    public function roundRect(float $x, float $y, float $w, float $h, float $rad,
                              ?array $fill = null, ?array $stroke = null, float $strokeW = 0.6): void
    {
        $rad = max(0.0, min($rad, $w / 2, $h / 2));
        $k   = 0.5522847498 * $rad;
        $yb  = $this->pageH - $y - $h;      // bottom edge in PDF (bottom-up) coords
        $yt  = $this->pageH - $y;           // top edge
        $xl  = $x;  $xr = $x + $w;

        // path: start at top-left after the corner, go clockwise
        $p  = sprintf('%.2F %.2F m ', $xl + $rad, $yt);
        $p .= sprintf('%.2F %.2F l ', $xr - $rad, $yt);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $xr - $rad + $k, $yt, $xr, $yt - $rad + $k, $xr, $yt - $rad);
        $p .= sprintf('%.2F %.2F l ', $xr, $yb + $rad);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $xr, $yb + $rad - $k, $xr - $rad + $k, $yb, $xr - $rad, $yb);
        $p .= sprintf('%.2F %.2F l ', $xl + $rad, $yb);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $xl + $rad - $k, $yb, $xl, $yb + $rad - $k, $xl, $yb + $rad);
        $p .= sprintf('%.2F %.2F l ', $xl, $yt - $rad);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c h', $xl, $yt - $rad + $k, $xl + $rad - $k, $yt, $xl + $rad, $yt);

        $ops = '';
        if ($fill !== null)   { [$r,$g,$b] = $fill;   $ops .= sprintf('%.3F %.3F %.3F rg ', $r/255,$g/255,$b/255); }
        if ($stroke !== null) { [$r,$g,$b] = $stroke; $ops .= sprintf('%.3F %.3F %.3F RG %.2F w ', $r/255,$g/255,$b/255, $strokeW); }
        $paint = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->curOps[] = $ops . $p . ' ' . $paint;
    }

    /**
     * A filled (and optionally stroked) circle centred at TOP-DOWN (cx, cy)
     * with radius $rad. Four Bezier arcs.
     */
    public function circle(float $cx, float $cy, float $rad, ?array $fill = null,
                           ?array $stroke = null, float $strokeW = 0.6): void
    {
        $cyf = $this->pageH - $cy;
        $k   = 0.5522847498 * $rad;
        $p  = sprintf('%.2F %.2F m ', $cx + $rad, $cyf);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx + $rad, $cyf + $k, $cx + $k, $cyf + $rad, $cx, $cyf + $rad);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx - $k, $cyf + $rad, $cx - $rad, $cyf + $k, $cx - $rad, $cyf);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $cx - $rad, $cyf - $k, $cx - $k, $cyf - $rad, $cx, $cyf - $rad);
        $p .= sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c h', $cx + $k, $cyf - $rad, $cx + $rad, $cyf - $k, $cx + $rad, $cyf);

        $ops = '';
        if ($fill !== null)   { [$r,$g,$b] = $fill;   $ops .= sprintf('%.3F %.3F %.3F rg ', $r/255,$g/255,$b/255); }
        if ($stroke !== null) { [$r,$g,$b] = $stroke; $ops .= sprintf('%.3F %.3F %.3F RG %.2F w ', $r/255,$g/255,$b/255, $strokeW); }
        $paint = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->curOps[] = $ops . $p . ' ' . $paint;
    }

    /**
     * Embed a PNG image (raw file bytes, e.g. straight from a Mapbox Static
     * Images API response) at TOP-DOWN (x,y) with the given display size in
     * points. Supports the two PNG shapes actually produced in this
     * codebase's own use (Mapbox Static Images output): 8-bit palette
     * (colorType 3, used by Mapbox's vector-style raster output - see
     * includes/mapbox_static.php) and 8-bit truecolor/truecolor+alpha
     * (colorType 2/6). Interlaced PNGs and <8-bit palettes are NOT
     * supported (throws) - not produced by this app's own image source, so
     * not worth the extra decoding complexity.
     */
    public function image(string $pngBytes, float $x, float $y, float $w, float $h): void
    {
        $img = self::parsePng($pngBytes);
        $this->images[] = $img;
        $idx = count($this->images) - 1;
        $this->pageImages[count($this->pages)][] = $idx;

        $name = 'Im' . $idx;
        $yf = $this->pageH - $y - $h;
        $this->curOps[] = sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, $yf, $name);
    }

    /**
     * @return array{width:int,height:int,bitDepth:int,colorType:int,palette:string,idat:string,hasAlpha:bool,alphaIdat:?string}
     */
    private static function parsePng(string $data): array
    {
        if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('image() only accepts real PNG file bytes');
        }
        $pos = 8;
        $width = $height = $bitDepth = $colorType = null;
        $palette = '';
        $idat = '';
        $trns = '';
        while ($pos < strlen($data)) {
            $len = unpack('N', substr($data, $pos, 4))[1];
            $type = substr($data, $pos + 4, 4);
            $chunk = substr($data, $pos + 8, $len);
            if ($type === 'IHDR') {
                $width = unpack('N', substr($chunk, 0, 4))[1];
                $height = unpack('N', substr($chunk, 4, 4))[1];
                $bitDepth = ord($chunk[8]);
                $colorType = ord($chunk[9]);
                if (ord($chunk[12]) !== 0) {
                    throw new RuntimeException('interlaced PNG not supported by this minimal writer');
                }
            } elseif ($type === 'PLTE') {
                $palette = $chunk;
            } elseif ($type === 'tRNS') {
                $trns = $chunk;
            } elseif ($type === 'IDAT') {
                $idat .= $chunk;
            } elseif ($type === 'IEND') {
                break;
            }
            $pos += 8 + $len + 4;
        }
        if ($bitDepth !== 8) {
            throw new RuntimeException('only 8-bit PNGs supported by this minimal writer');
        }
        if (!in_array($colorType, [2, 3, 6], true)) {
            throw new RuntimeException('unsupported PNG color type ' . $colorType);
        }

        $hasAlpha = $colorType === 6;
        $alphaIdat = null;
        if ($hasAlpha) {
            // Split the decompressed RGBA scanlines into separate RGB and
            // alpha streams, then re-compress each - PDF wants the image's
            // alpha as a separate /SMask XObject, not interleaved with the
            // color channel the way PNG stores it. Simple channel-split
            // (no filter re-application needed since it's done on the
            // already-unfiltered decoded pixels, not the raw IDAT stream).
            $raw = @gzuncompress($idat);
            if ($raw === false) {
                throw new RuntimeException('could not inflate PNG IDAT data');
            }
            $rowBytes = $width * 4;
            $rgb = '';
            $alpha = '';
            for ($rowStart = 0; $rowStart < strlen($raw); $rowStart += $rowBytes + 1) {
                $filterByte = $raw[$rowStart];
                $row = substr($raw, $rowStart + 1, $rowBytes);
                // Filter type must be 0 (None) for this simplified path -
                // Mapbox's own PNG output (this app's only image source)
                // uses filter-per-row adaptively in general, so unfilter
                // properly rather than assuming None:
                $row = self::unfilterRow($filterByte, $row, $rowStart === 0 ? null : $prevRow ?? null, 4);
                $prevRow = $row;
                for ($px = 0; $px < $width; $px++) {
                    $o = $px * 4;
                    $rgb .= substr($row, $o, 3);
                    $alpha .= $row[$o + 3];
                }
            }
            $idat = gzcompress($rgb, 6);
            $alphaIdat = gzcompress($alpha, 6);
        }

        return [
            'width' => $width, 'height' => $height, 'bitDepth' => $bitDepth, 'colorType' => $colorType,
            'palette' => $palette, 'trns' => $trns, 'idat' => $idat, 'hasAlpha' => $hasAlpha, 'alphaIdat' => $alphaIdat,
        ];
    }

    /** Reverses one PNG scanline filter (see PNG spec 9.2) on already-separated 4-byte-per-pixel (RGBA) data. */
    private static function unfilterRow(string $filterByte, string $row, ?string $prevRow, int $bpp): string
    {
        $type = ord($filterByte);
        if ($type === 0) return $row;
        $len = strlen($row);
        $out = $row;
        for ($i = 0; $i < $len; $i++) {
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = $prevRow !== null ? ord($prevRow[$i]) : 0;
            $c = ($prevRow !== null && $i >= $bpp) ? ord($prevRow[$i - $bpp]) : 0;
            $x = ord($row[$i]);
            $val = match ($type) {
                1 => $x + $a,
                2 => $x + $b,
                3 => $x + intdiv($a + $b, 2),
                4 => $x + self::paeth($a, $b, $c),
                default => $x,
            };
            $out[$i] = chr($val & 0xFF);
        }
        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) return $a;
        if ($pb <= $pc) return $b;
        return $c;
    }

    /**
     * Assemble every accumulated page + resource into final PDF bytes.
     * Object numbering: 1=Catalog, 2=Pages, 3..=one per page (Page+Contents
     * pairs), then fonts, then images, in that fixed order.
     */
    private function build(): string
    {
        // Flush whatever was drawn on the last (still-open) page - build()
        // can run right after the last addPage()'s drawing calls, with no
        // further addPage() to trigger that flush itself.
        if ($this->pageOpen) {
            $this->flushPage();
            $this->pageOpen = false; // build() must be safe to call more than once (e.g. toString() then output())
        }
        $pageCount = count($this->pages);
        if ($pageCount === 0) {
            throw new RuntimeException('TrackPdf: build() called with no pages - call addPage() at least once');
        }

        if ($this->runningHeaderFooter !== null) {
            $writer = new TrackPdfPageWriter($this);
            for ($p = 0; $p < $pageCount; $p++) {
                $writer->beginPage($p);
                ($this->runningHeaderFooter)($writer, $p, $pageCount);
                $this->pages[$p] .= "\n" . $writer->takeOps();
            }
        }

        $objects = [];   // 1-indexed via count($objects)+1 when pushing
        $pageObjNums = [];
        $contentObjNums = [];

        $objects[] = null; // placeholder for object 1 (Catalog) - filled at the end once page refs are known
        $objects[] = null; // placeholder for object 2 (Pages)

        // Reserve font object numbers up front (always both, even if one is unused - harmless, keeps numbering simple).
        $fontRegularNum = count($objects) + 1; $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $fontBoldNum    = count($objects) + 1; $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        // Images: each may need TWO objects if it has an alpha mask (SMask + the color image referencing it).
        $imageObjNums = [];
        $imageSMaskNums = [];
        foreach ($this->images as $i => $img) {
            if ($img['hasAlpha']) {
                $smaskNum = count($objects) + 1;
                $objects[] = "<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} "
                    . "/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode "
                    . "/DecodeParms << /Predictor 1 >> /Length " . strlen($img['alphaIdat']) . " >>\nstream\n{$img['alphaIdat']}\nendstream";
                $imageSMaskNums[$i] = $smaskNum;
            }
        }
        foreach ($this->images as $i => $img) {
            $num = count($objects) + 1;
            if ($img['colorType'] === 3) {
                $paletteHex = bin2hex($img['palette']);
                $entries = max(1, (int) (strlen($img['palette']) / 3) - 1);
                $dict = "<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} "
                    . "/ColorSpace [/Indexed /DeviceRGB $entries <$paletteHex>] /BitsPerComponent 8 "
                    . "/Filter /FlateDecode /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns {$img['width']} >> "
                    . "/Length " . strlen($img['idat']) . " >>";
            } else {
                $smaskRef = isset($imageSMaskNums[$i]) ? " /SMask {$imageSMaskNums[$i]} 0 R" : '';
                $dict = "<< /Type /XObject /Subtype /Image /Width {$img['width']} /Height {$img['height']} "
                    . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode"
                    . " /DecodeParms << /Predictor 1 >>$smaskRef /Length " . strlen($img['idat']) . " >>";
            }
            $objects[] = "$dict\nstream\n{$img['idat']}\nendstream";
            $imageObjNums[$i] = $num;
        }

        // Pages + content streams.
        for ($p = 0; $p < $pageCount; $p++) {
            $contentNum = count($objects) + 1;
            $stream = $this->pages[$p];
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream";
            $contentObjNums[$p] = $contentNum;

            $xobjEntries = [];
            foreach (($this->pageImages[$p] ?? []) as $imgIdx) {
                $xobjEntries[] = "/Im$imgIdx {$imageObjNums[$imgIdx]} 0 R";
            }
            $resources = "<< /Font << /F1 $fontRegularNum 0 R /F2 $fontBoldNum 0 R >>"
                . ($xobjEntries ? ' /XObject << ' . implode(' ', $xobjEntries) . ' >>' : '') . " >>";

            $pageNum = count($objects) + 1;
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageW} {$this->pageH}] "
                . "/Resources $resources /Contents $contentNum 0 R >>";
            $pageObjNums[$p] = $pageNum;
        }

        $kids = implode(' ', array_map(static fn($n) => "$n 0 R", $pageObjNums));
        $objects[1] = "<< /Type /Pages /Kids [$kids] /Count $pageCount >>"; // object 2
        $objects[0] = "<< /Type /Catalog /Pages 2 0 R >>"; // object 1

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; // the comment line's high bytes are the standard "this is binary" marker for FTP/mail transports
        $offsets = [0];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n$obj\nendobj\n";
        }
        $xrefStart = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size $count /Root 1 0 R >>\nstartxref\n$xrefStart\n%%EOF";
        return $pdf;
    }

    /** Return the finished PDF as a raw byte string (for saving/emailing rather than a direct download response). */
    public function toString(): string
    {
        return $this->build();
    }

    /** Stream the finished PDF as a browser download and end the request. */
    public function output(string $filename): void
    {
        $bytes = $this->build();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
        exit;
    }
}

/**
 * The small writer handed to a runningHeaderFooter() callback - exposes
 * just text()/line() (same signatures as TrackPdf's own, so the callback
 * body reads identically to normal page-drawing code), but appends into a
 * SEPARATE op buffer that TrackPdf::build() then appends onto one specific
 * already-finished page's content stream, rather than onto whatever page is
 * "currently open" (there is no "currently open" page any more by the time
 * running headers/footers run - see TrackPdf::build()).
 */
final class TrackPdfPageWriter
{
    private array $ops = [];
    private int $pageIndex = 0;

    public function __construct(private readonly TrackPdf $pdf)
    {
    }

    /** @internal called by TrackPdf::build() before invoking the callback for each page. */
    public function beginPage(int $pageIndex): void
    {
        $this->pageIndex = $pageIndex;
        $this->ops = [];
    }

    /** @internal called by TrackPdf::build() after the callback returns, to collect what it drew. */
    public function takeOps(): string
    {
        return implode("\n", $this->ops);
    }

    public function pageIndex(): int
    {
        return $this->pageIndex;
    }

    public function text(float $x, float $y, string $s, array $opts = []): void
    {
        $this->ops[] = $this->pdf->textOp($x, $y, $s, $opts);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $color = [200, 200, 200]): void
    {
        $this->ops[] = $this->pdf->lineOp($x1, $y1, $x2, $y2, $width, $color);
    }

    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        return $this->pdf->textWidth($s, $size, $bold);
    }

    public function pageWidth(): float { return $this->pdf->pageWidth(); }
    public function pageHeight(): float { return $this->pdf->pageHeight(); }
}
