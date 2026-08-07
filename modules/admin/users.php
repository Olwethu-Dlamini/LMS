<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'create';

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif ($action === 'create') {
        $empId = sanitize($_POST['emp_id'] ?? '');
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 0);
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;

        if (empty($empId) || empty($firstName) || empty($email) || empty($password) || $roleId <= 0) {
            $error = 'Please fill in all mandatory fields.';
        } else {
            try {
                $pwdHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("
                    INSERT INTO users (emp_id, first_name, last_name, email, password_hash, role_id, department_id, manager_id, status)
                    VALUES (:emp_id, :fn, :ln, :email, :pwd, :role_id, :dept_id, :mgr_id, 'active')
                ");
                $stmt->execute([
                    'emp_id' => $empId,
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'email' => $email,
                    'pwd' => $pwdHash,
                    'role_id' => $roleId,
                    'dept_id' => $deptId,
                    'mgr_id' => $managerId
                ]);
                $newUserId = (int)$db->lastInsertId();

                // Auto-seed default leave entitlements for current year
                $year = (int)date('Y');
                $stmtTypes = $db->query("SELECT id, max_days_per_year FROM leave_types");
                $stmtEntSeed = $db->prepare("
                    INSERT INTO leave_entitlements (user_id, leave_type_id, year, total_days, used_days, pending_days)
                    VALUES (:user_id, :type_id, :year, :total_days, 0, 0)
                    ON DUPLICATE KEY UPDATE total_days = VALUES(total_days)
                ");
                while ($lt = $stmtTypes->fetch()) {
                    $stmtEntSeed->execute([
                        'user_id' => $newUserId,
                        'type_id' => $lt['id'],
                        'year' => $year,
                        'total_days' => $lt['max_days_per_year']
                    ]);
                }

                set_flash('success', "User account {$email} created and default leave entitlements seeded!");
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error creating user: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        $editUserId = (int)($_POST['user_id'] ?? 0);
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($editUserId <= 0 || empty($firstName) || empty($email) || $roleId <= 0) {
            $error = 'Please fill in all mandatory fields for user edit.';
        } else {
            try {
                $stmtEdit = $db->prepare("
                    UPDATE users 
                    SET first_name = :fn, last_name = :ln, email = :email, role_id = :role_id, 
                        department_id = :dept_id, manager_id = :mgr_id, status = :status
                    WHERE id = :id
                ");
                $stmtEdit->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'email' => $email,
                    'role_id' => $roleId,
                    'dept_id' => $deptId,
                    'mgr_id' => $managerId,
                    'status' => $status,
                    'id' => $editUserId
                ]);
                set_flash('success', "User account updated successfully!");
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error updating user: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset_password') {
        $resetUserId = (int)($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';

        if ($resetUserId <= 0 || empty($newPassword)) {
            $error = 'Please enter a valid new password.';
        } else {
            try {
                $pwdHash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmtPwd = $db->prepare("UPDATE users SET password_hash = :pwd WHERE id = :id");
                $stmtPwd->execute(['pwd' => $pwdHash, 'id' => $resetUserId]);
                set_flash('success', "User password reset successfully!");
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error resetting password: ' . $e->getMessage();
            }
        }
    }
}

// Fetch Users List
$stmtUsers = $db->query("
    SELECT u.*, r.name as role_name, d.name as dept_name, CONCAT(m.first_name, ' ', m.last_name) as manager_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN users m ON u.manager_id = m.id
    ORDER BY u.created_at DESC
");
$usersList = $stmtUsers->fetchAll();

$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$depts = $db->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-user text-primary"></i> User & Role Management</h3>
        <p class="text-muted mb-0">Create accounts, assign user roles, and define reporting managers.</p>
    </div>
    <button type="button" class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#newUserModal">
        <i class="ti-plus"></i> Create New User
    </button>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- New User Modal -->
<div class="modal fade" id="newUserModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Create User Account</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Employee ID *</label>
                            <input type="text" name="emp_id" class="form-control" placeholder="EMP-1006" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Email Address *</label>
                            <input type="email" name="email" class="form-control" placeholder="name@lms.com" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Assigned Role *</label>
                            <select name="role_id" class="form-control" required>
                                <option value="">-- Select Role --</option>
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo strtoupper($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Department</label>
                            <select name="department_id" class="form-control">
                                <option value="">-- None --</option>
                                <?php foreach ($depts as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Line Manager</label>
                            <select name="manager_id" class="form-control">
                                <option value="">-- None --</option>
                                <?php foreach ($usersList as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . strtoupper($u['role_name']) . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark">System User Accounts</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>EMP ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Reporting Manager</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usersList as $u): ?>
                    <tr>
                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($u['emp_id']); ?></td>
                        <td><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><span class="badge badge-info"><?php echo strtoupper($u['role_name']); ?></span></td>
                        <td><?php echo htmlspecialchars($u['dept_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($u['manager_name'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if (($u['status'] ?? 'active') === 'active'): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold mr-1" data-toggle="modal" data-target="#editModal<?php echo $u['id']; ?>">
                                <i class="ti-pencil"></i> Edit
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-warning font-weight-bold" data-toggle="modal" data-target="#pwdModal<?php echo $u['id']; ?>">
                                <i class="ti-key"></i> Password
                            </button>

                            <!-- Edit User Modal -->
                            <div class="modal fade" id="editModal<?php echo $u['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog modal-lg" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title font-weight-bold">Edit Account: <?php echo htmlspecialchars($u['emp_id']); ?></h5>
                                                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="row">
                                                    <div class="col-md-6 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">First Name *</label>
                                                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($u['first_name']); ?>" required>
                                                    </div>
                                                    <div class="col-md-6 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Last Name *</label>
                                                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($u['last_name']); ?>" required>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-6 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Email Address *</label>
                                                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($u['email']); ?>" required>
                                                    </div>
                                                    <div class="col-md-6 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Assigned Role *</label>
                                                        <select name="role_id" class="form-control" required>
                                                            <?php foreach ($roles as $r): ?>
                                                                <option value="<?php echo $r['id']; ?>" <?php echo $u['role_id'] == $r['id'] ? 'selected' : ''; ?>><?php echo strtoupper($r['name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="row">
                                                    <div class="col-md-4 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Department</label>
                                                        <select name="department_id" class="form-control">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($depts as $d): ?>
                                                                <option value="<?php echo $d['id']; ?>" <?php echo $u['department_id'] == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Line Manager</label>
                                                        <select name="manager_id" class="form-control">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($usersList as $mgr): if ($mgr['id'] == $u['id']) continue; ?>
                                                                <option value="<?php echo $mgr['id']; ?>" <?php echo $u['manager_id'] == $mgr['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($mgr['first_name'] . ' ' . $mgr['last_name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Account Status</label>
                                                        <select name="status" class="form-control">
                                                            <option value="active" <?php echo ($u['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                                            <option value="inactive" <?php echo ($u['status'] ?? 'active') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Password Reset Modal -->
                            <div class="modal fade" id="pwdModal<?php echo $u['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            
                                            <div class="modal-header bg-warning text-dark">
                                                <h5 class="modal-title font-weight-bold">Reset Password: <?php echo htmlspecialchars($u['emp_id']); ?></h5>
                                                <button type="button" class="close text-dark" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group mb-3">
                                                    <label class="font-weight-bold text-dark">New Password *</label>
                                                    <input type="password" name="new_password" class="form-control" placeholder="Enter new strong password" required>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-warning font-weight-bold text-dark">Update Password</button>
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
$pageTitle = 'User Management | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
