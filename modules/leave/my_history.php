<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/ApprovalWorkflow.php';
check_auth();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$db = getDBConnection();
$workflow = new ApprovalWorkflow($db);

// Handle POST Cancellation Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $appId = (int)($_POST['application_id'] ?? 0);
    $cancelReason = sanitize($_POST['cancel_reason'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        set_flash('danger', 'Invalid security token.');
    } elseif ($appId <= 0) {
        set_flash('danger', 'Invalid application selection.');
    } else {
        $res = $workflow->cancelApplication($appId, $userId, $userRole, $cancelReason);
        if ($res['success']) {
            set_flash('success', 'Leave application cancelled successfully. Entitlement balance has been restored.');
            header('Location: ' . APP_URL . '/modules/leave/my_history.php');
            exit;
        } else {
            set_flash('danger', 'Failed to cancel application: ' . $res['error']);
        }
    }
}

// Fetch History
$stmt = $db->prepare("
    SELECT a.*, t.name as leave_name 
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    WHERE a.user_id = :user_id
    ORDER BY a.created_at DESC
");
$stmt->execute(['user_id' => $userId]);
$applications = $stmt->fetchAll();

// Handle Detail Modal View
$viewApp = null;
$approvalLogs = [];
if (isset($_GET['view'])) {
    $appId = (int)$_GET['view'];
    $stmtView = $db->prepare("
        SELECT a.*, t.name as leave_name, u.first_name, u.last_name, u.email 
        FROM leave_applications a
        JOIN leave_types t ON a.leave_type_id = t.id
        JOIN users u ON a.user_id = u.id
        WHERE a.id = :id AND a.user_id = :user_id
    ");
    $stmtView->execute(['id' => $appId, 'user_id' => $userId]);
    $viewApp = $stmtView->fetch();

    if ($viewApp) {
        $stmtLogs = $db->prepare("
            SELECT l.*, u.first_name, u.last_name 
            FROM leave_approval_logs l
            JOIN users u ON l.approver_id = u.id
            WHERE l.leave_application_id = :app_id
            ORDER BY l.action_at ASC
        ");
        $stmtLogs->execute(['app_id' => $appId]);
        $approvalLogs = $stmtLogs->fetchAll();
    }
}

ob_start();
?>

<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold text-dark"><i class="ti-time"></i> My Leave Application History</h5>
        <a href="<?php echo APP_URL; ?>/modules/leave/apply.php" class="btn btn-primary btn-sm font-weight-bold">
            <i class="ti-plus"></i> New Application
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Application No</th>
                        <th>Leave Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Submitted On</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($applications)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No leave application records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($applications as $app): ?>
                        <tr>
                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($app['application_no']); ?></td>
                            <td><?php echo htmlspecialchars($app['leave_name']); ?></td>
                            <td><?php echo htmlspecialchars($app['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($app['end_date']); ?></td>
                            <td><span class="badge badge-light border"><?php echo number_format($app['total_days'], 1); ?> Days</span></td>
                            <td><?php echo get_status_badge($app['status']); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($app['created_at'])); ?></td>
                            <td>
                                <a href="?view=<?php echo $app['id']; ?>" class="btn btn-sm btn-outline-info font-weight-bold mr-1">
                                    <i class="ti-eye"></i> View Details
                                </a>
                                <?php if (!in_array($app['status'], ['cancelled', 'rejected'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger font-weight-bold" data-toggle="modal" data-target="#cancelModal<?php echo $app['id']; ?>">
                                        <i class="ti-close"></i> Cancel
                                    </button>

                                    <!-- Cancel Modal -->
                                    <div class="modal fade" id="cancelModal<?php echo $app['id']; ?>" tabindex="-1" role="dialog">
                                        <div class="modal-dialog" role="document">
                                            <div class="modal-content">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                    <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                    <input type="hidden" name="action" value="cancel">
                                                    
                                                    <div class="modal-header bg-danger text-white">
                                                        <h5 class="modal-title font-weight-bold">Cancel Application: <?php echo htmlspecialchars($app['application_no']); ?></h5>
                                                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p class="text-dark">Are you sure you want to cancel this leave application? Any reserved or deducted leave days will be released back to your balance.</p>
                                                        <div class="form-group">
                                                            <label class="font-weight-bold text-dark">Reason for Cancellation (Optional)</label>
                                                            <textarea name="cancel_reason" class="form-control" rows="2" placeholder="State reason for cancelling..."></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                                                        <button type="submit" class="btn btn-danger font-weight-bold">Confirm Cancellation</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Application Details & Workflow Timeline Modal / Card -->
<?php if ($viewApp): ?>
<div class="card border-primary">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0 font-weight-bold">Application Details: <?php echo htmlspecialchars($viewApp['application_no']); ?></h5>
        <a href="<?php echo APP_URL; ?>/modules/leave/my_history.php" class="btn btn-sm btn-light">Close View</a>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-3">
                <span class="text-muted small d-block">Leave Category</span>
                <strong class="text-dark"><?php echo htmlspecialchars($viewApp['leave_name']); ?></strong>
            </div>
            <div class="col-md-3">
                <span class="text-muted small d-block">Duration</span>
                <strong class="text-dark"><?php echo htmlspecialchars($viewApp['start_date']); ?> to <?php echo htmlspecialchars($viewApp['end_date']); ?> (<?php echo number_format($viewApp['total_days'], 1); ?> Working Days)</strong>
            </div>
            <div class="col-md-3">
                <span class="text-muted small d-block">Current Status</span>
                <?php echo get_status_badge($viewApp['status']); ?>
            </div>
            <div class="col-md-3">
                <span class="text-muted small d-block">Attachment</span>
                <?php if ($viewApp['attachment_path']): ?>
                    <a href="<?php echo APP_URL . '/' . htmlspecialchars($viewApp['attachment_path']); ?>" target="_blank" class="btn btn-xs btn-outline-primary mt-1">View File</a>
                <?php else: ?>
                    <span class="text-muted small">None</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="mb-4">
            <span class="text-muted small d-block mb-1">Reason for Leave</span>
            <div class="p-3 bg-light rounded border text-dark"><?php echo nl2br(htmlspecialchars($viewApp['reason'])); ?></div>
        </div>

        <!-- 3-Stage Approval Progress Tracker -->
        <h6 class="font-weight-bold text-dark mb-3"><i class="ti-bar-chart"></i> 3-Tier Approval Workflow Timeline</h6>
        <div class="row text-center mb-4">
            <!-- Stage 1 -->
            <div class="col-md-4 mb-2">
                <div class="p-3 rounded border <?php 
                    if ($viewApp['status'] === 'pending_manager') echo 'bg-warning text-dark font-weight-bold';
                    elseif (in_array($viewApp['status'], ['pending_hr', 'pending_executive', 'approved'])) echo 'bg-success text-white font-weight-bold';
                    elseif ($viewApp['status'] === 'rejected') echo 'bg-danger text-white font-weight-bold';
                    else echo 'bg-light text-muted';
                ?>">
                    Stage 1: Line Manager
                </div>
            </div>
            <!-- Stage 2 -->
            <div class="col-md-4 mb-2">
                <div class="p-3 rounded border <?php 
                    if ($viewApp['status'] === 'pending_hr') echo 'bg-info text-white font-weight-bold';
                    elseif (in_array($viewApp['status'], ['pending_executive', 'approved'])) echo 'bg-success text-white font-weight-bold';
                    else echo 'bg-light text-muted';
                ?>">
                    Stage 2: HR Manager
                </div>
            </div>
            <!-- Stage 3 -->
            <div class="col-md-4 mb-2">
                <div class="p-3 rounded border <?php 
                    if ($viewApp['status'] === 'pending_executive') echo 'bg-primary text-white font-weight-bold';
                    elseif ($viewApp['status'] === 'approved') echo 'bg-success text-white font-weight-bold';
                    else echo 'bg-light text-muted';
                ?>">
                    Stage 3: Executive Boss
                </div>
            </div>
        </div>

        <!-- Audit Trail Table -->
        <h6 class="font-weight-bold text-dark mb-2">Approval Log History</h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="thead-light">
                    <tr>
                        <th>Stage</th>
                        <th>Approver</th>
                        <th>Action</th>
                        <th>Comments / Remarks</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($approvalLogs)): ?>
                        <tr><td colspan="5" class="text-center text-muted small py-2">No approval logs recorded yet. Application is currently pending review.</td></tr>
                    <?php else: ?>
                        <?php foreach ($approvalLogs as $log): ?>
                        <tr>
                            <td><span class="badge badge-light border"><?php echo strtoupper($log['stage']); ?></span></td>
                            <td><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?> (<?php echo strtoupper($log['approver_role']); ?>)</td>
                            <td>
                                <?php if ($log['action'] === 'approved'): ?>
                                    <span class="badge badge-success">Approved</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Rejected</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['comments'] ?? 'No remarks provided.'); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($log['action_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'My Leave History | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
