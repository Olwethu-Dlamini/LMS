<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/ApprovalWorkflow.php';
require_role([ROLE_MANAGER, ROLE_ADMIN]);

$db = getDBConnection();
$workflow = new ApprovalWorkflow($db);
$approverId = $_SESSION['user_id'];
$approverRole = $_SESSION['user_role'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $appId = (int)($_POST['application_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'approve' or 'reject'
    $comments = sanitize($_POST['comments'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif ($appId <= 0 || !in_array($action, ['approve', 'reject'])) {
        $error = 'Invalid action or request selection.';
    } else {
        $res = $workflow->processAction($appId, $approverId, $approverRole, $action, $comments);
        if ($res['success']) {
            $msg = ($action === 'approve') 
                ? 'Request Stage 1 Approved! Transferred to Stage 2 (HR Review).' 
                : 'Request Rejected. Reserved days released back to employee.';
            set_flash($action === 'approve' ? 'success' : 'warning', $msg);
            header('Location: ' . APP_URL . '/modules/manager/approvals.php');
            exit;
        } else {
            $error = 'Error processing request: ' . $res['error'];
        }
    }
}

// Fetch Stage 1 Pending Applications
$stmt = $db->query("
    SELECT a.*, t.name as leave_name, u.first_name, u.last_name, u.emp_id, d.name as dept_name
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    JOIN users u ON a.user_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE a.status = 'pending_manager'
    ORDER BY a.created_at ASC
");
$pendingApps = $stmt->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-check-box text-warning"></i> Stage 1: Line Manager Approvals</h3>
        <p class="text-muted mb-0">Review pending leave applications from team members before escalating to HR.</p>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark"><i class="ti-time text-warning"></i> Pending Stage 1 Approval Queue (<?php echo count($pendingApps); ?>)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>App No</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Dates</th>
                        <th>Duration</th>
                        <th>Reason & Attachment</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingApps)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No pending Stage 1 leave applications. Queue is clear!</td></tr>
                    <?php else: ?>
                        <?php foreach ($pendingApps as $app): ?>
                        <tr>
                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($app['application_no']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></strong>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($app['emp_id']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars($app['dept_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($app['leave_name']); ?></td>
                            <td>
                                <small class="d-block font-weight-bold"><?php echo htmlspecialchars($app['start_date']); ?></small>
                                <small class="text-muted">to <?php echo htmlspecialchars($app['end_date']); ?></small>
                            </td>
                            <td><span class="badge badge-info"><?php echo number_format($app['total_days'], 1); ?> Days</span></td>
                            <td>
                                <div class="small text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($app['reason']); ?>">
                                    <?php echo htmlspecialchars($app['reason']); ?>
                                </div>
                                <?php if ($app['attachment_path']): ?>
                                    <a href="<?php echo APP_URL . '/' . htmlspecialchars($app['attachment_path']); ?>" target="_blank" class="badge badge-primary">File</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-xs btn-success font-weight-bold" data-toggle="modal" data-target="#actionModal<?php echo $app['id']; ?>">
                                    Review / Action
                                </button>

                                <!-- Action Modal -->
                                <div class="modal fade" id="actionModal<?php echo $app['id']; ?>" tabindex="-1" role="dialog">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                
                                                <div class="modal-header bg-primary text-white">
                                                    <h5 class="modal-title font-weight-bold">Stage 1 Review: <?php echo htmlspecialchars($app['application_no']); ?></h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <div class="modal-body">
                                                    <p class="mb-1"><strong>Employee:</strong> <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></p>
                                                    <p class="mb-1"><strong>Category:</strong> <?php echo htmlspecialchars($app['leave_name']); ?></p>
                                                    <p class="mb-3"><strong>Working Days:</strong> <?php echo number_format($app['total_days'], 1); ?> Days (<?php echo $app['start_date']; ?> to <?php echo $app['end_date']; ?>)</p>
                                                    
                                                    <div class="form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Manager Remarks / Comments</label>
                                                        <textarea name="comments" class="form-control" rows="3" placeholder="Add approval or rejection remarks..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer d-flex justify-content-between">
                                                    <button type="submit" name="action" value="reject" class="btn btn-danger font-weight-bold">
                                                        <i class="ti-close"></i> Reject Request
                                                    </button>
                                                    <button type="submit" name="action" value="approve" class="btn btn-success font-weight-bold">
                                                        <i class="ti-check"></i> Approve Stage 1
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Stage 1 Approvals | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
