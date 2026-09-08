<?php
/**
 * admin/08-evidence-photos/_repo.php - data access for the Evidence Photos section.
 * Pure functions over $pdo, no output.
 *
 * A "photo" is a row in `photos` - a live-camera capture an Employee took during a
 * working day. There are exactly THREE kinds:
 *   checkin  - taken at check-in  (attached to the attendance row)
 *   visit    - taken at a shop     (attached to a visit row)
 *   checkout - taken at check-out (attached to the attendance row)
 *
 * This section is a GALLERY: browse / filter / view / download / export. There
 * is NO verify / reject / approval workflow - anything suspicious surfaces in
 * the Alerts section, not here.
 */

declare(strict_types=1);

/** The photo kinds, in day order: value => human label. */
function photo_kinds(): array
{
    return [
        'checkin'  => 'Check-in',
        'visit'    => 'Shop visit',
        'checkout' => 'Check-out',
    ];
}

/** Employees that have at least one photo, for the filter <select>. */
function photos_employees(PDO $pdo): array
{
    return $pdo->query(
        "SELECT DISTINCT u.id, u.name, u.code, u.is_active
           FROM users u
           JOIN photos p ON p.employee_id = u.id
          WHERE u.role = 'employee' AND u.deleted_at IS NULL
          ORDER BY u.is_active DESC, u.name"
    )->fetchAll();
}

/**
 * Resolve the date window from the request.
 *
 * @param array $f  ['range'=>'all'|'today'|'7d'|'30d'|'on', 'on'=>'YYYY-MM-DD']
 * @return array{0:?string,1:?string,2:string}  [from, to, label]  (null = open end)
 */
function photos_window(array $f): array
{
    $range = in_array($f['range'] ?? 'all', ['all', 'today', '7d', '30d', 'on'], true)
        ? $f['range'] : 'all';
    $today = server_today();

    return match ($range) {
        'today' => [$today, $today, 'Today'],
        '7d'    => [date('Y-m-d', strtotime($today . ' -6 days')), $today, 'Last 7 days'],
        '30d'   => [date('Y-m-d', strtotime($today . ' -29 days')), $today, 'Last 30 days'],
        'on'    => (function () use ($f) {
            $on = (string) ($f['on'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
                return [null, null, 'All time'];
            }
            return [$on, $on, (new DateTimeImmutable($on))->format('j M Y')];
        })(),
        default => [null, null, 'All time'],
    };
}

/** Shared WHERE builder for the date window + Employee + kind + search. */
function photos_where(array $f): array
{
    [$from, $to] = photos_window($f);
    $where = ['1 = 1'];
    $args  = [];

    if ($from !== null) { $where[] = 'p.work_date >= ?'; $args[] = $from; }
    if ($to   !== null) { $where[] = 'p.work_date <= ?'; $args[] = $to; }

    if (!empty($f['employee'])) {
        $where[] = 'p.employee_id = ?';
        $args[]  = (int) $f['employee'];
    }
    if (!empty($f['kind']) && array_key_exists($f['kind'], photo_kinds())) {
        $where[] = 'p.photo_kind = ?';
        $args[]  = $f['kind'];
    }
    if (!empty($f['q'])) {
        $where[] = "(COALESCE(v.shop_name, '') LIKE ? OR u.name LIKE ? OR u.code LIKE ?)";
        $like = '%' . $f['q'] . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }

    return [implode(' AND ', $where), $args];
}

/** The SELECT + JOINs shared by list + export. */
function photos_base_sql(): string
{
    return "FROM photos p
            JOIN users u  ON u.id = p.employee_id
       LEFT JOIN visits v ON v.id = p.visit_id
       LEFT JOIN attendance a ON a.id = p.attendance_id";
}

/**
 * One page of photos, newest first, with the filters applied.
 *
 * @return array{rows:array,total:int,page:int,per_page:int,pages:int}
 */
function photos_list(PDO $pdo, array $f): array
{
    [$whereSql, $args] = photos_where($f);
    $base = photos_base_sql();

    $perPage = in_array((int) ($f['per_page'] ?? 12), [12, 24, 48, 96], true) ? (int) $f['per_page'] : 12;
    $page    = max(1, (int) ($f['page'] ?? 1));

    $c = $pdo->prepare("SELECT COUNT(*) $base WHERE $whereSql");
    $c->execute($args);
    $total  = (int) $c->fetchColumn();
    $pages  = max(1, (int) ceil($total / $perPage));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        "SELECT p.id, p.photo_kind, p.stored_path, p.mime, p.bytes, p.width, p.height,
                p.taken_at, p.lat, p.lng, p.work_date,
                p.visit_id, p.attendance_id,
                v.shop_name,
                u.id AS employee_id, u.name AS employee_name, u.code AS employee_code,
                u.region, u.photo_path AS employee_photo
           $base
          WHERE $whereSql
          ORDER BY p.taken_at DESC, p.id DESC
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
    ];
}

/** All rows for the CSV export (no paging), same filters. */
function photos_all_for_export(PDO $pdo, array $f): array
{
    [$whereSql, $args] = photos_where($f);
    $base = photos_base_sql();
    $stmt = $pdo->prepare(
        "SELECT p.id, p.photo_kind, p.bytes, p.taken_at, p.work_date, p.lat, p.lng,
                v.shop_name,
                u.name AS employee_name, u.code AS employee_code, u.region
           $base
          WHERE $whereSql
          ORDER BY p.taken_at DESC, p.id DESC"
    );
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/**
 * The headline numbers for the current window (Employee / kind / search NOT
 * applied - these describe the whole window).
 */
function photos_stats(PDO $pdo, array $f): array
{
    [$from, $to] = photos_window($f);
    $win  = ['1 = 1'];
    $args = [];
    if ($from !== null) { $win[] = 'work_date >= ?'; $args[] = $from; }
    if ($to   !== null) { $win[] = 'work_date <= ?'; $args[] = $to; }
    $winSql = implode(' AND ', $win);

    $one = static function (PDO $pdo, string $sql, array $a) {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return $s->fetchColumn();
    };

    return [
        'total'    => (int) $one($pdo, "SELECT COUNT(*) FROM photos WHERE $winSql", $args),
        'today'    => (int) $one($pdo, "SELECT COUNT(*) FROM photos WHERE work_date = ?", [server_today()]),
        'employees'  => (int) $one($pdo, "SELECT COUNT(DISTINCT employee_id) FROM photos WHERE $winSql", $args),
        'days'     => (int) $one($pdo, "SELECT COUNT(DISTINCT work_date) FROM photos WHERE $winSql", $args),
        'visit_ph' => (int) $one($pdo, "SELECT COUNT(*) FROM photos WHERE $winSql AND photo_kind = 'visit'", $args),
        'mb'       => round(((int) $one($pdo, "SELECT COALESCE(SUM(bytes),0) FROM photos WHERE $winSql", $args)) / 1048576, 1),
    ];
}

/**
 * Right rail: a by-kind breakdown for the window + a Top Employees list
 * (by photo count).
 */
function photos_side(PDO $pdo, array $f): array
{
    [$from, $to, $label] = photos_window($f);
    $win  = ['1 = 1'];
    $args = [];
    if ($from !== null) { $win[] = 'p.work_date >= ?'; $args[] = $from; }
    if ($to   !== null) { $win[] = 'p.work_date <= ?'; $args[] = $to; }
    $winSql = implode(' AND ', $win);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM photos p WHERE $winSql");
    $totalStmt->execute($args);
    $total = (int) $totalStmt->fetchColumn();

    $byKindStmt = $pdo->prepare(
        "SELECT p.photo_kind, COUNT(*) AS n FROM photos p WHERE $winSql GROUP BY p.photo_kind"
    );
    $byKindStmt->execute($args);
    $raw = [];
    foreach ($byKindStmt->fetchAll() as $r) {
        $raw[$r['photo_kind']] = (int) $r['n'];
    }
    $byKind = [];
    foreach (photo_kinds() as $key => $lbl) {
        $n = $raw[$key] ?? 0;
        $byKind[] = [
            'key'   => $key,
            'label' => $lbl,
            'count' => $n,
            'pct'   => $total > 0 ? round($n * 100 / $total) : 0,
        ];
    }

    $topStmt = $pdo->prepare(
        "SELECT u.id, u.name, u.code, u.photo_path AS employee_photo, COUNT(*) AS photos
           FROM photos p
           JOIN users u ON u.id = p.employee_id
          WHERE $winSql
          GROUP BY u.id, u.name, u.code, u.photo_path
          ORDER BY photos DESC, u.name
          LIMIT 5"
    );
    $topStmt->execute($args);

    return [
        'label'       => $label,
        'total'       => $total,
        'by_kind'     => $byKind,
        'top_employees' => $topStmt->fetchAll(),
    ];
}
