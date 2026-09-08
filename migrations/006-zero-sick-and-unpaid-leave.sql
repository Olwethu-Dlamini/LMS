-- Migration 006: withdraw the sick leave and unpaid leave allowance
--
-- Sets every user's Sick Leave and Unpaid Leave allowance to zero for the
-- current leave year, and sets the same two allowances to zero in the leave
-- policy so they are not handed straight back out again.
--
-- Apply with:  sudo mysql lms_db < migrations/006-zero-sick-and-unpaid-leave.sql
--
-- Safe to re-run: every statement writes a fixed value rather than adjusting
-- one, so a second run finds the work already done and changes nothing.
--
-- Both halves matter
-- ---------------------------------------------------------------------------
-- A person's allowance is leave_entitlements.total_days, one row per leave
-- type per year. The DEFAULT it was copied from is
-- leave_types.max_days_per_year (Sick 10, Unpaid 30), and three things still
-- read that default:
--
--   * HR > Leave Allocations > "Bulk Initialize Annual Allocations"
--   * Administration > User Management, whenever an account is created
--   * tools/seed_employees.php, for each new roster entry
--
-- Step 1 alone would therefore be undone by the next account created. Step 2 is
-- what makes it stick. Drop step 2 if this is meant to be a correction to this
-- year only, and leave a note for whoever runs the next bulk initialisation.
--
-- What is deliberately NOT touched
-- ---------------------------------------------------------------------------
-- used_days and pending_days keep their values. They are the record of leave
-- already taken and already reserved; zeroing them would show staff who never
-- had a sick day. The consequence is that anyone with days on the clock is
-- left with a negative available balance:
--
--     available = total_days - used_days - pending_days
--
-- Nothing breaks - no new request can pass a zero balance either way - but the
-- dashboard and HR reports display the negative. The two SELECTs at the top
-- list exactly who that is before the transaction opens, and there is an
-- optional step 3 at the bottom for wiping the slate instead.
--
-- Reversing it
-- ---------------------------------------------------------------------------
-- The old numbers are not recorded anywhere once this has run, so take a dump
-- first:  ./tools/export_database.sh --dump-only
--
-- Leave types are matched on code, not on id, because the ids seeded by
-- schema.sql are not guaranteed on a database that has had types added or
-- retired by hand in the admin console.

-- ---------------------------------------------------------------------------
-- Before: who is affected, and who ends up negative. Read these, then apply.
-- ---------------------------------------------------------------------------
SELECT t.code,
       COUNT(*)              AS rows_affected,
       COUNT(DISTINCT e.user_id) AS users_affected,
       SUM(e.total_days)     AS days_withdrawn
FROM leave_entitlements e
JOIN leave_types t ON t.id = e.leave_type_id
WHERE t.code IN ('SCK', 'UNP')
  AND e.year = YEAR(CURDATE())
GROUP BY t.code;

SELECT u.emp_id,
       CONCAT(u.first_name, ' ', u.last_name) AS name,
       t.code,
       e.total_days,
       e.used_days,
       e.pending_days,
       -(e.used_days + e.pending_days) AS available_becomes
FROM leave_entitlements e
JOIN leave_types t ON t.id = e.leave_type_id
JOIN users u        ON u.id = e.user_id
WHERE t.code IN ('SCK', 'UNP')
  AND e.year = YEAR(CURDATE())
  AND (e.used_days > 0 OR e.pending_days > 0)
ORDER BY u.first_name, u.last_name;

-- Requests still in the approval queue hold days in pending_days, and only the
-- approval workflow releases them. Approving one after this runs moves its days
-- into used_days against an allowance of zero. Cancel or reject them in the
-- application first if the books need to be clean.
SELECT a.application_no,
       u.emp_id,
       CONCAT(u.first_name, ' ', u.last_name) AS name,
       t.code,
       a.start_date,
       a.end_date,
       a.total_days,
       a.status
FROM leave_applications a
JOIN leave_types t ON t.id = a.leave_type_id
JOIN users u       ON u.id = a.user_id
WHERE t.code IN ('SCK', 'UNP')
  AND a.status IN ('pending_manager', 'pending_hr', 'pending_executive')
ORDER BY a.start_date;

START TRANSACTION;

-- 1. Every user's allowance for this year, whatever it was set to and whether
--    the account is active or not. Only total_days moves.
UPDATE leave_entitlements e
JOIN leave_types t ON t.id = e.leave_type_id
SET e.total_days = 0.00
WHERE t.code IN ('SCK', 'UNP')
  AND e.year = YEAR(CURDATE());

-- 2. The policy default, so new accounts and the next bulk initialisation stop
--    re-allocating the days over the top of step 1.
UPDATE leave_types
SET max_days_per_year = 0
WHERE code IN ('SCK', 'UNP');

-- 3. OPTIONAL, and destructive: also erase the record of sick and unpaid leave
--    already taken or reserved. This is what removes the negative balances
--    listed above, at the cost of HR reports showing staff who never had a sick
--    day. Uncomment only if that is genuinely wanted.
--
-- UPDATE leave_entitlements e
-- JOIN leave_types t ON t.id = e.leave_type_id
-- SET e.used_days = 0.00, e.pending_days = 0.00
-- WHERE t.code IN ('SCK', 'UNP')
--   AND e.year = YEAR(CURDATE());

COMMIT;

-- ---------------------------------------------------------------------------
-- Post-migration checks. The first must return zero rows, the second must show
-- 0 against both codes. MySQL reports "0 rows changed" for a value that was
-- already correct, so the row counts above cannot tell "nothing needed doing"
-- apart from "nothing happened" - only reading it back can.
-- ---------------------------------------------------------------------------
SELECT u.emp_id, t.code, e.year, e.total_days
FROM leave_entitlements e
JOIN leave_types t ON t.id = e.leave_type_id
JOIN users u        ON u.id = e.user_id
WHERE t.code IN ('SCK', 'UNP')
  AND e.year = YEAR(CURDATE())
  AND e.total_days <> 0;

SELECT code, name, max_days_per_year
FROM leave_types
WHERE code IN ('SCK', 'UNP');
