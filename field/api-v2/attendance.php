<?php
/**
 * GET field/api-v2/attendance.php - the Attendance screen (my history).
 *
 * Header: Authorization: Bearer <token>
 * Query:
 *   ?view=day&on=YYYY-MM-DD          one day's statement + timeline
 *   ?view=month&month=YYYY-MM        month totals + a day-by-day list
 *   ?view=alltime                    all-time totals
 * Default: view=day, on=today.
 *
 * 200 (view=day):
 *   { "ok": true, "view": "day", "date": "...", "period_label": "Today",
 *     "statement": { has_attendance, status, visits, km, shop_seconds,
 *                    road_seconds, active_seconds, check_in_at, check_out_at,
 *                    check_in_km, check_out_km },
 *     "timeline": [ { kind, at, label, area?, lat, lng, accuracy_m?, remark,
 *                     left_at?, dwell_seconds?, photo_url? }, ... ] }
 *
 * 200 (view=month | alltime):
 *   { "ok": true, "view": "...", "period_label": "September 2026",
 *     "totals": { days, incomplete, productive_km, shop_secs, road_secs,
 *                 total_secs, visits, shops, avg_dwell_secs },
 *     "days": [ { date, present, status, km, visits }, ... ] }   // month only
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__, 2) . '/includes/distance.php';
require dirname(__DIR__) . '/attendance/_repo.php';
api_method('GET');

$me    = api_require();
$uid   = (int) $me['id'];
$today = server_today();

$view = in_array($_GET['view'] ?? '', ['day', 'month', 'alltime'], true) ? $_GET['view'] : 'day';

if ($view === 'day') {
    $onDate = (string) ($_GET['on'] ?? $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate) || !strtotime($onDate) || $onDate > $today) {
        $onDate = $today;
    }
    $isToday = $onDate === $today;

    $s  = field_statement($pdo, $uid, $onDate, server_now());
    $tl = field_timeline($pdo, $uid, $onDate);

    json_out([
        'ok'           => true,
        'view'         => 'day',
        'date'         => $onDate,
        'period_label' => $isToday ? 'Today' : (new DateTimeImmutable($onDate))->format('l, j F Y'),
        'statement' => [
            'has_attendance' => (bool) $s['has_attendance'],
            'status'         => $s['status'],
            'visits'         => (int) $s['visits'],
            'km'             => (float) $s['km'],
            'shop_seconds'   => (int) $s['shop_secs'],
            'road_seconds'   => (int) $s['road_secs'],
            'active_seconds' => $s['active_secs'] !== null ? (int) $s['active_secs'] : null,
            'check_in_at'    => $s['first_check_in'] ? $s['first_check_in']->format('c') : null,
            'check_out_at'   => $s['last_check_out'] ? $s['last_check_out']->format('c') : null,
            'check_in_km'    => $s['check_in_km'],
            'check_out_km'   => $s['check_out_km'],
        ],
        'timeline' => array_map(static function (array $r): array {
            return [
                'kind'          => $r['kind'],
                'visit_no'      => $r['visit_no'] ?? null,
                'at'            => $r['at']->format('c'),
                'label'         => $r['label'],
                'area'          => $r['area'] ?? null,
                'lat'           => $r['lat'],
                'lng'           => $r['lng'],
                'accuracy_m'    => $r['accuracy'] ?? null,
                'remark'        => $r['remark'] ?? null,
                'left_at'       => isset($r['left_at']) && $r['left_at'] ? $r['left_at']->format('c') : null,
                'dwell_seconds' => $r['dwell_secs'] ?? null,
                'photo_url'     => api_photo_url($r['photo_path'] ?? null),
            ];
        }, $tl),
    ]);
}

// month | alltime
if ($view === 'month') {
    $onMonth = (string) ($_GET['month'] ?? substr($today, 0, 7));
    if (!preg_match('/^\d{4}-\d{2}$/', $onMonth) || !strtotime($onMonth . '-01') || $onMonth > substr($today, 0, 7)) {
        $onMonth = substr($today, 0, 7);
    }
    $from  = $onMonth . '-01';
    $to    = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
    $label = (new DateTimeImmutable($from))->format('F Y');
    $days  = field_calendar($pdo, $uid, $from, $to, $today);
} else {
    $from = $to = null;
    $label = 'All time';
    $days = [];
}

$t = field_period_totals($pdo, $uid, $from, $to);

json_out([
    'ok'           => true,
    'view'         => $view,
    'period_label' => $label,
    'totals' => [
        'days'           => (int) $t['days'],
        'incomplete'     => (int) $t['incomplete'],
        'productive_km'  => (float) $t['productive_km'],
        'shop_secs'      => (int) $t['shop_secs'],
        'road_secs'      => (int) $t['road_secs'],
        'total_secs'     => (int) $t['total_secs'],
        'visits'         => (int) $t['visits'],
        'shops'          => (int) $t['shops'],
        'avg_dwell_secs' => $t['avg_dwell_secs'],
    ],
    'days' => array_map(static function (array $d): array {
        return [
            'date'    => $d['date'],
            'present' => (bool) ($d['has_attendance'] ?? false),
            'status'  => $d['status'] ?? null,
            'km'      => isset($d['road_km']) ? (float) $d['road_km'] : null,
            'visits'  => isset($d['visit_count']) ? (int) $d['visit_count'] : null,
        ];
    }, $days),
]);
