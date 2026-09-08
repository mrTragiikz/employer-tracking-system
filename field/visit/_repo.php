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
