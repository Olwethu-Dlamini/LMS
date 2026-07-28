<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role([ROLE_HR, ROLE_EXECUTIVE, ROLE_ADMIN]);

$db = getDBConnection();

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leave_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Application No', 'Employee ID', 'Employee Name', 'Department', 'Leave Type', 'Start Date', 'End Date', 'Working Days', 'Status', 'Submitted At']);

    $stmtExp = $db->query("
        SELECT a.application_no, u.emp_id, CONCAT(u.first_name, ' ', u.last_name) as emp_name, d.name as dept_name, t.name as leave_name, a.start_date, a.end_date, a.total_days, a.status, a.created_at
        FROM leave_applications a
        JOIN users u ON a.user_id = u.id
        JOIN leave_types t ON a.leave_type_id = t.id
        LEFT JOIN departments d ON u.department_id = d.id
        ORDER BY a.created_at DESC
    ");
    while ($row = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

$stmtReports = $db->query("
    SELECT a.*, t.name as leave_name, u.first_name, u.last_name, u.emp_id, d.name as dept_name
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    JOIN users u ON a.user_id = u.id
    LEFT JOIN departments d ON u.department_id = d.id
    ORDER BY a.created_at DESC
");
$allApps = $stmtReports->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-files text-success"></i> Leave Reports & Analytics</h3>
        <p class="text-muted mb-0">Company-wide audit reporting and payroll CSV export.</p>
    </div>
    <a href="?export=csv" class="btn btn-success font-weight-bold">
        <i class="ti-download"></i> Export Payroll Report (CSV)
    </a>
</div>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark">Master Leave Audit Records</span>
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
                    <?php foreach ($allApps as $app): ?>
                    <tr>
                        <td class="font-weight-bold"><?php echo htmlspecialchars($app['application_no']); ?></td>
                        <td><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name'] . ' (' . $app['emp_id'] . ')'); ?></td>
                        <td><?php echo htmlspecialchars($app['dept_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($app['leave_name']); ?></td>
                        <td><?php echo htmlspecialchars($app['start_date']); ?></td>
                        <td><?php echo htmlspecialchars($app['end_date']); ?></td>
                        <td><span class="badge badge-light border"><?php echo number_format($app['total_days'], 1); ?> Days</span></td>
                        <td><?php echo get_status_badge($app['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
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
