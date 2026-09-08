<?php
/**
 * includes/static_map.php - a real, non-interactive route map image (PNG
 * bytes) for embedding in exported PDF statements, via the Mapbox Static
 * Images API. Server-side only - unlike admin/components/mapbox (the live,
 * interactive GL JS map the admin browses), a PDF page is a flat static
 * document, so it needs a flat static image, not a script.
 *
 * Deliberately requests the classic 'streets-v12' style, NOT 'standard' (the
 * style admin/components/mapbox's live map uses) - Standard is a 3D/config
 * style built for the interactive GL JS renderer and is not compatible with
 * the raster Static Images endpoint (confirmed: it 400s with "Unsupported
 * rasterarray tileset format"). streets-v12 is Mapbox's classic raster-
 * compatible style and renders correctly through this endpoint.
 */

declare(strict_types=1);

/**
 * Fetch a static PNG map image with numbered pins for a route's points.
 * Returns the raw PNG bytes, or null if the token is missing/placeholder,
 * there are no points, or the request fails for any reason - callers must
 * treat null as "skip the map image", never as an error to surface to the
 * person exporting a statement (the report's numbers are what matters; a
 * missing map thumbnail is a cosmetic degradation, not a broken export).
 *
 * @param array<int,array{lat:float,lng:float,kind:string,no?:?int}> $points
 *        same shape day_points()/employee_timeline() already produce.
 */
function static_route_map_png(array $points, int $width = 640, int $height = 400): ?string
{
    if (!defined('MAPBOX_ACCESS_TOKEN') || MAPBOX_ACCESS_TOKEN === '' || str_starts_with(MAPBOX_ACCESS_TOKEN, 'REPLACE-WITH')) {
        return null;
    }
    if (!$points) {
        return null;
    }
    // The Static Images API caps at 100 overlay markers - a real field day
    // never gets close, but capped defensively so a data anomaly can't
    // build a URL long enough to be rejected outright.
    $points = array_slice($points, 0, 100);

    $pins = [];
    foreach ($points as $i => $p) {
        $color = match ($p['kind']) {
            'checkin'  => '26964a',
            'checkout' => 'dc4b4b',
            default    => '3977c9',
        };
        $label = $p['kind'] === 'checkin' ? 'a' : ($p['kind'] === 'checkout' ? 'b' : (string) min(99, (int) ($p['no'] ?? ($i + 1))));
        // Static Images pin labels only accept a single a-z/0-99 character -
        // 'a'/'b' stand in for check-in/check-out (no 'in'/'out' word fits),
        // matching the live map's own A/Z convention closely enough for a
        // quick-glance thumbnail; the numbered table alongside it in the
        // PDF carries the real labels.
        $pins[] = "pin-s-{$label}+{$color}(" . $p['lng'] . ',' . $p['lat'] . ')';
    }
    $overlay = implode(',', $pins);

    $url = 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/static/' . $overlay . '/auto/'
        . $width . 'x' . $height
        . '?padding=40&access_token=' . urlencode(MAPBOX_ACCESS_TOKEN);

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT_MS => 6000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch) !== 0 || $httpCode !== 200 || $body === false) {
            return null;
        }
        if (substr($body, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null; // an error JSON body, or something unexpected - never hand non-PNG bytes to TrackPdf::image()
        }
        return $body;
    } catch (Throwable $e) {
        return null;
    }
}
