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
$noDept        = $one("SELECT COUNT(*) FROM users WHERE department_id IS NULL AND status = 'active'");

// An approval chain only works if somebody actually holds each role. With no
// active HR account every application stalls at Stage 2; with no executive,
// at Stage 3. Both are invisible until leave starts piling up, so surface them.
$roleHolders = function (string $role) use ($one): int {
    return $one("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE r.name = :role AND u.status = 'active'", ['role' => $role]);
};
$hrCount   = $roleHolders('hr');
$execCount = $roleHolders('executive');
$mgrCount  = $roleHolders('manager');

// Employees with neither a personal line manager nor a department head have
// nobody to clear Stage 1 for them.
$noApprover = $one("SELECT COUNT(*) FROM users u
                    JOIN roles r ON r.id = u.role_id
                    LEFT JOIN departments d ON d.id = u.department_id
                    WHERE u.status = 'active' AND r.name = 'employee'
                      AND u.manager_id IS NULL
                      AND (d.line_manager_id IS NULL OR u.department_id IS NULL)");

$deptsNoHead = $one("SELECT COUNT(*) FROM departments WHERE line_manager_id IS NULL");

$chainBroken = $hrCount === 0 || $execCount === 0 || $noApprover > 0;

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
            <div class="ri-stat-foot">
                <span<?php echo $mgrCount  === 0 ? ' class="text-danger font-weight-bold"' : ''; ?>><?php echo $mgrCount; ?> mgr</span>
                <span<?php echo $hrCount   === 0 ? ' class="text-danger font-weight-bold"' : ''; ?>><?php echo $hrCount; ?> hr</span>
                <span<?php echo $execCount === 0 ? ' class="text-danger font-weight-bold"' : ''; ?>><?php echo $execCount; ?> exec</span>
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

<?php if ($chainBroken): ?>
<div class="card border-danger">
    <div class="card-header bg-danger text-white">
        <i class="ti-alert"></i> Approval chain incomplete: leave requests will stall
    </div>
    <div class="card-body">
        <ul class="list-unstyled mb-0">
            <?php if ($hrCount === 0): ?>
                <li class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-danger mr-2">!</span>
                        <strong>No active HR account.</strong> Stage&nbsp;2 has no approver, so every
                        request, including a manager's or an executive's, which enter at
                        Stage&nbsp;2, will stop there. Assign the HR role to someone.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/users.php" class="btn btn-sm btn-danger">Assign HR role</a>
                </li>
            <?php endif; ?>
            <?php if ($execCount === 0): ?>
                <li class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-danger mr-2">!</span>
                        <strong>No active executive account.</strong> Stage&nbsp;3 has no approver, so
                        nothing can reach final approval.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/users.php" class="btn btn-sm btn-danger">Assign executive role</a>
                </li>
            <?php endif; ?>
            <?php if ($noApprover > 0): ?>
                <li class="mb-0 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-danger mr-2"><?php echo $noApprover; ?></span>
                        employee(s) have <strong>no line manager and no department head</strong>, so
                        nobody can clear their Stage&nbsp;1. Give them a reporting manager, or put them
                        in a department that has one.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/users.php" class="btn btn-sm btn-danger">Fix reporting lines</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if ($pendingFirst > 0 || $noDept > 0 || $deptsNoHead > 0 || $holidayCount === 0): ?>
<div class="card">
    <div class="card-header"><i class="ti-alert"></i> Setup Attention</div>
    <div class="card-body">
        <ul class="list-unstyled mb-0">
            <?php if ($deptsNoHead > 0): ?>
                <li class="mb-3 d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <span class="badge badge-warning mr-2"><?php echo $deptsNoHead; ?></span>
                        department(s) have <strong>no designated line manager</strong>, so new users
                        placed there get no reporting manager filled in automatically.
                    </span>
                    <a href="<?php echo APP_URL; ?>/modules/admin/departments.php" class="btn btn-sm btn-outline-primary">Assign heads</a>
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
