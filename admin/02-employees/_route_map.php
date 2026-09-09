<?php
/**
 * admin/02-employees/_route_map.php  -  the collapsible "Route on map" panel.
 * Shared by day.php and _view_overview.php (day mode).
 *
 * A placeholder styled like a street map: real GPS points projected into an SVG,
 * a smooth blue route line, green "A" check-in pin, red "Z" check-out pin (only
 * when the day is closed), blue numbered shop pins. The real map API drops in
 * later, fed by the same $mapPoints array.
 *
 * Expects:
 *   $mapPoints    array of ['kind'=>'checkin'|'visit'|'checkout','no'=>?int,
 *                 'label'=>string,'at'=>string,'lat'=>float,'lng'=>float]
 *                 - already in travel order; at least 2 entries.
 *   $mapPanelId   string   id for the <details> (unique on the page)
 *   $mapPersistKey string  localStorage key ("...<Employee>.<date>")
 *   $mapFoot      string   caption under the map (already escaped/plain text)
 * Optional:
 *   $mapOpen      bool     start expanded (default false)
 *   $mapTrailUrl  string   URL returning a tracked worker's real GPS trail
 *                          ({ok, points:[{lat,lng}]}); when it has points the
 *                          map draws THAT instead of the checkpoint line.
 *                          Empty / omitted -> current behaviour unchanged.
 */

declare(strict_types=1);

/** @var array $mapPoints */
$mapTrailUrl = $mapTrailUrl ?? '';   // optional; unset -> no GPS-trail overlay
$rm_hasOut = (bool) array_filter($mapPoints, static fn($p) => $p['kind'] === 'checkout');
$rm_shops  = count(array_filter($mapPoints, static fn($p) => $p['kind'] === 'visit'));

// Project the real GPS points into the SVG box. Equirectangular is fine at city
// scale: scale lng by cos(lat) so the aspect ratio stays sane.
$rm_lats = array_column($mapPoints, 'lat');
$rm_lngs = array_column($mapPoints, 'lng');
$rm_kx   = cos(deg2rad((min($rm_lats) + max($rm_lats)) / 2));
$rm_xs   = array_map(static fn($lng) => $lng * $rm_kx, $rm_lngs);
$rm_minX = min($rm_xs);
$rm_minY = min($rm_lats);
$rm_spanX = max(max($rm_xs) - $rm_minX, 1e-9);
$rm_spanY = max(max($rm_lats) - $rm_minY, 1e-9);

$rm_W = 960; $rm_H = 380; $rm_pad = 54;
$rm_scale = min(($rm_W - 2 * $rm_pad) / $rm_spanX, ($rm_H - 2 * $rm_pad) / $rm_spanY);
$rm_offX  = ($rm_W - $rm_spanX * $rm_scale) / 2;
$rm_offY  = ($rm_H - $rm_spanY * $rm_scale) / 2;

$rm_project = static function (array $p) use ($rm_kx, $rm_minX, $rm_minY, $rm_spanY, $rm_scale, $rm_offX, $rm_offY): array {
    $x = $rm_offX + (($p['lng'] * $rm_kx) - $rm_minX) * $rm_scale;
    $y = $rm_offY + ($rm_spanY - ($p['lat'] - $rm_minY)) * $rm_scale; // invert: north up
    return [round($x, 1), round($y, 1)];
};
$rm_pts = array_map($rm_project, $mapPoints);

// Spread markers that sit on top of each other so every pin stays readable.
$rm_placed = [];
foreach ($rm_pts as &$rm_c) {
    $rm_bump = 0;
    foreach ($rm_placed as $rm_q) {
        while (hypot($rm_c[0] - $rm_q[0], $rm_c[1] - $rm_q[1]) < 30 && $rm_bump < 8) {
            $rm_c[0] += 16; $rm_c[1] -= 12; $rm_bump++;
        }
    }
    $rm_c[0] = max($rm_pad, min($rm_W - $rm_pad, $rm_c[0]));
    $rm_c[1] = max($rm_pad, min($rm_H - $rm_pad, $rm_c[1]));
    $rm_placed[] = $rm_c;
}
unset($rm_c);

// Smooth the route line with a Catmull-Rom -> cubic Bezier path.
$rm_path = '';
$rm_n = count($rm_pts);
if ($rm_n === 2) {
    $rm_path = "M{$rm_pts[0][0]},{$rm_pts[0][1]} L{$rm_pts[1][0]},{$rm_pts[1][1]}";
} elseif ($rm_n > 2) {
    $rm_path = "M{$rm_pts[0][0]},{$rm_pts[0][1]}";
    for ($i = 0; $i < $rm_n - 1; $i++) {
        $p0 = $rm_pts[max(0, $i - 1)];
        $p1 = $rm_pts[$i];
        $p2 = $rm_pts[$i + 1];
        $p3 = $rm_pts[min($rm_n - 1, $i + 2)];
        $rm_path .= sprintf(
            ' C%.1f,%.1f %.1f,%.1f %.1f,%.1f',
            $p1[0] + ($p2[0] - $p0[0]) / 6, $p1[1] + ($p2[1] - $p0[1]) / 6,
            $p2[0] - ($p3[0] - $p1[0]) / 6, $p2[1] - ($p3[1] - $p1[1]) / 6,
            $p2[0], $p2[1]
        );
    }
}

$rm_mark = static fn(array $p): string =>
    $p['kind'] === 'checkin' ? 'A' : ($p['kind'] === 'checkout' ? 'Z' : (string) (int) $p['no']);
$rm_subtitle = $rm_hasOut ? 'Check-in to check-out, in visit order' : 'Route so far - Employee has not checked out yet';
?>
    <details class="card day-map" id="<?= e($mapPanelId) ?>"<?= !empty($mapOpen) ? ' open' : '' ?>>
      <summary class="day-map__summary">
        <span class="day-map__summary-title"><i class="bi bi-map"></i> Route on map</span>
        <span class="section-note"><?= e($rm_subtitle) ?></span>
        <i class="bi bi-chevron-down day-map__chev"></i>
      </summary>

      <div class="day-map__canvas">
        <button type="button" class="day-map__refresh" data-map-refresh title="Reload to pick up new visits">
          <i class="bi bi-arrow-clockwise"></i> Refresh map
        </button>
        <button type="button" class="day-map__expand" title="Expand map to inspect"
                onclick="if (typeof TrackMapboxRoute !== 'undefined') { TrackMapboxRoute.openRoute(<?= json_encode('mb-' . $mapPanelId) ?>); }">
          <i class="bi bi-arrows-fullscreen"></i>
        </button>

        <?php
          // Real Mapbox map, fed by the same $mapPoints array as the SVG
          // fallback below. TrackMapboxRoute.render() only clears/replaces
          // this div's content if Mapbox's script loaded AND a real token
          // is configured (see mapbox-route.js) - until then the SVG sketch
          // underneath stays visible exactly as before.
          $rm_pointsJson = json_encode(array_map(static function (array $p): array {
              return [
                  'kind' => $p['kind'], 'label' => $p['label'], 'at' => $p['at'],
                  'lat' => $p['lat'], 'lng' => $p['lng'], 'no' => $p['no'] ?? null,
              ];
          }, $mapPoints));
        ?>
        <div class="day-map__real mb-route-map" id="mb-<?= e($mapPanelId) ?>"
             data-map-token="<?= e(defined('MAPBOX_ACCESS_TOKEN') ? MAPBOX_ACCESS_TOKEN : '') ?>"
             data-map-points='<?= e($rm_pointsJson) ?>'
             <?php if (!empty($mapTrailUrl)): ?>data-ping-trail-url="<?= e($mapTrailUrl) ?>"<?php endif; ?>>
        <span class="day-map__badge"><i class="bi bi-info-circle"></i> Sketch from GPS points - live map is integrated later</span>
        <svg class="day-map__svg" viewBox="0 0 <?= $rm_W ?> <?= $rm_H ?>" preserveAspectRatio="xMidYMid slice" role="img"
             aria-label="Route through <?= $rm_shops ?> shop<?= $rm_shops === 1 ? '' : 's' ?>">
          <defs>
            <pattern id="rm-grid-<?= e($mapPanelId) ?>" width="46" height="46" patternUnits="userSpaceOnUse">
              <path d="M46 0H0V46" fill="none" stroke="#e4e7ea" stroke-width="1"/>
            </pattern>
          </defs>

          <rect x="0" y="0" width="<?= $rm_W ?>" height="<?= $rm_H ?>" fill="#eef1ec"/>
          <rect x="0" y="0" width="<?= $rm_W ?>" height="<?= $rm_H ?>" fill="url(#rm-grid-<?= e($mapPanelId) ?>)"/>
          <ellipse cx="<?= (int) ($rm_W * 0.16) ?>" cy="<?= (int) ($rm_H * 0.8) ?>" rx="150" ry="90" fill="#e0ebdd"/>
          <ellipse cx="<?= (int) ($rm_W * 0.9) ?>" cy="<?= (int) ($rm_H * 0.2) ?>" rx="120" ry="80" fill="#dce9ef"/>

          <path d="<?= e($rm_path) ?>" class="day-map__route-case" />
          <path d="<?= e($rm_path) ?>" class="day-map__route<?= $rm_hasOut ? '' : ' day-map__route--live' ?>" />

          <?php foreach ($mapPoints as $i => $p): [$x, $y] = $rm_pts[$i];
            $isEnd = $p['kind'] === 'checkin' || $p['kind'] === 'checkout'; ?>
            <g class="day-map__pin day-map__pin--<?= e($p['kind']) ?>">
              <?php if ($isEnd): ?>
                <path d="M<?= $x ?>,<?= $y ?> c-11,-13 -16,-20 -16,-28 a16,16 0 1 1 32,0 c0,8 -5,15 -16,28 z" class="day-map__pin-body"/>
                <text x="<?= $x ?>" y="<?= $y - 22 ?>" text-anchor="middle" class="day-map__pin-text"><?= $rm_mark($p) ?></text>
              <?php else: ?>
                <circle cx="<?= $x ?>" cy="<?= $y ?>" r="14" class="day-map__pin-body"/>
                <text x="<?= $x ?>" y="<?= $y + 4.5 ?>" text-anchor="middle" class="day-map__pin-text"><?= $rm_mark($p) ?></text>
              <?php endif; ?>
            </g>
          <?php endforeach; ?>

          <?php if (!$rm_hasOut): [$lx, $ly] = $rm_pts[array_key_last($rm_pts)]; ?>
            <circle cx="<?= $lx ?>" cy="<?= $ly ?>" r="22" class="day-map__here-halo"/>
          <?php endif; ?>
        </svg>
        </div><!-- .day-map__real -->
        <script>if (typeof TrackMapboxRoute !== 'undefined') { TrackMapboxRoute.render(<?= json_encode('mb-' . $mapPanelId) ?>); }</script>
      </div>

      <p class="section-note day-map__foot"><?= e($mapFoot) ?></p>

      <ol class="day-map__list">
        <?php foreach ($mapPoints as $p):
          $pMap = map_link($p['lat'], $p['lng']);
          $pAt  = new DateTimeImmutable($p['at']);
        ?>
        <li class="day-map__row day-map__row--<?= e($p['kind']) ?>">
          <span class="day-map__mark"><?= $rm_mark($p) ?></span>
          <span class="day-map__label"><strong><?= e($p['label']) ?></strong></span>
          <span class="day-map__time"><?= e($pAt->format('g:i A')) ?></span>
          <span class="day-map__coord">
            <?php if ($pMap): ?>
              <a href="<?= e($pMap) ?>" target="_blank" rel="noopener"><?= e(latlng($p['lat'], $p['lng'])) ?></a>
            <?php else: ?>
              <?= e(latlng($p['lat'], $p['lng'])) ?>
            <?php endif; ?>
          </span>
        </li>
        <?php endforeach; ?>
        <?php if (!$rm_hasOut): ?>
        <li class="day-map__row day-map__row--pending">
          <span class="day-map__mark day-map__mark--pending"><i class="bi bi-three-dots"></i></span>
          <span class="day-map__label"><em>Not checked out yet</em></span>
          <span class="day-map__time"></span>
          <span class="day-map__coord"></span>
        </li>
        <?php endif; ?>
      </ol>
    </details>

    <script>
      (function () {
        // Persist this panel's open/closed state per day, so it survives a
        // refresh until the admin explicitly toggles the "Route on map" bar.
        var box = document.getElementById(<?= json_encode($mapPanelId) ?>);
        if (!box) return;
        var key = <?= json_encode($mapPersistKey) ?>;
        try { if (localStorage.getItem(key) === '1') box.open = true; } catch (e) {}
        box.addEventListener('toggle', function () {
          try { localStorage.setItem(key, box.open ? '1' : '0'); } catch (e) {}
        });

        // "Refresh map" - reload so the panel picks up any new visits. The
        // button is only visible while the panel is open (CSS), and we hide it
        // the moment it is clicked so it cannot be double-fired mid-reload.
        var refresh = box.querySelector('[data-map-refresh]');
        if (refresh) refresh.addEventListener('click', function () {
          refresh.disabled = true;
          refresh.classList.add('is-loading');
          location.reload();
        });
      })();
    </script>
