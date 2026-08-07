<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role([ROLE_HR, ROLE_EXECUTIVE, ROLE_ADMIN]);

$db = getDBConnection();

// Extract filter parameters
$deptId = !empty($_GET['department_id']) ? (int)$_GET['department_id'] : null;
$status = !empty($_GET['status']) ? sanitize($_GET['status']) : null;
$fromDate = !empty($_GET['from_date']) ? sanitize($_GET['from_date']) : null;
$toDate = !empty($_GET['to_date']) ? sanitize($_GET['to_date']) : null;

// Build dynamic WHERE clause
$where = ["1=1"];
$params = [];

if ($deptId) {
    $where[] = "u.department_id = :dept_id";
    $params['dept_id'] = $deptId;
}
if ($status) {
    $where[] = "a.status = :status";
    $params['status'] = $status;
}
if ($fromDate) {
    $where[] = "a.start_date >= :from_date";
    $params['from_date'] = $fromDate;
}
if ($toDate) {
    $where[] = "a.end_date <= :to_date";
    $params['to_date'] = $toDate;
}

$whereClause = "WHERE " . implode(" AND ", $where);

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leave_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Application No', 'Employee ID', 'Employee Name', 'Department', 'Leave Type', 'Start Date', 'End Date', 'Working Days', 'Status', 'Submitted At']);

    $stmtExp = $db->prepare("
        SELECT a.application_no, u.emp_id, CONCAT(u.first_name, ' ', u.last_name) as emp_name, d.name as dept_name, t.name as leave_name, a.start_date, a.end_date, a.total_days, a.status, a.created_at
        FROM leave_applications a
        JOIN users u ON a.user_id = u.id
        JOIN leave_types t ON a.leave_type_id = t.id
        LEFT JOIN departments d ON u.department_id = d.id
        $whereClause
        ORDER BY a.created_at DESC
    ");
    $stmtExp->execute($params);
    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Fetch report data
$stmtReports = $db->prepare("
    SELECT a.*, t.name as leave_name, u.first_name, u.last_name, u.emp_id, d.name as dept_name
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    JOIN users u ON a.user_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    $whereClause
    ORDER BY a.created_at DESC
");
$stmtReports->execute($params);
$allApps = $stmtReports->fetchAll();

$depts = $db->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll();

// Build queryString for export link
$exportQuery = http_build_query(array_merge($_GET, ['export' => 'csv']));

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-files text-success"></i> Leave Reports & Analytics</h3>
        <p class="text-muted mb-0">Company-wide audit reporting and filtered payroll CSV export.</p>
    </div>
    <a href="?<?php echo $exportQuery; ?>" class="btn btn-success font-weight-bold">
        <i class="ti-download"></i> Export Filtered CSV Report
    </a>
</div>

<!-- Filter Control Panel -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <span class="font-weight-bold text-dark"><i class="ti-filter"></i> Filter Audit Records</span>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row">
            <div class="col-md-3 form-group mb-2">
                <label class="small font-weight-bold text-dark">Department</label>
                <select name="department_id" class="form-control form-control-sm">
                    <option value="">-- All Departments --</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $deptId == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 form-group mb-2">
                <label class="small font-weight-bold text-dark">Status</label>
                <select name="status" class="form-control form-control-sm">
                    <option value="">-- All Statuses --</option>
                    <option value="pending_manager" <?php echo $status === 'pending_manager' ? 'selected' : ''; ?>>Pending Line Manager</option>
                    <option value="pending_hr" <?php echo $status === 'pending_hr' ? 'selected' : ''; ?>>Pending HR</option>
                    <option value="pending_executive" <?php echo $status === 'pending_executive' ? 'selected' : ''; ?>>Pending Executive</option>
                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2 form-group mb-2">
                <label class="small font-weight-bold text-dark">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($fromDate ?? ''); ?>">
            </div>
            <div class="col-md-2 form-group mb-2">
                <label class="small font-weight-bold text-dark">To Date</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($toDate ?? ''); ?>">
            </div>
            <div class="col-md-2 form-group mb-2 d-flex align-items-end">
                <button type="submit" class="btn btn-sm btn-primary font-weight-bold btn-block mr-1">Filter</button>
                <a href="<?php echo APP_URL; ?>/modules/hr/reports.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="font-weight-bold text-dark">Master Leave Audit Records (<?php echo count($allApps); ?> Records)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>App No</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Duration</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allApps)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No leave records match the specified filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($allApps as $app): ?>
                        <tr>
                            <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($app['application_no']); ?></td>
                            <td><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name'] . ' (' . $app['emp_id'] . ')'); ?></td>
                            <td><?php echo htmlspecialchars($app['dept_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($app['leave_name']); ?></td>
                            <td><?php echo htmlspecialchars($app['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($app['end_date']); ?></td>
                            <td><span class="badge badge-light border"><?php echo number_format($app['total_days'], 1); ?> Days</span></td>
                            <td><?php echo get_status_badge($app['status']); ?></td>
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
$pageTitle = 'Leave Reports | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
