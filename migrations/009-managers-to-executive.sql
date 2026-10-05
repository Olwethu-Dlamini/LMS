-- Migration 009: a manager's own leave is decided by the executive
--
-- From 2026-10-05 a line manager's leave goes to the executive rather than HR:
--
--   employee   -> their line manager (or their department head)
--   manager    -> the executive        (was HR)
--   executive  -> HR
--   hr         -> the executive
--
-- The code routes every new request that way. This moves the managers'
-- requests that were already waiting in HR's queue, so the executive can decide
-- them too: HR is refused on them from this release, and without this they
-- would sit in a queue nobody may act on.
--
-- Apply with:  sudo mysql lms_db < migrations/009-managers-to-executive.sql
--
-- Safe to re-run: it only touches pending_hr rows whose applicant is a manager,
-- which the new routing never produces, so a second run finds nothing to do.
--
-- What this changes
-- ---------------------------------------------------------------------------
--   status       applicant   becomes             because
--   pending_hr   manager     pending_executive   the executive now decides it
--
-- Only the queue changes. No balance moves: the days stay reserved in
-- pending_days exactly as they were, and are deducted when the executive
-- approves, or released if she rejects.
--
-- What is deliberately left alone
-- ---------------------------------------------------------------------------
--   pending_hr          executive   left   HR still decides the executive's leave
--   pending_executive   hr          left   the executive already decides HR's
--   pending_manager     anyone      left   unchanged
--   approved/rejected   anyone      left   decided requests are history
--
-- What this does NOT do
-- ---------------------------------------------------------------------------
-- It raises no notification. Moved requests appear in the executive's queue
-- (Approvals -> Manager & HR leave) and in the count on her dashboard, but no
-- bell entry or email announces each one. No row is written to
-- leave_approval_logs either: nothing was decided, only re-queued.
--
-- Reversing it
-- ---------------------------------------------------------------------------
-- The SELECT below lists every row this moves. Keep its output; setting those
-- application numbers back to status 'pending_hr', current_approver_role 'hr'
-- undoes it, as long as the code is rolled back with it.

-- ---------------------------------------------------------------------------
-- Before: exactly which applications this will move. Read this, then apply.
-- ---------------------------------------------------------------------------
SELECT a.application_no,
       u.emp_id,
       CONCAT(u.first_name, ' ', u.last_name) AS applicant,
       r.name   AS applicant_role,
       a.status AS waiting_at,
       t.code   AS leave_code,
       a.start_date,
       a.end_date,
       a.total_days
FROM leave_applications a
JOIN users u       ON u.id = a.user_id
JOIN roles r       ON r.id = u.role_id
JOIN leave_types t ON t.id = a.leave_type_id
WHERE a.status = 'pending_hr' AND r.name = 'manager'
ORDER BY a.start_date;

START TRANSACTION;

UPDATE leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET a.status = 'pending_executive',
    a.current_approver_role = 'executive'
WHERE a.status = 'pending_hr' AND r.name = 'manager';

COMMIT;

-- ---------------------------------------------------------------------------
-- Post-migration checks.
--
-- The first must return zero rows: no manager's request may still be waiting on
-- HR. The second lists what is in flight per queue and applicant role.
-- ---------------------------------------------------------------------------
SELECT a.application_no, r.name AS applicant_role, a.status
FROM leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
WHERE a.status = 'pending_hr' AND r.name = 'manager';

SELECT a.status, r.name AS applicant_role, COUNT(*) AS still_in_flight
FROM leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
WHERE a.status IN ('pending_manager', 'pending_hr', 'pending_executive')
GROUP BY a.status, r.name;
