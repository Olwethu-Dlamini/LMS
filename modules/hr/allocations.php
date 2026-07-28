<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role([ROLE_HR, ROLE_ADMIN]);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);
    $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
    $year = (int)($_POST['year'] ?? date('Y'));
    $totalDays = (float)($_POST['total_days'] ?? 0);

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif ($userId <= 0 || $leaveTypeId <= 0 || $totalDays < 0) {
        $error = 'Please provide valid user, leave type, and day allocation.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO leave_entitlements (user_id, leave_type_id, year, total_days)
            VALUES (:user_id, :type_id, :year, :total_days)
            ON DUPLICATE KEY UPDATE total_days = :total_days
        ");
        $stmt->execute(['user_id' => $userId, 'type_id' => $leaveTypeId, 'year' => $year, 'total_days' => $totalDays]);
        set_flash('success', 'Leave allocation updated successfully!');
        header('Location: ' . APP_URL . '/modules/hr/allocations.php');
        exit;
    }
}

// Fetch All Entitlements
$stmt = $db->query("
    SELECT e.*, u.first_name, u.last_name, u.emp_id, t.name as leave_name
    FROM leave_entitlements e
    JOIN users u ON e.user_id = u.id
    JOIN leave_types t ON e.leave_type_id = t.id
    ORDER BY u.first_name ASC, e.year DESC
");
$allocations = $stmt->fetchAll();

// Fetch Users & Leave Types for dropdown
$users = $db->query("SELECT id, emp_id, first_name, last_name FROM users ORDER BY first_name ASC")->fetchAll();
$leaveTypes = $db->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-pie-chart text-primary"></i> Leave Entitlements Allocation</h3>
        <p class="text-muted mb-0">Manage annual leave balances for all employees.</p>
    </div>
    <button type="button" class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#allocationModal">
        <i class="ti-plus"></i> Allocate / Update Entitlement
    </button>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Allocation Modal -->
<div class="modal fade" id="allocationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Leave Entitlement Allocation</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Employee</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['emp_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Leave Type</label>
                        <select name="leave_type_id" class="form-control" required>
                            <option value="">-- Select Leave Category --</option>
                            <?php foreach ($leaveTypes as $lt): ?>
                                <option value="<?php echo $lt['id']; ?>"><?php echo htmlspecialchars($lt['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Year</label>
                            <input type="number" name="year" class="form-control" value="<?php echo date('Y'); ?>" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Total Days</label>
                            <input type="number" step="0.5" name="total_days" class="form-control" placeholder="e.g. 20" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark">Current Employee Allocations</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Employee</th>
                        <th>Leave Type</th>
                        <th>Year</th>
                        <th>Total Allocated</th>
                        <th>Used Days</th>
                        <th>Pending Days</th>
                        <th>Available Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allocations as $a): 
                        $avail = (float)$a['total_days'] - (float)$a['used_days'] - (float)$a['pending_days'];
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($a['first_name'] . ' ' . $a['last_name']); ?></strong>
                            <small class="d-block text-muted"><?php echo htmlspecialchars($a['emp_id']); ?></small>
                        </td>
                        <td><?php echo htmlspecialchars($a['leave_name']); ?></td>
                        <td><?php echo $a['year']; ?></td>
                        <td><span class="badge badge-light border"><?php echo number_format($a['total_days'], 1); ?></span></td>
                        <td><span class="badge badge-warning text-dark"><?php echo number_format($a['used_days'], 1); ?></span></td>
                        <td><span class="badge badge-info"><?php echo number_format($a['pending_days'], 1); ?></span></td>
                        <td><span class="badge badge-success font-weight-bold"><?php echo number_format($avail, 1); ?> Days</span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Leave Allocations | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
