<?php
/**
 * Server-side distance & time maths. NEVER trust km sent from the phone.
 *
 * - haversine_km() : straight-line km between two GPS points
 * - road_km() : haversine * road_factor (Settings)
 * - day totals : sum of every hop (check-in -> shop 1 -> ... -> check-out)
 * - time split : shop time (dwell) vs road time (travel) vs total hours
 */

declare(strict_types=1);

// Re-include guard: several pages `require` this file directly, and a couple
// now do so alongside includes that also pull it in - without this, the
// second require fatals with "Cannot redeclare haversine_km()". Keeping the
// plain `require` at call sites (rather than switching them all to
// require_once) means this one guard covers every current and future caller.
if (defined('TRACK_DISTANCE_LOADED')) {
    return;
}
define('TRACK_DISTANCE_LOADED', true);

const EARTH_RADIUS_KM = 6371.0088;

/** Great-circle distance in kilometres. */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

    return EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
}

/** Straight-line metres - handy for the GPS-radius fraud checks. */
function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    return haversine_km($lat1, $lng1, $lat2, $lng2) * 1000.0;
}

/** Apply the configurable road factor to a straight-line distance. */
function road_km(float $straightKm, ?float $factor = null): float
{
    $factor ??= road_factor();
    return round($straightKm * $factor, 3);
}

// A hop's real road distance is trusted as-is up to this multiple of its
// straight-line (haversine) distance. Past it, the Directions result is
// treated as a snap-to-road artifact, not a real answer - see
// road_km_real()'s "sanity cap" step for the full reasoning. 6x comfortably
// covers legitimate detours (one-way systems, a river/rail crossing forcing
// a long way around) while still catching the specific failure mode that
// matters here: two points a few meters apart both snapping onto the same
// road, so Directions computes a real drivable loop around the block that
// is enormous relative to how close the points actually are.
const ROAD_SANITY_MULTIPLE = 6.0;

// Below this straight-line distance, the multiple-based sanity check above
// is too noisy to rely on alone (a 2m hop "capped at 6x" is still only 12m,
// so the cap does nothing) - a hop this short additionally gets capped to
// this many meters flat. Chosen well above realistic GPS drift (a few
// meters) so it never clips a real short walk between adjacent shops, but
// well below "drove around the block" (typically 150m+ for even a small
// block).
const ROAD_SANITY_SHORT_HOP_M = 25.0;
const ROAD_SANITY_SHORT_HOP_KM_THRESHOLD = 0.05; // 50m

/**
 * Real road distance/duration between two points via the Mapbox Directions
 * API. Distance-by-road is mandatory here - every hop's GPS points come
 * from the field employee's phone, which the business trusts as accurate,
 * so the app's job is purely to turn two trusted points into the correct
 * real-road distance between them, never a straight-line guess. That
 * number can end up feeding payment/reimbursement or contractual distance
 * terms, so silently substituting an estimate is not acceptable - a failed
 * or slow lookup is retried, not quietly downgraded.
 *
 * Two calls are attempted before giving up: a normal-timeout first attempt,
 * then one retry with a longer timeout if that first attempt fails
 * (network hiccup, brief Mapbox slowness) - see ROAD_TIMEOUT_MS /
 * ROAD_RETRY_TIMEOUT_MS. This runs inline during check-in/checkout/visit
 * save, so the field employee's phone can wait up to roughly the sum of
 * both timeouts on a bad-signal save - accepted deliberately, per the
 * "distance must be real road, always" requirement, rather than the old
 * behavior of falling back to an instant but inaccurate straight-line
 * estimate.
 *
 * Sanity cap: Mapbox Directions never reports "0" or "no route" for two
 * points that are genuinely almost the same spot - it snaps each one to
 * the nearest mapped road and computes a real, legal DRIVE between those
 * two snapped points. When both points sit on the same street, "drive from
 * here back to here" can come back as a full loop around the block (one-way
 * streets make the loop mandatory, not optional) - a real driving route,
 * but wildly disproportionate to how far apart the two points actually
 * are. Left uncapped, that inflated number would land directly in
 * road_km/hop_road_km and any payment/reimbursement calculation built on
 * them. So: after a successful Directions call, the result is compared
 * against the straight-line distance; a result more than ROAD_SANITY_MULTIPLE
 * times (or, for very short hops, more than ROAD_SANITY_SHORT_HOP_M meters)
 * the straight-line distance is treated as a snap artifact and capped back
 * down - see cap_road_km() below. This keeps the number stable and
 * trustworthy long-term instead of occasionally spiking on a geometry
 * fluke, without ever pretending the hop didn't happen.
 *
 * Cached in `road_distance_cache`, keyed by the two points rounded to 5
 * decimal places (~1m) - a given physical hop (e.g. this check-in spot to
 * this shop) is looked up from the Directions API at most once ever, no
 * matter how many times it recurs across different days or is recomputed
 * on every dashboard page view (field/home/_repo.php calls compute_day()
 * live on every view of the still-open day). The CAPPED value is what gets
 * cached (not the raw Directions figure) - the cache is "the trustworthy
 * road distance for this hop", not "whatever Directions happened to say".
 *
 * @return array{km: float, seconds: ?int, source: 'directions'|'directions_capped'|'cache'|'unavailable'}
 *         source is 'unavailable' only when both attempts failed AND no
 *         token/cache entry exists - km is then a haversine*factor number
 *         as a last-resort placeholder, never silently treated as a real
 *         road distance elsewhere (callers should treat 'unavailable' as
 *         "retry later", not as a finished number - see compute_day()).
 */
const ROAD_TIMEOUT_MS = 2500;
const ROAD_RETRY_TIMEOUT_MS = 6000;

function road_km_real(float $lat1, float $lng1, float $lat2, float $lng2, ?float $factor = null): array
{
    global $pdo;

    $straightKm = haversine_km($lat1, $lng1, $lat2, $lng2);
    $placeholder = ['km' => road_km($straightKm, $factor), 'seconds' => null, 'source' => 'unavailable'];

    // TRACK_SKIP_REAL_ROAD_DISTANCE: an explicit opt-out for read-only
    // diagnostic contexts (test.php's compute_day() sanity check) that must
    // never make a real network call or write a cache row - they only need
    // to verify compute_day()'s return SHAPE, not real distance accuracy.
    // Never define this outside such a context.
    if (defined('TRACK_SKIP_REAL_ROAD_DISTANCE') && TRACK_SKIP_REAL_ROAD_DISTANCE) {
        return $placeholder;
    }

    if (!defined('MAPBOX_ACCESS_TOKEN') || MAPBOX_ACCESS_TOKEN === '' || str_starts_with(MAPBOX_ACCESS_TOKEN, 'REPLACE-WITH')) {
        return $placeholder;
    }

    // Round to 5 decimals (~1m) - matches road_distance_cache's column
    // precision, so two GPS fixes for "the same physical spot" that differ
    // only in noise past the 5th decimal still hit the same cache row.
    $r1lat = round($lat1, 5); $r1lng = round($lng1, 5);
    $r2lat = round($lat2, 5); $r2lng = round($lng2, 5);

    if ($pdo instanceof PDO) {
        try {
            $cached = $pdo->prepare(
                'SELECT road_km, duration_seconds FROM road_distance_cache
                  WHERE from_lat = ? AND from_lng = ? AND to_lat = ? AND to_lng = ? LIMIT 1'
            );
            $cached->execute([$r1lat, $r1lng, $r2lat, $r2lng]);
            $row = $cached->fetch();
            if ($row) {
                return ['km' => (float) $row['road_km'], 'seconds' => $row['duration_seconds'] !== null ? (int) $row['duration_seconds'] : null, 'source' => 'cache'];
            }
        } catch (Throwable $e) {
            // Cache table missing/unreachable - fall through to a real API
            // call rather than failing the whole thing.
        }
    }

    $url = sprintf(
        'https://api.mapbox.com/directions/v5/mapbox/driving/%F,%F;%F,%F?geometries=geojson&overview=false&access_token=%s',
        $lng1, $lat1, $lng2, $lat2, urlencode(MAPBOX_ACCESS_TOKEN)
    );

    // Attempt 1 (normal timeout), then attempt 2 (longer timeout) if the
    // first one failed outright or timed out - a brief network hiccup or a
    // moment of Mapbox slowness should not permanently downgrade a hop to
    // "unavailable" when trying again a few seconds later would likely
    // succeed. Both attempts hit the same URL; only the timeout differs.
    $attemptTimeouts = [ROAD_TIMEOUT_MS, ROAD_RETRY_TIMEOUT_MS];
    $data = null;
    foreach ($attemptTimeouts as $timeoutMs) {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errored = curl_errno($ch) !== 0;
            // No curl_close() - deprecated since PHP 8.5 (a no-op since PHP
            // 8.0; the handle is freed automatically once $ch goes out of scope).

            if ($errored || $httpCode !== 200 || $body === false) {
                continue; // try the next (longer) timeout, if any remain
            }

            $decoded = json_decode($body, true);
            $meters = $decoded['routes'][0]['distance'] ?? null;
            $seconds = $decoded['routes'][0]['duration'] ?? null;
            if (!is_numeric($meters) || !is_numeric($seconds)) {
                continue;
            }

            $data = ['meters' => (float) $meters, 'seconds' => (int) round((float) $seconds)];
            break;
        } catch (Throwable $e) {
            continue;
        }
    }

    if ($data === null) {
        // Both attempts failed - return the placeholder, clearly marked
        // 'unavailable' so callers know this is not a trusted road figure.
        return $placeholder;
    }

    $rawKm = round($data['meters'] / 1000, 3);
    [$finalKm, $wasCapped] = cap_road_km($rawKm, $straightKm);
    $source = $wasCapped ? 'directions_capped' : 'directions';

    if ($pdo instanceof PDO) {
        try {
            $pdo->prepare(
                'INSERT INTO road_distance_cache (from_lat, from_lng, to_lat, to_lng, road_km, duration_seconds)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE road_km = VALUES(road_km), duration_seconds = VALUES(duration_seconds)'
            )->execute([$r1lat, $r1lng, $r2lat, $r2lng, $finalKm, $data['seconds']]);
        } catch (Throwable $e) {
            // Caching is an optimization, not a requirement - a failed
            // write just means this hop gets looked up again next time.
        }
    }

    return ['km' => $finalKm, 'seconds' => $data['seconds'], 'source' => $source];
}

/**
 * Sanity-caps a real Directions road-km figure against the straight-line
 * distance for the same hop - see road_km_real()'s docblock for why this
 * exists (Mapbox never returns "0"/"no route" for near-identical points; it
 * snaps to the nearest road and drives a real, sometimes very long, loop).
 *
 * Two independent checks, either of which can trigger the cap:
 *   - ratio check: roadKm > straightKm * ROAD_SANITY_MULTIPLE
 *   - short-hop check: for straightKm below ROAD_SANITY_SHORT_HOP_KM_THRESHOLD,
 *     roadKm > ROAD_SANITY_SHORT_HOP_M in absolute meters
 * The short-hop check exists because the ratio check alone is meaningless
 * at very small distances (6x a 2m hop is still only 12m - nowhere near
 * enough to catch a 400m loop-the-block artifact).
 *
 * When capped, the reported value is the LARGER of the straight-line
 * distance and a small fixed floor (never below the straight-line distance
 * itself - a road can never be shorter than "as the crow flies" for two
 * distinct points) so the capped figure still reads as a real, physically
 * possible number rather than an arbitrary round value.
 *
 * @return array{0: float, 1: bool} [finalKm, wasCapped]
 */
function cap_road_km(float $roadKm, float $straightKm): array
{
    $isShortHop = $straightKm < ROAD_SANITY_SHORT_HOP_KM_THRESHOLD;
    $shortHopCapKm = ROAD_SANITY_SHORT_HOP_M / 1000;

    $triggered = ($roadKm > $straightKm * ROAD_SANITY_MULTIPLE)
        || ($isShortHop && $roadKm > $shortHopCapKm);

    if (!$triggered) {
        return [$roadKm, false];
    }

    $cap = $isShortHop ? max($straightKm, $shortHopCapKm) : $straightKm * ROAD_SANITY_MULTIPLE;
    return [round(max($straightKm, $cap), 3), true];
}

/**
 * Build the ordered list of points for a working day:
 * [check-in] -> [visit seq 1] -> [visit seq 2] -> ... -> [check-out?]
 *
 * @param array $attendance row from `attendance`
 * @param array $visits rows from `visits`, ordered by seq ASC
 * @return array<int,array{kind:string,ref_id:?int,lat:float,lng:float,at:string}>
 */
function day_points(array $attendance, array $visits): array
{
    $points = [];

    // check_in_lat/lng are NOT NULL by schema (a check-in always needs a real
    // fix - see checkin_validate() in field/checkinout/_repo.php), but this
    // guard keeps a null/missing value from ever being silently cast to
    // (float) null = 0.0 and poisoning the whole day's distance chain with a
    // bogus leg from Null Island, the same way the check-out point below is
    // guarded.
    if ($attendance['check_in_lat'] !== null && $attendance['check_in_lng'] !== null) {
        $points[] = [
            'kind' => 'checkin',
            'ref_id' => null,
            'lat' => (float) $attendance['check_in_lat'],
            'lng' => (float) $attendance['check_in_lng'],
            'at' => $attendance['check_in_at'],
        ];
    }

    foreach ($visits as $v) {
        $points[] = [
            'kind' => 'visit',
            'ref_id' => (int) $v['id'],
            'lat' => (float) $v['lat'],
            'lng' => (float) $v['lng'],
            'at' => $v['arrived_at'],
        ];
    }

    if (!empty($attendance['check_out_at']) && $attendance['check_out_lat'] !== null) {
        $points[] = [
            'kind' => 'checkout',
            'ref_id' => null,
            'lat' => (float) $attendance['check_out_lat'],
            'lng' => (float) $attendance['check_out_lng'],
            'at' => $attendance['check_out_at'],
        ];
    }

    return $points;
}

/**
 * Compute every hop for a day plus the day totals and the 3-way time split.
 *
 * Returns:
 * [
 * 'hops' => [ { leg_index, from_*, to_*, straight_km, road_km, seconds, speed_kmh }, ... ],
 * 'totals' => {
 * total_hops, straight_km, road_km, road_factor_used,
 * total_seconds, shop_seconds, road_seconds
 * }
 * ]
 *
 * Time split:
 * total_seconds = check_out - check_in (null if day still open)
 * road_seconds = sum of hop seconds (travel between consecutive points)
 * shop_seconds = sum of dwell at each visit (left_at - arrived_at)
 * Note road_seconds + shop_seconds need not equal total_seconds exactly
 * (rounding, waiting at attendance point, etc.) - report all three, as the spec says.
 */
function compute_day(array $attendance, array $visits, ?float $factor = null): array
{
    $factor ??= road_factor();
    $points = day_points($attendance, $visits);

    $hops = [];
    $sumStraight = 0.0;
    $sumRoad = 0.0;
    $roadSeconds = 0;

    for ($i = 0; $i < count($points) - 1; $i++) {
        $a = $points[$i];
        $b = $points[$i + 1];

        $straight = round(haversine_km($a['lat'], $a['lng'], $b['lat'], $b['lng']), 3);

        // Real road distance (Mapbox Directions API), mandatory - see
        // road_km_real()'s own docblock for the retry-then-'unavailable'
        // behavior (never a silent straight-line substitute) and the
        // sanity cap that guards against a snap-to-road loop artifact.
        $real = road_km_real($a['lat'], $a['lng'], $b['lat'], $b['lng'], $factor);
        $rkm = $real['km'];

        // Elapsed time is ALWAYS the real clock difference between the two
        // timestamps - never the Directions API's theoretical drive-time
        // estimate. Those measure different things (how long the employee
        // actually took, including any stop/traffic/detour, vs. how long
        // Mapbox thinks the route normally takes at driving speed) - using
        // the API's number here would silently corrupt productive-time and
        // fraud speed-check math, which depend on what really happened.
        $seconds = max(0, strtotime($b['at']) - strtotime($a['at']));
        $hours = $seconds / 3600;
        $speed = $hours > 0 ? round($rkm / $hours, 2) : null;

        $hops[] = [
            'leg_index' => $i,
            'from_kind' => $a['kind'],
            'from_ref_id' => $a['ref_id'],
            'from_lat' => $a['lat'],
            'from_lng' => $a['lng'],
            'from_at' => $a['at'],
            'to_kind' => $b['kind'],
            'to_ref_id' => $b['ref_id'],
            'to_lat' => $b['lat'],
            'to_lng' => $b['lng'],
            'to_at' => $b['at'],
            'straight_km' => $straight,
            'road_km' => $rkm,
            'road_km_source' => $real['source'], // 'directions' | 'directions_capped' | 'cache' | 'unavailable' - see road_km_real()
            'seconds' => $seconds,
            'speed_kmh' => $speed,
        ];

        $sumStraight += $straight;
        $sumRoad += $rkm;
        $roadSeconds += $seconds;
    }

    // Shop time = sum of dwell at each visit.
    $shopSeconds = 0;
    foreach ($visits as $v) {
        if (!empty($v['arrived_at']) && !empty($v['left_at'])) {
            $shopSeconds += max(0, strtotime($v['left_at']) - strtotime($v['arrived_at']));
        }
    }

    $totalSeconds = null;
    if (!empty($attendance['check_in_at']) && !empty($attendance['check_out_at'])) {
        $totalSeconds = max(0, strtotime($attendance['check_out_at']) - strtotime($attendance['check_in_at']));
    }

    return [
        'hops' => $hops,
        'totals' => [
            'total_hops' => count($hops),
            'straight_km' => round($sumStraight, 3),
            'road_km' => round($sumRoad, 3),
            'road_factor_used' => $factor,
            'total_seconds' => $totalSeconds,
            'shop_seconds' => $shopSeconds,
            'road_seconds' => $roadSeconds,
        ],
    ];
}

/** Seconds -> "H घण्टा M मिनेट" style; keep simple for the field UI. */
function fmt_hm(int $seconds): string
{
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return sprintf('%dh %02dm', $h, $m);
}

/**
 * SELF-HEAL: fill in any visit that is missing its own hop distance/time
 * (hop_seconds IS NULL) on days that are NOT checked out yet.
 *
 * Normally field/visit/api/save.php computes each visit's hop the moment it
 * is logged, and field/checkinout/api/checkout.php recomputes the whole day
 * authoritatively at check-out. This is the belt-and-braces third layer: if
 * for any reason (a stale PHP OpCache right after a deploy, a save that hit
 * a transient Mapbox timeout, an old row from before the feature existed) a
 * visit still has NULL hop_seconds, the next admin page that shows distances
 * calls this and the gap closes itself - the admin never sees a permanent
 * "0" or "distance pending".
 *
 * Cheap: does nothing (one indexed COUNT) when there is nothing to fix, and
 * a field day has only a handful of visits. road_km_real() is cached, so a
 * hop it has seen before (very common - same shops, same routes) costs no
 * API call. Safe to call on every relevant page load.
 *
 * @param int|null $employeeId  limit to one employee's open day (dashboard
 *                              route widget, employee detail); null = every
 *                              open day (the global Visits / Alerts lists).
 * @return int  number of visit rows updated
 */
function heal_missing_visit_hops(PDO $pdo, ?int $employeeId = null): int
{
    $where = 'a.check_out_at IS NULL AND v.hop_seconds IS NULL';
    $args  = [];
    if ($employeeId !== null) {
        $where .= ' AND v.employee_id = ?';
        $args[] = $employeeId;
    }

    $rows = $pdo->prepare(
        "SELECT v.id, v.seq, v.lat, v.lng, v.arrived_at, v.attendance_id,
                a.check_in_lat, a.check_in_lng, a.check_in_at
           FROM visits v JOIN attendance a ON a.id = v.attendance_id
          WHERE $where
          ORDER BY v.attendance_id, v.seq"
    );
    $rows->execute($args);
    $todo = $rows->fetchAll();
    if (!$todo) {
        return 0;
    }

    $prevStmt = $pdo->prepare(
        'SELECT lat, lng, arrived_at FROM visits
          WHERE attendance_id = ? AND seq < ? ORDER BY seq DESC LIMIT 1'
    );
    $upd = $pdo->prepare(
        'UPDATE visits SET hop_straight_km = ?, hop_road_km = ?, hop_seconds = ? WHERE id = ?'
    );

    $done = 0;
    foreach ($todo as $r) {
        $prevStmt->execute([(int) $r['attendance_id'], (int) $r['seq']]);
        $prev = $prevStmt->fetch();

        $fromLat = $prev ? (float) $prev['lat'] : (float) ($r['check_in_lat'] ?? 0);
        $fromLng = $prev ? (float) $prev['lng'] : (float) ($r['check_in_lng'] ?? 0);
        $fromAt  = $prev ? $prev['arrived_at']  : ($r['check_in_at'] ?? null);

        $toLat = (float) $r['lat'];
        $toLng = (float) $r['lng'];
        if (($fromLat === 0.0 && $fromLng === 0.0) || ($toLat === 0.0 && $toLng === 0.0)) {
            continue; // no usable point - leave it for check-out to sort out
        }

        try {
            $straight = round(haversine_km($fromLat, $fromLng, $toLat, $toLng), 3);
            $real     = road_km_real($fromLat, $fromLng, $toLat, $toLng);
            $roadKm   = ($real['km'] > 0) ? $real['km'] : $straight;
            $hopSecs  = $fromAt ? max(0, strtotime($r['arrived_at']) - strtotime((string) $fromAt)) : null;
            $upd->execute([$straight, $roadKm, $hopSecs, (int) $r['id']]);
            $done++;
        } catch (Throwable $e) {
            error_log('heal_missing_visit_hops: visit ' . $r['id'] . ' - ' . $e->getMessage());
        }
    }
    return $done;
}
