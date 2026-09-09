<?php
/**
 * POST field/api-v2/visit-complete.php - mark the current open visit Done.
 *
 * Header: Authorization: Bearer <token>
 * Body (JSON or form): { "visit_id": 45 }
 *
 * 200: { "ok": true, "dwell_seconds": 1240 }
 * 4xx: { "ok": false, "error": "That visit is not open ..." }
 *
 * Same rule as the web (visit_complete_perform()): the visit must be this
 * employee's, part of today's attendance, and still open.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/api-v2/_boot.php';
require dirname(__DIR__) . '/visit/_repo.php';
require dirname(__DIR__) . '/checkinout/_repo.php'; // field_today_attendance()
api_method('POST');

$me = api_require();
$in = body();

$result = visit_complete_perform(
    $pdo, $me, server_today(), (int) ($in['visit_id'] ?? 0)
);

if (!$result['ok']) {
    json_error($result['error'] ?? 'Could not complete the visit.', 422);
}

json_out(['ok' => true, 'dwell_seconds' => (int) $result['dwell_seconds']]);
