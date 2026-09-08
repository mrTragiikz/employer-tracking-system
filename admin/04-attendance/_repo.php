<?php
/**
 * admin/04-attendance/_repo.php - data access for the Attendance section.
 * Pure functions over $pdo. The employee IS the field person - no separate split.
 *
 * "Working days" = weekdays in the month up to today (Saturday is the Nepal
 * weekend, matching the demo generator and the field app).
 */

declare(strict_types=1);

/** Employees for the filter <select>. */
function att_employees(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name, code, is_active
           FROM users
          WHERE role = 'employee' AND deleted_at IS NULL
          ORDER BY is_active DESC, name"
    )->fetchAll();
}

/** [from, to, label, days_in_month] for a 'YYYY-MM' (defaults to current). */
function att_month(?string $month): array
{
    $today = new DateTimeImmutable(server_today());
    if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month) || $month > $today->format('Y-m')) {
        $month = $today->format('Y-m');
    }
    $first = new DateTimeImmutable($month . '-01');
    $last  = $first->modify('last day of this month');
    return [
        'month'  => $month,
        'from'   => $first->format('Y-m-d'),
        'to'     => $last->format('Y-m-d'),
        'label'  => $first->format('F Y'),
        'days'   => (int) $last->format('j'),
        'is_now' => $month === $today->format('Y-m'),
    ];
}

/**
 * Count Sun-Fri (skip Saturday) days in [from, to], but not past today.
 */
function att_working_days(string $from, string $to): int
{
    $today = server_today();
    $end   = $to < $today ? $to : $today;
    if ($end < $from) return 0;
    $d   = new DateTimeImmutable($from);
    $lim = new DateTimeImmutable($end);
    $n = 0;
    while ($d <= $lim) {
        if ((int) $d->format('N') !== 6) $n++;   // 6 = Saturday
        $d = $d->modify('+1 day');
    }
    return $n;
}

/**
 * The five headline numbers for one month, optionally one employee.
 * "present" days = attendance rows; "field days" = days with >=1 visit.
 */
function att_stats(PDO $pdo, array $m, int $employeeId = 0): array
{
    $where = 'a.work_date BETWEEN ? AND ?';
    $args  = [$m['from'], $m['to']];
    if ($employeeId) { $where .= ' AND a.employee_id = ?'; $args[] = $employeeId; }

    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };

    $employeeCount = $employeeId ? 1 : (int) $one($pdo,
        "SELECT COUNT(*) FROM users WHERE role='employee' AND deleted_at IS NULL", []);
    $workingDays = att_working_days($m['from'], $m['to']);
    $expected    = $workingDays * max(1, $employeeCount);

    $present = (int) $one($pdo, "SELECT COUNT(*) FROM attendance a WHERE $where", $args);
    $incomplete = (int) $one($pdo, "SELECT COUNT(*) FROM attendance a WHERE $where AND a.status='incomplete'", $args);
    $fieldDays  = (int) $one($pdo,
        "SELECT COUNT(DISTINCT CONCAT(a.employee_id,'|',a.work_date))
           FROM attendance a JOIN visits v ON v.attendance_id = a.id WHERE $where", $args);
    $totalSecs  = (int) $one($pdo, "SELECT COALESCE(SUM(a.total_seconds),0) FROM attendance a WHERE $where AND a.total_seconds IS NOT NULL", $args);
    $closed     = (int) $one($pdo, "SELECT COUNT(*) FROM attendance a WHERE $where AND a.total_seconds IS NOT NULL", $args);
    $visits     = (int) $one($pdo, "SELECT COUNT(*) FROM visits v JOIN attendance a ON a.id = v.attendance_id WHERE $where", $args);

    return [
        'working_days' => $workingDays,
        'expected'     => $expected,
        'present'      => $present,
        'absent'       => max(0, $expected - $present),
        'incomplete'   => $incomplete,
        'field_days'   => $fieldDays,
        'avg_secs'     => $closed > 0 ? (int) round($totalSecs / $closed) : 0,
        'rate'         => $expected > 0 ? round($present / $expected * 100, 1) : 0.0,
        'visits'       => $visits,
        'employee_count' => $employeeCount,
    ];
}

/**
 * "Top attendees" for the month - employees with the most present days.
 */
function att_top(PDO $pdo, array $m, int $limit = 5): array
{
    $wd = max(1, att_working_days($m['from'], $m['to']));
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.code, COUNT(*) AS days
           FROM attendance a JOIN users u ON u.id = a.employee_id
          WHERE a.work_date BETWEEN ? AND ?
          GROUP BY u.id, u.name, u.code
          ORDER BY days DESC, u.name
          LIMIT $limit"
    );
    $stmt->execute([$m['from'], $m['to']]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['rate'] = round(min(100, $r['days'] / $wd * 100), 1);
    }
    return $rows;
}

/**
 * Last N days of present-count, for the trend mini-bars. Newest last.
 */
function att_trend(PDO $pdo, int $days = 14): array
{
    $from = (new DateTimeImmutable(server_today()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $stmt = $pdo->prepare(
        "SELECT work_date, COUNT(*) AS n
           FROM attendance WHERE work_date BETWEEN ? AND ?
          GROUP BY work_date"
    );
    $stmt->execute([$from, server_today()]);
    $byDay = [];
    foreach ($stmt as $r) $byDay[$r['work_date']] = (int) $r['n'];

    $out = [];
    $d = new DateTimeImmutable($from);
    $today = new DateTimeImmutable(server_today());
    while ($d <= $today) {
        $key = $d->format('Y-m-d');
        $out[] = ['date' => $key, 'n' => $byDay[$key] ?? 0, 'label' => $d->format('j M')];
        $d = $d->modify('+1 day');
    }
    return $out;
}

/**
 * Resolve the table's date window from a 'range' preset (or a specific date),
 * falling back to the whole selected month.
 *
 * @return array{0:string,1:string,2:string}  [from, to, human label]
 */
function att_range(array $m, ?string $range, ?string $on): array
{
    $today = server_today();
    if ($on && preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) && strtotime($on) && $on <= $today) {
        return [$on, $on, (new DateTimeImmutable($on))->format('l, j F Y')];
    }
    switch ($range) {
        case 'today':
            return [$today, $today, 'Today'];
        case 'yesterday':
            $y = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            return [$y, $y, 'Yesterday'];
        case 'week': // last 7 days incl. today
            $f = (new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
            return [$f, $today, 'Last 7 days'];
        case '30d':
            $f = (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d');
            return [$f, $today, 'Last 30 days'];
        default:
            return [$m['from'], $m['to'], 'All of ' . $m['label']];
    }
}

/**
 * The daily attendance table - one row per attendance record, newest first.
 *
 * @param array $f  ['from'=>'YYYY-MM-DD','to'=>'YYYY-MM-DD','employee'=>int,
 *                    'status'=>'','q'=>string,'page'=>1-based,'per_page'=>int]
 */
function att_list(PDO $pdo, array $f): array
{
    $perPage = in_array((int) ($f['per_page'] ?? 10), [10, 25, 50, 100], true) ? (int) $f['per_page'] : 10;
    $page    = max(1, (int) ($f['page'] ?? 1));

    $where = ['a.work_date BETWEEN ? AND ?'];
    $args  = [$f['from'], $f['to']];
    if (!empty($f['employee'])) { $where[] = 'a.employee_id = ?'; $args[] = (int) $f['employee']; }
    if (in_array($f['status'] ?? '', ['open', 'closed', 'incomplete'], true)) {
        $where[] = 'a.status = ?'; $args[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $where[] = '(u.name LIKE ? OR u.code LIKE ?)';
        $args[] = '%' . $f['q'] . '%'; $args[] = '%' . $f['q'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN users u ON u.id = a.employee_id WHERE $whereSql");
    $cnt->execute($args);
    $total  = (int) $cnt->fetchColumn();
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT a.id, a.work_date, a.check_in_at, a.check_out_at, a.total_seconds,
                a.shop_seconds, a.road_seconds, a.road_km, a.status,
                u.id AS employee_id, u.name AS employee_name, u.code AS employee_code,
                (SELECT COUNT(*) FROM visits v WHERE v.attendance_id = a.id) AS visit_count
           FROM attendance a JOIN users u ON u.id = a.employee_id
          WHERE $whereSql
          ORDER BY a.work_date DESC, a.check_in_at DESC
          LIMIT ? OFFSET ?"
    );
    foreach ($args as $i => $val) $stmt->bindValue($i + 1, $val);
    $stmt->bindValue(count($args) + 1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(count($args) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows'     => $stmt->fetchAll(),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $pages,
    ];
}

/**
 * Every attendance row matching the filters, no pagination - for CSV export
 * (api/export.php). Same filter logic as att_list() above, kept in sync
 * deliberately since this is the "export everything on screen" button.
 *
 * @param array $f  ['from'=>'YYYY-MM-DD', 'to'=>'YYYY-MM-DD', 'employee'=>int,
 *                    'status'=>string, 'q'=>string]
 */
function att_all_for_export(PDO $pdo, array $f): array
{
    $where = ['a.work_date BETWEEN ? AND ?'];
    $args  = [$f['from'], $f['to']];
    if (!empty($f['employee'])) { $where[] = 'a.employee_id = ?'; $args[] = (int) $f['employee']; }
    if (in_array($f['status'] ?? '', ['open', 'closed', 'incomplete'], true)) {
        $where[] = 'a.status = ?'; $args[] = $f['status'];
    }
    if (!empty($f['q'])) {
        $where[] = '(u.name LIKE ? OR u.code LIKE ?)';
        $args[] = '%' . $f['q'] . '%'; $args[] = '%' . $f['q'] . '%';
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT a.work_date, a.check_in_at, a.check_out_at, a.total_seconds,
                a.shop_seconds, a.road_seconds, a.road_km, a.status,
                u.name AS employee_name, u.code AS employee_code,
                (SELECT COUNT(*) FROM visits v WHERE v.attendance_id = a.id) AS visit_count
           FROM attendance a JOIN users u ON u.id = a.employee_id
          WHERE $whereSql
          ORDER BY a.work_date DESC, a.check_in_at DESC"
    );
    $stmt->execute($args);

    return $stmt->fetchAll();
}
