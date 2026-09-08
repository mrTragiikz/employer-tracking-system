-- =============================================================================
-- 2026-09-08  left-job  (employee offboarding)
--
-- Adds users.left_job_at - a "Left the Job" state for employees, distinct from
-- both a temporary Lock (is_active) and a true delete (deleted_at):
--   * left_job_at set  -> the employee cannot log in and is hidden from the
--     active Employees list, but every record they ever created (attendance,
--     visits, routes, evidence photos, PDFs) stays fully browsable under a
--     "Former Employees" view.
--   * NULL             -> still employed.
--   * cleared again    -> "Rejoin" (they came back).
--
-- Nothing is deleted. This is a single nullable column.
--
-- RUN ONCE, against the database that is already selected:
--   Local XAMPP : D:\xampp\mysql\bin\mysql -u root trying < sql\updates\2026-09-08_left-job.sql
--   cPanel      : phpMyAdmin -> select the database -> Import -> this file
--
-- Safe to re-run : the ADD COLUMN is guarded; a second run is a no-op.
-- Safe to undo   : ALTER TABLE `users` DROP COLUMN `left_job_at`;
--                  (every former employee simply becomes "active" again - no
--                   data loss; you would just relock/redelete them).
-- =============================================================================

SET NAMES utf8mb4;

-- MariaDB 10.5+ / MySQL 8.0.29+ support IF NOT EXISTS here. On older servers,
-- if this errors with "duplicate column", the column already exists - ignore.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `left_job_at` DATETIME DEFAULT NULL AFTER `locked_until`;
