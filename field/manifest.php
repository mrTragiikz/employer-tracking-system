<?php
/**
 * field/manifest.php - the PWA Web App Manifest for the field app.
 *
 * A PHP file, not a static .webmanifest, because every URL in it
 * (start_url, scope, icons) must be correct for BOTH:
 *   - production:  https://prabinsharma.com/field/...
 *   - local dev:   https://<lan-ip>/try/field/...
 * and only APP_URL knows which. Served with the right content type below.
 *
 * Linked from the field pages as:  <link rel="manifest" href=".../field/manifest.php">
 */

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = rtrim(APP_URL, '/');           // e.g. https://prabinsharma.com  OR  https://1.2.3.4/try
$field = $base . '/field';

$manifest = [
    'name'             => 'Rajdoot Field',
    'short_name'       => 'Rajdoot',
    'description'      => 'Field visit attendance and tracking for Rajdoot field staff.',
    'start_url'        => $field . '/home/',
    'scope'            => $field . '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#faf6ee',
    'theme_color'      => '#6b4423',
    'lang'             => 'en',
    'dir'              => 'ltr',
    'icons'            => [
        ['src' => $field . '/icons/icon-192.png',     'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $field . '/icons/icon-256.png',     'sizes' => '256x256', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $field . '/icons/icon-384.png',     'sizes' => '384x384', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $field . '/icons/icon-512.png',     'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $field . '/icons/maskable-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => $field . '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
