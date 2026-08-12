-- Migration 001: role-aware approval routing
--
-- Applications used to enter the chain at Stage 1 regardless of who applied,
-- which stranded any request made by a manager, HR or an executive: the Stage 1
-- check demands the 'manager' or 'admin' role, and self-approval is prohibited.
-- Routing is now decided by the applicant's own role (see
-- ApprovalWorkflow::initialStageFor), and this migration moves applications that
-- are still in flight onto the new route.
--
-- There is no table or column change - only in-flight rows are corrected. Safe
-- to re-run: every statement is scoped to a status the new rules never produce.
--
-- Apply with:  sudo mysql lms_db < migrations/001-role-aware-routing.sql

START TRANSACTION;

-- 1. Managers and executives never sit in the Stage 1 queue they would own or
--    outrank. Move them to HR review.
UPDATE leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET a.status = 'pending_hr',
    a.current_approver_role = 'hr'
WHERE a.status = 'pending_manager'
  AND r.name IN ('manager', 'executive');

-- 2. HR staff own Stage 2, so their own leave starts at the executive instead.
UPDATE leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET a.status = 'pending_executive',
    a.current_approver_role = 'executive'
WHERE a.status IN ('pending_manager', 'pending_hr')
  AND r.name = 'hr';

-- 3. An executive's own leave is finalised by HR - nobody sits above them. Any
--    executive request already waiting at Stage 3 has therefore cleared its
--    final approver, so settle the balance and close it. The entitlement update
--    runs first, while the rows can still be matched on the pending status.
UPDATE leave_entitlements e
JOIN leave_applications a
      ON a.user_id = e.user_id
     AND a.leave_type_id = e.leave_type_id
     AND e.year = YEAR(a.start_date)
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET e.pending_days = GREATEST(0, e.pending_days - a.total_days),
    e.used_days    = e.used_days + a.total_days
WHERE a.status = 'pending_executive'
  AND r.name = 'executive';

UPDATE leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
SET a.status = 'approved',
    a.current_approver_role = 'none'
WHERE a.status = 'pending_executive'
  AND r.name = 'executive';

-- 4. Admin accounts hold no leave entitlement and can no longer apply. Any
--    entitlement rows seeded for them under the old rules are dead weight;
--    applications they filed are left untouched so history stays intact.
DELETE e FROM leave_entitlements e
JOIN users u ON u.id = e.user_id
JOIN roles r ON r.id = u.role_id
LEFT JOIN leave_applications a ON a.user_id = e.user_id
WHERE r.name = 'admin'
  AND a.id IS NULL;

COMMIT;

-- Post-migration check: no application should be waiting on a stage its own
-- applicant is responsible for. This must return zero rows.
SELECT a.application_no, r.name AS applicant_role, a.status
FROM leave_applications a
JOIN users u ON u.id = a.user_id
JOIN roles r ON r.id = u.role_id
WHERE (a.status = 'pending_manager'   AND r.name IN ('manager', 'hr', 'executive', 'admin'))
   OR (a.status = 'pending_hr'        AND r.name = 'hr')
   OR (a.status = 'pending_executive' AND r.name = 'executive');
