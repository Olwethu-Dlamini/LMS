<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/ApprovalWorkflow.php';
require_once __DIR__ . '/../../helpers/LeaveCapacity.php';
require_once __DIR__ . '/../../includes/coverage_notice.php';
require_role([ROLE_HR, ROLE_ADMIN]);

$db = getDBConnection();
$workflow = new ApprovalWorkflow($db);
$approverId = $_SESSION['user_id'];
$approverRole = $_SESSION['user_role'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $appId = (int)($_POST['application_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $comments = sanitize($_POST['comments'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif ($appId <= 0 || !in_array($action, ['approve', 'reject'])) {
        $error = 'Invalid action or request selection.';
    } else {
        $res = $workflow->processAction($appId, $approverId, $approverRole, $action, $comments);
        if ($res['success']) {
            $msg = ($action === 'approve')
                ? stage_transition_message($res['new_status']) 
                : 'Request Rejected by HR. Reserved days released.';
            set_flash($action === 'approve' ? 'success' : 'warning', $msg);
            header('Location: ' . APP_URL . '/modules/hr/approvals.php');
            exit;
        } else {
            $error = 'Error processing request: ' . $res['error'];
        }
    }
}

// Fetch Stage 2 Pending Applications
$stmt = $db->query("
    SELECT a.*, t.name as leave_name, u.first_name, u.last_name, u.emp_id, d.name as dept_name,
           r.name AS applicant_role
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    JOIN users u ON a.user_id = u.id
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE a.status = 'pending_hr'
    ORDER BY a.created_at ASC
");
$pendingApps = $stmt->fetchAll();

// Coverage impact per request, so Stage 2 sees the same staffing picture the
// line manager saw at Stage 1.
$capacity = new LeaveCapacity($db);
$coverage = [];
foreach ($pendingApps as $app) {
    $coverage[$app['id']] = $capacity->coverageImpact((int)$app['id']);
}

ob_start();
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<?php if (is_admin()): ?>
    <div class="alert alert-warning mb-4">
        <strong><i class="ti-alert"></i> Administrator override.</strong>
        You are acting outside the normal approval chain. Use this only when the
        designated approver is unavailable &mdash; every action is recorded in the
        audit log against your account.
    </div>
<?php endif; ?>


<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark"><i class="ti-time text-info"></i> Pending Stage 2 HR Queue (<?php echo count($pendingApps); ?>)</span>
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
                        <tr><td colspan="8" class="text-center py-4 text-muted">No pending Stage 2 HR applications. All clear!</td></tr>
                    <?php else: ?>
                        <?php foreach ($pendingApps as $app):
                            // HR is the last word on an executive's own leave; there is
                            // no Stage 3 above them.
                            $isFinal = strtolower($app['applicant_role']) === ROLE_EXECUTIVE;
                        ?>
                        <tr>
                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($app['application_no']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></strong>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($app['emp_id']); ?></small>
                                <?php if ($isFinal): ?>
                                    <span class="badge badge-primary">Executive &middot; your approval is final</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($app['dept_name'] ?? 'N/A'); ?>
                                <?php $badge = coverage_badge($coverage[$app['id']]); ?>
                                <?php if ($badge !== ''): ?>
                                    <small class="d-block mt-1"><?php echo $badge; ?></small>
                                <?php endif; ?>
                            </td>
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
                                <button type="button" class="btn btn-xs btn-info font-weight-bold" data-toggle="modal" data-target="#actionModal<?php echo $app['id']; ?>">
                                    Review / Action
                                </button>

                                <!-- Action Modal -->
                                <div class="modal fade" id="actionModal<?php echo $app['id']; ?>" tabindex="-1" role="dialog">
                                    <div class="modal-dialog" role="document">
                                        <div class="modal-content">
                                            <form method="POST" action="">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="application_id" value="<?php echo $app['id']; ?>">
                                                
                                                <div class="modal-header bg-info text-white">
                                                    <h5 class="modal-title font-weight-bold">Stage 2 HR Review: <?php echo htmlspecialchars($app['application_no']); ?></h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <div class="modal-body">
                                                    <p class="mb-1"><strong>Employee:</strong> <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></p>
                                                    <p class="mb-1"><strong>Category:</strong> <?php echo htmlspecialchars($app['leave_name']); ?></p>
                                                    <p class="mb-3"><strong>Working Days:</strong> <?php echo number_format($app['total_days'], 1); ?> Days (<?php echo $app['start_date']; ?> to <?php echo $app['end_date']; ?>)</p>

                                                    <?php echo coverage_notice($coverage[$app['id']]); ?>

                                                    <?php if (($coverage[$app['id']]['limit'] ?? null) !== null): ?>
                                                        <p class="mb-3">
                                                            <a href="<?php echo APP_URL . '/modules/leave/team_calendar.php?month='
                                                                . htmlspecialchars(date('Y-m', strtotime($app['start_date'])))
                                                                . '&dept=' . (int)$coverage[$app['id']]['department_id']; ?>"
                                                               target="_blank" class="btn btn-xs btn-outline-primary">
                                                                <i class="ti-calendar"></i> Open the team calendar for these dates
                                                            </a>
                                                        </p>
                                                    <?php endif; ?>

                                                    <div class="form-group mb-3">
                                                        <label class="font-weight-bold text-dark">HR Policy Remarks</label>
                                                        <textarea name="comments" class="form-control" rows="3" placeholder="Add HR compliance notes or remarks..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer d-flex justify-content-between">
                                                    <button type="submit" name="action" value="reject" class="btn btn-danger font-weight-bold">
                                                        <i class="ti-close"></i> Reject Request
                                                    </button>
                                                    <button type="submit" name="action" value="approve" class="btn btn-info font-weight-bold">
                                                        <i class="ti-check"></i> <?php echo $isFinal ? 'Approve (Final)' : 'Approve Stage 2'; ?>
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
$pageTitle = 'Stage 2 HR Approvals | ' . APP_NAME;
$pageHeading = 'Stage 2: HR Manager Approvals';
$pageSubtitle = 'Review requests that have passed Stage 1 Line Manager sign-off.';
$pageIcon = 'ti-shield';
require_once __DIR__ . '/../../includes/layout.php';
?>
