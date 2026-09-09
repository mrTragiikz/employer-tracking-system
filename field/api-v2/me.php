<?php
/**
 * GET field/api-v2/me.php - the signed-in employee + today's attendance summary.
 *
 * Header: Authorization: Bearer <token>
 *
 * 200:
 *   { "ok": true,
 *     "employee": { id, name, phone, code, photo_url },
 *     "attendance": <attendance object or null>,   // today
 *     "server_time": "<ISO>" }
 *
 * The app calls this on launch (to decide: go to Home, or the check-in
 * screen) and after any check-in/out/visit to refresh.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__) . '/checkinout/_repo.php'; // field_today_attendance()
api_method('GET');

$me    = api_require();
$today = server_today();
$now   = server_now();

$att = field_today_attendance($pdo, (int) $me['id'], $today);

json_out([
    'ok' => true,
    'employee' => [
        'id'        => (int) $me['id'],
        'name'      => $me['name'],
        'phone'     => $me['phone'],
        'code'      => $me['code'],
        'photo_url' => api_photo_url($me['photo_path']),
    ],
    'attendance'  => api_attendance($att, $now),
    'server_time' => api_iso($now),
]);
