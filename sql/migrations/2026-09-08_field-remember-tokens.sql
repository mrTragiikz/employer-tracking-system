-- =============================================================================
-- 2026-09-08  field-remember-tokens
--
-- Adds the `field_remember_tokens` table - "stay logged in" for the field app
-- (Phase 1). The field app (employee phone / installed APK) stays signed in
-- across app closes, phone restarts, and long idle gaps; it only ends on an
-- explicit Logout or an admin action (Lock / Reset PIN / Reset Device).
--
-- `database.sql` DROPs every table, so it is fresh-install only. This file
-- adds JUST the new table to an already-populated database without touching
-- any existing data.
--
-- RUN ONCE, against the database that is already selected:
--   Local XAMPP : D:\xampp\mysql\bin\mysql -u root trying < sql\migrations\2026-09-08_field-remember-tokens.sql
--   cPanel      : phpMyAdmin -> select the database -> Import -> this file
--
-- Safe to re-run : CREATE TABLE IF NOT EXISTS is a no-op once the table exists.
-- Safe to undo   : DROP TABLE `field_remember_tokens`;
--                  (every field user just signs in again on their next visit -
--                   nothing else uses this table).
--
-- If you imported an EARLIER copy of this file that lacked `issued_at`, drop
-- and re-create (the table is disposable), or add the column by hand:
--   ALTER TABLE `field_remember_tokens`
--     ADD COLUMN `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `user_agent`;
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `field_remember_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,               -- the employee
  `token_hash` CHAR(64) NOT NULL,                -- SHA-256 hex of the raw token (raw is never stored)
  `device_id` VARCHAR(128) DEFAULT NULL,         -- device_id bound at issue time (rule 10 link)
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `issued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, -- first login of this chain; carried forward on rotation; anchor for the security_stamp_at check
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, -- when THIS (post-rotation) row was written
  `last_used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_frt_hash` (`token_hash`),
  KEY `ix_frt_user` (`user_id`),
  CONSTRAINT `fk_frt_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
