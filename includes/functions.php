<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Sanitize User Input for XSS Prevention
 */
function sanitize(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF Token
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF Token
 */
function verify_csrf_token(?string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

/**
 * Check if user is logged in. Also holds users with a pending forced password
 * change on the change-password screen until they set a new one.
 */
function check_auth(): void {
    if (!isset($_SESSION['user_id'])) {
        set_flash('error', 'Please log in to access the system.');
        header('Location: ' . APP_URL . '/modules/auth/login.php');
        exit;
    }
    enforce_password_change();
}

/**
 * Redirect users flagged must_change_password to the change-password screen.
 * Skipped on the change-password and logout pages so there is no redirect loop.
 */
function enforce_password_change(): void {
    if (empty($_SESSION['must_change_password'])) {
        return;
    }
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['change_password.php', 'logout.php'], true)) {
        return;
    }
    header('Location: ' . APP_URL . '/modules/auth/change_password.php');
    exit;
}

/**
 * Validate a new password against the minimum policy.
 * Returns a list of failures; empty means the password is acceptable.
 */
function password_policy_errors(string $password): array {
    $errors = [];
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must include at least one number.';
    }
    return $errors;
}

/**
 * Is the signed-in user a system administrator?
 */
function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === ROLE_ADMIN;
}

/**
 * Check if active user has a specific role or list of roles.
 *
 * Admin retains a break-glass override on approval and policy checks, which is
 * why $allowAdmin defaults to true. Pass false for anything that decides what a
 * user *sees* - navigation, staff-portal entry, leave entitlement - so the admin
 * console stays the admin's only home instead of admin inheriting every screen.
 */
function has_role($roles, bool $allowAdmin = true): bool {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    $allowed = is_array($roles) ? $roles : [$roles];
    if (in_array($_SESSION['user_role'], $allowed, true)) {
        return true;
    }
    return $allowAdmin && is_admin();
}

/**
 * Enforce minimum role access
 */
function require_role($roles, bool $allowAdmin = true): void {
    check_auth();
    if (!has_role($roles, $allowAdmin)) {
        set_flash('error', 'Access Denied: You do not have permission for this section.');
        header('Location: ' . landing_url());
        exit;
    }
}

/**
 * Where a signed-in user belongs after login. Admins land in the console; every
 * other role lands on the staff dashboard.
 */
function landing_url(): string {
    return APP_URL . (is_admin()
        ? '/modules/admin/index.php'
        : '/modules/dashboard/index.php');
}

/**
 * Guard the staff portal. Admins hold no leave entitlement and do not sit in the
 * approval chain, so they are sent to the admin console rather than shown leave
 * screens that do not apply to them. Their break-glass approval override lives
 * under Leave Oversight in the console navigation.
 */
function require_staff(): void {
    check_auth();
    if (is_admin()) {
        header('Location: ' . landing_url());
        exit;
    }
}

/**
 * SQL condition limiting leave applications to the people a line manager is
 * accountable for: their own direct reports, plus everybody in a department they
 * are the designated line manager of. The second half is what lets one manager
 * head more than one department and see every request from all of them.
 *
 * The query using this must expose `users u` and a joined `departments d`.
 * Pair it with manager_scope_params(); the two placeholders are deliberately
 * distinct because native (non-emulated) prepares reject a named placeholder
 * that appears twice.
 */
function manager_scope_clause(): string {
    return '(u.manager_id = :scope_manager_id OR d.line_manager_id = :scope_dept_manager_id)';
}

/**
 * Bound values for manager_scope_clause().
 */
function manager_scope_params(int $managerId): array {
    return [
        'scope_manager_id'      => $managerId,
        'scope_dept_manager_id' => $managerId,
    ];
}

/**
 * Departments whose leave calendar a user may look at.
 *
 * Employees see the department they belong to and nothing else. A manager also
 * sees every department they head and every department their direct reports sit
 * in, so their coverage view matches their approval queue. HR, executives and
 * administrators oversee the whole organisation, signalled by an empty array
 * meaning "no restriction" - callers treat that as all departments.
 *
 * @return array{0:bool,1:int[]} [isUnrestricted, departmentIds]
 */
function visible_department_ids(PDO $db, int $userId, string $role, ?int $ownDepartmentId): array {
    if (in_array($role, [ROLE_HR, ROLE_EXECUTIVE, ROLE_ADMIN], true)) {
        return [true, []];
    }

    $ids = [];
    if ($ownDepartmentId !== null) {
        $ids[] = (int)$ownDepartmentId;
    }

    if ($role === ROLE_MANAGER) {
        $stmt = $db->prepare("
            SELECT d.id
            FROM departments d
            WHERE d.line_manager_id = :head_id
            UNION
            SELECT u.department_id
            FROM users u
            WHERE u.manager_id = :report_of_id AND u.department_id IS NOT NULL
        ");
        $stmt->execute(['head_id' => $userId, 'report_of_id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $deptId) {
            $ids[] = (int)$deptId;
        }
    }

    return [false, array_values(array_unique($ids))];
}

/**
 * Next employee ID in the EMP-#### sequence. Split into a pure helper so the
 * numbering rule is testable without a database.
 */
function format_emp_id(int $sequence): string {
    return 'EMP-' . $sequence;
}

/**
 * Sequence numbers start at 1001 so a fresh install matches the seeded accounts.
 */
function next_emp_sequence(?int $highestExisting): int {
    return max(1000, (int)$highestExisting) + 1;
}

/**
 * Read the highest existing EMP-#### number and return the next ID. Only IDs in
 * the canonical format are considered, so hand-entered legacy IDs never make the
 * sequence jump or collide.
 */
function next_emp_id(PDO $db): string {
    $highest = $db->query("
        SELECT MAX(CAST(SUBSTRING(emp_id, 5) AS UNSIGNED))
        FROM users
        WHERE emp_id REGEXP '^EMP-[0-9]+$'
    ")->fetchColumn();
    return format_emp_id(next_emp_sequence($highest === null || $highest === false ? null : (int)$highest));
}

/**
 * Set Flash Alert Message
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'danger', 'info', 'warning'
        'message' => $message
    ];
}

/**
 * Render & Clear Flash Alert Message
 */
function display_flash(): string {
    if (isset($_SESSION['flash'])) {
        $type = $_SESSION['flash']['type'] === 'error' ? 'danger' : $_SESSION['flash']['type'];
        $msg = $_SESSION['flash']['message'];
        unset($_SESSION['flash']);
        return '<div class="alert alert-' . $type . ' alert-dismissible fade show my-3" role="alert">
                    ' . htmlspecialchars($msg) . '
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>';
    }
    return '';
}

/**
 * Human-readable name of the stage an application is waiting on.
 */
function pending_stage_label(string $status): string {
    switch ($status) {
        case STATUS_PENDING_MANAGER:
            return 'Stage 1 (Line Manager)';
        case STATUS_PENDING_HR:
            return 'Stage 2 (HR Review)';
        case STATUS_PENDING_EXECUTIVE:
            return 'Stage 3 (Executive Sign-Off)';
        default:
            return 'review';
    }
}

/**
 * Message describing where an approval left the application. Driven by the
 * status the workflow actually returned, because the stage after HR depends on
 * the applicant's role - HR sign-off is final on an executive's own leave.
 */
function stage_transition_message(string $newStatus): string {
    switch ($newStatus) {
        case STATUS_PENDING_HR:
            return 'Approved. Transferred to Stage 2 (HR Review).';
        case STATUS_PENDING_EXECUTIVE:
            return 'Approved. Transferred to Stage 3 (Executive Sign-Off).';
        case STATUS_APPROVED:
            return 'Fully approved. The leave balance has been deducted.';
        default:
            return 'Application updated.';
    }
}

/**
 * Format Status Badges
 */
function get_status_badge(string $status): string {
    switch ($status) {
        case STATUS_PENDING_MANAGER:
            return '<span class="badge badge-warning text-dark"><i class="ti-time"></i> Pending Line Manager</span>';
        case STATUS_PENDING_HR:
            return '<span class="badge badge-info"><i class="ti-time"></i> Pending HR Review</span>';
        case STATUS_PENDING_EXECUTIVE:
            return '<span class="badge badge-primary"><i class="ti-time"></i> Pending Executive Approval</span>';
        case STATUS_APPROVED:
            return '<span class="badge badge-success"><i class="ti-check"></i> Approved</span>';
        case STATUS_REJECTED:
            return '<span class="badge badge-danger"><i class="ti-close"></i> Rejected</span>';
        case STATUS_CANCELLED:
            return '<span class="badge badge-secondary"><i class="ti-na"></i> Cancelled</span>';
        default:
            return '<span class="badge badge-light">' . htmlspecialchars($status) . '</span>';
    }
}
