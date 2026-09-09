<?php
/**
 * GET field/api-v2/routes.php - a day's route points (the Routes screen).
 *
 * Header: Authorization: Bearer <token>
 * Optional query: ?date=YYYY-MM-DD  (defaults to today; not future)
 *
 * 200:
 *   { "ok": true,
 *     "date": "2026-09-09",
 *     "points": [
 *       { "kind": "start"|"visit"|"end", "no": 1|null, "at": "<ISO>",
 *         "label": "...", "sub": "area name"|null, "lat": .., "lng": .. },
 *       ...
 *     ] }
 *
 * Same list the web Routes page shows (check-in -> visits by seq -> check-out).
 * The app draws the map from these points itself (or shows the plain list).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/routes/_repo.php';     // routes_points()
api_method('GET');

$me    = api_require();
$today = server_today();

$date = (string) ($_GET['date'] ?? $today);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date) || $date > $today) {
    $date = $today;
}

$points = routes_points($pdo, (int) $me['id'], $date);

json_out([
    'ok'     => true,
    'date'   => $date,
    'points' => array_map(static function (array $p): array {
        return [
            'kind'  => $p['kind'],
            'no'    => $p['no'],
            'at'    => $p['at'] instanceof DateTimeInterface ? $p['at']->format('c') : null,
            'label' => $p['label'],
            'sub'   => $p['sub'],
            'lat'   => $p['lat'],
            'lng'   => $p['lng'],
        ];
    }, $points),
]);
