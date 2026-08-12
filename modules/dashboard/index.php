<?php
require_once __DIR__ . '/../../includes/functions.php';
require_staff();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$db = getDBConnection();

// Fetch Leave Balances for User
$stmtEnt = $db->prepare("
    SELECT e.*, t.name as leave_name, t.code 
    FROM leave_entitlements e
    JOIN leave_types t ON e.leave_type_id = t.id
    WHERE e.user_id = :user_id AND e.year = :year
");
$stmtEnt->execute(['user_id' => $userId, 'year' => date('Y')]);
$entitlements = $stmtEnt->fetchAll();

// Fetch Pending Approvals count depending on Role
$pendingStage1Count = 0;
$pendingStage2Count = 0;
$pendingStage3Count = 0;

// Admins never reach this page (require_staff sends them to the console), so
// these queues are scoped to the staff roles that actually own each stage.
if (has_role(ROLE_MANAGER, false)) {
    $stmtCount = $db->prepare("
        SELECT COUNT(*)
        FROM leave_applications a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE a.status = 'pending_manager' AND (u.manager_id = :mgr_id OR d.line_manager_id = :dept_mgr_id)
    ");
    $stmtCount->execute(['mgr_id' => $userId, 'dept_mgr_id' => $userId]);
    $pendingStage1Count = (int)$stmtCount->fetchColumn();
}

if (has_role(ROLE_HR, false)) {
    $stmtCount = $db->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending_hr'");
    $pendingStage2Count = (int)$stmtCount->fetchColumn();
}

if (has_role(ROLE_EXECUTIVE, false)) {
    $stmtCount = $db->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending_executive'");
    $pendingStage3Count = (int)$stmtCount->fetchColumn();
}

// Fetch User's Recent Leave Applications
$stmtApps = $db->prepare("
    SELECT a.*, t.name as leave_name 
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    WHERE a.user_id = :user_id
    ORDER BY a.created_at DESC LIMIT 5
");
$stmtApps->execute(['user_id' => $userId]);
$myApplications = $stmtApps->fetchAll();

ob_start();
?>

<!-- Role Approval Notifications -->
<?php if (has_role([ROLE_MANAGER, ROLE_HR, ROLE_EXECUTIVE], false)): ?>
<div class="row mb-4">
    <?php if (has_role(ROLE_MANAGER, false)): ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-warning bg-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-uppercase small font-weight-bold text-muted">Stage 1: Line Manager Queue</span>
                        <h2 class="font-weight-bold text-warning mb-0"><?php echo $pendingStage1Count; ?></h2>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/modules/manager/approvals.php" class="btn btn-warning text-dark font-weight-bold btn-sm">
                            View Requests <i class="ti-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (has_role(ROLE_HR, false)): ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-info bg-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-uppercase small font-weight-bold text-muted">Stage 2: HR Manager Queue</span>
                        <h2 class="font-weight-bold text-info mb-0"><?php echo $pendingStage2Count; ?></h2>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/modules/hr/approvals.php" class="btn btn-info font-weight-bold btn-sm">
                            View Requests <i class="ti-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (has_role(ROLE_EXECUTIVE, false)): ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-primary bg-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-uppercase small font-weight-bold text-muted">Stage 3: Executive Boss Queue</span>
                        <h2 class="font-weight-bold text-primary mb-0"><?php echo $pendingStage3Count; ?></h2>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/modules/executive/approvals.php" class="btn btn-primary font-weight-bold btn-sm">
                            View Requests <i class="ti-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Leave Entitlement Summary Cards -->
<h5 class="font-weight-bold mb-3 text-secondary"><i class="ti-pie-chart"></i> Your Leave Balances (<?php echo date('Y'); ?>)</h5>
<div class="row mb-4">
    <?php if (empty($entitlements)): ?>
        <div class="col-12">
            <div class="alert alert-warning">No leave entitlements allocated for your account for year <?php echo date('Y'); ?>.</div>
        </div>
    <?php else: ?>
        <?php foreach ($entitlements as $ent): 
            $available = (float)$ent['total_days'] - (float)$ent['used_days'] - (float)$ent['pending_days'];
        ?>
        <div class="col-md-3 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="font-weight-bold text-dark"><?php echo htmlspecialchars($ent['leave_name']); ?></h6>
                    <div class="d-flex justify-content-between align-items-baseline mt-2">
                        <span class="h3 font-weight-bold text-primary mb-0"><?php echo number_format($available, 1); ?></span>
                        <span class="small text-muted">Days Available</span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Total: <?php echo number_format($ent['total_days'], 1); ?></span>
                        <span>Used: <?php echo number_format($ent['used_days'], 1); ?></span>
                        <span>Pending: <?php echo number_format($ent['pending_days'], 1); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- My Recent Applications Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="font-weight-bold"><i class="ti-list"></i> My Recent Leave Applications</span>
        <a href="<?php echo APP_URL; ?>/modules/leave/my_history.php" class="btn btn-sm btn-outline-primary">View All History</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Application No</th>
                        <th>Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($myApplications)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">You have not submitted any leave applications yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($myApplications as $app): ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo htmlspecialchars($app['application_no']); ?></td>
                            <td><?php echo htmlspecialchars($app['leave_name']); ?></td>
                            <td><?php echo htmlspecialchars($app['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($app['end_date']); ?></td>
                            <td><span class="badge badge-light"><?php echo number_format($app['total_days'], 1); ?> Days</span></td>
                            <td><?php echo get_status_badge($app['status']); ?></td>
                            <td>
                                <a href="<?php echo APP_URL; ?>/modules/leave/my_history.php?view=<?php echo $app['id']; ?>" class="btn btn-xs btn-outline-info">
                                    Details
                                </a>
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
$pageTitle = 'Dashboard | ' . APP_NAME;
$pageHeading = 'Welcome back, ' . ($_SESSION['user_name'] ?? '');
$pageSubtitle = 'Overview of your leave entitlements and approval queues for ' . date('Y') . '.';
$pageActions = '<a href="' . APP_URL . '/modules/leave/apply.php" class="btn btn-light font-weight-bold">'
             . '<i class="ti-plus"></i> Apply for Leave</a>';
require_once __DIR__ . '/../../includes/layout.php';
?>
