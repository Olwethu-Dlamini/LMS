-- Migration 007: one approval decides a leave request
--
-- The approval chain is now one stage deep. A request is decided by a single
-- approver and that decision is final:
--
--   employee   -> their line manager (or their department head), and done
--   manager    -> HR, and done
--   executive  -> HR, and done
--   hr         -> the executive, and done
--
-- Nothing about where a request *enters* the chain has changed, so this file
-- has no schema change in it. What changed is that an approval no longer
-- escalates, and that leaves applications sitting in queues whose approver the
-- new rules do not consult. This migration settles them.
--
-- Apply with:  sudo mysql lms_db < migrations/007-single-stage-approval.sql
--
-- Safe to re-run: every statement is scoped to a combination of status and
-- applicant role that the new rules never produce, so a second run finds
-- nothing to do.
--
-- What gets settled, and why
-- ---------------------------------------------------------------------------
-- An application is settled when it has already been through the approver who
-- is now its only one. That approval was real; under the new rules it was also
-- the last word, so the request is approved and the days are moved from
-- pending to used.
--
--   status              applicant   action      because
--   pending_hr          employee    approve     their line manager already decided
--   pending_executive   employee    approve     their line manager already decided
--   pending_executive   manager     approve     HR already decided
--   pending_executive   executive   approve     HR already decided
--
-- What is deliberately left alone
-- ---------------------------------------------------------------------------
--   pending_executive   hr          left        the executive is still its decider
--   pending_manager     anyone      left        its one approver has not acted yet
--
-- An HR manager's own leave is decided by the executive, which is the one route
-- that still ends at Stage 3. Those rows are correct where they are.
--
-- What this does NOT write
-- ---------------------------------------------------------------------------
-- No rows are added to leave_approval_logs. There is no system account to
-- attribute them to, and inventing one to satisfy a NOT NULL foreign key would
-- put a person's name against a decision they did not make. The audit trail for
-- a settled application therefore shows the approval it genuinely received and
-- then a status of approved, with no entry for the settlement itself. The
-- SELECT below is the record: read it before applying, and keep the output.
--
-- Reversing it
-- ---------------------------------------------------------------------------
-- The days moved out of pending_days are not recorded anywhere else once this
-- has run, so take a dump first:  ./tools/export_database.sh --dump-only

-- ---------------------------------------------------------------------------
-- Before: exactly which applications this will approve. Read this, then apply.
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
WHERE (a.status IN ('pending_hr', 'pending_executive') AND r.name = 'employee')
   OR (a.status = 'pending_executive' AND r.name IN ('manager', 'executive'))
ORDER BY a.start_date;

-- And what stays in flight, with the approver who now owns it.
SELECT a.application_no,
       CONCAT(u.first_name, ' ', u.last_name) AS applicant,
       r.name   AS applicant_role,
       a.status AS waiting_at,
       CASE r.name
            WHEN 'employee' THEN 'their line manager'
            WHEN 'manager'  THEN 'HR'
            WHEN 'executive' THEN 'HR'
            WHEN 'hr'       THEN 'the executive'
            ELSE 'their line manager'
       END AS decided_by
FROM leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
WHERE a.status IN ('pending_manager', 'pending_hr', 'pending_executive')
  AND NOT ((a.status IN ('pending_hr', 'pending_executive') AND r.name = 'employee')
        OR (a.status = 'pending_executive' AND r.name IN ('manager', 'executive')))
ORDER BY a.status, a.start_date;

START TRANSACTION;

-- 1. The entitlement rows first, while the applications can still be matched on
--    the status they are about to leave. Reversing these two statements would
--    settle nothing, because by then no row is pending any more - the same trap
--    migration 001 documents.
UPDATE leave_entitlements e
JOIN leave_applications a
      ON a.user_id       = e.user_id
     AND a.leave_type_id = e.leave_type_id
     AND e.year          = YEAR(a.start_date)
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET e.pending_days = GREATEST(0, e.pending_days - a.total_days),
    e.used_days    = e.used_days + a.total_days
WHERE (a.status IN ('pending_hr', 'pending_executive') AND r.name = 'employee')
   OR (a.status = 'pending_executive' AND r.name IN ('manager', 'executive'));

-- 2. Then the applications.
UPDATE leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET a.status = 'approved',
    a.current_approver_role = 'none'
WHERE (a.status IN ('pending_hr', 'pending_executive') AND r.name = 'employee')
   OR (a.status = 'pending_executive' AND r.name IN ('manager', 'executive'));

COMMIT;

-- ---------------------------------------------------------------------------
-- Post-migration checks.
--
-- The first must return zero rows: no application may be waiting on an approver
-- the new rules never ask. The second lists what is still in flight, which
-- should be pending_manager rows plus any HR leave awaiting the executive.
-- ---------------------------------------------------------------------------
SELECT a.application_no, r.name AS applicant_role, a.status
FROM leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
WHERE (a.status = 'pending_hr'        AND r.name = 'employee')
   OR (a.status = 'pending_executive' AND r.name IN ('employee', 'manager', 'executive'))
   OR (a.status = 'pending_manager'   AND r.name IN ('manager', 'hr', 'executive', 'admin'))
   OR (a.status = 'pending_hr'        AND r.name = 'hr');

SELECT a.status, r.name AS applicant_role, COUNT(*) AS still_in_flight
FROM leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
WHERE a.status IN ('pending_manager', 'pending_hr', 'pending_executive')
GROUP BY a.status, r.name;
