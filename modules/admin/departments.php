<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? 'create';
    $name      = sanitize($_POST['name'] ?? '');
    $managerId = !empty($_POST['line_manager_id']) ? (int)$_POST['line_manager_id'] : null;
    $deptId    = (int)($_POST['dept_id'] ?? 0);

    // Blank means "no limit", which is different from zero: zero would say
    // nobody may ever be away. Both are accepted, an empty box is not treated
    // as a mistake.
    $rawLimit = trim((string)($_POST['max_concurrent_absences'] ?? ''));
    $maxAway  = $rawLimit === '' ? null : (int)$rawLimit;
    $limitInvalid = $rawLimit !== '' && (!ctype_digit($rawLimit) || $maxAway < 0);

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($limitInvalid && in_array($action, ['create', 'edit'], true)) {
        $error = 'The concurrent absence limit must be a whole number of people, or left blank for no limit.';
    } elseif ($action === 'create') {
        if ($name === '') {
            $error = 'Department name cannot be empty.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO departments (name, line_manager_id, max_concurrent_absences)
                VALUES (:name, :mgr_id, :max_away)
            ");
            $stmt->execute(['name' => $name, 'mgr_id' => $managerId, 'max_away' => $maxAway]);
            set_flash('success', "Department '{$name}' created.");
            header('Location: ' . APP_URL . '/modules/admin/departments.php');
            exit;
        }
    } elseif ($action === 'edit') {
        if ($deptId <= 0 || $name === '') {
            $error = 'Please provide a valid department name.';
        } else {
            $stmt = $db->prepare("
                UPDATE departments
                SET name = :name, line_manager_id = :mgr_id, max_concurrent_absences = :max_away
                WHERE id = :id
            ");
            $stmt->execute(['name' => $name, 'mgr_id' => $managerId, 'max_away' => $maxAway, 'id' => $deptId]);
            set_flash('success', "Department '{$name}' updated.");
            header('Location: ' . APP_URL . '/modules/admin/departments.php');
            exit;
        }
    } elseif ($action === 'delete') {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id = :id");
        $countStmt->execute(['id' => $deptId]);
        $members = (int)$countStmt->fetchColumn();

        if ($deptId <= 0) {
            $error = 'Invalid department.';
        } elseif ($members > 0) {
            $error = "That department still has {$members} member(s). Move them to another department first.";
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM departments WHERE id = :id");
                $stmt->execute(['id' => $deptId]);
                set_flash('success', 'Department deleted.');
                header('Location: ' . APP_URL . '/modules/admin/departments.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error deleting department: ' . $e->getMessage();
            }
        }
    }
}

$depts = $db->query("
    SELECT d.*,
           CONCAT(u.first_name, ' ', u.last_name) AS manager_name,
           (SELECT COUNT(*) FROM users m WHERE m.department_id = d.id) AS member_count
    FROM departments d
    LEFT JOIN users u ON d.line_manager_id = u.id
    ORDER BY d.name ASC
")->fetchAll();

$managers = $db->query("
    SELECT id, first_name, last_name, emp_id
    FROM users
    WHERE role_id IN (2, 3, 4, 5) AND status = 'active'
    ORDER BY first_name ASC
")->fetchAll();

ob_start();
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Create -->
<div class="modal fade" id="newDeptModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Create Department</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label>Department Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Finance &amp; Operations" required>
                    </div>
                    <div class="form-group mb-3">
                        <label>Designated Line Manager</label>
                        <select name="line_manager_id" class="form-control">
                            <option value="">-- None --</option>
                            <?php foreach ($managers as $m): ?>
                                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['emp_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-0">
                        <label>Maximum Away At Once</label>
                        <input type="number" name="max_concurrent_absences" class="form-control" min="0" step="1"
                               placeholder="Leave blank for no limit">
                        <small class="form-text text-muted">
                            How many members may be on leave on the same working day. The team calendar
                            turns amber on days that reach this number and red on days that pass it, and
                            approvers are warned before they sign off. Blank means no limit is enforced.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="ti-layout-grid2"></i> Departments (<?php echo count($depts); ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Department</th>
                        <th>Designated Line Manager</th>
                        <th>Members</th>
                        <th>Max Away At Once</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($depts as $d): ?>
                    <tr>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($d['name']); ?></td>
                        <td><?php echo $d['manager_name'] !== null
                                ? htmlspecialchars($d['manager_name'])
                                : '<span class="badge badge-warning">Unassigned</span>'; ?></td>
                        <td><span class="badge badge-light"><?php echo (int)$d['member_count']; ?></span></td>
                        <td>
                            <?php if ($d['max_concurrent_absences'] === null): ?>
                                <span class="text-muted small">No limit</span>
                            <?php else: ?>
                                <span class="badge badge-info"><?php echo (int)$d['max_concurrent_absences']; ?></span>
                                <?php if ((int)$d['max_concurrent_absences'] >= (int)$d['member_count'] && (int)$d['member_count'] > 0): ?>
                                    <small class="d-block text-muted">
                                        Cannot be exceeded &mdash; it is not below the headcount
                                    </small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="ri-actions">
                            <button type="button" class="btn btn-xs btn-outline-primary mb-1"
                                    data-toggle="modal" data-target="#editDept<?php echo (int)$d['id']; ?>">
                                <i class="ti-pencil"></i> Edit
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-danger mb-1"
                                    data-toggle="modal" data-target="#delDept<?php echo (int)$d['id']; ?>">
                                <i class="ti-trash"></i> Delete
                            </button>

                            <div class="modal fade text-left" id="editDept<?php echo (int)$d['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="dept_id" value="<?php echo (int)$d['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Department</h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group mb-3">
                                                    <label>Department Name *</label>
                                                    <input type="text" name="name" class="form-control" required
                                                           value="<?php echo htmlspecialchars($d['name']); ?>">
                                                </div>
                                                <div class="form-group mb-3">
                                                    <label>Designated Line Manager</label>
                                                    <select name="line_manager_id" class="form-control">
                                                        <option value="">-- None --</option>
                                                        <?php foreach ($managers as $m): ?>
                                                            <option value="<?php echo (int)$m['id']; ?>"
                                                                <?php echo (int)$d['line_manager_id'] === (int)$m['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name'] . ' (' . $m['emp_id'] . ')'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="form-group mb-0">
                                                    <label>Maximum Away At Once</label>
                                                    <input type="number" name="max_concurrent_absences" class="form-control"
                                                           min="0" step="1" placeholder="Leave blank for no limit"
                                                           value="<?php echo $d['max_concurrent_absences'] === null
                                                               ? ''
                                                               : (int)$d['max_concurrent_absences']; ?>">
                                                    <small class="form-text text-muted">
                                                        Currently <?php echo (int)$d['member_count']; ?> member(s) in this
                                                        department. Blank means no limit is enforced.
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade text-left" id="delDept<?php echo (int)$d['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="dept_id" value="<?php echo (int)$d['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Delete <?php echo htmlspecialchars($d['name']); ?>?</h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if ((int)$d['member_count'] > 0): ?>
                                                    <div class="alert alert-warning mb-0">
                                                        This department has <strong><?php echo (int)$d['member_count']; ?></strong>
                                                        member(s). Reassign them in User Management before deleting it.
                                                    </div>
                                                <?php else: ?>
                                                    <p class="mb-0">This department has no members and can be removed.</p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger font-weight-bold"
                                                    <?php echo (int)$d['member_count'] > 0 ? 'disabled' : ''; ?>>
                                                    Delete Department
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
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
$pageHeading = 'Department Management';
$pageSubtitle = 'Define organisational units and assign head managers.';
$pageIcon = 'ti-layout-grid2';
$pageActions = '<button type="button" class="btn btn-light font-weight-bold" data-toggle="modal" data-target="#newDeptModal">'
             . '<i class="ti-plus"></i> Add Department</button>';
require_once __DIR__ . '/../../includes/admin_layout.php';
?>
