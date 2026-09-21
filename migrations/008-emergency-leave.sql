-- Migration 008: emergency leave, and a category that spends another's balance
--
-- Adds the Emergency Leave category. It is instant in the sense that matters
-- to somebody who needs it - no notice period, no length cap, and it may be
-- recorded for dates that have already passed - and the days come off the
-- person's Annual Leave rather than out of an allowance of its own.
--
-- Apply with:  sudo mysql lms_db < migrations/008-emergency-leave.sql
--
-- Safe to re-run: the columns are added only when absent, and the category is
-- inserted with ON DUPLICATE KEY UPDATE that re-asserts its configuration
-- rather than adjusting it.
--
-- Three new columns on leave_types
-- ---------------------------------------------------------------------------
--   deducts_from_type_id    The category whose leave_entitlements row this one
--                           spends. NULL means it has an allowance of its own,
--                           which is true of every category except this one.
--                           Emergency Leave points at Annual Leave, so an
--                           emergency reserves and deducts annual days and no
--                           separate balance has to be allocated, watched or
--                           reconciled.
--
--   allow_negative_balance  Whether a request may take that balance below
--                           zero. Emergency Leave may. An emergency has
--                           already happened by the time it is recorded, and a
--                           system that refuses to record it because the annual
--                           allowance is spent is a system that makes its own
--                           register wrong.
--
--   notify_as_urgent        Whether approvers are told about it as urgent. The
--                           notice leads with the category and the email carries
--                           the urgent accent, because an emergency request
--                           sitting unnoticed in a queue defeats the point of
--                           having one.
--
-- Why a column rather than a check for the code 'EMG'
-- ---------------------------------------------------------------------------
-- Policy on this system lives in leave_types and is edited in the admin
-- console, not in PHP. Behaviour keyed to a hardcoded code would mean the one
-- category whose rules cannot be seen or changed where every other category's
-- rules are, and it would quietly do nothing if somebody renamed it.
--
-- Zero allowance is deliberate
-- ---------------------------------------------------------------------------
-- max_days_per_year is 0 and no leave_entitlements rows are created. The three
-- places that seed entitlements skip categories that spend another's balance,
-- so nobody is given an Emergency Leave balance to exhaust and the dashboard
-- shows no tile for it. What it costs shows up in the Annual Leave figures,
-- which is where the days actually come from.

-- ---------------------------------------------------------------------------
-- Before: the category this will be pointed at. Expect exactly one row.
-- ---------------------------------------------------------------------------
SELECT id, code, name, max_days_per_year
FROM leave_types
WHERE code = 'ANN';

-- 1. deducts_from_type_id.
--    ALTER TABLE ... ADD COLUMN IF NOT EXISTS is MariaDB-only, so each column
--    goes in through a prepared statement that becomes a no-op on MySQL when
--    the column is already there - the same shape migration 002 uses.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_types'
      AND COLUMN_NAME = 'deducts_from_type_id'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `leave_types` ADD COLUMN `deducts_from_type_id` INT NULL DEFAULT NULL AFTER `is_active`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. allow_negative_balance.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_types'
      AND COLUMN_NAME = 'allow_negative_balance'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `leave_types` ADD COLUMN `allow_negative_balance` TINYINT(1) NOT NULL DEFAULT 0 AFTER `deducts_from_type_id`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. notify_as_urgent.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leave_types'
      AND COLUMN_NAME = 'notify_as_urgent'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `leave_types` ADD COLUMN `notify_as_urgent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `allow_negative_balance`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. The foreign key, added the same way and for the same reason: a bare ADD
--    CONSTRAINT fails on a second run and would abort the rest of the file.
--    ON DELETE SET NULL, so a category that somehow loses the one it spends
--    from falls back to holding its own balance rather than pointing at nothing.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME       = 'leave_types'
      AND CONSTRAINT_NAME  = 'fk_leave_types_deducts_from'
);
SET @ddl := IF(@fk_exists = 0,
    'ALTER TABLE `leave_types` ADD CONSTRAINT `fk_leave_types_deducts_from` FOREIGN KEY (`deducts_from_type_id`) REFERENCES `leave_types`(`id`) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. The category itself, pointed at whatever id Annual Leave actually holds.
--    Matched on code rather than id, because the ids seeded by schema.sql are
--    not guaranteed on a database where categories have been added or retired
--    by hand - the same reasoning migration 006 sets out.
SET @ann_id := (SELECT id FROM leave_types WHERE code = 'ANN' LIMIT 1);

INSERT INTO `leave_types`
    (`name`, `code`, `max_days_per_year`, `requires_attachment`, `is_paid`,
     `min_days_per_request`, `max_days_per_request`, `allow_half_day`,
     `min_notice_days`, `attachment_threshold_days`, `is_active`,
     `deducts_from_type_id`, `allow_negative_balance`, `notify_as_urgent`)
VALUES
    ('Emergency Leave', 'EMG', 0, 0, 1, 0.5, NULL, 1, 0, 0.0, 1, @ann_id, 1, 1)
ON DUPLICATE KEY UPDATE
    `max_days_per_year`      = 0,
    `min_notice_days`        = 0,
    `max_days_per_request`   = NULL,
    `allow_half_day`         = 1,
    `is_active`              = 1,
    `deducts_from_type_id`   = @ann_id,
    `allow_negative_balance` = 1,
    `notify_as_urgent`       = 1;

-- ---------------------------------------------------------------------------
-- Post-migration checks.
--
-- The first must show all three columns. The second must show Emergency Leave
-- with a non-empty spends_from: a NULL there means no category with the code
-- ANN was found, and emergency leave would then try to spend an allowance of
-- its own that nobody has been given. Fix the code on the annual category and
-- re-run this file if so.
-- ---------------------------------------------------------------------------
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'leave_types'
  AND COLUMN_NAME IN ('deducts_from_type_id', 'allow_negative_balance', 'notify_as_urgent')
ORDER BY ORDINAL_POSITION;

SELECT t.code,
       t.name,
       t.max_days_per_year,
       t.min_notice_days,
       t.max_days_per_request,
       t.allow_negative_balance,
       t.notify_as_urgent,
       s.code AS spends_from
FROM leave_types t
LEFT JOIN leave_types s ON s.id = t.deducts_from_type_id
WHERE t.code = 'EMG';
