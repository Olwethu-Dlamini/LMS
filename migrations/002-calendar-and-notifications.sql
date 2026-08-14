-- Migration 002: team leave calendar capacity limits and in-app notifications
--
-- Two additions:
--
-- 1. departments.max_concurrent_absences - how many members of a department may
--    be away on the same working day before the department is considered
--    understaffed. NULL means no limit is configured, which is the state every
--    existing row starts in, so nothing changes until an administrator sets one.
--    It lives on departments rather than in a settings table because the number
--    is a property of the department: a two-person team and a twenty-person team
--    do not share a threshold.
--
-- 2. notifications - one row per person per event. Leave routing already knows
--    who needs to act next; this table is how they find out without polling the
--    approval queue. read_at is NULL until the recipient opens it, which is what
--    the unread badge counts.
--
-- Safe to re-run: the column is added only when absent and the table uses
-- CREATE TABLE IF NOT EXISTS.
--
-- Apply with:  sudo mysql lms_db < migrations/002-calendar-and-notifications.sql

START TRANSACTION;

-- 1. Per-department concurrency threshold.
--    ALTER TABLE ... ADD COLUMN IF NOT EXISTS is MariaDB-only, so the column is
--    added through a prepared statement that becomes a no-op on MySQL when the
--    column already exists.
SET @col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'departments'
      AND COLUMN_NAME = 'max_concurrent_absences'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `departments` ADD COLUMN `max_concurrent_absences` INT NULL DEFAULT NULL AFTER `line_manager_id`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. In-app notifications.
--    leave_application_id is nullable so the table can carry messages that are
--    not about a specific request (an entitlement allocation, for example).
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `body` TEXT NULL,
    `link` VARCHAR(255) NULL,
    `leave_application_id` INT NULL,
    `read_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_application` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications`(`id`) ON DELETE CASCADE,
    -- The unread badge reads (user_id, read_at) on every page load; the created_at
    -- index serves the newest-first notification list.
    KEY `idx_notifications_unread` (`user_id`, `read_at`),
    KEY `idx_notifications_recent` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

-- Post-migration check: both objects should now exist. Expect one row reporting
-- the column and a non-zero notifications table count.
SELECT
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments'
        AND COLUMN_NAME = 'max_concurrent_absences') AS has_limit_column,
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications') AS has_notifications_table;
