<?php
/**
 * field/api-v2/_shape.php - turn DB rows into the JSON shapes the app expects.
 *
 * One place for every "row -> API object" mapping so the endpoints stay thin
 * and every date/number/photo is formatted the same way. Loaded by the
 * endpoints that need it (require dirname(__DIR__) . '/api-v2/_shape.php').
 */

declare(strict_types=1);

/** Absolute URL for a stored upload path, or null. */
function api_photo_url(?string $storedPath): ?string
{
    if ($storedPath === null || $storedPath === '') {
        return null;
    }
    return rtrim(UPLOAD_URL, '/') . '/' . ltrim($storedPath, '/');
}

/** "2026-09-09T14:32:00+05:45" ISO-8601, or null. The app parses this. */
function api_iso(?string $dbDateTime): ?string
{
    if ($dbDateTime === null || $dbDateTime === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($dbDateTime))->format('c');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * One attendance row -> API object. $now is server_now() (so an open day's
 * active_seconds is "until now").
 */
function api_attendance(?array $att, string $now): ?array
{
    if ($att === null) {
        return null;
    }
    $ci = $att['check_in_at'] ?? null;
    $co = $att['check_out_at'] ?? null;

    $activeSecs = null;
    if ($ci) {
        $end = $co ?: $now;
        $activeSecs = max(0, strtotime($end) - strtotime($ci));
    }

    return [
        'id'              => (int) $att['id'],
        'work_date'       => $att['work_date'],
        'status'          => $att['status'],           // 'open' | 'closed'
        'checked_in'      => $ci !== null,
        'checked_out'     => $co !== null,
        'check_in_at'     => api_iso($ci),
        'check_out_at'    => api_iso($co),
        'check_in_lat'    => $att['check_in_lat']  !== null ? (float) $att['check_in_lat']  : null,
        'check_in_lng'    => $att['check_in_lng']  !== null ? (float) $att['check_in_lng']  : null,
        'check_out_lat'   => $att['check_out_lat'] !== null ? (float) $att['check_out_lat'] : null,
        'check_out_lng'   => $att['check_out_lng'] !== null ? (float) $att['check_out_lng'] : null,
        'check_in_odometer_km'  => $att['check_in_odometer_km']  !== null ? (float) $att['check_in_odometer_km']  : null,
        'check_out_odometer_km' => $att['check_out_odometer_km'] !== null ? (float) $att['check_out_odometer_km'] : null,
        'road_km'         => (float) ($att['road_km'] ?? 0),
        'shop_seconds'    => (int) ($att['shop_seconds'] ?? 0),
        'road_seconds'    => (int) ($att['road_seconds'] ?? 0),
        'active_seconds'  => $activeSecs,
    ];
}

/** One visit row (optionally joined with its photo) -> API object. */
function api_visit(array $v): array
{
    return [
        'id'            => (int) $v['id'],
        'seq'           => (int) $v['seq'],
        'shop_name'     => $v['shop_name'],
        'area_name'     => $v['area_name'],
        'lat'           => (float) $v['lat'],
        'lng'           => (float) $v['lng'],
        'accuracy_m'    => $v['accuracy_m'] !== null ? (float) $v['accuracy_m'] : null,
        'arrived_at'    => api_iso($v['arrived_at']),
        'left_at'       => api_iso($v['left_at'] ?? null),
        'is_open'       => ($v['left_at'] ?? null) === null,
        'dwell_seconds' => isset($v['dwell_seconds']) && $v['dwell_seconds'] !== null ? (int) $v['dwell_seconds'] : null,
        'hop_road_km'   => isset($v['hop_road_km']) ? (float) $v['hop_road_km'] : null,
        'remark'        => $v['remark'] ?? null,
        'photo_url'     => api_photo_url($v['photo_path'] ?? null),
    ];
}
