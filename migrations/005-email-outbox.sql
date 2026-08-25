-- 005 - An outbox, so leave notices can leave the building by email.
--
-- Notifications have been in-app only: correct, guarded, and invisible to
-- anybody not signed in. A request could sit in an approver's queue for days
-- because nobody had reason to log in and look. Email closes that gap.
--
-- Mail is queued here rather than sent during the request. Three reasons:
--
--   An approval must not wait on a mail server. Notifier is called after the
--   transaction commits, so an inline send could not corrupt anything, but it
--   would still hold the page open for the length of an SMTP round trip - and
--   for the socket timeout on every approval when the server is unreachable.
--
--   A mail server outage should delay mail, not lose it. A queued row survives
--   until it is sent; a failed inline send is gone.
--
--   Delivery becomes answerable. "Was Thandi told?" is a question this table
--   can answer, including the reason when the answer is no.
--
-- status is the whole state machine. 'sending' is held only for the moment a
-- worker owns the row, which is what stops two overlapping cron runs from
-- sending the same message twice: the worker claims rows with an UPDATE before
-- it reads them, so a second worker finds nothing left to claim.
--
-- next_attempt_at is when the row becomes eligible. It is set on failure to
-- back off, so a server that is down is retried at widening intervals rather
-- than hammered every minute. claimed_at is when a worker last took the row,
-- which is how an abandoned message is told apart from one in flight.
--
-- Rows are kept after sending. The table doubles as the delivery log, and
-- tools/send_queued_email.php --prune is what eventually clears it.
--
-- Safe to re-run.
--
-- Apply with:  mysql -u root -p lms_db < migrations/005-email-outbox.sql

CREATE TABLE IF NOT EXISTS `email_outbox` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,

    -- Who it is for. Both are nullable and ON DELETE SET NULL: a delivery record
    -- should outlive the staff account it was addressed to, or the log develops
    -- holes exactly where somebody has left.
    `user_id` INT NULL,
    `notification_id` INT NULL,

    -- The address is copied rather than joined. users.email can change, and a
    -- record of where mail was actually sent is worth more than a record of
    -- where it would be sent today.
    `to_email` VARCHAR(150) NOT NULL,
    `to_name` VARCHAR(120) NULL,

    `subject` VARCHAR(255) NOT NULL,
    `body_html` MEDIUMTEXT NOT NULL,
    `body_text` MEDIUMTEXT NOT NULL,

    `status` ENUM('queued', 'sending', 'sent', 'failed') NOT NULL DEFAULT 'queued',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` TEXT NULL,

    `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `next_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- When a worker took the row. Needed to tell a message being sent right now
    -- from one abandoned by a worker that died: staleness has to be measured
    -- from the claim, not from queued_at, or a message queued last week and
    -- claimed a second ago looks abandoned and gets sent twice.
    `claimed_at` DATETIME NULL,
    `sent_at` DATETIME NULL,

    CONSTRAINT `fk_email_outbox_user` FOREIGN KEY (`user_id`)
        REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_email_outbox_notification` FOREIGN KEY (`notification_id`)
        REFERENCES `notifications`(`id`) ON DELETE SET NULL,

    -- The worker's claim query, which is the only hot read: everything queued
    -- and due, oldest first.
    KEY `idx_email_outbox_due` (`status`, `next_attempt_at`),
    -- Serves the admin delivery log and the --prune sweep.
    KEY `idx_email_outbox_recent` (`queued_at`),
    KEY `idx_email_outbox_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Confirmation: one row, naming the table that now exists.
SELECT TABLE_NAME AS created_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_outbox';
