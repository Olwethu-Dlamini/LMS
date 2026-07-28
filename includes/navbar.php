<?php
require_once __DIR__ . '/functions.php';
$userEmail = $_SESSION['user_email'] ?? 'User';
$userRole = strtoupper($_SESSION['user_role'] ?? 'GUEST');
$userName = $_SESSION['user_name'] ?? 'User';
?>
<div class="top-navbar">
    <div class="d-flex align-items-center">
        <h5 class="mb-0 font-weight-bold text-slate-800">
            <i class="ti-layers-alt text-primary mr-2"></i> <?php echo APP_NAME; ?>
        </h5>
    </div>
    <div class="d-flex align-items-center">
        <div class="mr-3 text-right">
            <span class="d-block font-weight-bold text-dark mb-0"><?php echo htmlspecialchars($userName); ?></span>
            <span class="badge badge-info"><?php echo htmlspecialchars($userRole); ?></span>
        </div>
        <a href="<?php echo APP_URL; ?>/modules/auth/logout.php" class="btn btn-outline-danger btn-sm">
            <i class="ti-power-off"></i> Logout
        </a>
    </div>
</div>
