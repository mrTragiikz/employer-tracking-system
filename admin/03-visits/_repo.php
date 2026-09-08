<?php
/**
 * admin/03-visits/_repo.php - data access for the Visits section.
 * Pure functions over $pdo, no output.
 *
 * A "visit" is a row in `visits` (one "I'm here" at a shop). The Employee IS the
 * field person - there is no separate employee. No approval / status workflow.
 */

declare(strict_types=1);

/** Employees for the filter <select> (id, name, code, is_active). */
function visits_employees(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name, code, is_active
           FROM users
          WHERE role = 'employee' AND deleted_at IS NULL
          ORDER BY is_active DESC, name"
    )->fetchAll();
}

/**
 * One page of visits, newest first, with the filters applied.
 *
 * @param array $f  ['scope'=>'all'|'today', 'employee'=>int, 'month'=>'YYYY-MM',
 *                    'q'=>string, 'page'=>1-based, 'per_page'=>int]
 * @return array{rows:array,total:int,page:int,per_page:int,pages:int,
 *               month:string,scope:string}
 */
function visits_list(PDO $pdo, array $f): array
{
    $scope   = in_array($f['scope'] ?? 'all', ['all', 'today'], true) ? $f['scope'] : 'all';
    $perPage = in_array((int) ($f['per_page'] ?? 10), [10, 25, 50, 100], true) ? (int) $f['per_page'] : 10;
    $page    = max(1, (int) ($f['page'] ?? 1));

    $where = ['1 = 1'];
    $args  = [];

    if ($scope === 'today') {
        $where[] = 'v.work_date = ?';
        $args[]  = server_today();
    } elseif (!empty($f['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $f['month'])) {
        $mFrom = $f['month'] . '-01';
        $mTo   = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
        $where[] = 'v.work_date BETWEEN ? AND ?';
        $args[]  = $mFrom;
        $args[]  = $mTo;
    }

    if (!empty($f['employee'])) {
        $where[] = 'v.employee_id = ?';
        $args[]  = (int) $f['employee'];
    }
    if (!empty($f['q'])) {
        $where[] = '(v.shop_name LIKE ? OR u.name LIKE ? OR u.code LIKE ?)';
        $like = '%' . $f['q'] . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $whereSql = implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM visits v JOIN users u ON u.id = v.employee_id WHERE $whereSql");
    $countStmt->execute($args);
    $total  = (int) $countStmt->fetchColumn();
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT v.id, v.work_date, v.seq, v.arrived_at, v.left_at, v.shop_name,
                v.lat, v.lng, v.dwell_seconds, v.hop_road_km, v.hop_seconds, v.remark,
                u.id AS employee_id, u.name AS employee_name, u.code AS employee_code, u.region,
                (SELECT COUNT(*) FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit') AS photo_count,
                (SELECT p.stored_path FROM photos p WHERE p.visit_id = v.id AND p.photo_kind = 'visit' ORDER BY p.id LIMIT 1) AS photo_path
           FROM visits v
           JOIN users u ON u.id = v.employee_id
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

    return [
        'rows'     => $stmt->fetchAll(),
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $pages,
        'month'    => $f['month'] ?? substr(server_today(), 0, 7),
        'scope'    => $scope,
    ];
}

/**
 * Every visit matching the filters, newest first, no pagination - for CSV
 * export (api/export.php). Same filter logic as visits_list() above, kept
 * in sync deliberately since this is the "export everything on screen" button.
 *
 * @param array $f  ['scope'=>'all'|'today', 'employee'=>int, 'month'=>'YYYY-MM', 'q'=>string]
 */
function visits_all_for_export(PDO $pdo, array $f): array
{
    $scope = in_array($f['scope'] ?? 'all', ['all', 'today'], true) ? $f['scope'] : 'all';

    $where = ['1 = 1'];
    $args  = [];

    if ($scope === 'today') {
        $where[] = 'v.work_date = ?';
        $args[]  = server_today();
    } elseif (!empty($f['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $f['month'])) {
        $mFrom = $f['month'] . '-01';
        $mTo   = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
        $where[] = 'v.work_date BETWEEN ? AND ?';
        $args[]  = $mFrom;
        $args[]  = $mTo;
    }

    if (!empty($f['employee'])) {
        $where[] = 'v.employee_id = ?';
        $args[]  = (int) $f['employee'];
    }
    if (!empty($f['q'])) {
        $where[] = '(v.shop_name LIKE ? OR u.name LIKE ? OR u.code LIKE ?)';
        $like = '%' . $f['q'] . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare(
        "SELECT v.work_date, v.arrived_at, v.left_at, v.shop_name, v.lat, v.lng,
                v.dwell_seconds, v.hop_road_km, v.hop_seconds, v.remark,
                u.name AS employee_name, u.code AS employee_code, u.region
           FROM visits v
           JOIN users u ON u.id = v.employee_id
          WHERE $whereSql
          ORDER BY v.arrived_at DESC"
    );
    $stmt->execute($args);

    return $stmt->fetchAll();
}

/**
 * The five headline numbers. Scoped to the same window as the list
 * (today, a month, or all-time) but NOT to the Employee / search filters.
 */
function visits_stats(PDO $pdo, string $scope, ?string $month): array
{
    $where = '1 = 1';
    $args  = [];
    if ($scope === 'today') {
        $where = 'work_date = ?';
        $args  = [server_today()];
    } elseif ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $mFrom = $month . '-01';
        $mTo   = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
        $where = 'work_date BETWEEN ? AND ?';
        $args  = [$mFrom, $mTo];
    }

    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };

    // attendance also has a work_date column, so the same clause applies there.
    // km_est: a CLOSED day contributes attendance.road_km (the full-day audit
    // figure incl. the trip back to check-out); a STILL-OPEN day contributes
    // the sum of its visits' own hops (visits.hop_road_km, set at visit-save
    // time) - the route so far. Without this an employee still out on the
    // road shows 0 km all day.
    $kmSql = "SELECT COALESCE(SUM(
                  CASE WHEN a.check_out_at IS NOT NULL THEN a.road_km
                       ELSE (SELECT COALESCE(SUM(v.hop_road_km),0)
                               FROM visits v WHERE v.attendance_id = a.id)
                  END
              ), 0)
              FROM attendance a WHERE " . str_replace('work_date', 'a.work_date', $where);

    return [
        'total_visits'   => (int) $one($pdo, "SELECT COUNT(*) FROM visits WHERE $where", $args),
        'active_employees' => (int) $one($pdo, "SELECT COUNT(DISTINCT employee_id) FROM visits WHERE $where", $args),
        'km_est'         => round($one($pdo, $kmSql, $args), 1),
        'avg_dwell_secs' => (int) $one($pdo,
            "SELECT COALESCE(AVG(dwell_seconds),0) FROM visits WHERE $where AND dwell_seconds IS NOT NULL", $args),
        'visits_today'   => (int) $one($pdo, "SELECT COUNT(*) FROM visits WHERE work_date = ?", [server_today()]),
    ];
}

/**
 * Right-rail "Visit Summary" for the current window + a Top Employees list.
 */
function visits_side(PDO $pdo, string $scope, ?string $month): array
{
    $where = '1 = 1';
    $args  = [];
    $label = 'All time';
    if ($scope === 'today') {
        $where = 'v.work_date = ?';
        $args  = [server_today()];
        $label = (new DateTimeImmutable(server_today()))->format('j M Y');
    } elseif ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $mFrom = $month . '-01';
        $mTo   = (new DateTimeImmutable($mFrom))->modify('last day of this month')->format('Y-m-d');
        $where = 'v.work_date BETWEEN ? AND ?';
        $args  = [$mFrom, $mTo];
        $label = (new DateTimeImmutable($mFrom))->format('F Y');
    }

    $one = static function (PDO $pdo, string $sql, array $a): float {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (float) $s->fetchColumn();
    };

    // attendance has no `v` alias - use `a`, and apply the same open-day
    // fallback as visits_stats() (closed day: attendance.road_km; open day:
    // sum of its visits' hops).
    $attWhere = str_replace('v.work_date', 'a.work_date', $where);
    $kmSql = "SELECT COALESCE(SUM(
                  CASE WHEN a.check_out_at IS NOT NULL THEN a.road_km
                       ELSE (SELECT COALESCE(SUM(v.hop_road_km),0)
                               FROM visits v WHERE v.attendance_id = a.id)
                  END
              ), 0)
              FROM attendance a WHERE $attWhere";

    $top = $pdo->prepare(
        "SELECT u.id, u.name, u.code, COUNT(*) AS visits
           FROM visits v JOIN users u ON u.id = v.employee_id
          WHERE $where
          GROUP BY u.id, u.name, u.code
          ORDER BY visits DESC, u.name
          LIMIT 5"
    );
    $top->execute($args);

    return [
        'label'          => $label,
        'total_visits'   => (int) $one($pdo, "SELECT COUNT(*) FROM visits v WHERE $where", $args),
        'unique_employees' => (int) $one($pdo, "SELECT COUNT(DISTINCT v.employee_id) FROM visits v WHERE $where", $args),
        'km_est'         => round($one($pdo, $kmSql, $args), 1),
        'total_secs'     => (int) $one($pdo, "SELECT COALESCE(SUM(dwell_seconds),0) FROM visits v WHERE $where AND v.dwell_seconds IS NOT NULL", $args),
        'avg_dwell_secs' => (int) $one($pdo, "SELECT COALESCE(AVG(dwell_seconds),0) FROM visits v WHERE $where AND v.dwell_seconds IS NOT NULL", $args),
        'top_employees'    => $top->fetchAll(),
    ];
}
