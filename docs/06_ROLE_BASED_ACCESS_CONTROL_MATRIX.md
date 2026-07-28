# Role-Based Access Control (RBAC) Matrix
## Leave Management System (LMS)

---

## 1. Permission Matrix

The table below details permissions granted to each of the 5 roles across system modules and actions:

| Module / Feature | Action / Resource | Employee | Line Manager | HR Manager | Executive / Boss | System Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| **Authentication** | Login / Logout | ✅ | ✅ | ✅ | ✅ | ✅ |
| | Edit Own Profile | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Leave Request** | View Own Balance | ✅ | ✅ | ✅ | ✅ | ✅ |
| | Apply for Leave | ✅ | ✅ | ✅ | ✅ | ✅ |
| | Cancel Pending Request | ✅ | ✅ | ✅ | ✅ | ✅ |
| | View Own Application History | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Stage 1 Approval** | View Direct Team Requests | ❌ | ✅ | ❌ | ❌ | ✅ |
| | Approve / Reject Stage 1 | ❌ | ✅ | ❌ | ❌ | ✅ |
| **Stage 2 Approval** | View All Company Requests | ❌ | ❌ | ✅ | ❌ | ✅ |
| | Approve / Reject Stage 2 | ❌ | ❌ | ✅ | ❌ | ✅ |
| **Stage 3 Approval** | View Executive Queue | ❌ | ❌ | ❌ | ✅ | ✅ |
| | Approve / Reject Stage 3 | ❌ | ❌ | ❌ | ✅ | ✅ |
| **HR Management** | Manage Leave Allocations | ❌ | ❌ | ✅ | ❌ | ✅ |
| | Manage Holiday Calendar | ❌ | ❌ | ✅ | ❌ | ✅ |
| | Generate Payroll Reports | ❌ | ❌ | ✅ | ✅ | ✅ |
| **Administration** | Manage Users & Roles | ❌ | ❌ | ❌ | ❌ | ✅ |
| | Manage Departments | ❌ | ❌ | ❌ | ❌ | ✅ |
| | Manage Leave Types | ❌ | ❌ | ❌ | ❌ | ✅ |
| | System Audit Logs | ❌ | ❌ | ❌ | ❌ | ✅ |

---

## 2. Session Authorization Middleware Specification

All protected routes execute RBAC validation functions defined in `includes/functions.php`:

```php
function check_auth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        header("Location: /modules/auth/login.php");
        exit;
    }
}

function has_role(array|string $roles): bool {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    $allowedRoles = is_array($roles) ? $roles : [$roles];
    return in_array($_SESSION['user_role'], $allowedRoles) || $_SESSION['user_role'] === 'admin';
}

function require_role(array|string $roles): void {
    check_auth();
    if (!has_role($roles)) {
        header("HTTP/1.1 403 Forbidden");
        echo "403 Forbidden: Access Denied for your role.";
        exit;
    }
}
```
