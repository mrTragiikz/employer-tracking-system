<?php
/**
 * GET admin/02-employees/api/live-track.php?id=N[&date=YYYY-MM-DD][&since=<ISO>]
 *
 * Feeds the "Live Track" modal on the employee detail page. Returns the
 * worker's current tracking state plus their GPS trail for the day.
 *
 * 200:
 *   { ok:true,
 *     state: "live"|"checked_out"|"not_started"|"web_user"|"tracking_off",
 *     employee: { id, name },
 *     date: "2026-09-09",
 *     checked_in_at, checked_out_at, last_ping_at, speed_kmh, point_count,
 *     interval_s: 90,
 *     points: [ {lat,lng,accuracy_m,speed_kmh,recorded_at}, ... ],
 *     checkpoints: [ {kind,label,at,lat,lng,no}, ... ] }
 *
 * With ?since=<ISO> only NEW points are returned (the modal polls
 * incrementally); checkpoints + state are always the full current picture.
 *
 * GET only - no CSRF (it is a read). Admin session required.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // -> bootstrap (loads settings.php)
$me = require_admin();
api_method('GET');
require dirname(__DIR__, 3) . '/includes/tracking.php';
require dirname(__DIR__) . '/_repo.php'; // employee_find()

$id = (int) ($_GET['id'] ?? 0);
$employee = $id ? employee_find($pdo, $id) : null;
if (!$employee) {
    json_error('Employee not found.', 404);
}

$date = (string) ($_GET['date'] ?? server_today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > server_today()) {
    $date = server_today();
}

$since = isset($_GET['since']) ? (string) $_GET['since'] : null;

$state = tracking_state($pdo, $employee, $date);

json_out([
    'ok'             => true,
    'state'          => $state['state'],
    'employee'       => ['id' => (int) $employee['id'], 'name' => $employee['name']],
    'date'           => $date,
    'checked_in_at'  => $state['checked_in_at'],
    'checked_out_at' => $state['checked_out_at'],
    'last_ping_at'   => $state['last_ping_at'],
    'speed_kmh'      => $state['speed_kmh'],
    'point_count'    => $state['point_count'],
    'interval_s'     => live_tracking_interval_s(),
    'points'         => tracking_pings($pdo, (int) $employee['id'], $date, $since),
    'checkpoints'    => tracking_checkpoints($pdo, (int) $employee['id'], $date),
]);
