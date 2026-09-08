-- =============================================================================
-- Track / Rajdoot - field visit attendance & tracking
-- Schema + settings defaults + one seeded admin.
--
-- THE single source of truth for the schema. There is no separate
-- migrations folder - every schema change made during development is
-- applied directly here, so this file is always the complete, final,
-- ready-to-import structure. Every fresh deploy (dev or live) just imports
-- this one file.
--
-- Import (local XAMPP): D:\xampp\mysql\bin\mysql -u root track < sql\database.sql
--   or via phpMyAdmin: create/select the `track` database first, then Import.
--
-- Import (cPanel / shared hosting): the DB user has NO permission to run
--   CREATE DATABASE, and the real DB name is prefixed (e.g. accountname_track).
--   So: create the database in cPanel > MySQL Databases, add your user to it,
--   open it in phpMyAdmin, select it, then Import this file. The
--   CREATE DATABASE / USE lines below are commented out for exactly this
--   reason - the import goes into whichever database is already selected.
--
-- MODEL
-- - An EMPLOYEE (the field salesperson) logs into the field app with
-- phone + 4-digit PIN, bound to ONE device (rule 10). role = 'employee'.
-- - An ADMIN uses the desktop panel with email + password. role = 'admin'.
-- - An employee TYPES a shop name at each visit (no admin-managed shop list).
-- The first time an employee uses a shop name, its GPS is auto-learned into
-- `shops`; later visits to that name are distance-checked against it
-- (soft version of fraud rule 2).
--
-- CONVENTIONS
-- - Money DECIMAL(12,2). Distances in KM as DECIMAL(10,3). Never float columns.
-- - Server time only (Asia/Kathmandu). A device clock, if sent, is stored only
-- in *_device_ts columns for fraud comparison - never used for calculation.
-- - Soft-delete via `deleted_at` where history must survive.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:45';
SET FOREIGN_KEY_CHECKS = 0;

-- CREATE DATABASE / USE are intentionally disabled - see the header note.
-- Local XAMPP: create + select `track` in phpMyAdmin before importing.
-- cPanel: create the prefixed database in the MySQL Databases panel, then
-- select it in phpMyAdmin before importing.
-- CREATE DATABASE IF NOT EXISTS `track`
--   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `track`;

DROP TABLE IF EXISTS `fraud_flags`;
DROP TABLE IF EXISTS `alerts`;
DROP TABLE IF EXISTS `photos`;
DROP TABLE IF EXISTS `visit_photos`;
DROP TABLE IF EXISTS `route_hops`;
DROP TABLE IF EXISTS `road_distance_cache`;
DROP TABLE IF EXISTS `visits`;
DROP TABLE IF EXISTS `attendance`;
DROP TABLE IF EXISTS `shops`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `auth_events`;
DROP TABLE IF EXISTS `field_devices`;
DROP TABLE IF EXISTS `dealers`; -- old pre-rework table name, dropped for good
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `users`;

-- =============================================================================
-- users - admins and employees.
-- role = 'admin' -> email + password, desktop panel
-- role = 'employee' -> phone + 4-digit PIN, field app, one device_id (rule 10)
-- =============================================================================
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role` ENUM('admin','employee') NOT NULL,
  `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0, -- exactly one protected admin; cannot be deleted, can only edit its own name/password
  `name` VARCHAR(120) NOT NULL,

  `username` VARCHAR(60) DEFAULT NULL, -- admins (login); unused for employees
  `email` VARCHAR(190) DEFAULT NULL, -- admins (optional); optional for employees
  `phone` VARCHAR(20) DEFAULT NULL, -- employees (login)

  -- password_hash() output for BOTH the admin password and the employee PIN.
  `secret_hash` VARCHAR(255) NOT NULL,

  -- employee profile (unused for admins)
  `area` VARCHAR(120) DEFAULT NULL, -- sub-zone within a region
  `region` VARCHAR(80) DEFAULT NULL, -- top-level zone (e.g. Koshi, Bagmati)
  `dob` DATE DEFAULT NULL,
  `gender` ENUM('male','female','other') DEFAULT NULL,
  `id_number` VARCHAR(40) DEFAULT NULL, -- Aadhaar / citizenship / national ID
  `document_type` ENUM('citizenship','driving_license','passport','national_id') DEFAULT NULL, -- what id_photo_front/back are
  `id_photo_front` VARCHAR(255) DEFAULT NULL, -- ID card front photo (<=200 KB)
  `id_photo_back` VARCHAR(255) DEFAULT NULL, -- ID card back photo (<=200 KB)
  `emergency_name` VARCHAR(120) DEFAULT NULL,
  `emergency_contact` VARCHAR(20) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL, -- admin notes on the employee detail page
  `photo_path` VARCHAR(255) DEFAULT NULL, -- employee photo, uploads/YYYY/MM/... (<=200 KB)
  `vehicle_type` ENUM('bike','car','auto') DEFAULT NULL, -- vehicle used for field visits
  `code` VARCHAR(32) DEFAULT NULL, -- human ref, e.g. DLR001

  -- rule 10: employee login bound to exactly one device. NULL until first bind.
  `device_id` VARCHAR(128) DEFAULT NULL,
  `device_bound_at` DATETIME DEFAULT NULL,

  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_logins` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,

  -- Bumped by any admin action that must kill an Employee's CURRENT session
  -- immediately (lock, PIN reset, device reset) - see require_employee() in
  -- includes/auth.php, which compares this against the session's login_at.
  `security_stamp_at` DATETIME DEFAULT NULL,

  `last_login_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  UNIQUE KEY `uq_users_code` (`code`),
  KEY `ix_users_role` (`role`),
  KEY `ix_users_device` (`device_id`),
  KEY `ix_users_active` (`is_active`),
  KEY `ix_users_region` (`region`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- settings - admin-editable key/value store.
-- =============================================================================
CREATE TABLE `settings` (
  `key_name` VARCHAR(64) NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  `value_type` ENUM('string','int','decimal','bool','json') NOT NULL DEFAULT 'string',
  `label_ne` VARCHAR(160) DEFAULT NULL,
  `label_en` VARCHAR(160) DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key_name`),
  CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- field_devices - device-binding history for employees (rule 10 audit trail).
-- =============================================================================
CREATE TABLE `field_devices` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL, -- the employee
  `device_id` VARCHAR(128) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` ENUM('active','replaced','rejected') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_user` (`user_id`,`device_id`),
  CONSTRAINT `fk_fd_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- auth_events - login / logout / device-bind / lockout.
-- =============================================================================
CREATE TABLE `auth_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `event` ENUM('login_ok','login_fail','logout','locked','device_bound',
                    'device_rejected','pin_changed','password_changed') NOT NULL,
  `detail` VARCHAR(255) DEFAULT NULL,
  `ip` VARBINARY(16) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_auth_user_time` (`user_id`,`created_at`),
  KEY `ix_auth_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- login_attempts - brute-force throttle keyed by identifier + ip.
-- =============================================================================
CREATE TABLE `login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(190) NOT NULL,
  `ip` VARBINARY(16) DEFAULT NULL,
  `succeeded` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_la_identifier_time` (`identifier`,`created_at`),
  KEY `ix_la_ip_time` (`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- shops - AUTO-LEARNED, not admin-managed.
-- Created the first time an employee types a shop name. Its GPS is the location
-- captured at that first visit. Later visits to the same (employee, name) are
-- distance-checked against `lat`/`lng` (soft fraud rule 2) and the point is
-- nudged toward the running average.
-- =============================================================================
CREATE TABLE `shops` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL, -- the employee who "owns" this name
  `name_display` VARCHAR(160) NOT NULL, -- as first typed
  `name_norm` VARCHAR(160) NOT NULL, -- lower/trim/collapsed spaces - match key

  `lat` DECIMAL(10,7) NOT NULL, -- learned point
  `lng` DECIMAL(10,7) NOT NULL,
  `samples` SMALLINT UNSIGNED NOT NULL DEFAULT 1, -- how many visits fed the average
  `visit_count` INT UNSIGNED NOT NULL DEFAULT 0,

  `first_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_employee_name` (`employee_id`,`name_norm`),
  KEY `ix_shops_employee` (`employee_id`),
  CONSTRAINT `fk_shops_employee` FOREIGN KEY (`employee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- attendance - one row per employee per working day.
-- =============================================================================
CREATE TABLE `attendance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,

  `check_in_at` DATETIME NOT NULL,
  `check_in_lat` DECIMAL(10,7) NOT NULL,
  `check_in_lng` DECIMAL(10,7) NOT NULL,
  `check_in_accuracy_m` DECIMAL(7,2) DEFAULT NULL,
  `check_in_device_ts` DATETIME DEFAULT NULL, -- phone clock, fraud compare ONLY
  `check_in_device_id` VARCHAR(128) DEFAULT NULL,
  `check_in_odometer_km` DECIMAL(8,1) DEFAULT NULL, -- hand-typed bike KM; photo evidence is a `photos` row (photo_kind='checkin')

  `check_out_at` DATETIME DEFAULT NULL,
  `check_out_lat` DECIMAL(10,7) DEFAULT NULL,
  `check_out_lng` DECIMAL(10,7) DEFAULT NULL,
  `check_out_accuracy_m` DECIMAL(7,2) DEFAULT NULL,
  `check_out_odometer_km` DECIMAL(8,1) DEFAULT NULL, -- hand-typed bike KM; photo evidence is a `photos` row (photo_kind='checkout')

  -- cached day totals (server-computed; see includes/distance.php)
  `total_hops` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `straight_km` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `road_km` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `road_factor_used` DECIMAL(4,2) NOT NULL DEFAULT 1.30,

  `total_seconds` INT UNSIGNED DEFAULT NULL, -- check_out - check_in
  `shop_seconds` INT UNSIGNED NOT NULL DEFAULT 0, -- time at shops (productive)
  `road_seconds` INT UNSIGNED NOT NULL DEFAULT 0, -- time travelling

  `status` ENUM('open','closed','incomplete') NOT NULL DEFAULT 'open',
  `location_denied` TINYINT(1) NOT NULL DEFAULT 0, -- rule 1 context
  `mock_location` TINYINT(1) NOT NULL DEFAULT 0, -- rule 9 context

  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_employee_day` (`employee_id`,`work_date`),
  KEY `ix_attendance_date` (`work_date`),
  KEY `ix_attendance_status` (`status`),
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- visits - one row per "I'm here" at a shop.
-- shop_name is what the employee typed; shop_id links the auto-learned row
-- (NULL only transiently before the shop row is created).
-- rule 8: one visit per (employee, shop_norm) per day - enforced by uq below.
-- =============================================================================
CREATE TABLE `visits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` INT UNSIGNED NOT NULL,
  `employee_id` INT UNSIGNED NOT NULL, -- denormalised
  `shop_id` INT UNSIGNED DEFAULT NULL, -- auto-learned shops.id
  `shop_name` VARCHAR(160) NOT NULL, -- typed text
  `area_name` VARCHAR(120) NOT NULL, -- typed locality/area text
  `shop_norm` VARCHAR(160) NOT NULL, -- normalised key for rule 8
  `work_date` DATE NOT NULL, -- = attendance.work_date
  `seq` SMALLINT UNSIGNED NOT NULL, -- 1-based order in the day

  `arrived_at` DATETIME NOT NULL,
  `left_at` DATETIME DEFAULT NULL,

  `lat` DECIMAL(10,7) NOT NULL,
  `lng` DECIMAL(10,7) NOT NULL,
  `accuracy_m` DECIMAL(7,2) DEFAULT NULL,
  `device_ts` DATETIME DEFAULT NULL, -- phone clock, fraud compare ONLY
  `device_id` VARCHAR(128) DEFAULT NULL,

  -- distance from the shop's learned point (metres). NULL for the first-ever visit.
  `distance_from_shop_m` DECIMAL(8,2) DEFAULT NULL,

  -- hop INTO this visit, server-computed & cached
  `hop_straight_km` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `hop_road_km` DECIMAL(10,3) NOT NULL DEFAULT 0,
  `hop_seconds` INT UNSIGNED DEFAULT NULL,
  `dwell_seconds` INT UNSIGNED DEFAULT NULL, -- left_at - arrived_at (shop time)

  -- Suspicious activity (rules 5, 6, 7, 9) is detected server-side and written to
  -- fraud_flags + raised as an alerts row. The admin reviews it in the Alerts
  -- section only - there is no per-visit "flag" stamp or badge anywhere.

  `remark` VARCHAR(255) DEFAULT NULL,

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visit_employee_shop_day` (`employee_id`,`shop_norm`,`work_date`), -- rule 8
  UNIQUE KEY `uq_visit_day_seq` (`attendance_id`,`seq`),
  KEY `ix_visits_attendance` (`attendance_id`),
  KEY `ix_visits_shop` (`shop_id`),
  KEY `ix_visits_date` (`work_date`),
  CONSTRAINT `fk_visits_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visits_employee` FOREIGN KEY (`employee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_visits_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- route_hops - per-leg breakdown of a day, rebuilt server-side.
-- leg 0: check-in -> visit 1 ; leg n: visit n -> visit n+1 ; last: -> check-out
-- =============================================================================
CREATE TABLE `route_hops` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `attendance_id` INT UNSIGNED NOT NULL,
  `leg_index` SMALLINT UNSIGNED NOT NULL,

  `from_kind` ENUM('checkin','visit') NOT NULL,
  `from_ref_id` INT UNSIGNED DEFAULT NULL,
  `from_lat` DECIMAL(10,7) NOT NULL,
  `from_lng` DECIMAL(10,7) NOT NULL,
  `from_at` DATETIME NOT NULL,

  `to_kind` ENUM('visit','checkout') NOT NULL,
  `to_ref_id` INT UNSIGNED DEFAULT NULL,
  `to_lat` DECIMAL(10,7) NOT NULL,
  `to_lng` DECIMAL(10,7) NOT NULL,
  `to_at` DATETIME NOT NULL,

  `straight_km` DECIMAL(10,3) NOT NULL,
  `road_km` DECIMAL(10,3) NOT NULL,
  `seconds` INT UNSIGNED NOT NULL,
  `speed_kmh` DECIMAL(7,2) DEFAULT NULL, -- feeds rule 6

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hop_leg` (`attendance_id`,`leg_index`),
  CONSTRAINT `fk_hops_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- road_distance_cache - Mapbox Directions API results, cached by coordinate
-- pair (rounded to 5 decimals, ~1m) so a given physical hop (e.g. "this
-- check-in spot to this shop") is only ever looked up once, however many
-- times it recurs across different days or is recomputed on every dashboard
-- page view. See road_km_real() in includes/distance.php - it checks here
-- before calling the API, and writes here after a successful real call
-- (never after a fallback-to-estimate, so a temporary Mapbox outage doesn't
-- permanently cache a stale estimate as if it were a real result).
-- =============================================================================
CREATE TABLE `road_distance_cache` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_lat` DECIMAL(9,5) NOT NULL,
  `from_lng` DECIMAL(9,5) NOT NULL,
  `to_lat` DECIMAL(9,5) NOT NULL,
  `to_lng` DECIMAL(9,5) NOT NULL,
  `road_km` DECIMAL(10,3) NOT NULL,
  `duration_seconds` INT UNSIGNED DEFAULT NULL, -- Directions API's own travel-time estimate, kept for reference only - NEVER used as elapsed time (see compute_day())
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_road_cache_pair` (`from_lat`,`from_lng`,`to_lat`,`to_lng`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- photos - live-camera captures an employee takes during a working day (rule 3).
-- MIME re-checked with finfo. Exactly THREE moments produce a photo:
--   photo_kind = 'checkin'  -> attached to the attendance row  (attendance_id set)
--   photo_kind = 'visit'    -> attached to a shop visit        (visit_id set)
--   photo_kind = 'checkout' -> attached to the attendance row  (attendance_id set)
-- Exactly one of (attendance_id, visit_id) is set - enforced by chk_photo_owner.
-- Deleting an employee cascades attendance + visits, which cascades these.
-- =============================================================================
CREATE TABLE `photos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `photo_kind` ENUM('checkin','visit','checkout') NOT NULL,
  `attendance_id` INT UNSIGNED DEFAULT NULL, -- set for checkin / checkout
  `visit_id` INT UNSIGNED DEFAULT NULL,      -- set for a shop visit
  `employee_id` INT UNSIGNED NOT NULL,       -- denormalised, for fast filtering
  `work_date` DATE NOT NULL,                 -- denormalised = attendance.work_date
  `taken_at` DATETIME NOT NULL,              -- when the shot was taken (server time)
  `lat` DECIMAL(10,7) DEFAULT NULL,
  `lng` DECIMAL(10,7) DEFAULT NULL,
  `stored_path` VARCHAR(255) NOT NULL,       -- relative to UPLOAD_DIR
  `original_name` VARCHAR(255) DEFAULT NULL,
  `mime` VARCHAR(64) NOT NULL,               -- verified, not client-declared
  `bytes` INT UNSIGNED NOT NULL,
  `width` SMALLINT UNSIGNED DEFAULT NULL,
  `height` SMALLINT UNSIGNED DEFAULT NULL,
  `sha256` CHAR(64) DEFAULT NULL,
  `captured_via` ENUM('camera','unknown') NOT NULL DEFAULT 'camera',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_photos_kind` (`photo_kind`),
  KEY `ix_photos_attendance` (`attendance_id`),
  KEY `ix_photos_visit` (`visit_id`),
  KEY `ix_photos_employee_date` (`employee_id`,`work_date`),
  CONSTRAINT `chk_photo_owner` CHECK (
    (`attendance_id` IS NOT NULL AND `visit_id` IS NULL)
    OR (`attendance_id` IS NULL AND `visit_id` IS NOT NULL)
  ),
  CONSTRAINT `fk_photos_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photos_visit` FOREIGN KEY (`visit_id`) REFERENCES `visits`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_photos_employee` FOREIGN KEY (`employee_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- fraud_flags - one row per triggered rule. rule_no maps to the spec's list.
-- severity: block = action refused; flag = allowed, raised as an alert for
-- review in the Alerts section; warn = retry asked. There is no per-visit flag
-- badge anywhere - this table + the Alerts section are the only surface.
-- =============================================================================
CREATE TABLE `fraud_flags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED DEFAULT NULL, -- the employee
  `work_date` DATE DEFAULT NULL,
  `subject_type` ENUM('checkin','checkout','visit','login','day') NOT NULL,
  `subject_id` INT UNSIGNED DEFAULT NULL,
  `rule_no` TINYINT UNSIGNED NOT NULL, -- 1..13
  `rule_key` VARCHAR(48) NOT NULL,
  `severity` ENUM('warn','flag','block') NOT NULL,
  `detail` VARCHAR(500) DEFAULT NULL,
  `resolved` TINYINT(1) NOT NULL DEFAULT 0,
  `resolved_by` INT UNSIGNED DEFAULT NULL,
  `resolved_at` DATETIME DEFAULT NULL,
  `resolution` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_flags_user_date` (`user_id`,`work_date`),
  KEY `ix_flags_rule` (`rule_no`),
  KEY `ix_flags_unresolved` (`resolved`),
  KEY `ix_flags_subject` (`subject_type`,`subject_id`),
  CONSTRAINT `fk_flags_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_flags_resolver` FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- alerts - admin-facing notifications (rule 5 "alert admin", digests, sweeps).
-- =============================================================================
CREATE TABLE `alerts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  `title` VARCHAR(160) NOT NULL,
  `body` VARCHAR(500) DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL, -- employee the alert is about
  `fraud_flag_id` BIGINT UNSIGNED DEFAULT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `read_by` INT UNSIGNED DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_alerts_unread` (`is_read`),
  KEY `ix_alerts_level` (`level`),
  KEY `ix_alerts_created` (`created_at`), -- feed_list()/feed_counts() range-scan this
  CONSTRAINT `fk_alerts_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_alerts_flag` FOREIGN KEY (`fraud_flag_id`) REFERENCES `fraud_flags`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- audit_log - generic admin action trail.
-- =============================================================================
CREATE TABLE `audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(64) NOT NULL, -- 'employee.update','visit.approve','setting.update'
  `entity` VARCHAR(48) NOT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `before_json` JSON DEFAULT NULL,
  `after_json` JSON DEFAULT NULL,
  `ip` VARBINARY(16) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_actor_time` (`actor_id`,`created_at`),
  KEY `ix_audit_entity` (`entity`,`entity_id`),
  CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- SEED DATA
-- =============================================================================

INSERT INTO `settings` (`key_name`,`value`,`value_type`,`label_ne`,`label_en`) VALUES
  ('road_factor', '1.30', 'decimal','सडक गुणक (सिधा दूरी × यो)', 'Road factor (straight km x this)'),
  ('impossible_speed_kmh', '120', 'int', 'असम्भव गति (कि.मि./घण्टा)', 'Impossible travel speed (km/h)'),
  ('bulk_entry_gap_seconds', '120', 'int', 'बल्क प्रविष्टि अन्तराल (सेकेन्ड)', 'Bulk-entry gap threshold (s)'),
  ('same_location_tol_m', '15', 'int', 'एउटै स्थान सहनशीलता (मिटर)', 'Same-location tolerance (m)'),
  ('workday_end_hour', '20', 'int', 'कार्यदिन समाप्ति घण्टा', 'Workday end hour (incomplete sweep)'),
  ('currency', 'NPR', 'string', 'मुद्रा', 'Currency'),
  ('locale', 'ne', 'string', 'भाषा', 'Language'),
  -- attendance check-in window policy (Settings section). One rule only:
  -- once enabled, a check-in outside [open_time, cutoff_time) is BLOCKED
  -- outright (no "mark late" option any more - see includes/settings.php
  -- attendance_checkin_blocked()).
  ('attendance_cutoff_enabled', '0', 'bool', 'हाजिरी समय सीमा लागू गर्नुहोस्', 'Enforce an attendance check-in window'),
  ('attendance_checkin_open_time', '09:00', 'string', 'हाजिरी पोर्टल खुल्ने समय (HH:MM)', 'Check-in portal opening time (HH:MM, 24h)'),
  ('attendance_cutoff_time', '09:30', 'string', 'हाजिरी पोर्टल बन्द हुने समय (HH:MM)', 'Check-in portal closing time (HH:MM, 24h)');

-- One admin, ready to log in. CHANGE THIS PASSWORD after first login
-- (Users & Roles section). Default: username "prabin_dev", password "12345".
-- is_super_admin = 1: the one protected account - cannot be deleted, can only
-- edit its own name/password (see admin/11-users-roles/).
INSERT INTO `users` (`role`,`is_super_admin`,`name`,`username`,`secret_hash`,`is_active`) VALUES
  ('admin',1,'Prabin','prabin_dev','$2y$10$26GIoRYbrPSjz5Fi4y/rje7XNxscW./Fnzun/QVvaadGk.vtPg2Pq',1);
