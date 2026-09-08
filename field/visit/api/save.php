<?php
/**
 * field/visit/api/save.php - record a visit (the "Log a Visit" modal on
 * field/visit/index.php - the only place visits are logged from).
 *
 * POST (multipart):
 *   csrf_token
 *   shop_name, area_name                  hand-typed
 *   lat, lng, accuracy                    auto-captured by JS, never typed
 *   shop_photo                            live camera capture (required)
 *
 * Browser form endpoint (not a JSON api/), so it redirects rather than
 * returning JSON - same pattern as field/checkinout/api/checkin.php. Employee
 * must be checked in (and not checked out) today; rule 8 forbids a second
 * visit to the same shop name the same day; and an employee is only ever at
 * one shop at a time - a visit still open (not marked Done) blocks a new one.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/bootstrap.php';
$me = require_employee();
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 2) . '/checkinout/_repo.php';
require dirname(__DIR__, 3) . '/includes/upload.php';
require dirname(__DIR__, 3) . '/includes/distance.php';

$formUrl = APP_URL . '/field/visit/';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect($formUrl);
}
if (!csrf_check()) {
    $_SESSION['visit_form_error'] = ['_' => 'Your session expired. Please try again.'];
    redirect($formUrl);
}

$today      = server_today();
$attendance = field_today_attendance($pdo, (int) $me['id'], $today);

if ($attendance === null) {
    $_SESSION['visit_form_error'] = ['_' => 'Please check in first.'];
    redirect($formUrl);
}
if (!empty($attendance['check_out_at'])) {
    $_SESSION['visit_form_error'] = ['_' => 'Your day is already checked out.'];
    redirect($formUrl);
}
if (visit_open_one($pdo, (int) $attendance['id']) !== null) {
    $_SESSION['visit_form_error'] = ['_' => 'Mark your current visit Done before starting a new one.'];
    redirect($formUrl);
}

$deviceIdStmt = $pdo->prepare('SELECT device_id FROM users WHERE id = ?');
$deviceIdStmt->execute([$me['id']]);
$myDeviceId = $deviceIdStmt->fetchColumn() ?: null;

$check = visit_validate($_POST);

$noFile = ['error' => UPLOAD_ERR_NO_FILE];
$upload = save_camera_photo($_FILES['shop_photo'] ?? $noFile, 0);
if (!$upload['ok']) {
    $check['ok'] = false;
    $check['errors']['shop_photo'] = $upload['error'];
}

if ($check['ok']) {
    $shopNorm = visit_normalize($check['data']['shop_name']);
    $dupStmt = $pdo->prepare(
        'SELECT id FROM visits WHERE employee_id = ? AND shop_norm = ? AND work_date = ? LIMIT 1'
    );
    $dupStmt->execute([$me['id'], $shopNorm, $today]);
    if ($dupStmt->fetchColumn()) {
        $check['ok'] = false;
        $check['errors']['shop_name'] = 'You already logged a visit to this shop today.';
    }
}

if (!$check['ok']) {
    if (!empty($upload['stored_path'])) {
        delete_upload($upload['stored_path']);
    }
    $_SESSION['visit_form_error'] = $check['errors'];
    redirect($formUrl);
}

$d = $check['data'];
$shopNorm = visit_normalize($d['shop_name']);

$pdo->beginTransaction();
try {
    // Auto-learn the shop (first visit to this name creates it; later visits
    // to the same employee+name reuse it - see sql/database.sql `shops`).
    $shopStmt = $pdo->prepare(
        'SELECT id FROM shops WHERE employee_id = ? AND name_norm = ? LIMIT 1'
    );
    $shopStmt->execute([$me['id'], $shopNorm]);
    $shopId = $shopStmt->fetchColumn();

    if ($shopId) {
        $pdo->prepare(
            'UPDATE shops SET samples = samples + 1, visit_count = visit_count + 1 WHERE id = ?'
        )->execute([$shopId]);
        $shopId = (int) $shopId;
    } else {
        $pdo->prepare(
            'INSERT INTO shops (employee_id, name_display, name_norm, lat, lng, samples, visit_count)
             VALUES (?, ?, ?, ?, ?, 1, 1)'
        )->execute([$me['id'], $d['shop_name'], $shopNorm, $d['lat'], $d['lng']]);
        $shopId = (int) $pdo->lastInsertId();
    }

    $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM visits WHERE attendance_id = ?');
    $seqStmt->execute([(int) $attendance['id']]);
    $seq = (int) $seqStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "INSERT INTO visits
           (attendance_id, employee_id, shop_id, shop_name, area_name, shop_norm,
            work_date, seq, arrived_at, lat, lng, accuracy_m, device_id)
         VALUES
           (:attendance_id, :employee_id, :shop_id, :shop_name, :area_name, :shop_norm,
            :work_date, :seq, NOW(), :lat, :lng, :accuracy, :device_id)"
    );
    $stmt->execute([
        ':attendance_id' => (int) $attendance['id'],
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

    // Fill in THIS visit's own hop (distance/time from the previous point) now,
    // so an open day's Latest Visits / timeline shows a real figure instead of
    // 0. The previous point is the visit right before this one (by seq), or the
    // day's check-in if this is the first visit. checkout.php still recomputes
    // every hop authoritatively from compute_day() at day end - road_km_real()
    // caches the Directions lookup, so that recompute reuses this result.
    //
    // Wrapped so a slow/failed distance lookup can NEVER lose the visit
    // itself: the visit + photo are already inserted above, and a hop of 0
    // shown for a few minutes until checkout recomputes is far better than
    // the employee's visit failing to save at all. Any problem here just
    // leaves hop_road_km at its column default (0) - self-healed at checkout.
    try {
        $prevStmt = $pdo->prepare(
            'SELECT lat, lng, arrived_at FROM visits
              WHERE attendance_id = ? AND seq < ? ORDER BY seq DESC LIMIT 1'
        );
        $prevStmt->execute([(int) $attendance['id'], $seq]);
        $prev = $prevStmt->fetch();

        // "From" point: the previous visit, or the day's check-in for the
        // first visit. Guard against a missing/zero check-in fix (schema says
        // NOT NULL, but a 0.0 would poison the distance with a leg from Null
        // Island) - if there's genuinely no usable previous point, skip the
        // hop rather than store a garbage distance.
        $fromLat = $prev ? (float) $prev['lat'] : (float) ($attendance['check_in_lat'] ?? 0);
        $fromLng = $prev ? (float) $prev['lng'] : (float) ($attendance['check_in_lng'] ?? 0);
        $fromAt  = $prev ? $prev['arrived_at']  : ($attendance['check_in_at'] ?? null);

        $hasFrom = ($fromLat !== 0.0 || $fromLng !== 0.0);
        $toLat   = (float) $d['lat'];
        $toLng   = (float) $d['lng'];

        if ($hasFrom && ($toLat !== 0.0 || $toLng !== 0.0)) {
            $straight = round(haversine_km($fromLat, $fromLng, $toLat, $toLng), 3);
            $real     = road_km_real($fromLat, $fromLng, $toLat, $toLng);
            // road_km_real() never returns a negative or non-numeric km, but
            // fall back to the straight-line figure if it somehow came back
            // as an unusable 0 for a hop that clearly covered real ground.
            $roadKm   = ($real['km'] > 0) ? $real['km'] : $straight;

            $arrivedAt = (string) $pdo->query(
                'SELECT arrived_at FROM visits WHERE id = ' . (int) $visitId
            )->fetchColumn();
            $hopSecs = $fromAt ? max(0, strtotime($arrivedAt) - strtotime((string) $fromAt)) : null;

            $pdo->prepare(
                'UPDATE visits SET hop_straight_km = ?, hop_road_km = ?, hop_seconds = ? WHERE id = ?'
            )->execute([$straight, $roadKm, $hopSecs, $visitId]);
        }
    } catch (Throwable $hopErr) {
        // Distance lookup / update failed - log and move on. The visit is
        // already saved; checkout.php's compute_day() will fill the real
        // per-hop figures for the whole day.
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
        ':original_name' => $_FILES['shop_photo']['name'] ?? null,
        ':mime'          => $upload['mime'],
        ':bytes'         => $upload['bytes'],
        ':width'         => $upload['width'],
        ':height'        => $upload['height'],
        ':sha256'        => $upload['sha256'],
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    delete_upload($upload['stored_path'] ?? null);
    $_SESSION['visit_form_error'] = ['_' => 'Could not save your visit. Please try again.'];
    redirect($formUrl);
}

redirect($formUrl . '?ok=visit');
