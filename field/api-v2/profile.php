<?php
/**
 * GET field/api-v2/profile.php - the signed-in employee's full profile (bio).
 *
 * Header: Authorization: Bearer <token>
 *
 * 200:
 *   { "ok": true,
 *     "profile": {
 *       id, name, phone, email, code, is_active,
 *       area, region, address,
 *       dob, gender, vehicle_type,
 *       document_type, document_label, id_number,
 *       emergency_name, emergency_contact,
 *       photo_url, id_photo_front_url, id_photo_back_url,
 *       joined_on, last_login_at
 *     } }
 *
 * Read-only. The employee cannot edit these fields (only an admin can, on
 * the admin side) - same rule as the web field/profile/ page. Reuses the
 * SAME profile_find() the web page uses.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/api-v2/_shape.php';
require dirname(__DIR__) . '/profile/_repo.php'; // profile_find(), profile_document_label()
api_method('GET');

$me = api_require();

$p = profile_find($pdo, (int) $me['id']);
if ($p === null) {
    json_error('Profile not found.', 404);
}

$area   = trim((string) ($p['area'] ?? ''));
$region = trim((string) ($p['region'] ?? ''));
$areaRegion = $area !== '' && $region !== '' ? "$area / $region" : ($area !== '' ? $area : $region);

json_out([
    'ok' => true,
    'profile' => [
        'id'                => (int) $p['id'],
        'name'              => $p['name'],
        'phone'             => $p['phone'],
        'email'             => $p['email'] ?: null,
        'code'              => $p['code'] ?: null,
        'is_active'         => (int) $p['is_active'] === 1,
        'area'              => $area ?: null,
        'region'            => $region ?: null,
        'area_region'       => $areaRegion ?: null,
        'address'           => $p['address'] ?: null,
        'dob'               => $p['dob'] ?: null,          // YYYY-MM-DD
        'gender'            => $p['gender'] ?: null,
        'vehicle_type'      => $p['vehicle_type'] ?: null,
        'document_type'     => $p['document_type'] ?: null,
        'document_label'    => $p['document_type'] ? profile_document_label($p['document_type']) : null,
        'id_number'         => $p['id_number'] ?: null,
        'emergency_name'    => $p['emergency_name'] ?: null,
        'emergency_contact' => $p['emergency_contact'] ?: null,
        'photo_url'         => api_photo_url($p['photo_path'] ?? null),
        'id_photo_front_url'=> api_photo_url($p['id_photo_front'] ?? null),
        'id_photo_back_url' => api_photo_url($p['id_photo_back'] ?? null),
        'joined_on'         => $p['created_at'] ? substr((string) $p['created_at'], 0, 10) : null,
        'last_login_at'     => api_iso($p['last_login_at'] ?? null),
    ],
]);
