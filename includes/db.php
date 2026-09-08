<?php
/**
 * PDO connection. Prepared statements everywhere, emulation OFF.
 * Exposes a single shared $pdo.
 */

declare(strict_types=1);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    // Match the app timezone for NOW()/CURRENT_TIMESTAMP in SQL.
    $pdo->exec("SET time_zone = '+05:45'");
} catch (PDOException $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    $showDetail = APP_ENV !== 'production' || defined('TRACK_DEBUG_REQUEST');
    http_response_code(500);
    if ($showDetail) {
        exit('DB connection failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }
    exit('Service unavailable.');
}
