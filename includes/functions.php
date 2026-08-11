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
 * Check if active user has a specific role or list of roles
 */
function has_role($roles): bool {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    $allowed = is_array($roles) ? $roles : [$roles];
    return in_array($_SESSION['user_role'], $allowed) || $_SESSION['user_role'] === ROLE_ADMIN;
}

/**
 * Enforce minimum role access
 */
function require_role($roles): void {
    check_auth();
    if (!has_role($roles)) {
        set_flash('error', 'Access Denied: You do not have permission for this section.');
        header('Location: ' . APP_URL . '/modules/dashboard/index.php');
        exit;
    }
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
