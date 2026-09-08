# Rajdoot - field visit attendance & tracking

Plain PHP + MySQL, runs on XAMPP. No framework. Admin panel is **desktop-only**.

An **employee** (the field salesperson) logs into the mobile app with phone + 4-digit
PIN, checks in for the day, taps **"I'm here"** at each shop - typing the shop
name, with a live photo and a GPS check - then ends the day. **Admins** use the
desktop panel to see employees, attendance, visits, **Today's Route** (the map
connects the visit points - it is **not** a live moving trail), and alerts.

**Model note:** the employee *is* the field person - there is no separate
"dealer" concept, and there are **no admin-assigned routes** - each employee
picks their own (check in -> shops they type -> check out). The admin's job is
to *see* the whole route and judge honesty from it. Shops are **not** a managed
list; the first time an employee types a shop name its GPS is auto-learned into
the `shops` table, purely so "distance from shop" can be shown as neutral context.

**Suspicious activity** (same GPS point for two shops, impossible travel speed,
bulk entry, mock GPS) is detected server-side, written to `fraud_flags` and
raised as an **alert**. The admin reviews all of it in the **Alerts** section -
there is no "flag" badge or highlight on any visit row, timeline or dashboard.

---

## Setup

1. **Put the code at** `d:\xampp\htdocs\track` (already there).

2. **Create the database, then import.** The SQL file does NOT create a
   database (a `CREATE DATABASE` in an import fails on shared hosting) - you
   create it first, select it, then import `sql/database.sql` into it.

   - **Local XAMPP:** in phpMyAdmin create a database named `track`, select
     it, Import `sql/database.sql`. Or from a shell:

     ```
     C:\xampp\mysql\bin\mysql -u root -e "CREATE DATABASE IF NOT EXISTS track CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
     C:\xampp\mysql\bin\mysql -u root track < sql\database.sql
     ```

   - **cPanel / shared hosting:** create the database in **MySQL Databases**
     (the real name is prefixed, e.g. `accountname_track`), add your DB user
     to it with all privileges, open it in phpMyAdmin, select it, Import
     `sql/database.sql`. Set the same prefixed name as `DB_NAME` in
     `secure_config/secure_config.php`.

3. **Admin login.** The SQL file seeds one working admin -
   username `prabin_dev`, password `12345`. Change it after first login and
   create further admins in the Users & Roles section.

4. **Secrets.** Every connection value (DB credentials, `APP_URL`,
   `APP_SECRET`, `COOKIE_SECURE`) lives in `secure_config/secure_config.php`,
   a folder that ships with the project, right next to `config/`.
   `config/config.php` requires it unconditionally - there is no fallback,
   so this file must exist. Edit it for your DB user/password and a long
   random `APP_SECRET`. For production also set `APP_ENV='production'` and
   `COOKIE_SECURE=true`. On a live server, once it's working, drag the
   `secure_config/` folder to one level above `public_html` (cPanel File
   Manager) so it can never be served over HTTP - see DEPLOY.md for the
   full checklist.

5. **Writable folders.** Ensure `uploads/` and `logs/` are writable by Apache.

6. Admin panel: <http://localhost/track/admin/> redirects to the sign-in page
   at <http://localhost/track/admin/login/>. Default credentials
   `prabin_dev` / `12345` (change after first login). To skip the sign-in
   screen while developing, add `define('DEV_AUTOLOGIN', 'admin');` to your
   `secure_config.php`.

---

## Layout

```
config/ config.php (+ .local override), locked down by .htaccess
includes/ shared library, not web-served
  bootstrap.php loaded by every entry point: tz, session, errors, $pdo
  api.php endpoint guard: bootstrap + JSON errors + auth + CSRF + api_method()
  db.php PDO, prepared statements, emulation OFF
  helpers.php e(), server_now(), json_out(), t() (i18n)
  csrf.php per-session token; csrf_require() on every write
  auth.php login/session, require_admin() / require_field(), throttle
  settings.php runtime settings from `settings` table, typed accessors
  distance.php haversine, road factor, per-day hops + 3-way time split
  fraud.php the 13 anti-fraud rules, one function each
  upload.php finfo MIME check, rename, size limit (live-camera photos)
lang/ne.php Nepali labels (field app)
sql/
  database.sql full schema + settings defaults + bootstrap admin (prabin_dev / 12345)

admin/
  components/ shared chrome, each self-contained
    header/ header.php css/header.css js/header.js
    sidebar/ sidebar.php css/sidebar.css js/sidebar.js (included BY header.php)
    footer/ footer.php css/footer.css js/footer.js
  index.php /track/admin/ -> dashboard (or login)
  login/ admin sign-in: index.php + api/authenticate.php + api/logout.php,
         own standalone layout (no sidebar/topbar), css/ js/

  # Section folders are NUMBERED so the file tree lists them in sidebar order.
  # slug ('employees') -> folder ('02-employees') mapping: includes/helpers.php::admin_url()
  01-dashboard/ index.php <- dashboard is the one section using index.php
  02-employees/ employee.php index.php <- 02..11: page is a NAMED file; index.php is a
  03-visits/ visit.php index.php 2-line shim (`require __DIR__.'/<name>.php';`)
  04-attendance/ attendance.php
  05-routes-map/ routes-map.php
  06-reports/ report.php
  07-alerts/ alert.php
  08-evidence-photos/ evidence-photos.php
  10-settings/ setting.php
  11-users-roles/ users-roles.php
  each section folder also has: api/ css/<name>.css js/<name>.js

field/ <section>/ - same shape (JS-driven: GPS, live camera, fetch)
  <section>.php api/*.php js/<section>.js css/<section>.css
sections: login home visit endday

cron/ nightly sweeps (rule 11 incomplete-day, digests) - CLI/secret only
uploads/ photos, YYYY/MM/. PHP execution blocked by .htaccess
logs/ php-error.log in production
```

**No shared stylesheet.** CSS lives only in component and section folders:
`admin/components/<c>/css/<c>.css` (header.css also carries the `:root` palette
tokens + `body.admin` base + `.admin-shell` frame, since it loads first) and
`admin/<NN-section>/css/<name>.css` (that page's own `.card` / `.table` / etc.).
A page sets `$sectionCss` before including `header.php` to get its own CSS linked.

### Section endpoint pattern

Every file under `admin/<s>/api/` or `field/<s>/api/` starts with exactly:

```php
require dirname(__DIR__, 3) . '/includes/api.php'; // root: api/ -> <section>/ -> admin|field/
$me = require_admin(); // or require_field()
api_method('POST'); // optional: 405 on wrong verb
```

Every admin section **page** (`admin/01-dashboard/index.php`, or the named file
`admin/02-employees/employee.php`, …). URL is always the directory:
`/track/admin/02-employees/` (the `index.php` shim requires the named file):

```php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$pageTitle = 'Employees';
$activeSection = 'employees'; // short slug -> highlights the sidebar item
require dirname(__DIR__) . '/components/header/header.php'; // opens html/body, includes sidebar, opens <main>
// ... page content (h1, cards, tables) ...
require dirname(__DIR__) . '/components/footer/footer.php'; // closes <main>/shell/body, loads component JS
```

Link to other sections with `admin_url('<slug>')` - never hard-code the numbered
folder. `header.php` links all three component stylesheets and pulls in
`sidebar.php` itself - a page never includes the sidebar directly. See
`admin/01-dashboard/index.php` and `admin/02-employees/employee.php` for worked stubs.

Field section pages are `field/<section>/index.php` (no shared chrome - big-button layout per screen):

```php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_field();
```

The CSRF token (`csrf_token()` / `csrf_field()`) is sent by forms as a hidden
input and by `fetch()` as the `X-CSRF-Token` header; `api.php` rejects any
POST/PUT/PATCH/DELETE without it.

## Build order (per spec)

1. ✅ `config/` + `includes/` + `sql/database.sql` ← **this step**
2. `admin/login/`
3. `admin/employees/` (need employee data before anything else works)
4. `admin/dashboard/` (4 stat cards + latest visits table first)
5. `field/login/` → `field/home/` → `field/visit/` → `field/endday/`
6. `admin/attendance/` → `admin/visits/`
7. routes, alerts, photos, settings, users

**Finish and test one complete working day end-to-end before building reports.**

---

## Key rules baked into the schema

| Concern | Where |
|---|---|
| Distances computed server-side only | `includes/distance.php`; phone-sent km is never stored as authoritative |
| Day total = sum of all hops | `route_hops` (checkin → shop 1 → … → checkout), cached on `attendance` |
| 3-way time split | `attendance.total_seconds` / `shop_seconds` / `road_seconds` |
| Server time only (Asia/Kathmandu) | `date_default_timezone_set()` + `SET time_zone='+05:45'`; phone clock kept only in `*_device_ts`, never used for a calculation (rule 4) |
| One device per employee login (rule 10) | `users.device_id` + `field_devices` history |
| Shop location auto-learned | `shops` row per `(employee_id, name_norm)`; `fraud.php::shop_lookup_or_learn()` stores the point on first visit and nudges it after - shown as context only, not a fraud check |
| One visit per shop per day (rule 8) | `UNIQUE (employee_id, shop_norm, work_date)` on `visits` |
| Suspicious activity -> Alerts only | rules 5, 6, 7, 9 write `fraud_flags` + an `alerts` row; no flag UI on visit rows |
| Active rules | 3, 4, 5, 6, 7, 8, 9, 10, 11 (`fraud_flags.rule_no`) |
| Employee = the field person | `users.role = 'employee'`; no separate table |

---

## Security checklist status

- [x] PDO prepared statements, `ATTR_EMULATE_PREPARES = false`
- [x] `password_hash()` / `password_verify()` for admin passwords **and** employee PINs
- [x] `e()` = `htmlspecialchars()` helper for all output
- [x] `includes/api.php` - every section endpoint's first line: session + auth check, exits if not logged in
- [x] CSRF token helper + `csrf_require()` for every write
- [x] Uploads: `finfo` MIME, random rename, size cap, `.htaccess` blocks PHP exec
- [x] `display_errors=0` + file logging when `APP_ENV='production'`
- [x] `session_regenerate_id(true)` on login; httponly + (prod) secure cookies
- [ ] Applied to concrete pages - done as each page is built in later steps
```
