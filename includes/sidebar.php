<?php
require_once __DIR__ . '/functions.php';
$role = $_SESSION['user_role'] ?? '';
$currentPage = $_SERVER['REQUEST_URI'] ?? '';
?>
<div class="sidebar">
    <div class="px-4 py-4 border-bottom border-secondary text-center">
        <h4 class="text-white font-weight-bold mb-0"><i class="ti-calendar text-primary"></i> LMS Portal</h4>
        <small class="text-muted">Leave Management System</small>
    </div>
    
    <ul class="nav flex-column py-3">
        <!-- Common Dashboard -->
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, 'dashboard') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/dashboard/index.php">
                <i class="ti-dashboard"></i> Dashboard
            </a>
        </li>

        <!-- Employee Links -->
        <?php if (has_role([ROLE_EMPLOYEE, ROLE_MANAGER, ROLE_HR, ROLE_EXECUTIVE, ROLE_ADMIN])): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/leave/apply.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/leave/apply.php">
                <i class="ti-pencil-alt"></i> Apply for Leave
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/leave/my_history.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/leave/my_history.php">
                <i class="ti-time"></i> My Leave History
            </a>
        </li>
        <?php endif; ?>

        <!-- Line Manager Links (Stage 1 Approver) -->
        <?php if (has_role([ROLE_MANAGER, ROLE_ADMIN])): ?>
        <li class="nav-section px-4 pt-3 pb-1 text-uppercase small text-muted font-weight-bold">Manager Portal</li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/manager/approvals.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/manager/approvals.php">
                <i class="ti-check-box"></i> Stage 1 Approvals
            </a>
        </li>
        <?php endif; ?>

        <!-- HR Manager Links (Stage 2 Approver) -->
        <?php if (has_role([ROLE_HR, ROLE_ADMIN])): ?>
        <li class="nav-section px-4 pt-3 pb-1 text-uppercase small text-muted font-weight-bold">HR Management</li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/hr/approvals.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/hr/approvals.php">
                <i class="ti-shield"></i> Stage 2 Approvals
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/hr/allocations.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/hr/allocations.php">
                <i class="ti-pie-chart"></i> Leave Allocations
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/hr/reports.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/hr/reports.php">
                <i class="ti-files"></i> Leave Reports
            </a>
        </li>
        <?php endif; ?>

        <!-- Executive / Boss Links (Stage 3 Approver) -->
        <?php if (has_role([ROLE_EXECUTIVE, ROLE_ADMIN])): ?>
        <li class="nav-section px-4 pt-3 pb-1 text-uppercase small text-muted font-weight-bold">Executive Portal</li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/executive/approvals.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/executive/approvals.php">
                <i class="ti-crown"></i> Stage 3 Approvals
            </a>
        </li>
        <?php endif; ?>

        <!-- System Admin Links -->
        <?php if (has_role([ROLE_ADMIN])): ?>
        <li class="nav-section px-4 pt-3 pb-1 text-uppercase small text-muted font-weight-bold">Administration</li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/admin/users.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/admin/users.php">
                <i class="ti-user"></i> User Management
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/admin/departments.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/admin/departments.php">
                <i class="ti-layout-grid2"></i> Departments
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/admin/leave_types.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/admin/leave_types.php">
                <i class="ti-settings"></i> Leave Types
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo strpos($currentPage, '/admin/holidays.php') !== false ? 'active' : ''; ?>" href="<?php echo APP_URL; ?>/modules/admin/holidays.php">
                <i class="ti-calendar"></i> Holiday Calendar
            </a>
        </li>
        <?php endif; ?>
    </ul>
</div>
