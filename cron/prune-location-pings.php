<?php
/**
 * cron/prune-location-pings.php - delete old GPS pings so location_pings
 * never grows without bound.
 *
 * Keeps the last 90 days (enough to review any recent day's real route);
 * older rows are display history no one looks at. Deleting them changes NO
 * audit figure - the KM / time totals were frozen into `attendance` at
 * check-out and never re-read the pings.
 *
 * RUN: from cron, daily. Two ways:
 *   CLI:  php /path/to/cron/prune-location-pings.php
 *   HTTP: https://yoursite/cron/prune-location-pings.php?key=<CRON_SECRET>
 *         (only if CRON_SECRET is defined in secure_config.php)
 *
 * Safe to run repeatedly / more than once a day.
 */

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

require dirname(__DIR__) . '/config/config.php'; // -> constants
require dirname(__DIR__) . '/includes/db.php';   // -> $pdo

if (!$isCli) {
    // Web trigger allowed only with the shared secret.
    $key = (string) ($_GET['key'] ?? '');
    $secret = defined('CRON_SECRET') ? (string) CRON_SECRET : '';
    if ($secret === '' || !hash_equals($secret, $key)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$KEEP_DAYS = 90;

try {
    $stmt = $pdo->prepare(
        'DELETE FROM location_pings WHERE recorded_at < (NOW() - INTERVAL ? DAY)'
    );
    $stmt->execute([$KEEP_DAYS]);
    $deleted = $stmt->rowCount();
    echo "prune-location-pings: deleted {$deleted} row(s) older than {$KEEP_DAYS} days\n";
} catch (Throwable $e) {
    // location_pings not created yet (feature never deployed) - nothing to do.
    echo 'prune-location-pings: skipped (' . $e->getMessage() . ")\n";
}
