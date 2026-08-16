<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();

// Filters
$filterAction = sanitize($_GET['action_filter'] ?? '');
$filterRole   = sanitize($_GET['role'] ?? '');
$filterFrom   = sanitize($_GET['from_date'] ?? '');
$filterTo     = sanitize($_GET['to_date'] ?? '');

$where  = [];
$params = [];
if (in_array($filterAction, ['approved', 'rejected'], true)) {
    $where[] = 'l.action = :act';
    $params['act'] = $filterAction;
}
if ($filterRole !== '') {
    $where[] = 'l.approver_role = :role';
    $params['role'] = $filterRole;
}
if ($filterFrom !== '') {
    $where[] = 'DATE(l.action_at) >= :from_date';
    $params['from_date'] = $filterFrom;
}
if ($filterTo !== '') {
    $where[] = 'DATE(l.action_at) <= :to_date';
    $params['to_date'] = $filterTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $db->prepare("
    SELECT l.*,
           CONCAT(a.first_name, ' ', a.last_name) AS approver_name,
           a.emp_id AS approver_emp,
           app.application_no, app.start_date, app.end_date, app.total_days, app.status AS app_status,
           CONCAT(u.first_name, ' ', u.last_name) AS applicant_name,
           t.name AS leave_name
    FROM leave_approval_logs l
    JOIN users a ON a.id = l.approver_id
    JOIN leave_applications app ON app.id = l.leave_application_id
    JOIN users u ON u.id = app.user_id
    JOIN leave_types t ON t.id = app.leave_type_id
    $whereSql
    ORDER BY l.id DESC
    LIMIT 500
");
$stmt->execute($params);
$entries = $stmt->fetchAll();

$stageLabels = [
    'pending_manager'   => 'Stage 1 · Line Manager',
    'pending_hr'        => 'Stage 2 · HR',
    'pending_executive' => 'Stage 3 · Executive',
    'approved'          => 'Post-approval',
];

ob_start();
?>

<div class="card">
    <div class="card-header"><i class="ti-filter"></i> Filter Audit Entries</div>
    <div class="card-body">
        <form method="GET" action="" class="row align-items-end">
            <div class="col-md-3 form-group mb-2">
                <label>Action</label>
                <select name="action_filter" class="form-control">
                    <option value="">-- All Actions --</option>
                    <option value="approved" <?php echo $filterAction === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $filterAction === 'rejected' ? 'selected' : ''; ?>>Rejected / Cancelled</option>
                </select>
            </div>
            <div class="col-md-3 form-group mb-2">
                <label>Approver Role</label>
                <select name="role" class="form-control">
                    <option value="">-- All Roles --</option>
                    <?php foreach (['manager' => 'Line Manager', 'hr' => 'HR', 'executive' => 'Executive', 'admin' => 'Admin', 'employee' => 'Employee'] as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo $filterRole === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 form-group mb-2">
                <label>From</label>
                <input type="date" name="from_date" class="form-control" value="<?php echo htmlspecialchars($filterFrom); ?>">
            </div>
            <div class="col-md-2 form-group mb-2">
                <label>To</label>
                <input type="date" name="to_date" class="form-control" value="<?php echo htmlspecialchars($filterTo); ?>">
            </div>
            <div class="col-md-2 form-group mb-2">
                <button type="submit" class="btn btn-primary font-weight-bold">Filter</button>
                <a href="<?php echo APP_URL; ?>/modules/admin/audit_log.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <i class="ti-files"></i> Approval Audit Trail (<?php echo count($entries); ?><?php echo count($entries) === 500 ? '+, showing newest 500' : ''; ?>)
    </div>
    <div class="card-body p-0">
        <?php if (empty($entries)): ?>
            <div class="ri-empty"><i class="ti-folder"></i>No audit entries match the selected filters.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>When</th>
                        <th>Application</th>
                        <th>Applicant</th>
                        <th>Stage</th>
                        <th>Action</th>
                        <th>Actioned By</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $e): ?>
                    <tr>
                        <td class="ri-nowrap"><small><?php echo date('M d, Y H:i', strtotime($e['action_at'])); ?></small></td>
                        <td class="font-weight-bold text-primary ri-nowrap">
                            <?php echo htmlspecialchars($e['application_no']); ?>
                            <small class="d-block text-muted font-weight-normal"><?php echo htmlspecialchars($e['leave_name']); ?></small>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($e['applicant_name']); ?>
                            <small class="d-block text-muted"><?php echo number_format((float)$e['total_days'], 1); ?> days</small>
                        </td>
                        <td><small><?php echo htmlspecialchars($stageLabels[$e['stage']] ?? $e['stage']); ?></small></td>
                        <td>
                            <?php echo $e['action'] === 'approved'
                                ? '<span class="badge badge-success"><i class="ti-check"></i> Approved</span>'
                                : '<span class="badge badge-danger"><i class="ti-close"></i> Rejected</span>'; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($e['approver_name']); ?>
                            <small class="d-block text-muted"><?php echo htmlspecialchars($e['approver_emp'] . ' · ' . strtoupper($e['approver_role'])); ?></small>
                        </td>
                        <td><small class="text-muted"><?php echo htmlspecialchars($e['comments'] ?? 'No remarks'); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Audit Log | ' . APP_NAME;
$pageHeading = 'Approval Audit Log';
$pageSubtitle = 'Every approval and rejection recorded by the workflow engine, newest first.';
$pageIcon = 'ti-files';
require_once __DIR__ . '/../../includes/admin_layout.php';
?>
