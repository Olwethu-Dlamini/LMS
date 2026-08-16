<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/Notifier.php';

class ApprovalWorkflow {
    private PDO $db;
    private Notifier $notifier;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
        // Notices are raised after each commit, never inside the transaction:
        // routing must not depend on them, and nobody should be told about an
        // approval that was rolled back.
        $this->notifier = new Notifier($this->db);
    }

    /**
     * Where an application enters the chain, based on the applicant's own role.
     *
     * Nobody is asked to sign off on a peer or on themselves, so senior roles
     * skip the stages they would otherwise be the approver for:
     *   employee  -> Stage 1 line manager, then HR, then executive
     *   manager   -> straight to HR (they are the Stage 1 approver)
     *   hr        -> straight to the executive (they are the Stage 2 approver)
     *   executive -> HR, and HR's approval is final (nobody sits above them)
     * Admin is a system role with no leave entitlement and cannot apply.
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
     * Which stage an approval moves the application to next.
     *
     * The applicant's role matters here, not just the current status: HR sign-off
     * is the final decision on an executive's own leave, but only the middle
     * stage for everyone else.
     *
     * @return array{0:string,1:string} [status, current_approver_role]
     */
    public static function nextStageFor(string $applicantRole, string $currentStatus): array {
        if ($currentStatus === STATUS_PENDING_MANAGER) {
            return [STATUS_PENDING_HR, ROLE_HR];
        }
        if ($currentStatus === STATUS_PENDING_HR) {
            return $applicantRole === ROLE_EXECUTIVE
                ? [STATUS_APPROVED, 'none']
                : [STATUS_PENDING_EXECUTIVE, ROLE_EXECUTIVE];
        }
        if ($currentStatus === STATUS_PENDING_EXECUTIVE) {
            return [STATUS_APPROVED, 'none'];
        }
        throw new Exception("Application is already finalized.");
    }

    /**
     * Human-readable list of the stages an applicant's role skips, for the
     * notice shown on the application form.
     */
    public static function skippedStagesFor(string $applicantRole): array {
        switch ($applicantRole) {
            case ROLE_MANAGER:
                return ['Stage 1 · Line Manager'];
            case ROLE_HR:
                return ['Stage 1 · Line Manager', 'Stage 2 · HR Review'];
            case ROLE_EXECUTIVE:
                return ['Stage 1 · Line Manager', 'Stage 3 · Executive Sign-Off'];
            default:
                return [];
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
                'type_id' => $leaveTypeId,
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
                'skipped_stages' => self::skippedStagesFor($applicantRole)
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
            $year = (int)date('Y', strtotime($app['start_date']));
            $applicantRole = $this->roleOf($userId);

            // Self-approval restriction
            if ((int)$userId === (int)$approverId && $action === 'approve') {
                throw new Exception("Self-approval is prohibited: You cannot approve your own leave application.");
            }

            // Validate stage authorization
            if ($currentStatus === STATUS_PENDING_MANAGER) {
                if (!in_array($approverRole, [ROLE_MANAGER, ROLE_ADMIN])) {
                    throw new Exception("Unauthorized: Stage 1 approval requires Line Manager or Admin role.");
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
                throw new Exception("Unauthorized: Stage 2 approval requires HR Manager or Admin role.");
            }
            if ($currentStatus === STATUS_PENDING_EXECUTIVE && !in_array($approverRole, [ROLE_EXECUTIVE, ROLE_ADMIN])) {
                throw new Exception("Unauthorized: Stage 3 approval requires Executive or Admin role.");
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
                    'type_id' => $leaveTypeId,
                    'year' => $year
                ]);
            } else {
                // Approval Transition Path. Where this lands depends on the
                // applicant's role, not just the current stage: HR sign-off is
                // final for an executive's own leave.
                [$newStatus, $nextRole] = self::nextStageFor($applicantRole, $currentStatus);

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
                        'type_id' => $leaveTypeId,
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
                    'type_id' => $leaveTypeId,
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
                    'type_id' => $leaveTypeId,
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
