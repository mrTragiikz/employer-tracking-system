<?php
/**
 * POST field/api-v2/ping.php - the Android app reports GPS positions while
 * the worker is checked in (live location tracking).
 *
 * Header: Authorization: Bearer <token>
 * Body (JSON):
 *   { "points": [
 *       { "lat": 27.6, "lng": 84.4, "accuracy_m": 12.0, "speed_kmh": 24.0,
 *         "recorded_at": "2026-09-09T14:32:05+05:45" },
 *       ...   (a batch - the app buffers a few and sends them together)
 *   ] }
 *
 * 200 always (so the app never retries a "no" forever):
 *   { "ok": true, "tracking": "on"|"off"|"not_checked_in"|"checked_out",
 *     "stored": N, "next_interval_s": 90 }
 *
 * tracking != "on"  ->  the app STOPS its location service.
 *
 * DISPLAY-ONLY. Nothing here is read by compute_day() / the audit path. A
 * ping with a wild accuracy or outside Nepal is dropped server-side.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/checkinout/_repo.php'; // field_today_attendance()
api_method('POST');

$me    = api_require();
$today = server_today();
$now   = server_now();

$interval = live_tracking_interval_s();

// Kill switch off -> tell the app to stop, store nothing.
if (!live_tracking_enabled()) {
    json_out(['ok' => true, 'tracking' => 'off', 'stored' => 0, 'next_interval_s' => $interval]);
}

$att = field_today_attendance($pdo, (int) $me['id'], $today);
if ($att === null) {
    json_out(['ok' => true, 'tracking' => 'not_checked_in', 'stored' => 0, 'next_interval_s' => $interval]);
}
if (!empty($att['check_out_at'])) {
    json_out(['ok' => true, 'tracking' => 'checked_out', 'stored' => 0, 'next_interval_s' => $interval]);
}

$in     = body();
$points = is_array($in['points'] ?? null) ? $in['points'] : [];
if (count($points) > 500) {
    $points = array_slice($points, 0, 500); // hard cap per request
}

// Rough Nepal bounding box - anything outside is a bad fix, not a real location.
$NP_LAT_MIN = 26.0; $NP_LAT_MAX = 31.0;
$NP_LNG_MIN = 79.0; $NP_LNG_MAX = 89.0;

$ins = $pdo->prepare(
    'INSERT INTO location_pings
        (attendance_id, employee_id, lat, lng, accuracy_m, speed_kmh,
         recorded_at, received_at, device_id)
     VALUES (:att, :emp, :lat, :lng, :acc, :spd, :rec, :now, :dev)'
);

$stored  = 0;
$deviceId = $me['device_id'] ?? null;

foreach ($points as $p) {
    if (!is_array($p)) {
        continue;
    }
    $lat = filter_var($p['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($p['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false) {
        continue;
    }
    if ($lat < $NP_LAT_MIN || $lat > $NP_LAT_MAX || $lng < $NP_LNG_MIN || $lng > $NP_LNG_MAX) {
        continue;
    }

    $acc = filter_var($p['accuracy_m'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($acc === false) {
        $acc = null;
    } elseif ($acc > 150) {
        continue; // too fuzzy to be worth drawing
    }

    $spd = filter_var($p['speed_kmh'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($spd === false || $spd < 0 || $spd > 200) {
        $spd = null;
    }

    // phone clock -> clamp to a sane window around now (a wildly wrong device
    // clock must never put a ping in next week or last year)
    $rec = $now;
    if (!empty($p['recorded_at'])) {
        $ts = strtotime((string) $p['recorded_at']);
        if ($ts !== false) {
            $nowTs = strtotime($now);
            if ($ts <= $nowTs + 120 && $ts >= $nowTs - 86400) {
                $rec = date('Y-m-d H:i:s', $ts);
            }
        }
    }

    $ins->execute([
        ':att' => (int) $att['id'],
        ':emp' => (int) $me['id'],
        ':lat' => round($lat, 7),
        ':lng' => round($lng, 7),
        ':acc' => $acc !== null ? round($acc, 1) : null,
        ':spd' => $spd !== null ? round($spd, 1) : null,
        ':rec' => $rec,
        ':now' => $now,
        ':dev' => $deviceId,
    ]);
    $stored++;
}

json_out([
    'ok'              => true,
    'tracking'        => 'on',
    'stored'          => $stored,
    'next_interval_s' => $interval,
]);
