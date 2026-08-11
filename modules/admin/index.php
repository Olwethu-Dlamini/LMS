<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db   = getDBConnection();
$year = (int)date('Y');

$one = function (string $sql, array $params = []) use ($db) {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
};

$totalUsers    = $one("SELECT COUNT(*) FROM users");
$activeUsers   = $one("SELECT COUNT(*) FROM users WHERE status = 'active'");
$archivedUsers = $one("SELECT COUNT(*) FROM users WHERE status = 'inactive'");
$pendingFirst  = $one("SELECT COUNT(*) FROM users WHERE must_change_password = 1");
$noManager     = $one("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                       WHERE u.manager_id IS NULL AND u.status = 'active' AND r.name = 'employee'");
$noDept        = $one("SELECT COUNT(*) FROM users WHERE department_id IS NULL AND status = 'active'");

$deptCount     = $one("SELECT COUNT(*) FROM departments");
$typesActive   = $one("SELECT COUNT(*) FROM leave_types WHERE is_active = 1");
$typesRetired  = $one("SELECT COUNT(*) FROM leave_types WHERE is_active = 0");
$holidayCount  = $one("SELECT COUNT(*) FROM holidays WHERE YEAR(holiday_date) = :yr", ['yr' => $year]);

$appsTotal     = $one("SELECT COUNT(*) FROM leave_applications");
$appsPending   = $one("SELECT COUNT(*) FROM leave_applications
                       WHERE status IN ('pending_manager','pending_hr','pending_executive')");
$appsApproved  = $one("SELECT COUNT(*) FROM leave_applications WHERE status = 'approved'");

$recentActions = $db->query("
    SELECT l.action, l.stage, l.action_at, l.approver_role,
           CONCAT(a.first_name, ' ', a.last_name) AS approver,
           app.application_no
    FROM leave_approval_logs l
    JOIN users a ON a.id = l.approver_id
    JOIN leave_applications app ON app.id = l.leave_application_id
    ORDER BY l.id DESC
    LIMIT 6
")->fetchAll();

ob_start();
?>

<div class="row">
    <div class="col-md-3 mb-4">
        <div class="ri-stat">
            <div class="ri-stat-label">User Accounts</div>
            <div class="ri-stat-value"><?php echo $totalUsers; ?></div>
            <div class="ri-stat-foot">
                <span><?php echo $activeUsers; ?> active</span>
                <span><?php echo $archivedUsers; ?> archived</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="ri-stat ri-stat-queue">
            <div class="ri-stat-label">Departments</div>
            <div class="ri-stat-value"><?php echo $deptCount; ?></div>
            <div class="ri-stat-foot"><span>organisational units</span></div>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="ri-stat ri-stat-queue">
            <div class="ri-stat-label">Leave Types</div>
            <div class="ri-stat-value"><?php echo $typesActive; ?></div>
            <div class="ri-stat-foot">
                <span><?php echo $typesRetired; ?> retired</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="ri-stat ri-stat-queue">
            <div class="ri-stat-label">Holidays <?php echo $year; ?></div>
            <div class="ri-stat-value"><?php echo $holidayCount; ?></div>
            <div class="ri-stat-foot"><span>excluded from calculations</span></div>
        </div>
    </div>
</div>

<?php if ($pendingFirst > 0 || $noManager > 0 || $noDept > 0 || $holidayCount === 0): ?>
<div class="card">
    <div class="card-header"><i class="ti-alert"></i> Setup Attention</div>
    <div class="card-body">
        <ul class="list-unstyled mb-0">
            <?php if ($noManager > 0): ?>
                <li class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-warning mr-2"><?php echo $noManager; ?></span>
                        active employee(s) have <strong>no reporting manager</strong> &mdash; their
                        Stage&nbsp;1 approval can only be cleared by an admin.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/users.php" class="btn btn-sm btn-outline-primary">Assign managers</a>
                </li>
            <?php endif; ?>
            <?php if ($noDept > 0): ?>
                <li class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-warning mr-2"><?php echo $noDept; ?></span>
                        active user(s) have <strong>no department</strong>, so they are missing from
                        department reporting.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/users.php" class="btn btn-sm btn-outline-primary">Assign departments</a>
                </li>
            <?php endif; ?>
            <?php if ($pendingFirst > 0): ?>
                <li class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-info mr-2"><?php echo $pendingFirst; ?></span>
                        account(s) still hold a <strong>temporary password</strong> and must set their
                        own at first sign-in.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/users.php" class="btn btn-sm btn-outline-primary">View users</a>
                </li>
            <?php endif; ?>
            <?php if ($holidayCount === 0): ?>
                <li class="mb-0 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-danger mr-2">!</span>
                        No public holidays are defined for <?php echo $year; ?>, so no dates will be
                        excluded from working-day calculations.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/holidays.php" class="btn btn-sm btn-outline-primary">Add holidays</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><i class="ti-pie-chart"></i> Leave Applications</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <tbody>
                        <tr><td>Total submitted</td><td class="text-right font-weight-bold"><?php echo $appsTotal; ?></td></tr>
                        <tr><td>Awaiting a decision</td><td class="text-right font-weight-bold"><?php echo $appsPending; ?></td></tr>
                        <tr><td>Approved</td><td class="text-right font-weight-bold"><?php echo $appsApproved; ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="ti-time"></i> Latest Approval Activity</span>
                <a href="<?php echo APP_URL; ?>/modules/admin/audit_log.php" class="btn btn-sm btn-outline-primary">Full audit log</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentActions)): ?>
                    <div class="ri-empty"><i class="ti-folder"></i>No approval activity recorded yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <tbody>
                        <?php foreach ($recentActions as $r): ?>
                            <tr>
                                <td class="font-weight-bold text-primary ri-nowrap"><?php echo htmlspecialchars($r['application_no']); ?></td>
                                <td><?php echo $r['action'] === 'approved'
                                        ? '<span class="badge badge-success"><i class="ti-check"></i> Approved</span>'
                                        : '<span class="badge badge-danger"><i class="ti-close"></i> Rejected</span>'; ?></td>
                                <td><?php echo htmlspecialchars($r['approver']); ?>
                                    <small class="d-block text-muted"><?php echo htmlspecialchars(strtoupper($r['approver_role'])); ?></small>
                                </td>
                                <td class="text-muted"><small><?php echo date('M d, H:i', strtotime($r['action_at'])); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Admin Console | ' . APP_NAME;
$pageHeading = 'Admin Console';
$pageSubtitle = 'System administration for ' . ORG_NAME . ' - users, structure and leave policy.';
$pageIcon = 'ti-settings';
require_once __DIR__ . '/../../includes/admin_layout.php';
?>
