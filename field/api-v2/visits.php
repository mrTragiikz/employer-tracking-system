<?php
/**
 * GET field/api-v2/visits.php - today's visits (the "My Visits" screen).
 *
 * Header: Authorization: Bearer <token>
 * Optional query: ?date=YYYY-MM-DD  (defaults to today; must not be future)
 *
 * 200:
 *   { "ok": true,
 *     "date": "2026-09-09",
 *     "checked_in": true,
 *     "checked_out": false,
 *     "open_visit_id": 45,          // the visit not yet marked Done, or null
 *     "next_label": "Log Second Visit",
 *     "visits": [ <visit object>, ... ]   // ordered by seq
 *   }
 *
 * A new visit can only be logged when checked_in && !checked_out &&
 * open_visit_id === null - the app greys the button otherwise (the
 * visit-save endpoint enforces it for real too).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__) . '/checkinout/_repo.php';
require dirname(__DIR__) . '/visit/_repo.php';      // visit_open_one(), visit_next_label()
api_method('GET');

$me    = api_require();
$today = server_today();

$date = (string) ($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > $today) {
    $date = $today;
}

$att = field_today_attendance($pdo, (int) $me['id'], $date);

$visits = [];
$openId = null;
if ($att !== null) {
    $vs = $pdo->prepare(
        "SELECT v.*, p.stored_path AS photo_path
           FROM visits v
      LEFT JOIN photos p ON p.visit_id = v.id AND p.photo_kind = 'visit'
          WHERE v.attendance_id = ?
       ORDER BY v.seq ASC"
    );
    $vs->execute([(int) $att['id']]);
    $visits = $vs->fetchAll();

    $open = visit_open_one($pdo, (int) $att['id']);
    $openId = $open ? (int) $open['id'] : null;
}

json_out([
    'ok'            => true,
    'date'          => $date,
    'checked_in'    => $att !== null,
    'checked_out'   => $att !== null && !empty($att['check_out_at']),
    'open_visit_id' => $openId,
    'next_label'    => visit_next_label(count($visits)),
    'visits'        => array_map('api_visit', $visits),
]);
