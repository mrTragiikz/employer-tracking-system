-- =============================================================================
-- 2026-09-09  announcements  (admin -> employee live popup)
--
-- A Super Admin posts a short title + message from a new "Announcements"
-- section. It shows as a modal popup on employees' field screens within ~30
-- seconds (the field pages poll field/api/announcement.php). Once an employee
-- closes it, it stays closed for THEM (a row in announcement_dismissals).
-- Editing the message clears its dismissals so everyone sees the new version.
-- Only ONE announcement is active at a time.
--
-- Audience: 'all' (every field employee) or 'selected' (only the employees
-- listed in announcement_targets).
--
-- This is the "works today" in-app notice. A phone-locked / app-closed push
-- is a separate later step (Web Push). Nothing here touches attendance,
-- visits, distance, or any existing table - it only ADDS three tables.
--
-- RUN ONCE, against the database that is already selected:
--   Local XAMPP : D:\xampp\mysql\bin\mysql -u root trying < sql\migrations\2026-09-09_announcements.sql
--   cPanel      : phpMyAdmin -> select the database -> Import -> this file
--
-- Safe to re-run : CREATE TABLE IF NOT EXISTS is a no-op once the tables exist.
-- Safe to undo   : DROP TABLE `announcement_targets`;
--                  DROP TABLE `announcement_dismissals`;
--                  DROP TABLE `announcements`;
--                  (drop the child tables first - they FK to `announcements`)
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(120)  NOT NULL,
  `body`       VARCHAR(2000) NOT NULL,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,  -- 1 = showing to employees, 0 = off
  `audience`   ENUM('all','selected') NOT NULL DEFAULT 'all', -- 'all' = every employee; 'selected' = announcement_targets only
  `created_by` INT UNSIGNED  DEFAULT NULL,        -- which admin wrote it
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ann_active` (`is_active`, `id`),        -- "the one active announcement" lookup
  CONSTRAINT `fk_ann_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_dismissals` (
  `announcement_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,        -- the employee who closed it
  `dismissed_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`, `user_id`),     -- one row = "this employee closed this message"
  KEY `ix_ad_user` (`user_id`),
  CONSTRAINT `fk_ad_ann`  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_user` FOREIGN KEY (`user_id`)         REFERENCES `users`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_targets` (
  `announcement_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,        -- an employee this announcement is FOR (audience = 'selected')
  PRIMARY KEY (`announcement_id`, `user_id`),
  KEY `ix_at_user` (`user_id`),
  CONSTRAINT `fk_at_ann`  FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_at_user` FOREIGN KEY (`user_id`)         REFERENCES `users`(`id`)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
