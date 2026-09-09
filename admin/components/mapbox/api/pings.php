<?php
/**
 * GET admin/components/mapbox/api/pings.php?employee=N&date=YYYY-MM-DD
 *
 * The real GPS trail for one employee on one day, for the route maps
 * (Dashboard "Today's Route", Routes & Map, Employee day detail). When this
 * returns points, mapbox-route.js draws them as the route line instead of the
 * Directions-snapped guess. When it returns [], the maps keep their current
 * checkpoint-line behaviour - so web / iOS workers and past days look
 * unchanged.
 *
 * 200: { ok:true, tracking:bool, points: [ {lat,lng,recorded_at}, ... ] }
 *
 * GET, admin session, no CSRF (read only).
 */

declare(strict_types=1);

require dirname(__DIR__, 4) . '/includes/api.php'; // -> bootstrap (loads settings.php)
$me = require_admin();
api_method('GET');
require dirname(__DIR__, 4) . '/includes/tracking.php';

if (!live_tracking_enabled()) {
    json_out(['ok' => true, 'tracking' => false, 'points' => []]);
}

$employeeId = (int) ($_GET['employee'] ?? 0);
$date = (string) ($_GET['date'] ?? server_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > server_today()) {
    $date = server_today();
}

if ($employeeId <= 0) {
    json_out(['ok' => true, 'tracking' => true, 'points' => []]);
}

$points = tracking_pings($pdo, $employeeId, $date);

// Trim the payload - the map only needs coordinates + time.
$slim = array_map(static fn(array $p): array => [
    'lat' => $p['lat'],
    'lng' => $p['lng'],
    'at'  => $p['recorded_at'],
], $points);

json_out(['ok' => true, 'tracking' => true, 'points' => $slim]);
