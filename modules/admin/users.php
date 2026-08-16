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
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 0);
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;

        if (empty($firstName) || empty($email) || empty($password) || $roleId <= 0) {
            $error = 'Please fill in all mandatory fields.';
        } else {
            try {
                $pwdHash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("
                    INSERT INTO users (emp_id, first_name, last_name, email, password_hash, role_id, department_id, manager_id, status)
                    VALUES (:emp_id, :fn, :ln, :email, :pwd, :role_id, :dept_id, :mgr_id, 'active')
                ");

                // Employee IDs are assigned by the system, never typed in. Two
                // admins creating an account at the same moment would both read
                // the same highest number, so a unique-key clash on emp_id is
                // retried with a freshly read sequence rather than surfaced.
                $empId = null;
                $newUserId = 0;
                for ($attempt = 1; $attempt <= 5; $attempt++) {
                    $empId = next_emp_id($db);
                    try {
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
                        break;
                    } catch (PDOException $e) {
                        $isEmpIdClash = $e->getCode() === '23000'
                                        && stripos($e->getMessage(), 'emp_id') !== false;
                        if (!$isEmpIdClash || $attempt === 5) {
                            throw $e;
                        }
                    }
                }

                // Seed leave entitlements for the current leave year. Retired
                // leave types are skipped so archived policies are not allocated.
                $year = (int)date('Y');
                $stmtTypes = $db->query("SELECT id, max_days_per_year FROM leave_types WHERE is_active = 1");
                $stmtEntSeed = $db->prepare("
                    INSERT INTO leave_entitlements (user_id, leave_type_id, year, total_days, used_days, pending_days)
                    VALUES (:user_id, :type_id, :year, :total_days, 0, 0)
                    ON DUPLICATE KEY UPDATE total_days = VALUES(total_days)
                ");
                $seeded = 0;
                while ($lt = $stmtTypes->fetch()) {
                    $stmtEntSeed->execute([
                        'user_id' => $newUserId,
                        'type_id' => $lt['id'],
                        'year' => $year,
                        'total_days' => $lt['max_days_per_year']
                    ]);
                    $seeded++;
                }

                set_flash('success', "User account {$email} created as {$empId}. "
                    . "{$seeded} leave entitlement(s) seeded for {$year}.");
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error creating user: ' . escape_html($e->getMessage());
            }
        }
    } elseif ($action === 'edit') {
        $editUserId = (int)($_POST['user_id'] ?? 0);
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $roleId = (int)($_POST['role_id'] ?? 0);
        $deptId = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($editUserId <= 0 || empty($firstName) || empty($email) || $roleId <= 0) {
            $error = 'Please fill in all mandatory fields for user edit.';
        } else {
            try {
                // manager_id is intentionally not updated here: the form no longer
                // offers the field, so including it would blank any override on
                // every unrelated save. Stage 1 falls back to the department head.
                $stmtEdit = $db->prepare("
                    UPDATE users
                    SET first_name = :fn, last_name = :ln, email = :email, role_id = :role_id,
                        department_id = :dept_id, status = :status
                    WHERE id = :id
                ");
                $stmtEdit->execute([
                    'fn' => $firstName,
                    'ln' => $lastName,
                    'email' => $email,
                    'role_id' => $roleId,
                    'dept_id' => $deptId,
                    'status' => $status,
                    'id' => $editUserId
                ]);
                set_flash('success', "User account updated successfully!");
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error updating user: ' . escape_html($e->getMessage());
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
                // Treat an admin-issued password as temporary: the user must
                // replace it with their own on their next sign-in.
                $stmtPwd = $db->prepare("
                    UPDATE users
                    SET password_hash = :pwd, must_change_password = 1
                    WHERE id = :id
                ");
                $stmtPwd->execute(['pwd' => $pwdHash, 'id' => $resetUserId]);
                set_flash('success', "Password reset. The user must set their own password at next sign-in.");
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error resetting password: ' . escape_html($e->getMessage());
            }
        }
    } elseif ($action === 'archive' || $action === 'delete') {
        $targetId = (int)($_POST['user_id'] ?? 0);

        // How much history hangs off this account?
        $stmtCounts = $db->prepare("
            SELECT
              (SELECT COUNT(*) FROM leave_applications  WHERE user_id     = :uid_apps) AS app_count,
              (SELECT COUNT(*) FROM leave_approval_logs WHERE approver_id = :uid_logs) AS log_count,
              (SELECT COUNT(*) FROM users              WHERE manager_id  = :uid_reports) AS report_count,
              (SELECT COUNT(*) FROM departments        WHERE line_manager_id = :uid_depts) AS dept_count
        ");
        $stmtCounts->execute([
            'uid_apps'    => $targetId,
            'uid_logs'    => $targetId,
            'uid_reports' => $targetId,
            'uid_depts'   => $targetId,
        ]);
        $counts = $stmtCounts->fetch() ?: ['app_count' => 0, 'log_count' => 0, 'report_count' => 0, 'dept_count' => 0];

        $stmtTarget = $db->prepare("
            SELECT u.*, r.name AS role_name
            FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = :id
        ");
        $stmtTarget->execute(['id' => $targetId]);
        $target = $stmtTarget->fetch();

        $activeAdmins = (int)$db->query("
            SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'admin' AND u.status = 'active'
        ")->fetchColumn();

        $targetName = $target ? $target['first_name'] . ' ' . $target['last_name'] : '';
        $isLastAdmin = $target && $target['role_name'] === 'admin'
                       && $target['status'] === 'active' && $activeAdmins <= 1;

        if ($targetId <= 0 || !$target) {
            $error = 'That user account no longer exists.';
        } elseif ($targetId === (int)$_SESSION['user_id']) {
            $error = 'You cannot archive or delete the account you are signed in with.';
        } elseif ($isLastAdmin) {
            $error = 'This is the only active administrator. Promote another admin before removing this one.';
        } elseif ($action === 'archive') {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            $stmt = $db->prepare("UPDATE users SET status = :st WHERE id = :id");
            $stmt->execute(['st' => $newStatus, 'id' => $targetId]);
            set_flash('success', $newStatus === 'inactive'
                ? "{$targetName} has been archived. They can no longer sign in, and their leave history is preserved."
                : "{$targetName} has been reactivated and can sign in again.");
            header('Location: ' . APP_URL . '/modules/admin/users.php');
            exit;
        } else {
            // Hard delete only when there is genuinely nothing to lose. The FKs on
            // leave_applications and leave_approval_logs cascade, so a delete with
            // history attached would silently destroy the audit trail.
            $hasHistory = (int)$counts['app_count'] > 0 || (int)$counts['log_count'] > 0;
            if ($hasHistory) {
                $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = :id");
                $stmt->execute(['id' => $targetId]);
                set_flash('warning', sprintf(
                    "%s has %d leave application(s) and %d approval log entry(ies), so the account was archived instead of deleted. Deleting it would have erased that audit trail.",
                    $targetName, (int)$counts['app_count'], (int)$counts['log_count']
                ));
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            }
            try {
                $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute(['id' => $targetId]);
                $note = '';
                if ((int)$counts['report_count'] > 0) {
                    $note .= sprintf(' %d staff member(s) now have no reporting manager.', (int)$counts['report_count']);
                }
                if ((int)$counts['dept_count'] > 0) {
                    $note .= sprintf(' %d department(s) now have no designated line manager.', (int)$counts['dept_count']);
                }
                set_flash('success', "{$targetName} was deleted permanently." . $note);
                header('Location: ' . APP_URL . '/modules/admin/users.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error deleting user: ' . escape_html($e->getMessage());
            }
        }
    }
}

// Fetch Users List, with the history counts that decide archive vs delete
$stmtUsers = $db->query("
    SELECT u.*, r.name as role_name, d.name as dept_name,
           CONCAT(m.first_name, ' ', m.last_name) as manager_name,
           CONCAT(h.first_name, ' ', h.last_name) as dept_head_name,
           (SELECT COUNT(*) FROM leave_applications a  WHERE a.user_id     = u.id) AS app_count,
           (SELECT COUNT(*) FROM leave_approval_logs l WHERE l.approver_id = u.id) AS log_count,
           (SELECT COUNT(*) FROM users rp             WHERE rp.manager_id = u.id) AS report_count,
           (SELECT COUNT(*) FROM departments dp       WHERE dp.line_manager_id = u.id) AS heads_count
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN users m ON u.manager_id = m.id
    LEFT JOIN users h ON h.id = d.line_manager_id AND h.status = 'active'
    ORDER BY u.status ASC, r.id DESC, u.first_name ASC
");
$usersList = $stmtUsers->fetchAll();

$activeAdminCount = (int)$db->query("
    SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
    WHERE r.name = 'admin' AND u.status = 'active'
")->fetchColumn();

$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
$depts = $db->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll();

// Shown read-only on the create form. The value is re-read at insert time, so a
// stale preview in an open tab cannot produce a duplicate.
$nextEmpId = next_emp_id($db);

// Stage 1 approval accepts either the applicant's own manager_id or the head of
// their department (see ApprovalWorkflow::processAction), so a user in a
// department that has a head needs no explicit line manager. Naming one anyway
// duplicates the fact and goes stale when the department head changes, so the
// form shows who will approve and keeps the manager field as an override.
$deptHeadNames = [];
$stmtHeads = $db->query("
    SELECT d.id, CONCAT(m.first_name, ' ', m.last_name) AS head_name
    FROM departments d
    JOIN users m ON m.id = d.line_manager_id
    WHERE m.status = 'active'
");
while ($row = $stmtHeads->fetch()) {
    $deptHeadNames[(int)$row['id']] = $row['head_name'];
}

ob_start();
?>

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
                            <label class="font-weight-bold text-dark">Employee ID</label>
                            <input type="text" class="form-control bg-light" value="<?php echo htmlspecialchars($nextEmpId); ?>" readonly>
                            <small class="form-text text-muted">Assigned automatically when the account is saved.</small>
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
                            <select name="department_id" id="newUserDept" class="form-control">
                                <option value="">-- None --</option>
                                <?php foreach ($depts as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Approves their leave</label>
                            <p class="form-control-plaintext mb-1" id="newUserApprover">
                                <span class="text-muted">Select a department first.</span>
                            </p>
                            <a href="#" id="newUserOverrideToggle" class="small">Reports to someone else</a>
                            <select name="manager_id" id="newUserManager" class="form-control mt-2" style="display:none;">
                                <option value="">-- Use the department head --</option>
                                <?php foreach ($usersList as $u): ?>
                                    <?php if (($u['status'] ?? 'active') !== 'active') continue; ?>
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
                        <th>Approves Leave</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usersList as $u): ?>
                    <tr>
                        <td class="font-weight-bold text-primary ri-nowrap"><?php echo htmlspecialchars($u['emp_id']); ?></td>
                        <td><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($u['email']); ?></td>
                        <td><span class="badge badge-info"><?php echo strtoupper($u['role_name']); ?></span></td>
                        <td><?php echo htmlspecialchars($u['dept_name'] ?? 'N/A'); ?></td>
                        <td>
                            <?php
                            // Stage 1 accepts the explicit manager or the department
                            // head; senior roles skip Stage 1 altogether.
                            $skipsStage1 = in_array($u['role_name'], [ROLE_MANAGER, ROLE_HR, ROLE_EXECUTIVE], true);
                            $approver = $u['manager_name'] ?: $u['dept_head_name'];
                            ?>
                            <?php if ($u['role_name'] === ROLE_ADMIN): ?>
                                <span class="text-muted small">No leave entitlement</span>
                            <?php elseif ($skipsStage1): ?>
                                <span class="text-muted small">Skips Stage 1 &middot; HR reviews</span>
                            <?php elseif ($approver): ?>
                                <?php echo htmlspecialchars($approver); ?>
                                <?php if (!$u['manager_name']): ?>
                                    <small class="d-block text-muted">head of <?php echo htmlspecialchars($u['dept_name']); ?></small>
                                <?php else: ?>
                                    <small class="d-block text-muted">named directly</small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-danger">No approver</span>
                                <small class="d-block text-muted"><?php echo $u['dept_name'] ? 'department has no head' : 'no department set'; ?></small>
                            <?php endif; ?>
                        </td>
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
                            <button type="button" class="btn btn-xs btn-outline-warning font-weight-bold mr-1" data-toggle="modal" data-target="#pwdModal<?php echo $u['id']; ?>">
                                <i class="ti-key"></i> Password
                            </button>
                            <?php
                            $isSelf = (int)$u['id'] === (int)$_SESSION['user_id'];
                            $isOnlyAdmin = $u['role_name'] === 'admin' && $u['status'] === 'active' && $activeAdminCount <= 1;
                            $locked = $isSelf || $isOnlyAdmin;
                            $hasHistory = (int)$u['app_count'] > 0 || (int)$u['log_count'] > 0;
                            ?>
                            <?php if (!$locked): ?>
                                <button type="button" class="btn btn-xs btn-outline-secondary font-weight-bold mr-1" data-toggle="modal" data-target="#arcModal<?php echo $u['id']; ?>">
                                    <i class="ti-<?php echo $u['status'] === 'active' ? 'archive' : 'back-right'; ?>"></i>
                                    <?php echo $u['status'] === 'active' ? 'Archive' : 'Restore'; ?>
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-danger font-weight-bold" data-toggle="modal" data-target="#delModal<?php echo $u['id']; ?>">
                                    <i class="ti-trash"></i> Delete
                                </button>
                            <?php else: ?>
                                <span class="badge badge-light" title="<?php echo $isSelf ? 'This is your own account' : 'The only active administrator'; ?>">
                                    <i class="ti-lock"></i> <?php echo $isSelf ? 'Your account' : 'Only admin'; ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!$locked): ?>
                            <!-- Archive / restore -->
                            <div class="modal fade text-left" id="arcModal<?php echo $u['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">
                                                    <?php echo $u['status'] === 'active' ? 'Archive' : 'Restore'; ?>
                                                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>?
                                                </h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if ($u['status'] === 'active'): ?>
                                                    <p>They will no longer be able to sign in, and will drop out of
                                                       approver and manager dropdowns.</p>
                                                    <p class="mb-0 text-muted"><small>All leave history and audit
                                                       records are kept. You can restore the account at any time.</small></p>
                                                <?php else: ?>
                                                    <p class="mb-0">This will let them sign in again and appear in
                                                       manager and approver lists.</p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-<?php echo $u['status'] === 'active' ? 'warning' : 'success'; ?> font-weight-bold">
                                                    <?php echo $u['status'] === 'active' ? 'Archive Account' : 'Restore Account'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Delete (falls back to archive when history exists) -->
                            <div class="modal fade text-left" id="delModal<?php echo $u['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Delete <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>?</h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if ($hasHistory): ?>
                                                    <div class="alert alert-warning mb-3">
                                                        This account has
                                                        <strong><?php echo (int)$u['app_count']; ?></strong> leave application(s)
                                                        and <strong><?php echo (int)$u['log_count']; ?></strong> approval log entry(ies).
                                                    </div>
                                                    <p class="mb-0">Permanent delete is blocked to protect the audit trail.
                                                       Confirming will <strong>archive</strong> the account instead.</p>
                                                <?php else: ?>
                                                    <p>This account has no leave history, so it can be removed permanently.</p>
                                                    <?php if ((int)$u['report_count'] > 0 || (int)$u['heads_count'] > 0): ?>
                                                        <div class="alert alert-warning mb-3">
                                                            <?php if ((int)$u['report_count'] > 0): ?>
                                                                <div><strong><?php echo (int)$u['report_count']; ?></strong> staff member(s) report to them and will be left without a manager.</div>
                                                            <?php endif; ?>
                                                            <?php if ((int)$u['heads_count'] > 0): ?>
                                                                <div>They head <strong><?php echo (int)$u['heads_count']; ?></strong> department(s), which will be left unassigned.</div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="alert alert-danger mb-0">
                                                        This also removes their leave entitlement allocations and cannot be undone.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger font-weight-bold">
                                                    <?php echo $hasHistory ? 'Archive Instead' : 'Delete Permanently'; ?>
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

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
                                                    <div class="col-md-6 form-group mb-3">
                                                        <label class="font-weight-bold text-dark">Department</label>
                                                        <select name="department_id" class="form-control">
                                                            <option value="">-- None --</option>
                                                            <?php foreach ($depts as $d): ?>
                                                                <option value="<?php echo $d['id']; ?>" <?php echo $u['department_id'] == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-6 form-group mb-3">
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

<script>
// Stage 1 approval already accepts the head of the applicant's department, so
// the Line Manager field is an override for someone who reports outside it.
// Showing who will approve keeps manager_id empty in the normal case, which
// means the account follows the department if its head later changes.
document.addEventListener("DOMContentLoaded", function () {
    var deptSelect   = document.getElementById("newUserDept");
    var mgrSelect    = document.getElementById("newUserManager");
    var approverText = document.getElementById("newUserApprover");
    var overrideLink = document.getElementById("newUserOverrideToggle");
    if (!deptSelect || !mgrSelect || !approverText || !overrideLink) return;

    var deptHeads = <?php echo json_encode($deptHeadNames, JSON_UNESCAPED_SLASHES); ?>;
    var overrideShown = false;

    function showOverride(show) {
        overrideShown = show;
        mgrSelect.style.display = show ? "" : "none";
        overrideLink.textContent = show
            ? "Use the department head instead"
            : "Reports to someone else";
        if (!show) mgrSelect.value = "";
    }

    function render() {
        var head = deptHeads[deptSelect.value];
        if (!deptSelect.value) {
            approverText.innerHTML = '<span class="text-muted">Select a department first.</span>';
        } else if (head) {
            approverText.innerHTML = '<strong></strong> <span class="text-muted small">'
                + '&middot; head of this department</span>';
            approverText.querySelector("strong").textContent = head;
        } else {
            approverText.innerHTML = '<span class="text-danger">'
                + 'This department has no head, so nobody can approve Stage 1. '
                + 'Name a line manager below.</span>';
        }
        // With no department head there is no fallback, so the override is the
        // only way to give this person an approver: open it automatically.
        if (deptSelect.value && !head && !overrideShown) {
            showOverride(true);
        }
    }

    overrideLink.addEventListener("click", function (e) {
        e.preventDefault();
        showOverride(!overrideShown);
    });
    deptSelect.addEventListener("change", render);
    render();
});
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'User Management | ' . APP_NAME;
$pageHeading = 'User & Role Management';
$pageSubtitle = 'Create accounts, assign user roles, and define reporting managers.';
$pageIcon = 'ti-user';
$pageActions = '<button type="button" class="btn btn-light font-weight-bold" data-toggle="modal" data-target="#newUserModal">'
             . '<i class="ti-plus"></i> Create New User</button>';
require_once __DIR__ . '/../../includes/admin_layout.php';
?>
