<?php
/**
 * admin/02-employees/_repo.php
 *
 * Data access for the Employees section. Included by Employee.php / form.php /
 * view.php and by the api/ endpoints. Pure functions over $pdo - no output.
 *
 * A "Employee" is a row in `users` with role = 'employee'.
 */

declare(strict_types=1);

/**
 * One page of Employees for the list table, with the filters applied.
 *
 * @param array $f filters: q, region, area, status ('active'|'inactive'|''),
 * page (1-based), per_page
 * @return array{rows: array<int,array>, total: int, page: int, per_page: int, pages: int}
 */
function employees_list(PDO $pdo, array $f): array
{
    $page = max(1, (int) ($f['page'] ?? 1));
    $perPage = (int) ($f['per_page'] ?? 10);
    $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

    $where = ["u.role = 'employee'", 'u.deleted_at IS NULL'];
    $args = [];

    if (!empty($f['q'])) {
        $where[] = '(u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.code LIKE ?)';
        $like = '%' . $f['q'] . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if (!empty($f['region'])) {
        $where[] = 'u.region = ?';
        $args[] = $f['region'];
    }
    if (!empty($f['area'])) {
        $where[] = 'u.area = ?';
        $args[] = $f['area'];
    }
    if (($f['status'] ?? '') === 'active') {
        $where[] = 'u.is_active = 1';
    } elseif (($f['status'] ?? '') === 'inactive') {
        $where[] = 'u.is_active = 0';
    }

    $whereSql = implode(' AND ', $where);

    // total for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int) $countStmt->fetchColumn();

    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // page rows + visit count per Employee
    $sql = "
        SELECT u.id, u.name, u.code, u.phone, u.email, u.region, u.area,
               u.is_active, u.created_at, u.last_login_at, u.device_id,
               u.photo_path, u.vehicle_type,
               (SELECT COUNT(*) FROM visits v WHERE v.employee_id = u.id) AS visit_count
          FROM users u
         WHERE $whereSql
         ORDER BY u.name ASC
         LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    foreach ($args as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($args) + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(count($args) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll(),
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

/** Every Employee row for CSV export (filters applied, no pagination). */
function employees_all_for_export(PDO $pdo, array $f): array
{
    $base = ['q' => $f['q'] ?? '', 'region' => $f['region'] ?? '',
             'area' => $f['area'] ?? '', 'status' => $f['status'] ?? '', 'per_page' => 100];
    $out = [];
    $page = 1;
    do {
        $chunk = employees_list($pdo, $base + ['page' => $page]);
        $out = array_merge($out, $chunk['rows']);
        $page++;
    } while ($page <= $chunk['pages']);
    return $out;
}

/** One Employee by id, or null. */
function employee_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM users WHERE id = ? AND role = 'employee' AND deleted_at IS NULL"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Human label for a users.document_type value, or a generic fallback. */
function employee_document_label(?string $type): string
{
    return match ($type) {
        'citizenship'      => 'Nagrita (Citizenship)',
        'driving_license'  => 'Driving License',
        'passport'         => 'Passport',
        'national_id'      => 'NID Card',
        default            => 'ID Document',
    };
}

/** Distinct regions / areas currently in use - for the filter dropdowns. */
function employee_regions(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT region FROM users
          WHERE role = 'employee' AND deleted_at IS NULL AND region IS NOT NULL AND region <> ''
          ORDER BY region"
    )->fetchAll(PDO::FETCH_COLUMN);
}

function employee_areas(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT area FROM users
          WHERE role = 'employee' AND deleted_at IS NULL AND area IS NOT NULL AND area <> ''
          ORDER BY area"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/* =========================================================================
   Employee detail (view.php) - attendance days + visits for a chosen
   single day OR a single month.
   ========================================================================= */

/**
 * Resolve the picker request into a concrete span.
 *
 * @param array $in ['mode' => 'day'|'month', 'day' => 'YYYY-MM-DD', 'month' => 'YYYY-MM']
 * @return array{0:string,1:string,2:string,3:string}
 * [mode, from (Y-m-d), to (Y-m-d), label]
 *
 * Defaults to the current month when nothing valid is supplied.
 */
function employee_span(array $in): array
{
    $today = new DateTimeImmutable(server_today());
    $mode = ($in['mode'] ?? 'month') === 'day' ? 'day' : 'month';

    if ($mode === 'day') {
        $day = (string) ($in['day'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $d = new DateTimeImmutable($day);
        } else {
            $d = $today;
        }
        return ['day', $d->format('Y-m-d'), $d->format('Y-m-d'), $d->format('l, j F Y')];
    }

    // month
    $month = (string) ($in['month'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $month, $m) || (int) $m[2] < 1 || (int) $m[2] > 12) {
        $month = $today->format('Y-m');
    }
    $first = new DateTimeImmutable($month . '-01');
    $last = $first->modify('last day of this month');
    return ['month', $first->format('Y-m-d'), $last->format('Y-m-d'), $first->format('F Y')];
}

/** Summary numbers for an Employee over [from, to]. */
function employee_range_summary(PDO $pdo, int $employeeId, string $from, string $to): array
{
    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };
    $dr = [$employeeId, $from, $to];

    return [
        'days' => (int) $one($pdo, "SELECT COUNT(*) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?", $dr),
        'incomplete' => (int) $one($pdo, "SELECT COUNT(*) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ? AND status='incomplete'", $dr),
        'visits' => (int) $one($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id=? AND work_date BETWEEN ? AND ?", $dr),
        'road_km' => $one($pdo, "SELECT COALESCE(SUM(road_km),0) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?", $dr),
        'shop_secs' => (int) $one($pdo, "SELECT COALESCE(SUM(shop_seconds),0) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?", $dr),
        'road_secs' => (int) $one($pdo, "SELECT COALESCE(SUM(road_seconds),0) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?", $dr),
        'total_secs' => (int) $one($pdo, "SELECT COALESCE(SUM(total_seconds),0) FROM attendance WHERE employee_id=? AND work_date BETWEEN ? AND ?", $dr),
        'open_flags' => (int) $one($pdo, "SELECT COUNT(*) FROM fraud_flags WHERE user_id=? AND resolved=0 AND (work_date IS NULL OR work_date BETWEEN ? AND ?)", $dr),
    ];
}

/** Attendance rows (one per working day) for an Employee over [from, to], newest first. */
function employee_days(PDO $pdo, int $employeeId, string $from, string $to): array
{
    $stmt = $pdo->prepare(
        "SELECT a.*,
                (SELECT COUNT(*) FROM visits v WHERE v.attendance_id = a.id) AS visit_count
           FROM attendance a
          WHERE a.employee_id = ? AND a.work_date BETWEEN ? AND ?
          ORDER BY a.work_date DESC, a.check_in_at DESC"
    );
    $stmt->execute([$employeeId, $from, $to]);
    return $stmt->fetchAll();
}

/** All visits for one attendance day, in visit order, with photo count + first photo. */
function employee_day_visits(PDO $pdo, int $attendanceId): array
{
    $stmt = $pdo->prepare(
        "SELECT v.*,
                (SELECT COUNT(*) FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit') AS photo_count,
                (SELECT p.stored_path FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit' ORDER BY p.id LIMIT 1) AS photo_path
           FROM visits v
          WHERE v.attendance_id = ?
          ORDER BY v.seq ASC, v.arrived_at ASC"
    );
    $stmt->execute([$attendanceId]);
    return $stmt->fetchAll();
}

/**
 * One calendar row per day in [from, to], newest first, each marked with
 * whether the Employee had an attendance record (Yes/No column on view.php).
 * Attendance columns are NULL on days with no record.
 *
 * @return array<int,array>  keys: date, has_attendance, plus attendance.* when present
 */
function employee_calendar(PDO $pdo, int $employeeId, string $from, string $to): array
{
    // Pull the actual attendance rows once, key by date.
    $rows = employee_days($pdo, $employeeId, $from, $to);
    $byDate = [];
    foreach ($rows as $r) {
        $byDate[$r['work_date']] = $r;
    }

    // Walk every calendar day in the span (cap the span so a silly range can't blow up).
    $start = new DateTimeImmutable($from);
    $end   = new DateTimeImmutable($to);
    if ($start->diff($end)->days > 366) {
        $start = $end->modify('-366 days');
    }

    $today = server_today();
    $out   = [];
    for ($d = $end; $d >= $start; $d = $d->modify('-1 day')) {
        $key = $d->format('Y-m-d');
        if ($key > $today) {
            continue;                       // don't list future days
        }
        if (isset($byDate[$key])) {
            $out[] = ['date' => $key, 'has_attendance' => true] + $byDate[$key];
        } else {
            $out[] = ['date' => $key, 'has_attendance' => false];
        }
    }
    return $out;
}

/**
 * Visits History (view.php "Visits History" tab): a flat, paginated list of a
 * Employee's visits for one month, newest first, with per-visit detail.
 *
 * @param array $f  ['month' => 'YYYY-MM', 'q' => shop search,
 *                    'page' => 1-based, 'per_page' => int]
 * @return array{rows: array, total: int, page: int, per_page: int, pages: int,
 *               month_from: string, month_to: string,
 *               totals: array{visits:int,shops:int,avg_dwell_secs:int}}
 */
function employee_visits_history(PDO $pdo, int $employeeId, array $f): array
{
    $month = (string) ($f['month'] ?? substr(server_today(), 0, 7));
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || !strtotime($month . '-01')) {
        $month = substr(server_today(), 0, 7);
    }
    $from = $month . '-01';
    $to   = (new DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');

    $perPage = (int) ($f['per_page'] ?? 25);
    $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
    $page    = max(1, (int) ($f['page'] ?? 1));

    $where = ['v.employee_id = ?', 'v.work_date BETWEEN ? AND ?'];
    $args  = [$employeeId, $from, $to];
    if (!empty($f['q'])) {
        $where[] = 'v.shop_name LIKE ?';
        $args[]  = '%' . $f['q'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM visits v WHERE $whereSql");
    $countStmt->execute($args);
    $total  = (int) $countStmt->fetchColumn();
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT v.id, v.work_date, v.shop_name, v.seq,
                v.arrived_at, v.left_at, v.lat, v.lng, v.accuracy_m,
                v.dwell_seconds, v.hop_road_km, v.hop_seconds,
                v.distance_from_shop_m, v.remark,
                (SELECT COUNT(*) FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit') AS photo_count,
                (SELECT p.stored_path FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit' ORDER BY p.id LIMIT 1) AS photo_path
           FROM visits v
          WHERE $whereSql
          ORDER BY v.arrived_at DESC
          LIMIT ? OFFSET ?"
    );
    foreach ($args as $i => $val) {
        $stmt->bindValue($i + 1, $val);
    }
    $stmt->bindValue(count($args) + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(count($args) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    // month totals (ignore the shop search so the strip is stable context)
    $mArgs = [$employeeId, $from, $to];
    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };

    return [
        'rows'       => $stmt->fetchAll(),
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'pages'      => $pages,
        'month'      => $month,
        'month_from' => $from,
        'month_to'   => $to,
        'totals'     => [
            'visits'         => (int) $one($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id=? AND work_date BETWEEN ? AND ?", $mArgs),
            'shops'          => (int) $one($pdo, "SELECT COUNT(DISTINCT shop_norm) FROM visits WHERE employee_id=? AND work_date BETWEEN ? AND ?", $mArgs),
            'avg_dwell_secs' => (int) $one($pdo, "SELECT COALESCE(AVG(dwell_seconds),0) FROM visits WHERE employee_id=? AND work_date BETWEEN ? AND ? AND dwell_seconds IS NOT NULL", $mArgs),
        ],
    ];
}

/** One Employee's attendance row for a specific date, or null. */
function employee_day(PDO $pdo, int $employeeId, string $date): ?array
{
    $stmt = $pdo->prepare(
        "SELECT a.*,
                (SELECT COUNT(*) FROM visits v WHERE v.attendance_id = a.id) AS visit_count
           FROM attendance a
          WHERE a.employee_id = ? AND a.work_date = ?
          LIMIT 1"
    );
    $stmt->execute([$employeeId, $date]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Placeholder shown where there is genuinely no value (a plain hyphen). */
function dash(): string
{
    return '<span class="c-muted">-</span>';
}

/**
 * Format a single-day/single-hop distance for display. These figures are
 * real road distances (see includes/distance.php road_km_real() - the
 * Directions API is mandatory now, never a straight-line guess) and are
 * very often under a km in practice (shops clustered a few doors apart) -
 * number_format(x, 1) rounds all of those down to a flat, misleading "0.0"
 * even though the underlying number is accurate. Below 1 km, show metres
 * instead so a genuinely tiny hop still reads as a real figure ("3 m")
 * rather than looking like missing/broken data.
 */
function fmt_km(float $km): string
{
    if ($km < 1.0) {
        return round($km * 1000) . ' m';
    }
    return number_format($km, 1) . ' km';
}

/**
 * hh:mm from seconds.
 * $zeroText is what to return when the value is null/zero (default "0m").
 */
function hm(?int $seconds, string $zeroText = '0m'): string
{
    if ($seconds === null || $seconds <= 0) {
        return $zeroText;
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
}

/** Short lat,lng label, or empty string when unknown. */
function latlng(?float $lat, ?float $lng): string
{
    if ($lat === null || $lng === null) {
        return '';
    }
    return number_format((float) $lat, 5) . ', ' . number_format((float) $lng, 5);
}

/** Google-maps link for a point (opens in a new tab). */
function map_link(?float $lat, ?float $lng): ?string
{
    if ($lat === null || $lng === null) {
        return null;
    }
    return 'https://www.google.com/maps?q=' . rawurlencode($lat . ',' . $lng);
}

/* =========================================================================
   Employee detail page - all-time stats, today's statement, day timeline
   ========================================================================= */

/** The 4 cards across the top of the detail page (all-time + this month). */
function employee_alltime_stats(PDO $pdo, int $employeeId): array
{
    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };
    $monthStart = (new DateTimeImmutable(server_today()))->format('Y-m-01');

    // Productive km, all time: a CLOSED day contributes attendance.road_km
    // (the full-day audited figure incl. the trip home); a STILL-OPEN day's
    // attendance.road_km is 0, so it contributes the sum of its visits' own
    // hops (visits.hop_road_km, filled at visit-save time / self-healed) -
    // the route so far. Without this an employee mid-route reads "0.0 km".
    $productiveKm = $one($pdo,
        "SELECT COALESCE(SUM(
                    CASE WHEN a.check_out_at IS NOT NULL THEN a.road_km
                         ELSE (SELECT COALESCE(SUM(v.hop_road_km),0)
                                 FROM visits v WHERE v.attendance_id = a.id)
                    END
                ), 0)
           FROM attendance a
          WHERE a.employee_id = ?", [$employeeId]);

    return [
        'total_visits'      => (int) $one($pdo,
            "SELECT COUNT(*) FROM visits WHERE employee_id = ?", [$employeeId]),
        'visits_month'      => (int) $one($pdo,
            "SELECT COUNT(*) FROM visits WHERE employee_id = ? AND work_date >= ?", [$employeeId, $monthStart]),
        // road_km is server-computed (real Mapbox road distance), never a real odometer.
        'productive_km'     => $productiveKm,
        // the Employee's single longest shop visit ever (a personal-best figure).
        'longest_dwell_secs'=> (int) $one($pdo,
            "SELECT COALESCE(MAX(dwell_seconds),0) FROM visits WHERE employee_id = ? AND dwell_seconds IS NOT NULL",
            [$employeeId]),
    ];
}

/**
 * Aggregate totals for an Employee over a date range (both ends inclusive), or
 * all-time when $from/$to are null. Used by the Overview tab's "This Month"
 * and "All time" summary card.
 *
 *   attendance   : days worked, incomplete days
 *   visits       : total
 *   shops        : distinct shops visited
 *   productive_km: road km travelled (from attendance.road_km)
 *   shop_secs    : total time spent at shops
 *   road_secs    : total travel time
 *   avg_dwell    : average minutes per visit (visits with a recorded dwell)
 */
function employee_period_totals(PDO $pdo, int $employeeId, ?string $from, ?string $to): array
{
    $ranged = $from !== null && $to !== null;
    $attWhere = "employee_id = ?" . ($ranged ? " AND work_date BETWEEN ? AND ?" : "");
    $visWhere = "employee_id = ?" . ($ranged ? " AND work_date BETWEEN ? AND ?" : "");
    $args = $ranged ? [$employeeId, $from, $to] : [$employeeId];

    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };

    return [
        'days'          => (int) $one($pdo, "SELECT COUNT(*) FROM attendance WHERE $attWhere", $args),
        'incomplete'    => (int) $one($pdo, "SELECT COUNT(*) FROM attendance WHERE $attWhere AND status='incomplete'", $args),
        'visits'        => (int) $one($pdo, "SELECT COUNT(*) FROM visits WHERE $visWhere", $args),
        'shops'         => (int) $one($pdo, "SELECT COUNT(DISTINCT shop_norm) FROM visits WHERE $visWhere", $args),
        'productive_km' => round($one($pdo, "SELECT COALESCE(SUM(road_km),0) FROM attendance WHERE $attWhere", $args), 1),
        // total_secs = sum of (check_out - check_in) over closed days only
        'total_secs'    => (int) $one($pdo, "SELECT COALESCE(SUM(total_seconds),0) FROM attendance WHERE $attWhere", $args),
        'shop_secs'     => (int) $one($pdo, "SELECT COALESCE(SUM(shop_seconds),0) FROM attendance WHERE $attWhere", $args),
        'road_secs'     => (int) $one($pdo, "SELECT COALESCE(SUM(road_seconds),0) FROM attendance WHERE $attWhere", $args),
        'avg_dwell_secs'=> (int) $one($pdo, "SELECT COALESCE(AVG(dwell_seconds),0) FROM visits WHERE $visWhere AND dwell_seconds IS NOT NULL", $args),
    ];
}

/**
 * "Today's Statement" numbers for an Employee on a given date (defaults to today).
 * Returns nulls where there is no attendance record.
 */
function employee_statement(PDO $pdo, int $employeeId, string $date): array
{
    $att = employee_day($pdo, $employeeId, $date);

    $visitCount = 0;
    $kmToday    = 0.0;
    if ($att) {
        $visitCount = (int) $att['visit_count'];
        $kmToday    = (float) $att['road_km'];
    }

    $ci = $att ? new DateTimeImmutable($att['check_in_at']) : null;
    $co = ($att && $att['check_out_at']) ? new DateTimeImmutable($att['check_out_at']) : null;

    // Active duration = last checkout (or now, if still open) minus check-in.
    $activeSecs = null;
    if ($ci) {
        $end = $co ?: new DateTimeImmutable(server_now());
        $activeSecs = max(0, $end->getTimestamp() - $ci->getTimestamp());
    }

    return [
        'has_attendance'  => (bool) $att,
        'status'          => $att['status'] ?? null,
        'visits'          => $visitCount,
        'km'              => $kmToday,
        'working_secs'    => $att ? (int) $att['total_seconds'] : null,   // checkout - checkin (cached)
        'shop_secs'       => $att ? (int) $att['shop_seconds'] : 0,
        'road_secs'       => $att ? (int) $att['road_seconds'] : 0,
        'active_secs'     => $activeSecs,
        'first_check_in'  => $ci,
        'last_check_out'  => $co,
        // Bike odometer, hand-typed by the Employee at check-in AND
        // check-out (both mandatory - see field/checkinout) - shown as its
        // own line since it's the Employee's own reading, independent of
        // (and a cross-check against) the GPS-derived road km above.
        'odo_check_in'    => $att && $att['check_in_odometer_km'] !== null ? (float) $att['check_in_odometer_km'] : null,
        'odo_check_out'   => $att && $att['check_out_odometer_km'] !== null ? (float) $att['check_out_odometer_km'] : null,
    ];
}

/**
 * Unified timeline for one Employee-day: a check-in row, one row per visit,
 * and a check-out row, ordered by time. Each row:
 *   kind      : 'checkin' | 'visit' | 'checkout'
 *   at        : DateTimeImmutable
 *   label     : shop name / "Check-in" / "Check-out"
 *   lat,lng   : floats or null
 *   accuracy  : float metres or null
 *   remark    : short text
 * Visit rows also carry:
 *   left_at   : DateTimeImmutable or null
 *   dwell_secs: seconds at the shop (null if still there)
 *   hop_km    : road km from the previous point
 *   hop_secs  : travel seconds from the previous point
 *   from_shop_m: GPS distance from the shop's learned point (null on first visit)
 *   note      : Employee's free remark, or null
 *   photo_path / photo_count
 */
function employee_timeline(PDO $pdo, int $employeeId, string $date): array
{
    $att = employee_day($pdo, $employeeId, $date);
    if (!$att) {
        return [];
    }

    $rows = [];

    $rows[] = [
        'kind'      => 'checkin',
        'at'        => new DateTimeImmutable($att['check_in_at']),
        'label'     => 'Check-in',
        'lat'       => (float) $att['check_in_lat'],
        'lng'       => (float) $att['check_in_lng'],
        'accuracy'  => $att['check_in_accuracy_m'] !== null ? (float) $att['check_in_accuracy_m'] : null,
        'remark'    => 'Day started',
    ];

    $visits = employee_day_visits($pdo, (int) $att['id']);
    $ordinals = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];
    foreach ($visits as $i => $v) {
        $ord   = $ordinals[$i] ?? (($i + 1) . 'th');
        $left  = $v['left_at'] ? new DateTimeImmutable($v['left_at']) : null;
        $rows[] = [
            'kind'        => 'visit',
            'at'          => new DateTimeImmutable($v['arrived_at']),
            'label'       => $v['shop_name'],
            'lat'         => (float) $v['lat'],
            'lng'         => (float) $v['lng'],
            'accuracy'    => $v['accuracy_m'] !== null ? (float) $v['accuracy_m'] : null,
            'remark'      => $ord . ' visit',
            'left_at'     => $left,
            'dwell_secs'  => $v['dwell_seconds'] !== null ? (int) $v['dwell_seconds'] : null,
            'hop_km'      => (float) $v['hop_road_km'],
            'hop_secs'    => $v['hop_seconds'] !== null ? (int) $v['hop_seconds'] : null,
            'from_shop_m' => $v['distance_from_shop_m'] !== null ? (float) $v['distance_from_shop_m'] : null,
            'note'        => $v['remark'] !== null && $v['remark'] !== '' ? $v['remark'] : null,
            'photo_path'  => $v['photo_path'] ?? null,
            'photo_count' => (int) ($v['photo_count'] ?? 0),
        ];
    }

    if ($att['check_out_at']) {
        $rows[] = [
            'kind'      => 'checkout',
            'at'        => new DateTimeImmutable($att['check_out_at']),
            'label'     => 'Check-out',
            'lat'       => $att['check_out_lat'] !== null ? (float) $att['check_out_lat'] : null,
            'lng'       => $att['check_out_lng'] !== null ? (float) $att['check_out_lng'] : null,
            'accuracy'  => $att['check_out_accuracy_m'] !== null ? (float) $att['check_out_accuracy_m'] : null,
            'remark'    => 'Day ended',
        ];
    }

    usort($rows, static fn($a, $b) => $a['at'] <=> $b['at']);
    return $rows;
}

/** Section stat cards. */
function employee_stats(PDO $pdo): array
{
    $today = server_today();

    $scalar = static function (PDO $pdo, string $sql, array $a = []): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };

    // KM travelled today: for a CLOSED day use attendance.road_km (the
    // authoritative full-day figure that also includes the last-visit ->
    // check-out leg). For a STILL-OPEN day that column is 0, so fall back to
    // the sum of each visit's own hop (visits.hop_road_km, filled in at visit
    // save time) - the route so far, minus only the not-yet-happened trip
    // home. Without this, an employee mid-route contributes nothing to the
    // headline number all day.
    $kmToday = $scalar($pdo,
        "SELECT COALESCE(SUM(
                    CASE WHEN a.check_out_at IS NOT NULL THEN a.road_km
                         ELSE (SELECT COALESCE(SUM(v.hop_road_km), 0)
                                 FROM visits v WHERE v.attendance_id = a.id)
                    END
                ), 0)
           FROM attendance a
          WHERE a.work_date = ?", [$today]);

    return [
        'total' => (int) $scalar($pdo, "SELECT COUNT(*) FROM users WHERE role = 'employee' AND deleted_at IS NULL"),
        'active_today' => (int) $scalar($pdo, "SELECT COUNT(DISTINCT employee_id) FROM attendance WHERE work_date = ?", [$today]),
        'visits_today' => (int) $scalar($pdo, "SELECT COUNT(*) FROM visits WHERE work_date = ?", [$today]),
        'km_today' => $kmToday,
    ];
}

/**
 * Normalise + validate the add/edit form input.
 *
 * @return array{ok:bool, data?:array, errors?:array<string,string>}
 */
function employee_validate(array $in, ?int $editingId, PDO $pdo): array
{
    $errors = [];

    $name = trim((string) ($in['name'] ?? ''));
    $phone = trim((string) ($in['phone'] ?? ''));
    $email = trim((string) ($in['email'] ?? ''));
    $region = trim((string) ($in['region'] ?? ''));
    $area = trim((string) ($in['area'] ?? ''));
    $code = trim((string) ($in['code'] ?? ''));
    $pin = trim((string) ($in['pin'] ?? ''));
    $active = !empty($in['is_active']) ? 1 : 0;

    $dob      = trim((string) ($in['dob'] ?? ''));
    $gender   = trim((string) ($in['gender'] ?? ''));
    $idNum    = trim((string) ($in['id_number'] ?? ''));
    $docType  = trim((string) ($in['document_type'] ?? ''));
    $emName   = trim((string) ($in['emergency_name'] ?? ''));
    $emPhone  = trim((string) ($in['emergency_contact'] ?? ''));
    $address  = trim((string) ($in['address'] ?? ''));
    $vehicle  = trim((string) ($in['vehicle_type'] ?? ''));

    if ($name === '' || mb_strlen($name) > 120) {
        $errors['name'] = 'Enter a name (up to 120 characters).';
    }
    if (!preg_match('/^[0-9+\- ]{7,20}$/', $phone)) {
        $errors['phone'] = 'Enter a valid phone number (7 - 20 digits).';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email or leave it blank.';
    }
    if ($code !== '' && mb_strlen($code) > 32) {
        $errors['code'] = 'Code is too long (max 32).';
    }
    if ($dob !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !strtotime($dob) || $dob > server_today()) {
            $errors['dob'] = 'Enter a valid date of birth.';
        }
    }
    if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
        $errors['gender'] = 'Pick a valid option.';
    }
    if ($docType !== '' && !in_array($docType, ['citizenship', 'driving_license', 'passport', 'national_id'], true)) {
        $errors['document_type'] = 'Pick a valid document type.';
    }
    if ($vehicle !== '' && !in_array($vehicle, ['bike', 'car', 'auto'], true)) {
        $errors['vehicle_type'] = 'Pick a valid vehicle.';
    }
    if ($emPhone !== '' && !preg_match('/^[0-9+\- ]{7,20}$/', $emPhone)) {
        $errors['emergency_contact'] = 'Enter a valid emergency contact number.';
    }
    // PIN required on create; on edit only if provided (blank = keep current).
    if ($editingId === null) {
        if (!preg_match('/^\d{4}$/', $pin)) {
            $errors['pin'] = 'Set a 4-digit PIN.';
        }
    } elseif ($pin !== '' && !preg_match('/^\d{4}$/', $pin)) {
        $errors['pin'] = 'PIN must be exactly 4 digits (leave blank to keep the current one).';
    }

    // uniqueness
    if (!isset($errors['phone'])) {
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE phone = ? AND id <> ? AND deleted_at IS NULL"
        );
        $stmt->execute([$phone, $editingId ?? 0]);
        if ($stmt->fetchColumn()) {
            $errors['phone'] = 'Another Employee already uses this phone number.';
        }
    }
    if ($email !== '' && !isset($errors['email'])) {
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE email = ? AND id <> ? AND deleted_at IS NULL"
        );
        $stmt->execute([$email, $editingId ?? 0]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = 'Another user already uses this email.';
        }
    }
    if ($code !== '' && !isset($errors['code'])) {
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE code = ? AND id <> ? AND deleted_at IS NULL"
        );
        $stmt->execute([$code, $editingId ?? 0]);
        if ($stmt->fetchColumn()) {
            $errors['code'] = 'That code is already taken.';
        }
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    return ['ok' => true, 'data' => [
        'name' => $name,
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'region' => $region !== '' ? $region : null,
        'area' => $area !== '' ? $area : null,
        'code' => $code !== '' ? $code : null,
        'pin' => $pin, // '' on edit means "unchanged"
        'is_active' => $active,
        'dob' => $dob !== '' ? $dob : null,
        'gender' => $gender !== '' ? $gender : null,
        'id_number' => $idNum !== '' ? $idNum : null,
        'document_type' => $docType !== '' ? $docType : null,
        'emergency_name' => $emName !== '' ? $emName : null,
        'emergency_contact' => $emPhone !== '' ? $emPhone : null,
        'address' => $address !== '' ? $address : null,
        'vehicle_type' => $vehicle !== '' ? $vehicle : null,
    ]];
}

/** Write an audit_log row. */
function employee_audit(PDO $pdo, int $actorId, string $action, int $employeeId, ?array $before, ?array $after): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, entity, entity_id, before_json, after_json, ip)
         VALUES (?, ?, 'employee', ?, ?, ?, ?)"
    );
    $stmt->execute([
        $actorId, $action, $employeeId,
        $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        client_ip_bin(),
    ]);
}

/** Initials for the avatar placeholder. */
function employee_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($a . $b);
}
