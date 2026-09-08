<h1 align="center">Rajdoot — Field Visit Attendance &amp; Tracking</h1>

<p align="center">
  A production web application that gives a company an honest, verifiable picture
  of what its field sales staff actually did each day — where they went, which
  shops they visited, how long they spent, how far they rode, and whether any of
  it looks faked.
</p>

<p align="center">
  <strong>Live &amp; in daily use.</strong> Built as a single, dependency-free
  PHP + MySQL codebase — no framework, no build step, no third-party packages.
</p>

---

## Table of contents

- [What problem it solves](#what-problem-it-solves)
- [How it works, in one minute](#how-it-works-in-one-minute)
- [Feature list](#feature-list)
  - [Field app (the employee's phone)](#field-app-the-employees-phone)
  - [Admin panel (the manager's desktop)](#admin-panel-the-managers-desktop)
  - [Anti-fraud engine](#anti-fraud-engine)
  - [Reporting &amp; evidence](#reporting--evidence)
- [Security](#security)
- [Architecture](#architecture)
- [Tech stack &amp; why](#tech-stack--why)
- [Data model](#data-model)
- [Installation](#installation)
- [Deployment &amp; hardening](#deployment--hardening)
- [Project structure](#project-structure)
- [About this project](#about-this-project)

---

## What problem it solves

A distributor employs field salespeople. Each one rides a motorbike around a
territory, visiting hardware shops, taking stock, building relationships. The
company pays them for **distance covered** and **shops visited** — but has no way
to know if the numbers on a paper log are real.

Rajdoot replaces the paper log. The salesperson carries their phone. The app:

- records a **GPS-stamped, photo-backed check-in** at the start of the day, with the bike's odometer reading
- records a **GPS-stamped, photo-backed visit** at every shop, with the shop name they type
- records a **GPS-stamped, photo-backed check-out** at the end, with the closing odometer
- computes the **real road distance** of the route (not straight-line) and the
  **productive time** (time inside shops vs. time riding)
- runs every entry through an **anti-fraud engine** and quietly alerts the
  manager to anything suspicious

The manager opens a desktop panel and sees the whole day laid out — a map of the
route, a timeline of every stop, the distance to pay on, and any red flags —
plus one-click **PDF reports** clean enough to hand to a client as proof of work.

---

## How it works, in one minute

**The employee's day**

1. Opens the app, signs in with **phone number + 4-digit PIN**. The login is
   permanently bound to that one phone — a second device is refused.
2. **Checks in**: the app captures GPS, opens the camera for an odometer photo,
   and asks for the bike's KM reading. The working day starts.
3. At each shop, taps **"I'm here"**: types the shop name, the app captures GPS
   and a live photo. The visit is logged with the exact arrival time.
4. Leaves the shop — the app records the departure time, so "time at this shop"
   is measured, not guessed.
5. **Checks out**: GPS, a closing odometer photo, the closing KM. The server then
   computes the whole day — every road leg, every dwell, the 3-way time split.

**The manager's view**

- A **dashboard** with today's headline numbers and a live "who's checked in" list
- **Employee** profiles, each with a full attendance history and a day-by-day drill-down
- Every **visit** ever logged, filterable, with the photo and the drive distance from the previous stop
- A **Routes &amp; Map** page: one employee, one day, the route drawn on a real map that **follows the roads**, with a stop-by-stop timeline
- An **Alerts** feed: check-ins, visits, photos and check-outs streaming in, with fraud flags surfaced inline
- **PDF exports** everywhere — a single day, a whole month, a filtered visit list, or a full route report with an embedded map

---

## Feature list

### Field app (the employee's phone)

| Feature | Detail |
|---|---|
| **Phone + PIN login** | No email, no password to remember. 4-digit PIN, `password_hash()`-stored. |
| **One-device lock** | The first phone to sign in is bound to the account. Any other phone is refused. An admin can reset the binding (e.g. lost phone) — which also kills the old session instantly. |
| **Mandatory check-in** | GPS position + accuracy, a **live camera** odometer photo, and a typed KM reading. No check-in, no day. |
| **Shop visits** | Type the shop name, capture GPS + a live photo. Arrival time is server-stamped. One visit per shop per day is enforced. |
| **Measured dwell time** | The app records when you *leave* a shop, so "23 minutes at Sunita Hardware" is a real measurement. |
| **Mandatory check-out** | GPS, a live camera odometer photo, and the closing KM (must be ≥ the check-in reading). |
| **Browser-side photo compression** | Camera shots are compressed to ~70 KB in the browser before upload — fast even on a weak rural connection, no server-side image library needed. |
| **Suspended-account screen** | If an admin locks the account mid-day, the phone shows a "you've been suspended" screen that **polls the server and auto-reopens the login the moment the admin unlocks it** — no manual refresh. |
| **My routes / my visits / my profile** | The employee can review their own day and change their own PIN. |
| **Nepali-language labels** | The field UI ships with Nepali strings. |

### Admin panel (the manager's desktop)

| Section | What it does |
|---|---|
| **Dashboard** | Headline counts (employees, checked-in today, visits today), a "Today's Route" map widget, latest activity, top employees by visits. |
| **Employees** | Full CRUD. Each employee has a profile photo, ID-card front/back photos, vehicle type, region/area, emergency contact. Per-employee: attendance calendar, an **Overview** tab with the day's route + timeline, a **Visits** history, and a day-by-day drill-down showing the full timeline (check-in → each shop → check-out) with photos, GPS, and per-leg distance. Admin actions: reset PIN, reset device binding, lock/unlock. |
| **Visits** | Every "I'm here" across all employees, newest first. Filter by employee, month, or search. Five headline stats. Each row: shop, time at shop, drive distance from the previous stop, GPS, photo. |
| **Attendance** | Monthly stats, a calendar heat-view, a present-rate donut, a 14-day trend, and a daily table. Open days show "route so far"; closed days show the audited final numbers. |
| **Routes &amp; Map** | One employee, one day. The route line **follows the actual roads** (via the Mapbox Directions API), with direction arrows and a two-lane offset so an out-and-back on the same road reads as two separate lines. A stop-by-stop timeline sits beside the map. |
| **Alerts** | A live activity feed (auto-refreshing) of check-ins, visits, photos and check-outs. Fraud flags appear inline. This is the **only** place suspicion is shown — there is deliberately no "flagged" badge cluttering the visit rows. |
| **Evidence Photos** | A gallery of every captured photo — grid, lightbox, filters, CSV export. Three kinds only: check-in, visit, check-out. |
| **Settings** | A small, typed set of policy rows: the road-distance factor, the impossible-speed threshold, the bulk-entry gap, the same-location tolerance, the workday-end hour, and an optional check-in time window. |
| **Users &amp; Roles** | Manages **admin** accounts. One protected Super Admin (cannot be deleted, can only edit its own name/password) plus regular Admins. A full Super-Admin-vs-Normal-Admin permission model: Users &amp; Settings are hidden from and server-guarded against Normal Admins. |

### Anti-fraud engine

Every entry is checked server-side. Nine rules are active. Each is one small,
testable function in [`includes/fraud.php`](includes/fraud.php).

| # | Rule | Action |
|---|---|---|
| **3** | Photos must be **live camera captures**, not gallery picks | Enforced by the `capture` attribute + a server-side MIME check |
| **4** | **Server time only.** The phone's clock is stored for comparison but **never** used to compute a duration or make a decision | Structural — there is no client timestamp in any calculation |
| **5** | The **same GPS point used for two different shops** | Records a flag + raises an admin alert |
| **6** | **Impossible travel speed** between two consecutive points | Records a flag + raises an admin alert |
| **7** | **Bulk entry** — several visits logged within seconds of each other | Records a flag + raises an admin alert |
| **8** | **Duplicate visit** to the same shop on the same day | Blocked outright (also enforced by a `UNIQUE` DB constraint) |
| **9** | **Mock-location** (GPS spoofing app) detected | Blocked + flag + alert |
| **10** | Login **bound to one device** | A second device is refused at login |
| **11** | **No check-out by end of day** | A nightly sweep marks the day `incomplete` |

The distance and time figures the manager pays on are **always computed on the
server** from the recorded GPS points — a distance value sent by the phone is
never trusted or stored as authoritative.

### Reporting &amp; evidence

- **Employee Audit PDF** — one day: attendance summary, the odometer readings,
  a visit-by-visit timeline with per-leg distance, the day's productive distance
  and working hours, and a route map. Or one month: a day-by-day table with
  working / shop / road time columns and period totals.
- **Route Report PDF** — a client-ready, single-page report for one employee's
  day: brown letterhead, an **embedded real map** of the route, five headline
  cards, and a full stop-by-stop table. Designed to be handed to a client as
  proof of a day's fieldwork.
- **Visits Report PDF** — a landscape report of the filtered visit list, with a
  per-employee breakdown and the full visit table.
- **Attendance Report PDF** — the filtered attendance view.
- **CSV** export on every list, for spreadsheets.

The PDF engine is **hand-written** ([`includes/pdf.php`](includes/pdf.php)) — no
Composer library, no server extension. It draws text, lines, rectangles, rounded
rectangles, circles (Bézier arcs), and embeds PNG images, which is enough for a
polished, branded report.

---

## Security

Security was a first-class requirement, not an afterthought. What is in place:

### Authentication &amp; sessions

- Passwords (admin) **and** PINs (employee) stored with `password_hash()` /
  verified with `password_verify()` — never plaintext, never a fast hash.
- `session_regenerate_id(true)` on every login — defeats session fixation.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` in production.
- **Login throttling**: a blunt IP + identifier spam guard (blocks a script
  hammering many unknown accounts from one IP) sitting *above* the per-account
  lock, so it never pre-empts the real "account locked" message.
- **Account lockout**: 5 wrong admin passwords → 15-minute lock; 12 wrong
  employee PINs → 15-minute lock (a field worker fat-fingering a PIN shouldn't
  lock as fast as an admin).
- **Live re-validation for the field app**: `require_employee()` re-checks the
  account against the database on *every* request — so an admin locking an
  account, resetting its PIN, or resetting its device binding **kills the
  employee's current session immediately**, not "next time they log in".

### Injection &amp; output

- **100% PDO prepared statements**, with `ATTR_EMULATE_PREPARES = false` — real
  server-side parameter binding, no string interpolation into SQL anywhere.
- All output escaped through a single `e()` = `htmlspecialchars()` helper.
- **CSRF protection**: a per-session token, sent by forms as a hidden field and
  by `fetch()` as an `X-CSRF-Token` header. Every state-changing request
  (`POST` / `PUT` / `PATCH` / `DELETE`) is rejected without it — returned as a
  standard `403`.

### File uploads

- The **real MIME type is verified** with `finfo` (falling back to
  `getimagesize()` on hosts where the extension is disabled) — the
  client-declared type is ignored.
- Every uploaded file is **renamed to a random name**, with the extension
  derived from the *verified* MIME.
- A hard **size cap** is enforced.
- `uploads/.htaccess` **blocks PHP execution** in the upload directory — a file
  that somehow gets a `.php` name still can't run.

### Access control

- Every endpoint's first two lines are `require .../api.php` then
  `require_admin()` / `require_employee()` — auth is not optional and not
  per-page-remembered.
- A **two-tier admin model**: the Super Admin flag is a column, not a role
  value. Normal Admins have Users &amp; Settings **removed from the UI *and*
  server-guarded** — a hand-crafted request to those endpoints returns `403`.

### Configuration &amp; secrets

- **Every secret** (DB credentials, `APP_SECRET`, `APP_URL`) lives in
  `secure_config/secure_config.php`, which is **git-ignored** and deployed by
  hand.
- That folder ships with its own `.htaccess` denying web access, and on a live
  server is designed to be **moved entirely outside `public_html`** so it is
  physically unreachable over HTTP regardless of server config.
- A single `IS_LIVE` switch flips the whole config between the local and
  production value sets — no line-by-line commenting that can end up
  half-and-half.
- In production: `display_errors = 0`, errors logged to a file, and a
  `RewriteRule` / `.htaccess` that never exposes the framework.

### Error handling

- Non-standard HTTP codes that PHP's `http_response_code()` doesn't recognise
  (and silently turns into a 500) are avoided — CSRF failures return a real
  `403`, not a Laravel-ism.
- A missing or unwritable log directory can **never itself become the cause of a
  500** — it falls back to the host's default error log.

---

## Architecture

```
                       ┌─────────────────────────┐
   Employee's phone    │      FIELD APP          │   phone + PIN, one device
   (mobile browser)  ──▶  field/  (big-button    │   GPS + live camera + fetch()
                       │   screens, Nepali)      │
                       └───────────┬─────────────┘
                                   │
                       ┌───────────▼─────────────┐
                       │   SHARED CORE            │   includes/
                       │  bootstrap · auth · CSRF │   - session, guards, tokens
                       │  distance · fraud        │   - road-distance + 9 rules
                       │  upload · pdf · settings │   - MIME-checked uploads,
                       └───────────┬─────────────┘     hand-written PDF engine
                                   │
                       ┌───────────▼─────────────┐
   Manager's desktop   │      ADMIN PANEL         │   username + password
   (desktop browser) ──▶  admin/  (11 sections,   │   two-tier permissions
                       │   shared chrome)        │   server-rendered pages
                       └───────────┬─────────────┘
                                   │
                       ┌───────────▼─────────────┐
                       │       MySQL / MariaDB    │   14 tables, foreign keys,
                       │  sql/database.sql        │   cascade rules, unique
                       └─────────────────────────┘   constraints enforce the rules
                                   ▲
                       ┌───────────┴─────────────┐
                       │   Mapbox APIs           │   Directions (road-following
                       │  (admin side only)      │   route line) + Static Images
                       └─────────────────────────┘   (route map in PDFs)
```

**Design decisions that matter:**

- **Server-rendered, not a SPA.** The admin panel is plain PHP pages. Fast,
  crawlable, debuggable, no build pipeline. The field app is JS-driven only
  where it must be (GPS, camera, background `fetch`).
- **The database enforces the rules.** "One visit per shop per day" is a
  `UNIQUE` constraint, not just an `if`. "Delete an employee" cascades cleanly
  through attendance → visits → photos via foreign keys.
- **One source of truth for the schema.** [`sql/database.sql`](sql/database.sql)
  is the complete, final structure. Every schema change made during development
  was folded directly into it — there is no migrations folder to reconcile.
- **No shared stylesheet.** Every admin section and every shared component owns
  its full CSS. A change to one section's table styling cannot break another's.
- **Business logic is isolated.** The distance math, the fraud rules, the auth
  guards, the PDF engine — each is a self-contained file in `includes/` with a
  single responsibility. A future JSON API for a native mobile app would call
  the same functions.

---

## Tech stack &amp; why

| Layer | Choice | Why |
|---|---|---|
| Language | **PHP 8.3** | Runs on the cheapest shared hosting in the region; every hosting company supports it; it will still run in ten years. |
| Database | **MySQL / MariaDB** | Same reason. `DECIMAL` for money and distance — never floats. |
| Dependencies | **None** | No Composer, no npm, no framework. The whole app is `git clone` + import one `.sql` + edit one config file. Nothing to `npm audit`, nothing that breaks when a package publishes a major version. |
| PDF | **Hand-written** (`includes/pdf.php`) | A ~500-line class that writes the PDF byte stream directly. No `mpdf`/`dompdf`, no `imagick`/`gd` requirement. |
| Maps | **Mapbox** (admin side only) | Directions API for the road-following route line; Static Images API for the map embedded in PDF reports. Never loaded in the field app. |
| Frontend | **Vanilla JS + CSS** | No React, no jQuery-as-a-crutch. Small, per-section scripts. |

This is a deliberate choice for **operational simplicity and longevity**, not a
limitation. The trade-off — building things like the PDF engine by hand instead
of pulling a library — was made with eyes open.

---

## Data model

14 tables. The core flow:

```
users ──┬─▶ attendance ──┬─▶ visits ──▶ photos
        │   (one row per  │   (one per     (checkin / visit / checkout;
        │    employee-day) │    shop stop)   MIME-verified, random-named)
        │                  └─▶ route_hops
        │                      (per-leg breakdown: checkin→shop1→…→checkout)
        │
        ├─▶ shops            (auto-learned: created the first time an
        │                     employee types a shop name; GPS is captured
        │                     then and nudged toward a running average)
        │
        ├─▶ field_devices    (device-binding history — rule 10 audit trail)
        ├─▶ auth_events      (login / logout / device-bind / lockout)
        └─▶ fraud_flags ──▶ alerts

settings              (typed policy rows, admin-editable)
login_attempts        (brute-force throttle)
road_distance_cache   (Mapbox Directions results, cached by coordinate pair)
audit_log             (generic admin action trail, before/after JSON)
```

Rules baked into the schema, not just the code:

| Rule | Enforced by |
|---|---|
| One attendance row per employee per day | `UNIQUE (employee_id, work_date)` |
| One visit per shop per day | `UNIQUE (employee_id, shop_norm, work_date)` |
| A photo belongs to *either* an attendance row *or* a visit, never both | `CHECK` constraint |
| Deleting an employee removes all their data | `ON DELETE CASCADE` chains |
| A deleted shop doesn't orphan its visits | `ON DELETE SET NULL` |

---

## Installation

### Requirements

- PHP 8.1+ (developed on 8.3), with PDO-MySQL. `fileinfo` recommended but not required.
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (or nginx with equivalent rules)

### Local (XAMPP)

```bash
# 1. Put the code where the web server can see it
git clone https://github.com/mrTragiikz/employer-tracking-system.git track

# 2. Create the database and import the schema
#    (the SQL file does NOT run CREATE DATABASE — that fails on shared hosting)
mysql -u root -e "CREATE DATABASE track CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root track < sql/database.sql

# 3. Create secure_config/secure_config.php
#    A small PHP file of define() constants: DB_HOST / DB_NAME / DB_USER /
#    DB_PASS, APP_URL, APP_SECRET (a long random string), COOKIE_SECURE,
#    APP_ENV, APP_TZ, MAPBOX_ACCESS_TOKEN, plus the session / upload / fraud
#    tuning constants. It is git-ignored — it holds every real secret so
#    nothing sensitive lives in the public config/ folder.

# 4. Make uploads/ and logs/ writable by the web server

# 5. Open http://localhost/track/admin/
#    default login:  prabin_dev  /  12345   (change it immediately)
```

> `config/config.php` requires `secure_config/secure_config.php` unconditionally
> — the app will not boot without it. It looks for the folder as a sibling of
> `config/`, and (for production) also one level above the project root, so the
> whole `secure_config/` folder can be dragged outside `public_html`.

The field app is at `http://localhost/track/field/`. To test GPS and the camera
from a real phone on the same Wi-Fi, set `APP_URL` to the machine's LAN IP with
`https://` (a self-signed cert is fine) and `COOKIE_SECURE = true` — browsers
require a secure origin for geolocation and camera access.

### Shared hosting (cPanel)

1. Create the database in **MySQL Databases** (the real name is prefixed, e.g.
   `account_track`); add your DB user to it with all privileges.
2. Open it in phpMyAdmin, select it, **Import** `sql/database.sql`.
3. Upload the code into `public_html/` (or a subfolder).
4. Create `secure_config/secure_config.php` with the prefixed DB name and set
   `IS_LIVE = true`.
5. Change the admin password on first login.

---

## Deployment &amp; hardening

For a production deployment:

1. **Move `secure_config/` outside `public_html`** — one level up. `config.php`
   already looks for it there. Now the DB password is physically unreachable
   over HTTP no matter how the server is configured.
2. Set `IS_LIVE = true` (activates the production config: real DB, `APP_ENV =
   production`, `COOKIE_SECURE = true`).
3. Confirm `DEV_AUTOLOGIN` and `DEV_SKIP_DEVICE_LOCK` are **absent** from the
   config (they are hard-gated to `APP_ENV = development` anyway, but leave
   nothing to chance).
4. Remove the `?__debug=<APP_SECRET>` diagnostic block from
   `includes/bootstrap.php` once the site is confirmed working.
5. Ensure the host's daily backup covers **both** the database **and** the
   `uploads/` folder (the photos are not in the DB dump).
6. Set the nightly **incomplete-day sweep** (`sweep_incomplete_days()`) to run
   from cron.

---

## Project structure

```
config/            config.php — requires secure_config, exposes constants
secure_config/     secure_config.php (git-ignored) + a deny-all .htaccess
includes/          the shared core — not web-served
  bootstrap.php      loaded by every entry point: tz, errors, session, $pdo
  api.php            endpoint guard: bootstrap + JSON errors + auth + CSRF
  db.php             PDO, prepared statements, emulation OFF
  auth.php           login, session guards, throttle, lockout, live re-check
  csrf.php           per-session token, csrf_require()
  fraud.php          the 9 active anti-fraud rules, one function each
  distance.php       haversine, road factor, Mapbox road distance, day compute
  upload.php         verified-MIME photo pipeline
  pdf.php            hand-written PDF engine (text/line/rect/roundRect/circle/image)
  settings.php       typed accessors over the settings table
  static_map.php     Mapbox Static Images — route map for PDFs
  helpers.php        e(), server_now(), json output, i18n
lang/              ne.php — Nepali labels for the field app
sql/
  database.sql       complete schema + settings defaults + one seeded admin

admin/             desktop panel — server-rendered pages
  login/             standalone sign-in (no chrome)
  components/         header / sidebar / footer / confirm-modal / mapbox
                     (each self-contained: .php + css/ + js/)
  01-dashboard/  02-employees/  03-visits/  04-attendance/  05-routes-map/
  06-reports/    07-alerts/     08-evidence-photos/  10-settings/  11-users-roles/
                     each section: <name>.php + api/ + css/<name>.css + js/<name>.js

field/             mobile app — big-button screens, JS-driven
  login/  home/  checkinout/  visit/  routes/  attendance/  profile/  logout/
  components/         header / footer / photo-compress.js

cron/              nightly sweeps (incomplete-day, digests) — CLI / secret only
uploads/           YYYY/MM/ — photos; PHP execution blocked by .htaccess
logs/              php-error.log in production
```

**Scale:** ~16,000 lines of PHP, ~9,000 lines of JS + CSS, zero dependencies.

---

## About this project

Rajdoot was built end to end as a single-developer project: the data model, the
anti-fraud logic, the server-rendered admin panel, the mobile field app, the
hand-written PDF engine, the Mapbox route rendering, the security model, and the
deployment.

It is currently **live and in daily use** by a distribution business in Nepal,
tracking real field staff on real routes.

The guiding principle throughout was **operational simplicity**: a business
owner on a $3/month hosting plan should be able to run this for a decade without
a build server, a package manager, or a subscription to anything. Every place
that principle cost extra effort — writing a PDF engine by hand rather than
pulling a library, enforcing rules in the schema rather than only in code — that
trade was made deliberately.

---

<p align="center"><sub>
  Rajdoot — <code>rajdoot</code> is Nepali for "envoy / messenger".
</sub></p>
