<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/LeaveCalculator.php';
require_once __DIR__ . '/Notifier.php';

class ApprovalWorkflow {
    private PDO $db;
    private Notifier $notifier;
    private LeaveCalculator $calculator;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
        // Notices are raised after each commit, never inside the transaction:
        // routing must not depend on them, and nobody should be told about an
        // approval that was rolled back.
        $this->notifier = new Notifier($this->db);
        // Consulted for one question only: which category's entitlement a
        // request actually spends. Emergency leave comes off annual leave, and
        // every reservation, deduction and release below has to follow that
        // pointer or the days would be taken from a balance nobody holds.
        $this->calculator = new LeaveCalculator($this->db);
    }

    /**
     * Who decides an application, based on the applicant's own role.
     *
     * One approval decides a request. There is no chain to climb and no second
     * opinion: whoever this routes to has the last word, and their approval is
     * what books the leave and deducts the days.
     *
     *   employee  -> their line manager, or the head of their department
     *   manager   -> HR
     *   executive -> HR
     *   hr        -> the executive
     *
     * Nobody is asked to sign off on themselves, which is what the senior
     * routing is for: HR owns the queue a manager's leave would otherwise sit
     * in, and the executive owns HR's. Admin is a system role with no leave
     * entitlement and cannot apply.
     *
     * The three queues therefore all remain in use, each holding a different
     * kind of applicant rather than a different stage of the same request.
     *
     * @return array{0:string,1:string} [status, current_approver_role]
     */
    public static function initialStageFor(string $applicantRole): array {
        switch ($applicantRole) {
            case ROLE_EMPLOYEE:
                return [STATUS_PENDING_MANAGER, ROLE_MANAGER];
            case ROLE_MANAGER:
            case ROLE_EXECUTIVE:
                return [STATUS_PENDING_HR, ROLE_HR];
            case ROLE_HR:
                return [STATUS_PENDING_EXECUTIVE, ROLE_EXECUTIVE];
            case ROLE_ADMIN:
                throw new Exception("System administrators do not hold leave entitlement and cannot apply for leave.");
            default:
                // Unknown roles get the full chain rather than a shortcut.
                return [STATUS_PENDING_MANAGER, ROLE_MANAGER];
        }
    }

    /**
     * Where an approval leaves the application: approved, always.
     *
     * One approval is the whole decision, so this is terminal wherever it is
     * called from. It used to take the applicant's role as well, because HR
     * sign-off was final on an executive's own leave and intermediate for
     * everybody else; with nothing to escalate to, the role no longer changes
     * the answer and the parameter has gone.
     *
     * It still refuses to act on an application that is already finalised,
     * which is what stops a second approval deducting the days twice.
     *
     * @return array{0:string,1:string} [status, current_approver_role]
     */
    public static function nextStageFor(string $currentStatus): array {
        if (in_array($currentStatus, [STATUS_PENDING_MANAGER, STATUS_PENDING_HR, STATUS_PENDING_EXECUTIVE], true)) {
            return [STATUS_APPROVED, 'none'];
        }
        throw new Exception("Application is already finalized.");
    }

    /**
     * Who decides this applicant's own leave, in words.
     *
     * Used by the notice on the application form and by the notification that
     * confirms a submission, so somebody is told who to expect a decision from
     * rather than being left to infer it from a stage number.
     *
     * Named by role rather than by person on purpose: an administrator can
     * reassign a department's head, and a notification that named last week's
     * holder would be wrong. This is the same reason recipients are derived
     * from the workflow instead of being stored alongside the request.
     */
    public static function deciderLabelFor(string $applicantRole): string {
        switch ($applicantRole) {
            case ROLE_MANAGER:
            case ROLE_EXECUTIVE:
                return 'HR';
            case ROLE_HR:
                return 'the Executive';
            default:
                return 'your line manager';
        }
    }

    /**
     * Why a cancellation cannot go ahead, or null when it can.
     *
     * Pure, so the rule can be read and tested on its own.
     *
     * Anyone may withdraw a request that has not been decided, and may cancel
     * approved leave they have not started taking - plans change, and that is
     * the whole point of booking early. What nobody may do is cancel leave they
     * have already begun: the days were taken, and handing them back to the
     * balance afterwards turns time off into credit. HR and administrators can
     * still do it, because a genuine correction has to be possible somewhere,
     * and every one of those is written to the audit log.
     *
     * @param array $application status and start_date
     * @param string|null $today injectable so the boundary can be tested
     */
    public static function cancellationRefusal(
        array $application,
        bool $isOwner,
        string $userRole,
        ?string $today = null
    ): ?string {
        $status = $application['status'] ?? '';

        if (in_array($status, [STATUS_CANCELLED, STATUS_REJECTED], true)) {
            return "Application is already {$status}.";
        }

        $isAuthorisedRole = in_array($userRole, [ROLE_HR, ROLE_ADMIN], true);
        if (!$isOwner && !$isAuthorisedRole) {
            return "Unauthorized: You do not have permission to cancel this application.";
        }

        if ($isAuthorisedRole) {
            return null;
        }

        $startDate = $application['start_date'] ?? null;
        if ($status === STATUS_APPROVED && $startDate !== null) {
            $start = (new DateTime($startDate))->format('Y-m-d');
            $now   = (new DateTime($today ?? 'today'))->format('Y-m-d');
            if ($start <= $now) {
                return "This leave has already started, so it can no longer be cancelled here. "
                     . "Ask HR to correct it if the dates changed.";
            }
        }

        return null;
    }

    /**
     * The applicant's role name, used to pick their routing.
     */
    private function roleOf(int $userId): string {
        $stmt = $this->db->prepare("
            SELECT r.name AS role_name
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
        ");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new Exception("Applicant account not found.");
        }
        return strtolower($row['role_name']);
    }

    /**
     * Submit a new leave application
     */
    public function submitApplication(int $userId, int $leaveTypeId, string $startDate, string $endDate, float $totalDays, string $reason, ?string $attachmentPath): array {
        try {
            $this->db->beginTransaction();

            $appNo = 'LV-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
            $year = (int)date('Y', strtotime($startDate));
            // The application records the category that was asked for; the
            // entitlement touched is whichever one holds the days.
            $balanceTypeId = $this->calculator->balanceTypeIdFor($leaveTypeId);

            // Re-check the two rules that depend on rows other requests can move,
            // this time inside the transaction and holding the entitlement row.
            //
            // validateEligibility() runs before this against a snapshot nobody is
            // holding, so two submissions racing each other - a double-clicked
            // button is enough - both read the same balance, both find it
            // sufficient, and both reserve against it. The result is a negative
            // balance nobody can explain. Reading the row FOR UPDATE makes the
            // second one queue behind the first and see what it did.
            $stmtBalance = $this->db->prepare("
                SELECT total_days, used_days, pending_days
                FROM leave_entitlements
                WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
                FOR UPDATE
            ");
            $stmtBalance->execute(['user_id' => $userId, 'type_id' => $balanceTypeId, 'year' => $year]);
            $entitlement = $stmtBalance->fetch();

            if (!$entitlement) {
                throw new Exception("No leave balance allocation found for the year {$year}.");
            }

            $available = (float)$entitlement['total_days']
                       - (float)$entitlement['used_days']
                       - (float)$entitlement['pending_days'];
            // Emergency leave is allowed past this, because the absence has
            // already happened. The row is still read FOR UPDATE above either
            // way: two emergencies submitted at once must queue rather than
            // interleave their arithmetic, or the reservation is wrong however
            // much slack the balance had.
            if ($totalDays > $available && !$this->calculator->mayOverdraw($leaveTypeId)) {
                throw new Exception(
                    "Insufficient balance. Requested: {$totalDays} days, Available: {$available} days."
                );
            }

            $stmtOverlap = $this->db->prepare("
                SELECT COUNT(*) FROM leave_applications
                WHERE user_id = :user_id
                  AND status NOT IN ('rejected', 'cancelled')
                  AND start_date <= :end_date
                  AND end_date >= :start_date
            ");
            $stmtOverlap->execute([
                'user_id'    => $userId,
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]);
            if ((int)$stmtOverlap->fetchColumn() > 0) {
                throw new Exception("You already have an active leave request overlapping with this date range.");
            }

            // Route the application by the applicant's own role, so senior staff
            // do not sit in a queue waiting for themselves.
            $applicantRole = $this->roleOf($userId);
            [$initialStatus, $initialRole] = self::initialStageFor($applicantRole);

            // 1. Create Application
            $stmt = $this->db->prepare("
                INSERT INTO leave_applications
                (application_no, user_id, leave_type_id, start_date, end_date, total_days, reason, attachment_path, status, current_approver_role)
                VALUES (:app_no, :user_id, :type_id, :start_date, :end_date, :days, :reason, :attachment, :status, :approver_role)
            ");
            $stmt->execute([
                'app_no' => $appNo,
                'user_id' => $userId,
                'type_id' => $leaveTypeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $totalDays,
                'reason' => $reason,
                'attachment' => $attachmentPath,
                'status' => $initialStatus,
                'approver_role' => $initialRole
            ]);

            $appId = (int)$this->db->lastInsertId();

            // 2. Reserve Pending Days in Entitlements
            $stmtReserve = $this->db->prepare("
                UPDATE leave_entitlements 
                SET pending_days = pending_days + :days 
                WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
            ");
            $stmtReserve->execute([
                'days' => $totalDays,
                'user_id' => $userId,
                'type_id' => $balanceTypeId,
                'year' => $year
            ]);

            $this->db->commit();

            $this->notifier->applicationSubmitted($appId);

            return [
                'success' => true,
                'application_no' => $appNo,
                'id' => $appId,
                'status' => $initialStatus,
                'next_approver_role' => $initialRole,
                'decided_by' => self::deciderLabelFor($applicantRole)
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Process an Approval or Rejection Action across the 3 stages
     */
    public function processAction(int $applicationId, int $approverId, string $approverRole, string $action, ?string $comments): array {
        try {
            $this->db->beginTransaction();

            // 1. Fetch application
            $stmt = $this->db->prepare("SELECT * FROM leave_applications WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $applicationId]);
            $app = $stmt->fetch();

            if (!$app) {
                throw new Exception("Application not found.");
            }

            $currentStatus = $app['status'];
            $totalDays = (float)$app['total_days'];
            $userId = (int)$app['user_id'];
            $leaveTypeId = (int)$app['leave_type_id'];
            $balanceTypeId = $this->calculator->balanceTypeIdFor($leaveTypeId);
            $year = (int)date('Y', strtotime($app['start_date']));

            // Self-approval restriction
            if ((int)$userId === (int)$approverId && $action === 'approve') {
                throw new Exception("Self-approval is prohibited: You cannot approve your own leave application.");
            }

            // Validate stage authorization
            if ($currentStatus === STATUS_PENDING_MANAGER) {
                if (!in_array($approverRole, [ROLE_MANAGER, ROLE_ADMIN])) {
                    throw new Exception("Unauthorized: this request is decided by a line manager or an administrator.");
                }
                if ($approverRole === ROLE_MANAGER) {
                    $stmtUser = $this->db->prepare("
                        SELECT u.manager_id, d.line_manager_id 
                        FROM users u 
                        LEFT JOIN departments d ON u.department_id = d.id 
                        WHERE u.id = :id
                    ");
                    $stmtUser->execute(['id' => $userId]);
                    $applicantInfo = $stmtUser->fetch();
                    $isDirectMgr = $applicantInfo && ((int)($applicantInfo['manager_id'] ?? 0) === $approverId);
                    $isDeptMgr = $applicantInfo && ((int)($applicantInfo['line_manager_id'] ?? 0) === $approverId);
                    if (!$isDirectMgr && !$isDeptMgr) {
                        throw new Exception("Unauthorized: You are not designated as the line manager for this applicant.");
                    }
                }
            }
            if ($currentStatus === STATUS_PENDING_HR && !in_array($approverRole, [ROLE_HR, ROLE_ADMIN])) {
                throw new Exception("Unauthorized: this request is decided by HR or an administrator.");
            }
            if ($currentStatus === STATUS_PENDING_EXECUTIVE && !in_array($approverRole, [ROLE_EXECUTIVE, ROLE_ADMIN])) {
                throw new Exception("Unauthorized: this request is decided by an executive or an administrator.");
            }

            if ($action === 'reject') {
                // Rejection Path
                $newStatus = STATUS_REJECTED;
                $nextRole = 'none';

                // Release reserved pending days
                $stmtRelease = $this->db->prepare("
                    UPDATE leave_entitlements 
                    SET pending_days = GREATEST(0, pending_days - :days) 
                    WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
                ");
                $stmtRelease->execute([
                    'days' => $totalDays,
                    'user_id' => $userId,
                    'type_id' => $balanceTypeId,
                    'year' => $year
                ]);
            } else {
                // Approval path. One approval decides the request, so this is
                // always the end of it: the status becomes approved and the
                // days are deducted below.
                [$newStatus, $nextRole] = self::nextStageFor($currentStatus);

                if ($newStatus === STATUS_APPROVED) {
                    // Deduct from pending_days and add to used_days
                    $stmtDeduct = $this->db->prepare("
                        UPDATE leave_entitlements
                        SET pending_days = GREATEST(0, pending_days - :days),
                            used_days = used_days + :used_days
                        WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
                    ");
                    $stmtDeduct->execute([
                        'days' => $totalDays,
                        'used_days' => $totalDays,
                        'user_id' => $userId,
                        'type_id' => $balanceTypeId,
                        'year' => $year
                    ]);
                }
            }

            // Update Application Status
            $stmtUpdate = $this->db->prepare("
                UPDATE leave_applications 
                SET status = :status, current_approver_role = :role 
                WHERE id = :id
            ");
            $stmtUpdate->execute(['status' => $newStatus, 'role' => $nextRole, 'id' => $applicationId]);

            // Audit Log Entry
            $stmtLog = $this->db->prepare("
                INSERT INTO leave_approval_logs 
                (leave_application_id, approver_id, approver_role, stage, action, comments)
                VALUES (:app_id, :approver_id, :role, :stage, :action, :comments)
            ");
            $stmtLog->execute([
                'app_id' => $applicationId,
                'approver_id' => $approverId,
                'role' => $approverRole,
                'stage' => $currentStatus,
                // Forms post 'approve'/'reject'; the log column is ENUM('approved','rejected')
                'action' => $action === 'approve' ? 'approved' : 'rejected',
                'comments' => $comments
            ]);

            $this->db->commit();

            $this->notifier->decisionRecorded($applicationId, $action, $newStatus, $comments);

            return ['success' => true, 'new_status' => $newStatus];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Cancel a leave application and release/restore leave balance
     */
    public function cancelApplication(int $applicationId, int $userId, string $userRole, ?string $reason = null): array {
        try {
            $this->db->beginTransaction();

            $stmt = $this->db->prepare("SELECT * FROM leave_applications WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $applicationId]);
            $app = $stmt->fetch();

            if (!$app) {
                throw new Exception("Leave application not found.");
            }

            // Who may cancel what, and until when.
            $isOwner = ((int)$app['user_id'] === $userId);
            $refusal = self::cancellationRefusal($app, $isOwner, $userRole);
            if ($refusal !== null) {
                throw new Exception($refusal);
            }

            $currentStatus = $app['status'];

            $totalDays = (float)$app['total_days'];
            $appUserId = (int)$app['user_id'];
            $leaveTypeId = (int)$app['leave_type_id'];
            $balanceTypeId = $this->calculator->balanceTypeIdFor($leaveTypeId);
            $year = (int)date('Y', strtotime($app['start_date']));

            if (in_array($currentStatus, [STATUS_PENDING_MANAGER, STATUS_PENDING_HR, STATUS_PENDING_EXECUTIVE])) {
                // Pending application: release reserved pending_days
                $stmtRelease = $this->db->prepare("
                    UPDATE leave_entitlements 
                    SET pending_days = GREATEST(0, pending_days - :days) 
                    WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
                ");
                $stmtRelease->execute([
                    'days' => $totalDays,
                    'user_id' => $appUserId,
                    'type_id' => $balanceTypeId,
                    'year' => $year
                ]);
            } elseif ($currentStatus === STATUS_APPROVED) {
                // Approved application: restore used_days
                $stmtRestore = $this->db->prepare("
                    UPDATE leave_entitlements 
                    SET used_days = GREATEST(0, used_days - :days) 
                    WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
                ");
                $stmtRestore->execute([
                    'days' => $totalDays,
                    'user_id' => $appUserId,
                    'type_id' => $balanceTypeId,
                    'year' => $year
                ]);
            }

            // Update Status
            $stmtUpdate = $this->db->prepare("
                UPDATE leave_applications 
                SET status = 'cancelled', current_approver_role = 'none' 
                WHERE id = :id
            ");
            $stmtUpdate->execute(['id' => $applicationId]);

            // Audit log
            $stmtLog = $this->db->prepare("
                INSERT INTO leave_approval_logs 
                (leave_application_id, approver_id, approver_role, stage, action, comments)
                VALUES (:app_id, :approver_id, :role, :stage, 'rejected', :comments)
            ");
            $cancelComment = "Cancelled by " . ($isOwner ? "Applicant" : strtoupper($userRole)) . ($reason ? ": " . $reason : "");
            $stmtLog->execute([
                'app_id' => $applicationId,
                'approver_id' => $userId,
                'role' => $userRole,
                'stage' => $currentStatus,
                'comments' => $cancelComment
            ]);

            $this->db->commit();

            $this->notifier->applicationCancelled($applicationId, $isOwner, $currentStatus, $reason);

            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
