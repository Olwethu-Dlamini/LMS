-- 004 - Record failed sign-in attempts, so the login form can be rate limited.
--
-- The form accepted password guesses as fast as they could be sent, against
-- addresses that follow a predictable pattern. This table is what lets
-- LoginThrottle count failures per email and address and close the door for a
-- while once there are too many in fifteen minutes.
--
-- Only failures are stored, and only briefly: a successful sign-in deletes that
-- caller's rows, and every write sweeps away anything past the window. Nothing
-- here is a record of who signed in - that is not what this table is for.
--
-- Safe to re-run.

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_login_attempts_lookup` (`email`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Confirmation: one row, naming the table that now exists.
SELECT TABLE_NAME AS created_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts';
