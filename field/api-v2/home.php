<?php
/**
 * GET field/api-v2/home.php - everything the Home / Dashboard screen shows.
 *
 * Header: Authorization: Bearer <token>
 *
 * 200:
 *   { "ok": true,
 *     "greeting": "Good morning",              // server time of day
 *     "date": "2026-09-09",
 *     "attendance": <attendance object or null>,
 *     "stats": {
 *       "visits_today": 3,
 *       "km_today": 21.4,                       // Productive Work KM (compute_day)
 *       "check_in_odometer_km": 12000.0,        // null if not checked in
 *       "check_out_odometer_km": 12070.0        // null if not checked out
 *     }
 *   }
 *
 * km_today comes from the SAME compute_day() the web dashboard uses (see
 * field_home_stats()) - the audit figure, not a guess.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
require dirname(__DIR__) . '/home/_repo.php'; // field_today_attendance(), field_home_stats(), field_greeting()
api_method('GET');

$me    = api_require();
$today = server_today();
$now   = server_now();

$att = field_today_attendance($pdo, (int) $me['id'], $today);

$visits = [];
if ($att !== null) {
    $vs = $pdo->prepare('SELECT * FROM visits WHERE attendance_id = ? ORDER BY seq ASC');
    $vs->execute([(int) $att['id']]);
    $visits = $vs->fetchAll();
}

$stats = field_home_stats($pdo, (int) $me['id'], $today, $att, $visits);

json_out([
    'ok'         => true,
    'greeting'   => field_greeting(),
    'date'       => $today,
    'attendance' => api_attendance($att, $now),
    'stats' => [
        'visits_today'          => (int) $stats['visits_today'],
        'km_today'              => (float) $stats['km_today'],
        'check_in_odometer_km'  => ($att && $att['check_in_odometer_km']  !== null) ? (float) $att['check_in_odometer_km']  : null,
        'check_out_odometer_km' => ($att && $att['check_out_odometer_km'] !== null) ? (float) $att['check_out_odometer_km'] : null,
    ],
]);
