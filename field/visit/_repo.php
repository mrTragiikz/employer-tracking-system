<?php
/**
 * field/visit/_repo.php
 *
 * Data access for the "Log a Visit" flow (field/visit/index.php - the "My
 * Visits" tab). Pure functions over $pdo, scoped to one employee - no
 * output. Doesn't declare field_today_attendance() - callers require
 * field/checkinout/_repo.php for that, per the self-contained-section
 * pattern.
 */

declare(strict_types=1);

/** Lowercase/trim/collapse-spaces key used for rule 8 (one visit per shop per day). */
function visit_normalize(string $name): string
{
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)) ?? '');
}

/**
 * The visit still open (left_at IS NULL) for this attendance day, if any.
 * An employee is only ever at one shop at a time - only logging a NEW visit
 * and marking the current one Done both check this.
 */
function visit_open_one(PDO $pdo, int $attendanceId): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM visits WHERE attendance_id = ? AND left_at IS NULL ORDER BY seq DESC LIMIT 1"
    );
    $st->execute([$attendanceId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * "Log a Visit" for the 1st, "Log Second Visit" for the 2nd, etc. - the
 * "Log a Visit" button's label, based on how many visits are logged so far
 * today (done or not). $doneCount is the count BEFORE the next one.
 */
function visit_next_label(int $doneCount): string
{
    if ($doneCount === 0) {
        return 'Log a Visit';
    }
    $ordinals = [2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth',
        6 => 'Sixth', 7 => 'Seventh', 8 => 'Eighth', 9 => 'Ninth', 10 => 'Tenth'];
    $n = $doneCount + 1;
    return 'Log ' . ($ordinals[$n] ?? $n . 'th') . ' Visit';
}

/**
 * Validate the visit form input (everything except the photo, which the API
 * endpoint validates via save_camera_photo()).
 *
 * @return array{ok:bool, errors:array<string,string>, data:array}
 */
function visit_validate(array $in): array
{
    $errors = [];

    $shopName = trim((string) ($in['shop_name'] ?? ''));
    if ($shopName === '') {
        $errors['shop_name'] = 'Enter the shop name.';
    } elseif (mb_strlen($shopName) > 160) {
        $errors['shop_name'] = 'Shop name is too long.';
    }

    $areaName = trim((string) ($in['area_name'] ?? ''));
    if ($areaName === '') {
        $errors['area_name'] = 'Enter the area name.';
    } elseif (mb_strlen($areaName) > 120) {
        $errors['area_name'] = 'Area name is too long.';
    }

    $lat = $in['lat'] ?? '';
    $lng = $in['lng'] ?? '';
    if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
        $errors['_'] = 'Could not read your location. Tap the location button and try again.';
    }

    return [
        'ok'     => empty($errors),
        'errors' => $errors,
        'data'   => [
            'shop_name' => $shopName,
            'area_name' => $areaName,
            'lat'       => $lat !== '' && is_numeric($lat) ? (float) $lat : null,
            'lng'       => $lng !== '' && is_numeric($lng) ? (float) $lng : null,
            'accuracy'  => is_numeric($in['accuracy'] ?? null) ? (float) $in['accuracy'] : null,
        ],
    ];
}

/**
 * Log a visit. Shared by the web endpoint (field/visit/api/save.php) and the
 * mobile API (field/api-v2/visit-save.php) so rule 8 (no duplicate shop
 * today), the "one open visit at a time" rule, shop auto-learn and the
 * per-hop distance are all in exactly one place.
 *
 * REQUIRES includes/distance.php loaded (haversine_km, road_km_real).
 *
 * @param array $upload       return of save_camera_photo($_FILES[...])
 * @return array{ok:bool, errors:array<string,string>, visit_id?:int}
 */
function visit_perform(PDO $pdo, array $me, string $today, array $input, array $upload, ?string $originalName = null): array
{
    // must be checked in, not checked out, no visit still open
    $att = null;
    if (function_exists('field_today_attendance')) {
        $att = field_today_attendance($pdo, (int) $me['id'], $today);
    }
    if ($att === null) {
        return ['ok' => false, 'errors' => ['_' => 'Please check in first.']];
    }
    if (!empty($att['check_out_at'])) {
        return ['ok' => false, 'errors' => ['_' => 'Your day is already checked out.']];
    }
    if (visit_open_one($pdo, (int) $att['id']) !== null) {
        return ['ok' => false, 'errors' => ['_' => 'Mark your current visit Done before starting a new one.']];
    }

    $check = visit_validate($input);
    if (!$upload['ok']) {
        $check['ok'] = false;
        $check['errors']['photo'] = $upload['error'] ?? 'The photo upload failed.';
    }

    $shopNorm = $check['ok'] ? visit_normalize($check['data']['shop_name']) : '';
    if ($check['ok']) {
        $dup = $pdo->prepare(
            'SELECT id FROM visits WHERE employee_id = ? AND shop_norm = ? AND work_date = ? LIMIT 1'
        );
        $dup->execute([$me['id'], $shopNorm, $today]);
        if ($dup->fetchColumn()) {
            $check['ok'] = false;
            $check['errors']['shop_name'] = 'You already logged a visit to this shop today.';
        }
    }
    if (!$check['ok']) {
        return ['ok' => false, 'errors' => $check['errors']];
    }
    $d = $check['data'];

    $myDeviceId = $pdo->prepare('SELECT device_id FROM users WHERE id = ?');
    $myDeviceId->execute([$me['id']]);
    $myDeviceId = $myDeviceId->fetchColumn() ?: null;

    $pdo->beginTransaction();
    try {
        // shop auto-learn
        $shopStmt = $pdo->prepare('SELECT id FROM shops WHERE employee_id = ? AND name_norm = ? LIMIT 1');
        $shopStmt->execute([$me['id'], $shopNorm]);
        $shopId = $shopStmt->fetchColumn();
        if ($shopId) {
            $pdo->prepare('UPDATE shops SET samples = samples + 1, visit_count = visit_count + 1 WHERE id = ?')
                ->execute([$shopId]);
            $shopId = (int) $shopId;
        } else {
            $pdo->prepare(
                'INSERT INTO shops (employee_id, name_display, name_norm, lat, lng, samples, visit_count)
                 VALUES (?, ?, ?, ?, ?, 1, 1)'
            )->execute([$me['id'], $d['shop_name'], $shopNorm, $d['lat'], $d['lng']]);
            $shopId = (int) $pdo->lastInsertId();
        }

        $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM visits WHERE attendance_id = ?');
        $seqStmt->execute([(int) $att['id']]);
        $seq = (int) $seqStmt->fetchColumn();

        $pdo->prepare(
            "INSERT INTO visits
               (attendance_id, employee_id, shop_id, shop_name, area_name, shop_norm,
                work_date, seq, arrived_at, lat, lng, accuracy_m, device_id)
             VALUES
               (:attendance_id, :employee_id, :shop_id, :shop_name, :area_name, :shop_norm,
                :work_date, :seq, NOW(), :lat, :lng, :accuracy, :device_id)"
        )->execute([
            ':attendance_id' => (int) $att['id'],
            ':employee_id'   => $me['id'],
            ':shop_id'       => $shopId,
            ':shop_name'     => $d['shop_name'],
            ':area_name'     => $d['area_name'],
            ':shop_norm'     => $shopNorm,
            ':work_date'     => $today,
            ':seq'           => $seq,
            ':lat'           => $d['lat'],
            ':lng'           => $d['lng'],
            ':accuracy'      => $d['accuracy'],
            ':device_id'     => $myDeviceId,
        ]);
        $visitId = (int) $pdo->lastInsertId();

        // this visit's own hop (previous point -> here). Best-effort - a slow
        // Directions lookup must never lose the visit itself.
        try {
            $prevStmt = $pdo->prepare(
                'SELECT lat, lng, arrived_at FROM visits
                  WHERE attendance_id = ? AND seq < ? ORDER BY seq DESC LIMIT 1'
            );
            $prevStmt->execute([(int) $att['id'], $seq]);
            $prev = $prevStmt->fetch();

            $fromLat = $prev ? (float) $prev['lat'] : (float) ($att['check_in_lat'] ?? 0);
            $fromLng = $prev ? (float) $prev['lng'] : (float) ($att['check_in_lng'] ?? 0);
            $fromAt  = $prev ? $prev['arrived_at']  : ($att['check_in_at'] ?? null);
            $hasFrom = ($fromLat !== 0.0 || $fromLng !== 0.0);
            $toLat = (float) $d['lat'];
            $toLng = (float) $d['lng'];

            if ($hasFrom && ($toLat !== 0.0 || $toLng !== 0.0)) {
                $straight = round(haversine_km($fromLat, $fromLng, $toLat, $toLng), 3);
                $real     = road_km_real($fromLat, $fromLng, $toLat, $toLng);
                $roadKm   = ($real['km'] > 0) ? $real['km'] : $straight;

                $arrivedAt = (string) $pdo->query('SELECT arrived_at FROM visits WHERE id = ' . (int) $visitId)->fetchColumn();
                $hopSecs = $fromAt ? max(0, strtotime($arrivedAt) - strtotime((string) $fromAt)) : null;

                $pdo->prepare('UPDATE visits SET hop_straight_km = ?, hop_road_km = ?, hop_seconds = ? WHERE id = ?')
                    ->execute([$straight, $roadKm, $hopSecs, $visitId]);
            }
        } catch (Throwable $hopErr) {
            error_log('visit hop compute failed for visit ' . $visitId . ': ' . $hopErr->getMessage());
        }

        $pdo->prepare(
            "INSERT INTO photos
               (photo_kind, visit_id, employee_id, work_date, taken_at,
                lat, lng, stored_path, original_name, mime, bytes, width, height,
                sha256, captured_via)
             VALUES
               ('visit', :visit_id, :employee_id, :work_date, NOW(),
                :lat, :lng, :stored_path, :original_name, :mime, :bytes, :width, :height,
                :sha256, 'camera')"
        )->execute([
            ':visit_id'      => $visitId,
            ':employee_id'   => $me['id'],
            ':work_date'     => $today,
            ':lat'           => $d['lat'],
            ':lng'           => $d['lng'],
            ':stored_path'   => $upload['stored_path'],
            ':original_name' => $originalName,
            ':mime'          => $upload['mime'],
            ':bytes'         => $upload['bytes'],
            ':width'         => $upload['width'],
            ':height'        => $upload['height'],
            ':sha256'        => $upload['sha256'],
        ]);

        $pdo->commit();
        return ['ok' => true, 'visit_id' => $visitId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'errors' => ['_' => 'Could not save your visit. Please try again.']];
    }
}

/**
 * Mark the current open visit Done. Shared by the web endpoint and the API.
 *
 * @return array{ok:bool, error?:string, dwell_seconds?:int}
 */
function visit_complete_perform(PDO $pdo, array $me, string $today, int $visitId): array
{
    $att = function_exists('field_today_attendance')
        ? field_today_attendance($pdo, (int) $me['id'], $today) : null;
    if ($att === null) {
        return ['ok' => false, 'error' => 'You are not checked in today.'];
    }

    $open = visit_open_one($pdo, (int) $att['id']);
    if ($visitId <= 0 || $open === null || (int) $open['id'] !== $visitId) {
        return ['ok' => false, 'error' => 'That visit is not open, or is already marked Done.'];
    }

    $dwell = max(0, strtotime('now') - strtotime($open['arrived_at']));
    $pdo->prepare('UPDATE visits SET left_at = NOW(), dwell_seconds = ? WHERE id = ? AND employee_id = ?')
        ->execute([$dwell, $visitId, $me['id']]);

    return ['ok' => true, 'dwell_seconds' => $dwell];
}
