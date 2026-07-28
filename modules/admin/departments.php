<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $name = sanitize($_POST['name'] ?? '');
    $managerId = !empty($_POST['line_manager_id']) ? (int)$_POST['line_manager_id'] : null;

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif (empty($name)) {
        $error = 'Department name cannot be empty.';
    } else {
        $stmt = $db->prepare("INSERT INTO departments (name, line_manager_id) VALUES (:name, :mgr_id)");
        $stmt->execute(['name' => $name, 'mgr_id' => $managerId]);
        set_flash('success', 'Department created successfully!');
        header('Location: ' . APP_URL . '/modules/admin/departments.php');
        exit;
    }
}

$depts = $db->query("
    SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) as manager_name
    FROM departments d
    LEFT JOIN users u ON d.line_manager_id = u.id
    ORDER BY d.name ASC
")->fetchAll();

$managers = $db->query("SELECT id, first_name, last_name, emp_id FROM users WHERE role_id IN (2, 3, 4, 5)")->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-layout-grid2 text-primary"></i> Department Management</h3>
        <p class="text-muted mb-0">Define organizational units and assign head managers.</p>
    </div>
    <button type="button" class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#newDeptModal">
        <i class="ti-plus"></i> Add Department
    </button>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- New Dept Modal -->
<div class="modal fade" id="newDeptModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Create Department</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Department Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Finance & Accounting" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Designated Line Manager</label>
                        <select name="line_manager_id" class="form-control">
                            <option value="">-- None --</option>
                            <?php foreach ($managers as $m): ?>
                                <option value="<?php echo $m['id']; ?>"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['emp_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark">Departments List</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th>
                        <th>Department Name</th>
                        <th>Designated Line Manager</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($depts as $d): ?>
                    <tr>
                        <td><?php echo $d['id']; ?></td>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($d['name']); ?></td>
                        <td><?php echo htmlspecialchars($d['manager_name'] ?? 'Unassigned'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Departments | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
