<?php
/**
 * admin/07-alerts/_repo.php - the live activity feed.
 *
 * The Alerts section is a running stream of what every Employee does: checked in,
 * visited a shop, uploaded a photo, checked out - newest first. Suspicious
 * activity (fraud_flags -> alerts rows for rules 5/6/7/9) shows in the same
 * feed as a warning/critical entry. Pure functions over $pdo.
 */

declare(strict_types=1);

/** Employees for the filter <select>. */
function feed_employees(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id, name, code, is_active
           FROM users
          WHERE role = 'employee' AND deleted_at IS NULL
          ORDER BY is_active DESC, name"
    )->fetchAll();
}

/**
 * One page of the merged activity feed, newest first.
 *
 * @param array $f  ['type'=>'all'|'checkin'|'checkout'|'visit'|'photo'|'alert',
 *                    'employee'=>int, 'date'=>'YYYY-MM-DD', 'limit'=>int, 'before'=>datetime]
 * @return array{rows:array,has_more:bool,last_at:?string}
 */
/**
 * How far back "Load older activity" is willing to look in one page. Keeps
 * every UNION arm's WHERE bounded to a range its existing (employee_id,
 * work_date) / (employee_id, taken_at) indexes can seek into directly,
 * instead of scanning the whole table's history to sort it. A user paging
 * back with "Load older" just gets another 60-day slice further back -
 * cheap indexed range scans all the way down, however many years pile up.
 */
const FEED_WINDOW_DAYS = 60;

function feed_list(PDO $pdo, array $f): array
{
    $type   = in_array($f['type'] ?? 'all', ['all', 'checkin', 'checkout', 'visit', 'photo', 'alert'], true) ? $f['type'] : 'all';
    $limit  = max(10, min(100, (int) ($f['limit'] ?? 40)));
    $employee = (int) ($f['employee'] ?? 0);
    $date   = (!empty($f['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['date'])) ? $f['date'] : '';
    $before = (!empty($f['before']) && strtotime((string) $f['before'])) ? $f['before'] : null;

    // Bound the scan: a specific date is already narrow. Otherwise take a
    // rolling window ending at "before" (or now), FEED_WINDOW_DAYS wide, and
    // let the caller re-request further back once this slice runs dry.
    $windowFrom = null;
    if ($date === '') {
        $anchor = $before !== null ? new DateTimeImmutable($before) : new DateTimeImmutable('now');
        $windowFrom = $anchor->modify('-' . FEED_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s');
    }

    // Each UNION arm produces the same columns:
    //   at, kind, employee_id, employee_name, employee_code, subject, detail, ref_date, level
    // Real prepared statements (EMULATE_PREPARES=false) can't reuse one named
    // placeholder across the UNION, so each arm that needs the window floor
    // gets its own :wfN bound to the identical value.
    $arms = [];
    $args = [];
    $wfN  = 0;
    $wf   = static function () use (&$wfN, $windowFrom, &$args): string {
        if ($windowFrom === null) return '';
        $wfN++;
        $args[":wf$wfN"] = $windowFrom;
        return ":wf$wfN";
    };

    $wEmployee = $employee ? ' AND a.employee_id = ' . $employee : '';
    $wEmployeeV = $employee ? ' AND v.employee_id = ' . $employee : '';

    if ($type === 'all' || $type === 'checkin') {
        $p = $wf();
        $wWindow = $p !== '' ? " AND a.work_date >= DATE($p)" : '';
        $arms[] = "SELECT a.check_in_at AS at, 'checkin' AS kind, u.id AS employee_id, u.name AS employee_name,
                          u.code AS employee_code, 'checked in for the day' AS subject,
                          CONCAT(ROUND(a.check_in_lat,5),', ',ROUND(a.check_in_lng,5)) AS detail,
                          a.work_date AS ref_date, 'info' AS level
                     FROM attendance a JOIN users u ON u.id = a.employee_id
                    WHERE 1=1 $wEmployee$wWindow";
    }
    if ($type === 'all' || $type === 'checkout') {
        $p = $wf();
        $wWindow = $p !== '' ? " AND a.work_date >= DATE($p)" : '';
        $arms[] = "SELECT a.check_out_at AS at, 'checkout' AS kind, u.id AS employee_id, u.name AS employee_name,
                          u.code AS employee_code, 'checked out' AS subject,
                          CONCAT(ROUND(a.check_out_lat,5),', ',ROUND(a.check_out_lng,5)) AS detail,
                          a.work_date AS ref_date, 'info' AS level
                     FROM attendance a JOIN users u ON u.id = a.employee_id
                    WHERE a.check_out_at IS NOT NULL $wEmployee$wWindow";
    }
    if ($type === 'all' || $type === 'visit') {
        $p = $wf();
        $wWindow = $p !== '' ? " AND v.work_date >= DATE($p)" : '';
        $arms[] = "SELECT v.arrived_at AS at, 'visit' AS kind, u.id AS employee_id, u.name AS employee_name,
                          u.code AS employee_code, CONCAT('visited ', v.shop_name) AS subject,
                          CONCAT(ROUND(v.lat,5),', ',ROUND(v.lng,5)) AS detail,
                          v.work_date AS ref_date, 'info' AS level
                     FROM visits v JOIN users u ON u.id = v.employee_id
                    WHERE 1=1 $wEmployeeV$wWindow";
    }
    if ($type === 'all' || $type === 'photo') {
        $wEmployeeP = $employee ? ' AND p.employee_id = ' . $employee : '';
        $p = $wf();
        $wWindow = $p !== '' ? " AND p.work_date >= DATE($p)" : '';
        $arms[] = "SELECT p.taken_at AS at, 'photo' AS kind, u.id AS employee_id, u.name AS employee_name,
                          u.code AS employee_code,
                          CASE p.photo_kind
                            WHEN 'checkin'  THEN 'added a check-in photo'
                            WHEN 'checkout' THEN 'added a check-out photo'
                            ELSE CONCAT('added a photo at ', COALESCE(v.shop_name, 'a shop'))
                          END AS subject,
                          p.stored_path AS detail,
                          p.work_date AS ref_date, 'info' AS level
                     FROM photos p
                     JOIN users u ON u.id = p.employee_id
                LEFT JOIN visits v ON v.id = p.visit_id
                    WHERE 1=1 $wEmployeeP$wWindow";
    }
    if ($type === 'all' || $type === 'alert') {
        $p = $wf();
        $wWindow = $p !== '' ? " AND al.created_at >= $p" : '';
        $arms[] = "SELECT al.created_at AS at, 'alert' AS kind, u.id AS employee_id,
                          COALESCE(u.name,'System') AS employee_name, u.code AS employee_code,
                          al.title AS subject, al.body AS detail,
                          DATE(al.created_at) AS ref_date, al.level AS level
                     FROM alerts al LEFT JOIN users u ON u.id = al.user_id
                    WHERE al.fraud_flag_id IS NOT NULL"
                    . ($employee ? ' AND al.user_id = ' . $employee : '') . $wWindow;
    }

    // Force every arm's text columns to one explicit collation before the
    // UNION. Without this, a database where the `photos` table ended up
    // utf8mb4_general_ci while `users` / `visits` / `alerts` are
    // utf8mb4_unicode_ci throws "Illegal mix of collations for operation
    // 'UNION'". sql/database.sql now declares every table utf8mb4_unicode_ci,
    // so a fresh import is fine; this CAST keeps the feed working on any
    // older database whose `photos` table still carries the wrong collation.
    $coll = ' COLLATE utf8mb4_unicode_ci';
    $armsSafe = array_map(static function (string $arm) use ($coll): string {
        return 'SELECT at, kind,
                       employee_id,
                       CAST(employee_name AS CHAR)' . $coll . ' AS employee_name,
                       CAST(employee_code AS CHAR)' . $coll . ' AS employee_code,
                       CAST(subject AS CHAR)' . $coll . ' AS subject,
                       CAST(detail AS CHAR)' . $coll . ' AS detail,
                       ref_date,
                       CAST(level AS CHAR)' . $coll . ' AS level
                  FROM ( ' . $arm . ' ) arm';
    }, $arms);

    $union = '(' . implode(") UNION ALL (", $armsSafe) . ')';
    $conds = ['at IS NOT NULL'];
    if ($date !== '') { $conds[] = 'ref_date = :date'; $args[':date'] = $date; }
    if ($before !== null) { $conds[] = 'at < :before'; $args[':before'] = $before; }

    $sql = "SELECT * FROM ( $union ) feed WHERE " . implode(' AND ', $conds)
         . " ORDER BY at DESC LIMIT " . ($limit + 1);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    return [
        'rows'        => $rows,
        'has_more'    => $hasMore,
        'last_at'     => $rows ? end($rows)['at'] : null,
        'window_from' => $windowFrom,
        // true when this page came back empty/short AND there's older
        // history beyond the window - the UI can offer "search further back"
        // distinctly from "no more activity at all".
        'window_bound' => $windowFrom !== null,
    ];
}

/** Small counters shown as tabs. Scoped to today unless a date is given. */
function feed_counts(PDO $pdo, string $date = '', int $employee = 0): array
{
    $on     = $date !== '' ? $date : server_today();
    $wD     = $employee ? ' AND employee_id = ?' : '';
    $args   = $employee ? [$on, $employee] : [$on];
    // Half-open range instead of DATE(created_at) = ? so alerts can use an
    // index on created_at (a function-wrapped column can't be range-scanned).
    $dayEnd = date('Y-m-d', strtotime($on . ' +1 day'));
    $argsAl = $employee ? [$on, $dayEnd, $employee] : [$on, $dayEnd];

    $one = static function (PDO $pdo, string $sql, array $a): int {
        $s = $pdo->prepare($sql);
        $s->execute($a);
        return (int) $s->fetchColumn();
    };

    return [
        'checkin'  => $one($pdo, "SELECT COUNT(*) FROM attendance WHERE work_date = ?$wD", $args),
        'checkout' => $one($pdo, "SELECT COUNT(*) FROM attendance WHERE work_date = ? AND check_out_at IS NOT NULL"
                                 . ($employee ? ' AND employee_id = ?' : ''), $args),
        'visit'    => $one($pdo, "SELECT COUNT(*) FROM visits WHERE work_date = ?$wD", $args),
        'photo'    => $one($pdo, "SELECT COUNT(*) FROM photos WHERE work_date = ?$wD", $args),
        'alert'    => $one($pdo, "SELECT COUNT(*) FROM alerts WHERE created_at >= ? AND created_at < ? AND fraud_flag_id IS NOT NULL"
                                 . ($employee ? ' AND user_id = ?' : ''), $argsAl),
    ];
}
