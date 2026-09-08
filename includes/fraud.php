<?php
/**
 * Anti-fraud checks. Each function maps to a numbered rule in the spec.
 * Callers (field/visit, field/home, field/endday, api/) run the relevant
 * checks and act on the result: block, or record + alert.
 *
 * Nothing here decides policy on its own - it returns a verdict; the handler
 * writes fraud_flags / alerts and chooses to refuse or allow.
 *
 * Final rule set: 3, 4, 5, 6, 7, 8, 9, 10, 11.
 *   - 3 (live camera) is enforced in includes/upload.php, not here.
 *   - 5, 6, 7, 9 record a fraud_flags row AND raise an alerts row; the admin
 *     reviews everything in the Alerts section. There is no per-visit flag UI.
 *   - Dropped: 1 (block when location off - the route must be captured either
 *     way), 2 (distance from a learned shop point - Employees pick their own
 *     route, nothing is pre-registered), 12 (GPS accuracy warning),
 *     13 (approved-only fuel - the expenses feature was removed entirely).
 */

declare(strict_types=1);

require_once __DIR__ . '/distance.php';

// Severity constants
const FRAUD_WARN = 'warn';
const FRAUD_FLAG = 'flag';
const FRAUD_BLOCK = 'block';

/**
 * Record a fraud flag row and (optionally) raise an admin alert.
 *
 * @param array $ctx ['user_id','work_date','subject_type','subject_id']
 */
function fraud_record(
    PDO $pdo,
    array $ctx,
    int $ruleNo,
    string $ruleKey,
    string $severity,
    array $detail = [],
    bool $alertAdmin = false,
    string $alertTitle = ''
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO fraud_flags
           (user_id, work_date, subject_type, subject_id, rule_no, rule_key, severity, detail)
         VALUES (:uid, :wd, :st, :sid, :rn, :rk, :sev, :detail)'
    );
    $stmt->execute([
        ':uid' => $ctx['user_id'] ?? null,
        ':wd' => $ctx['work_date'] ?? null,
        ':st' => $ctx['subject_type'],
        ':sid' => $ctx['subject_id'] ?? null,
        ':rn' => $ruleNo,
        ':rk' => $ruleKey,
        ':sev' => $severity,
        ':detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
    ]);
    $flagId = (int) $pdo->lastInsertId();

    if ($alertAdmin) {
        $level = $severity === FRAUD_BLOCK ? 'critical' : 'warning';
        $a = $pdo->prepare(
            'INSERT INTO alerts (level, title, body, user_id, fraud_flag_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $a->execute([
            $level,
            $alertTitle ?: ("Rule $ruleNo: $ruleKey"),
            json_encode($detail, JSON_UNESCAPED_UNICODE),
            $ctx['user_id'] ?? null,
            $flagId,
        ]);
    }

    return $flagId;
}

// -----------------------------------------------------------------------------
// Shop auto-learn - shops are typed by the Employee, not pre-registered. The
// first time a (Employee, shop-name) is seen its GPS is stored; later visits
// nudge the point toward the running average. The stored point is only used to
// show "distance from shop" as neutral context on a visit - it is NOT a fraud
// check (old rule 2 was dropped: Employees pick their own route).
// -----------------------------------------------------------------------------

/** Normalise a typed shop name into the match key used by uq_shop_employee_name. */
function shop_norm(string $name): string
{
    $n = mb_strtolower(trim($name));
    $n = preg_replace('/\s+/u', ' ', $n) ?? $n;
    return mb_substr($n, 0, 160);
}

/**
 * Find the Employee's learned shop for this typed name, or create it from the
 * current GPS. Also nudges the learned point toward the running average and
 * bumps counters. Call inside the visit transaction.
 */
function shop_lookup_or_learn(PDO $pdo, int $employeeId, string $typedName, float $lat, float $lng): array
{
    $norm = shop_norm($typedName);

    $sel = $pdo->prepare(
        'SELECT id, lat, lng, samples FROM shops
          WHERE employee_id = ? AND name_norm = ? LIMIT 1'
    );
    $sel->execute([$employeeId, $norm]);
    $shop = $sel->fetch();

    if (!$shop) {
        $ins = $pdo->prepare(
            'INSERT INTO shops (employee_id, name_display, name_norm, lat, lng, samples, visit_count)
             VALUES (?, ?, ?, ?, ?, 1, 0)'
        );
        $ins->execute([$employeeId, mb_substr(trim($typedName), 0, 160), $norm, $lat, $lng]);
        return [
            'shop_id' => (int) $pdo->lastInsertId(),
            'first' => true,
            'distance_m' => null,
        ];
    }

    $shopId = (int) $shop['id'];
    $sLat = (float) $shop['lat'];
    $sLng = (float) $shop['lng'];
    $samples = max(1, (int) $shop['samples']);

    $dist = haversine_m($lat, $lng, $sLat, $sLng);

    // Running-average nudge (cap samples so old points still drift a little).
    $w = min($samples, 20);
    $newLat = ($sLat * $w + $lat) / ($w + 1);
    $newLng = ($sLng * $w + $lng) / ($w + 1);

    $upd = $pdo->prepare(
        'UPDATE shops
            SET lat = ?, lng = ?, samples = samples + 1, visit_count = visit_count + 1
          WHERE id = ?'
    );
    $upd->execute([$newLat, $newLng, $shopId]);

    return [
        'shop_id' => $shopId,
        'first' => false,
        'distance_m' => round($dist, 1),
    ];
}

// -----------------------------------------------------------------------------
// Rule 3 - Live camera only. Enforced in HTML (capture attr) + server MIME check.
// See includes/upload.php::save_camera_photo().
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Rule 4 - Server time only. There is no client time to trust; every timestamp
// used for calculation is server_now(). device_ts is stored for the record but
// never drives a duration or a decision. Nothing to check at call time.
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// Rule 5 - Same location used for multiple shops => flag + alert admin
// -----------------------------------------------------------------------------
function check_same_location(PDO $pdo, int $employeeId, string $workDate, float $lat, float $lng, int $excludeVisitId = 0): array
{
    $stmt = $pdo->prepare(
        'SELECT id, shop_name, lat, lng FROM visits
          WHERE employee_id = ? AND work_date = ? AND id <> ?'
    );
    $stmt->execute([$employeeId, $workDate, $excludeVisitId]);
    $tol = same_location_tolerance_m();

    foreach ($stmt as $row) {
        $d = haversine_m($lat, $lng, (float) $row['lat'], (float) $row['lng']);
        if ($d <= $tol) {
            return [
                'ok' => false, 'severity' => FRAUD_FLAG, 'rule' => 5,
                'key' => 'same_location', 'alert_admin' => true,
                'other_visit_id' => (int) $row['id'],
                'other_shop_name' => $row['shop_name'],
                'distance_m' => round($d, 1),
            ];
        }
    }
    return ['ok' => true];
}

// -----------------------------------------------------------------------------
// Rule 6 - Impossible travel speed between two points => record + alert admin
// -----------------------------------------------------------------------------
function check_travel_speed(float $prevLat, float $prevLng, string $prevAt, float $lat, float $lng, string $at): array
{
    $seconds = strtotime($at) - strtotime($prevAt);
    if ($seconds <= 0) {
        return ['ok' => false, 'severity' => FRAUD_FLAG, 'rule' => 6, 'alert_admin' => true,
                'key' => 'time_went_backwards', 'seconds' => $seconds];
    }
    $km = road_km(haversine_km($prevLat, $prevLng, $lat, $lng));
    $kmh = $km / ($seconds / 3600);
    $limit = impossible_speed_kmh();
    if ($kmh > $limit) {
        return ['ok' => false, 'severity' => FRAUD_FLAG, 'rule' => 6, 'alert_admin' => true,
                'key' => 'impossible_speed', 'speed_kmh' => round($kmh, 1), 'limit_kmh' => $limit];
    }
    return ['ok' => true, 'speed_kmh' => round($kmh, 1)];
}

// -----------------------------------------------------------------------------
// Rule 7 - Visits bunched together in seconds => record + alert admin (bulk entry)
// -----------------------------------------------------------------------------
function check_bulk_entry(PDO $pdo, int $employeeId, string $workDate, string $arrivedAt): array
{
    $stmt = $pdo->prepare(
        'SELECT arrived_at FROM visits
          WHERE employee_id = ? AND work_date = ?
          ORDER BY arrived_at DESC LIMIT 1'
    );
    $stmt->execute([$employeeId, $workDate]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $gap = strtotime($arrivedAt) - strtotime((string) $last);
        if ($gap >= 0 && $gap < bulk_entry_gap_seconds()) {
            return ['ok' => false, 'severity' => FRAUD_FLAG, 'rule' => 7, 'alert_admin' => true,
                    'key' => 'bulk_entry', 'gap_seconds' => $gap];
        }
    }
    return ['ok' => true];
}

// -----------------------------------------------------------------------------
// Rule 8 - Duplicate visit to same shop same day => block
// DB also enforces this via uq_visit_employee_shop_day (employee_id,
// shop_norm, work_date). This is the friendly pre-check.
// -----------------------------------------------------------------------------
function check_duplicate_visit(PDO $pdo, int $employeeId, string $typedShopName, string $workDate): array
{
    $stmt = $pdo->prepare(
        'SELECT id, shop_name FROM visits
          WHERE employee_id = ? AND shop_norm = ? AND work_date = ?'
    );
    $stmt->execute([$employeeId, shop_norm($typedShopName), $workDate]);
    $row = $stmt->fetch();
    if ($row) {
        return ['ok' => false, 'severity' => FRAUD_BLOCK, 'rule' => 8,
                'key' => 'duplicate_visit', 'existing_visit_id' => (int) $row['id']];
    }
    return ['ok' => true];
}

// -----------------------------------------------------------------------------
// Rule 9 - Mock location detected => block, record + alert admin
// -----------------------------------------------------------------------------
function check_mock_location(array $geo): array
{
    $mock = !empty($geo['is_mock']) || !empty($geo['mocked']);
    return $mock
        ? ['ok' => false, 'severity' => FRAUD_BLOCK, 'rule' => 9, 'alert_admin' => true, 'key' => 'mock_location']
        : ['ok' => true];
}

// -----------------------------------------------------------------------------
// Rule 10 - Login locked to one device_id
// -----------------------------------------------------------------------------
function check_device_bound(array $user, string $deviceId): array
{
    if ($deviceId === '') {
        return ['ok' => false, 'severity' => FRAUD_BLOCK, 'rule' => 10, 'key' => 'no_device_id'];
    }
    if ($user['device_id'] === null) {
        return ['ok' => true, 'bind' => true]; // first bind
    }
    if (!hash_equals($user['device_id'], $deviceId)) {
        return ['ok' => false, 'severity' => FRAUD_BLOCK, 'rule' => 10, 'key' => 'wrong_device'];
    }
    return ['ok' => true];
}

// -----------------------------------------------------------------------------
// Rule 11 - No check-out by end of day => flag as incomplete
// Run as a nightly sweep (cron / admin button). See api/cron/incomplete.php.
// -----------------------------------------------------------------------------
function sweep_incomplete_days(PDO $pdo): int
{
    // Any still-open day before today never got a check-out.
    $stmt = $pdo->prepare(
        "UPDATE attendance
            SET status = 'incomplete'
          WHERE status = 'open'
            AND check_out_at IS NULL
            AND work_date < CURDATE()"
    );
    $stmt->execute();
    return $stmt->rowCount();
}
