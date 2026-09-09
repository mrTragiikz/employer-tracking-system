# PROJECT STATE — read this first when resuming

_Last updated: 2026-09-10_

This one repo now contains **both halves** of a field-sales-rep visit-tracking
product:

| Path | What it is | Language |
|---|---|---|
| repo root (`admin/`, `field/`, `includes/`, `sql/`) | The web system — admin panel + web field pages | PHP 8 + MySQL, no framework, no build step |
| `mobile-app/` | The native Android app for field reps | Flutter / Dart |

`git log` shows the complete build history of both, in order. The Flutter app
was developed in its own repo first, then merged in with `git subtree` — so all
its commits (`9cb7197` … `8e487cf`, prefixed `B1`/`B3`/`Phase C`/etc.) are
preserved.

"Rajdoot" is the **working/demo identity**. This is intended as a reusable
product base — see "Adapting for a new client" below.

---

## WHERE IT'S DEPLOYED

| | |
|---|---|
| **Live web system** | `https://prabinsharma.com` (cPanel, DB `prabinsharma_track`, host server2.bisuphost.com) |
| **Live status** | Everything through commit `0c3029a` is live and working: the full system, "Left the Job", map road-line fix, employee-photo auto-shrink, **Announcements**. |
| **NOT on live yet** | The mobile JSON API (`field/api-v2/`) + **live location tracking** (commits `2dd773a` … `c8aff4c`). Built, tested locally, never deployed. |
| **Mobile app** | Never distributed. Debug + signed release APKs exist in `D:\projects-ship\rajdoot-field-app-SHIP-2026-09-09\APKs\`. |

---

## WHAT WORKS (feature list)

### Web (PHP) — live
- Admin: dashboard, employees, visits, attendance, reports, audit, alerts,
  evidence photos, announcements, settings, users/roles
- Web field pages (for iOS reps): login, home, check in/out, visits, routes,
  attendance, profile — GPS + camera via the browser
- Anti-fraud rules 1–11 (device binding, mock-location, duplicate-visit,
  impossible-speed, nightly incomplete-day sweep, …)
- Audit: KM + productive-time computed server-side in `includes/distance.php`
  `compute_day()` — check-in → visits → check-out, per-leg road distance via
  Mapbox Directions (cached), frozen into `attendance` at check-out

### Web (PHP) — built, NOT deployed
- **Mobile JSON API** (`field/api-v2/`) — stateless bearer-token auth, reuses
  the `field_remember_tokens` table. Read + write endpoints for every field
  screen. The heavy check-in/visit/checkout logic was refactored into shared
  `_repo.php` functions that BOTH the web form and the API call (one source
  of truth, regression-tested 33/33).
- **Live location tracking** — `location_pings` table, `field/api-v2/ping.php`
  ingest, `admin/…/live-track.php` read, a "Live Track" button + Mapbox modal
  on the employee page, real-GPS-trail overlay on the route maps. Kill switch
  `live_tracking_enabled` (Settings) — **OFF by default**. DISPLAY-ONLY: the
  audit path never reads `location_pings`.

### Mobile app (`mobile-app/`) — feature-complete, not shipped
- Login (phone + 4-digit PIN, stays logged in)
- Dashboard, Check In/Out (GPS + camera + odometer), My Visits (log + mark
  done), Routes, Attendance (day/month/all-time), Profile (+ change PIN)
- Announcement popup (polls every 8s)
- **Offline outbox** — check-in/visit/checkout queue locally when there's no
  signal, replay in order when back online (the real reason for going native)
- **Live tracking foreground service** — records the route between check-in
  and check-out, uploads batches to `ping.php`, server-controlled interval

---

## KEY FACTS / GOTCHAS (so you don't re-learn them)

### Backend
- **No framework, no Composer, no build step.** Plain PHP. Keep it that way.
- `includes/settings.php` has **NO re-include guard** — never
  `require` it a second time with a different path string (→ "Cannot
  redeclare setting()"). `bootstrap.php` already loads it.
- `includes/distance.php` HAS a guard (`TRACK_DISTANCE_LOADED`).
- `secure_config/secure_config.php` — git-ignored, holds ALL secrets (DB,
  APP_SECRET, Mapbox token). On live it may sit ABOVE public_html.
- **The live Mapbox token was corrupted once** (missing the leading
  `pk.eyJ1Ij`). Correct value:
  `pk.eyJ1IjoibXJwcmFiaW4iLCJhIjoiY210c3ByczF6MDFraDJ4cjFwYW5rZWpwNyJ9.Tzxypt8QJYCW2zKLagxmAw`
  A bad token blanks every map.
- Audit engine (`includes/distance.php`) has **not been edited since the
  initial commit**. Every KM/time figure comes only from check-in + visits +
  check-out. `location_pings` is never read by it.

### Mobile app
- Flutter SDK was `C:\src\flutter` (3.44.3 / Dart 3.12.2). JDK 17 at `C:\src\jdk-17`.
- deps: `dio`, `geolocator`, `image_picker`, `intl`, `uuid`,
  `shared_preferences` (NOT `flutter_secure_storage` — SDK conflict),
  `path_provider` (outbox), `flutter_foreground_task` 11.0.3 (tracking).
- `mobile-app/lib/core/config.dart` switches server via
  `--dart-define=RAJDOOT_ENV=prod`. Default = dev (`192.168.1.82/try` LAN).
- Release signing: `mobile-app/android/app/build.gradle.kts` reads
  `mobile-app/android/key.properties` (git-ignored). If absent → falls back
  to debug signing.
- **The keystore is NOT in this repo.** It's at
  `D:\projects-ship\rajdoot-field-app-SHIP-2026-09-09\keystore\rajdoot-release.jks`
  (alias `rajdoot`, pass `Rajd00t!Field2026`, SHA-256 `7c6ce99b…`).
  *** Losing it = every installed user must uninstall + reinstall. Back it
  up in 2+ safe places if the app is ever distributed. ***
- `flutter build apk` prints "Gradle build failed to produce an .apk file"
  even on success — IGNORE IT, the APK is at
  `mobile-app/android/app/build/outputs/flutter-apk/`.
- Clean rebuild if needed: `Remove-Item -Recurse -Force
  mobile-app/.dart_tool/flutter_build ; cd mobile-app/android ;
  .\gradlew.bat assembleDebug --rerun-tasks`

---

## TO RESUME WORK

### Web
1. XAMPP (Apache + MySQL). Project at `d:\xampp\htdocs\try`.
2. Local DB is `tryingg` (a full copy of the live export). Dev
   `secure_config.php`: `IS_LIVE=false`, `APP_URL=https://<LAN-ip>/try`.
3. Local logins: `prabin_dev` / `sashant` (admin) password `test1234`;
   employees phone `9714567335` / `9800000002`, PIN `1234`.

### Mobile app
```
cd mobile-app
flutter pub get
flutter build apk --debug          # LAN dev server
flutter build apk --release --dart-define=RAJDOOT_ENV=prod   # live (needs key.properties)
```

### Deploy the pending backend (mobile API + tracking) to live
See `D:\projects-ship\rajdoot-field-app-SHIP-2026-09-09\php-backend-pending\DEPLOY.txt`.
Short version:
1. Import `sql/migrations/2026-09-09_location-pings.sql` on the live DB.
   (The mobile API needs NO migration — reuses `field_remember_tokens`.)
2. Upload the changed files (`git diff --name-only 0c3029a..HEAD`), keeping
   paths, preserving live `uploads/` + `secure_config/`.
3. Turn tracking on in Admin > Settings when ready. Add
   `cron/prune-location-pings.php` to the host's daily cron.

---

## ADAPTING FOR A NEW CLIENT

This repo is the **template**. For a new company, clone it and customise:

| Change | Where |
|---|---|
| DB credentials, domain, Mapbox token, APP_SECRET | `secure_config/secure_config.php` (make a fresh one from the constants list) |
| App server URL | `mobile-app/lib/core/config.dart` — `_prodBase` |
| **App package id** (PERMANENT once installed) | `mobile-app/android/app/build.gradle.kts` — `applicationId` + `namespace`, currently `com.rajdoot.rajdoot_field` |
| App name | `mobile-app/android/app/src/main/AndroidManifest.xml` — `android:label` |
| Logo / colours | `assets/img/`, `admin/**/css/`, `mobile-app/lib/core/theme.dart` |
| **New keystore** — generate one PER client, never reuse Rajdoot's | `keytool -genkeypair …` then a client-specific `key.properties` |
| "Rajdoot" text in the UI | grep `Rajdoot` across `admin/`, `field/`, `mobile-app/lib/` |

Keep each client's `secure_config.php` + `key.properties` + keystore OUT of
git (they're already git-ignored). Store per-client secrets in a password
manager.

---

## FULL DETAIL

The `.claude` memory notes (copied into
`D:\projects-ship\rajdoot-field-app-SHIP-2026-09-09\memory-notes\`) have the
blow-by-blow of every phase, decision, and bug:
- `flutter-app-project.md` — the app, phase by phase
- `live-tracking.md` — the tracking feature in full
- `audit-mechanism.md` — exactly how KM/time is computed
- `announcements-feature.md`, `mapbox-token-corruption.md`,
  `mobile-app-project-roadmap.md`, `MEMORY.md`

GitHub: `github.com/mrTragiikz/employer-tracking-system` (the PHP repo; after
this commit it also has `mobile-app/`).
