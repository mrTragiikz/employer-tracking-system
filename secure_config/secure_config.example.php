<?php
/**
 * secure_config.example.php
 * -----------------------------------------------------------------------------
 * COPY THIS FILE to  secure_config/secure_config.php  and fill in real values.
 * The real file is git-ignored and must never be committed.
 *
 * It holds every connection detail and secret the app uses, so that nothing
 * sensitive lives in config/config.php (which sits in the public web folder).
 *
 * The folder ships as a sibling of config/. On a live server, drag the whole
 * secure_config/ folder to ONE LEVEL ABOVE public_html — config.php looks
 * there too, so the file becomes physically unreachable over HTTP.
 *
 * DEV vs PRODUCTION: flip the single IS_LIVE line below. Never hand-comment
 * individual define() lines — a half-switched config has caused real breakage.
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

// ============================================================================
// THE ONLY LINE TO CHANGE WHEN SWITCHING ENVIRONMENTS:
//   false = local machine (XAMPP / local database)
//   true  = the live server
// ============================================================================
const IS_LIVE = false;

if (IS_LIVE) {
    // ----- PRODUCTION -----
    define('APP_ENV', 'production');

    // Must match how the site is actually opened in the browser (scheme + host),
    // no trailing slash. A www / non-www mismatch here breaks cache-busting and
    // can cause redirect loops.
    define('APP_URL', 'https://your-domain.example');

    define('DB_HOST', 'localhost');        // cPanel MySQL is almost always 'localhost'
    define('DB_PORT', '3306');
    define('DB_NAME', 'account_track');    // the real (prefixed) database name
    define('DB_USER', 'account_dbuser');
    define('DB_PASS', 'CHANGE-ME');        // the real DB password — never commit it

    define('COOKIE_SECURE', true);         // true when the site is HTTPS

    // A fresh, unique 64-hex-char secret. Generate with:
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    // Rotating it invalidates every session + CSRF token at once (users just
    // log in again). MUST NOT be the same value as the dev secret below.
    define('APP_SECRET', 'CHANGE-ME-64-hex-chars');

} else {
    // ----- DEV (local machine) -----
    define('APP_ENV', 'development');

    // http://localhost/track works for developing on this PC itself.
    // To test GPS + camera from a real phone on the same Wi-Fi, use this PC's
    // LAN IP with https:// (a self-signed cert is fine) and set COOKIE_SECURE
    // = true — browsers require a secure origin for geolocation and camera.
    define('APP_URL', 'http://localhost/track');

    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', '3306');
    define('DB_NAME', 'track');
    define('DB_USER', 'root');
    define('DB_PASS', '');                 // default XAMPP root has no password

    define('COOKIE_SECURE', false);

    // Dev's own secret — MUST differ from the production APP_SECRET above.
    define('APP_SECRET', 'CHANGE-ME-a-different-64-hex-value');
}

define('DB_CHARSET', 'utf8mb4');

// ---- Timezone: server time only (Anti-Fraud rule 4) ----------------------
define('APP_TZ', 'Asia/Kathmandu');

// ---- App / session -----------------------------------------------------
define('APP_NAME', 'Rajdoot');
define('SESSION_NAME', 'tracksid');
define('SESSION_LIFETIME', 60 * 60 * 8);  // 8 hours — one working day
define('CSRF_TOKEN_TTL', 60 * 60 * 8);

// ---- Employee login throttle -----------------------------------------
// A field worker fat-fingering a 4-digit PIN shouldn't lock as fast as an
// admin. 12 tries (vs the admin's 5) before a 15-minute lock.
define('EMPLOYEE_MAX_FAILED_LOGINS', 12);

// ---- Mapbox (admin route maps only — never loaded in the field app) -----
// PUBLIC token only (starts "pk.", never "sk."). Create one at
// mapbox.com > Tokens and add a URL restriction scoped to APP_URL.
define('MAPBOX_ACCESS_TOKEN', 'pk.your-public-mapbox-token');

// ---- Uploads --------------------------------------------------------
// UPLOAD_DIR is built from TRACK_ROOT (from config.php), NOT this file's
// __DIR__ — this file may sit outside the project on a live server while
// uploads/ stays inside it.
define('UPLOAD_DIR', TRACK_ROOT . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');
define('UPLOAD_MAX_BYTES', 6 * 1024 * 1024);   // 6 MB — a raw live-camera shot
define('UPLOAD_ALLOWED_MIME', 'image/jpeg,image/png,image/webp');

// Tighter cap for admin-uploaded profile / ID-card photos (headshots, scans).
define('EMPLOYEE_PHOTO_MAX_BYTES', 180 * 1024);

// ---- Distance / fraud tuning (DEFAULTS; live values come from `settings`) --
define('DEFAULT_ROAD_FACTOR', 1.30);       // straight-line km * this ≈ road km
define('IMPOSSIBLE_SPEED_KMH', 120);       // rule 6
define('BULK_ENTRY_GAP_SECONDS', 120);     // rule 7
define('SAME_LOCATION_TOLERANCE_M', 15);   // rule 5

// ---- Locale --------------------------------------------------------
define('APP_LOCALE', 'ne');

// ---- DEV ONLY — must stay absent on any real deployment ----------------
// Both are hard-gated to APP_ENV === 'development' in includes/bootstrap.php,
// but leave them commented anyway.
//
// Skip the sign-in screen while developing:
//   define('DEV_AUTOLOGIN', 'admin');   // 'admin' | 'employee'
//
// Allow multiple devices on one employee login while testing:
//   define('DEV_SKIP_DEVICE_LOCK', true);
