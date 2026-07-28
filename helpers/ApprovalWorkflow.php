<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

class ApprovalWorkflow {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Submit a new leave application
     */
    public function submitApplication(int $userId, int $leaveTypeId, string $startDate, string $endDate, float $totalDays, string $reason, ?string $attachmentPath): array {
        try {
            $this->db->beginTransaction();

            $appNo = 'LV-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
            $year = (int)date('Y', strtotime($startDate));

            // 1. Create Application
            $stmt = $this->db->prepare("
                INSERT INTO leave_applications 
                (application_no, user_id, leave_type_id, start_date, end_date, total_days, reason, attachment_path, status, current_approver_role)
                VALUES (:app_no, :user_id, :type_id, :start_date, :end_date, :days, :reason, :attachment, 'pending_manager', 'manager')
            ");
            $stmt->execute([
                'app_no' => $appNo,
                'user_id' => $userId,
                'type_id' => $leaveTypeId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'days' => $totalDays,
                'reason' => $reason,
                'attachment' => $attachmentPath
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
            return ['success' => true, 'application_no' => $appNo, 'id' => $appId];
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

            // Validate stage authorization
            if ($currentStatus === STATUS_PENDING_MANAGER && !in_array($approverRole, [ROLE_MANAGER, ROLE_ADMIN])) {
                throw new Exception("Unauthorized: Stage 1 approval requires Line Manager or Admin role.");
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
                // Approval Transition Path
                if ($currentStatus === STATUS_PENDING_MANAGER) {
                    $newStatus = STATUS_PENDING_HR;
                    $nextRole = ROLE_HR;
                } elseif ($currentStatus === STATUS_PENDING_HR) {
                    $newStatus = STATUS_PENDING_EXECUTIVE;
                    $nextRole = ROLE_EXECUTIVE;
                } elseif ($currentStatus === STATUS_PENDING_EXECUTIVE) {
                    $newStatus = STATUS_APPROVED;
                    $nextRole = 'none';

                    // Deduct from pending_days and add to used_days
                    $stmtDeduct = $this->db->prepare("
                        UPDATE leave_entitlements 
                        SET pending_days = GREATEST(0, pending_days - :days), 
                            used_days = used_days + :days 
                        WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
                    ");
                    $stmtDeduct->execute([
                        'days' => $totalDays,
                        'user_id' => $userId,
                        'type_id' => $leaveTypeId,
                        'year' => $year
                    ]);
                } else {
                    throw new Exception("Application is already finalized.");
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
                'action' => $action,
                'comments' => $comments
            ]);

            $this->db->commit();
            return ['success' => true, 'new_status' => $newStatus];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
