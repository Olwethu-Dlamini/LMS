-- 003 - Decode text that was HTML-escaped on the way into the database.
--
-- Input used to be escaped on storage and escaped again on display, so text
-- people typed came back to them wrong: "Sales & Marketing" was stored as
-- "Sales &amp; Marketing" and rendered as "Sales &amp;amp; Marketing", and an
-- apostrophe in a leave reason reached the approver as &#039;. Escaping now
-- happens only at the point of output, which fixes everything written from here
-- on; this migration repairs what is already stored.
--
-- Safe to re-run: decoding text that holds no entities changes nothing. The
-- ampersand is decoded LAST on purpose - doing it first would turn a stored
-- "&amp;lt;" into "<" and lose the distinction the original text carried.

START TRANSACTION;

-- Departments
UPDATE `departments` SET `name` = REPLACE(`name`, '&#039;', '''');
UPDATE `departments` SET `name` = REPLACE(REPLACE(REPLACE(`name`, '&quot;', '"'), '&lt;', '<'), '&gt;', '>');
UPDATE `departments` SET `name` = REPLACE(`name`, '&amp;', '&');

-- Leave categories
UPDATE `leave_types` SET `name` = REPLACE(`name`, '&#039;', '''');
UPDATE `leave_types` SET `name` = REPLACE(REPLACE(REPLACE(`name`, '&quot;', '"'), '&lt;', '<'), '&gt;', '>');
UPDATE `leave_types` SET `name` = REPLACE(`name`, '&amp;', '&');

-- Public holidays
UPDATE `holidays` SET `title` = REPLACE(`title`, '&#039;', '''');
UPDATE `holidays` SET `title` = REPLACE(REPLACE(REPLACE(`title`, '&quot;', '"'), '&lt;', '<'), '&gt;', '>');
UPDATE `holidays` SET `title` = REPLACE(`title`, '&amp;', '&');

-- Staff names. Surnames carrying an apostrophe are the common case here.
UPDATE `users` SET `first_name` = REPLACE(`first_name`, '&#039;', ''''),
                   `last_name`  = REPLACE(`last_name`,  '&#039;', '''');
UPDATE `users` SET `first_name` = REPLACE(`first_name`, '&amp;', '&'),
                   `last_name`  = REPLACE(`last_name`,  '&amp;', '&');

-- Leave reasons and approver remarks
UPDATE `leave_applications` SET `reason` = REPLACE(`reason`, '&#039;', '''');
UPDATE `leave_applications` SET `reason` = REPLACE(REPLACE(REPLACE(`reason`, '&quot;', '"'), '&lt;', '<'), '&gt;', '>');
UPDATE `leave_applications` SET `reason` = REPLACE(`reason`, '&amp;', '&');

UPDATE `leave_approval_logs` SET `comments` = REPLACE(`comments`, '&#039;', '''') WHERE `comments` IS NOT NULL;
UPDATE `leave_approval_logs` SET `comments` = REPLACE(REPLACE(REPLACE(`comments`, '&quot;', '"'), '&lt;', '<'), '&gt;', '>') WHERE `comments` IS NOT NULL;
UPDATE `leave_approval_logs` SET `comments` = REPLACE(`comments`, '&amp;', '&') WHERE `comments` IS NOT NULL;

COMMIT;

-- Confirmation: anything still holding an entity after this is text somebody
-- genuinely typed, not a storage artefact. An empty result is what you want.
SELECT 'departments' AS source, COUNT(*) AS rows_with_entities FROM `departments` WHERE `name` LIKE '%&amp;%' OR `name` LIKE '%&#039;%'
UNION ALL
SELECT 'leave_types', COUNT(*) FROM `leave_types` WHERE `name` LIKE '%&amp;%' OR `name` LIKE '%&#039;%'
UNION ALL
SELECT 'holidays', COUNT(*) FROM `holidays` WHERE `title` LIKE '%&amp;%' OR `title` LIKE '%&#039;%'
UNION ALL
SELECT 'users', COUNT(*) FROM `users` WHERE `first_name` LIKE '%&#039;%' OR `last_name` LIKE '%&#039;%'
UNION ALL
SELECT 'leave_applications', COUNT(*) FROM `leave_applications` WHERE `reason` LIKE '%&amp;%' OR `reason` LIKE '%&#039;%';
