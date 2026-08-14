<?php
require_once __DIR__ . '/functions.php';

$uri = $_SERVER['REQUEST_URI'] ?? '';

/** Is the current request one of these module paths? */
function ri_nav_on(array $paths): bool {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    foreach ($paths as $p) {
        if (strpos($uri, $p) !== false) {
            return true;
        }
    }
    return false;
}
function ri_nav_active(array $paths): string { return ri_nav_on($paths) ? ' active' : ''; }
function ri_item_active(string $path): string { return ri_nav_on([$path]) ? ' active' : ''; }

// Staff navigation only. Admins are redirected to the admin console by
// require_staff() and never render this bar, so no admin override applies here.
$canApproveStage1 = has_role(ROLE_MANAGER, false);
$canApproveStage2 = has_role(ROLE_HR, false);
$canApproveStage3 = has_role(ROLE_EXECUTIVE, false);
$showApprovals    = $canApproveStage1 || $canApproveStage2 || $canApproveStage3;
$showHr           = has_role(ROLE_HR, false);
?>
<div class="ri-nav">
    <div class="container">
        <nav class="navbar navbar-expand-lg navbar-light p-0">
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#riMainNav"
                    aria-controls="riMainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="riMainNav">
                <ul class="navbar-nav mr-auto">

                    <li class="nav-item<?php echo ri_nav_active(['/dashboard/']); ?>">
                        <a class="nav-link" href="<?php echo APP_URL; ?>/modules/dashboard/index.php">
                            <i class="ti-dashboard"></i>Dashboard
                        </a>
                    </li>

                    <li class="nav-item dropdown<?php echo ri_nav_active(['/leave/apply.php', '/leave/my_history.php']); ?>">
                        <a class="nav-link dropdown-toggle" href="#" id="riNavLeave" data-toggle="dropdown"
                           aria-haspopup="true" aria-expanded="false">
                            <i class="ti-calendar"></i>My Leave
                        </a>
                        <div class="dropdown-menu" aria-labelledby="riNavLeave">
                            <a class="dropdown-item<?php echo ri_item_active('/leave/apply.php'); ?>" href="<?php echo APP_URL; ?>/modules/leave/apply.php">
                                <i class="ti-pencil-alt"></i>Apply for Leave
                            </a>
                            <a class="dropdown-item<?php echo ri_item_active('/leave/my_history.php'); ?>" href="<?php echo APP_URL; ?>/modules/leave/my_history.php">
                                <i class="ti-time"></i>My Leave History
                            </a>
                        </div>
                    </li>

                    <li class="nav-item<?php echo ri_nav_active(['/leave/team_calendar.php']); ?>">
                        <a class="nav-link" href="<?php echo APP_URL; ?>/modules/leave/team_calendar.php">
                            <i class="ti-layout-grid3"></i>Team Calendar
                        </a>
                    </li>

                    <?php if ($showApprovals): ?>
                    <li class="nav-item dropdown<?php echo ri_nav_active(['/manager/approvals.php', '/hr/approvals.php', '/executive/approvals.php']); ?>">
                        <a class="nav-link dropdown-toggle" href="#" id="riNavApprovals" data-toggle="dropdown"
                           aria-haspopup="true" aria-expanded="false">
                            <i class="ti-check-box"></i>Approvals
                        </a>
                        <div class="dropdown-menu" aria-labelledby="riNavApprovals">
                            <h6 class="dropdown-header">Approval Pipeline</h6>
                            <?php if ($canApproveStage1): ?>
                                <a class="dropdown-item<?php echo ri_item_active('/manager/approvals.php'); ?>" href="<?php echo APP_URL; ?>/modules/manager/approvals.php">
                                    <i class="ti-check-box"></i>Stage 1 &middot; Line Manager
                                </a>
                            <?php endif; ?>
                            <?php if ($canApproveStage2): ?>
                                <a class="dropdown-item<?php echo ri_item_active('/hr/approvals.php'); ?>" href="<?php echo APP_URL; ?>/modules/hr/approvals.php">
                                    <i class="ti-shield"></i>Stage 2 &middot; HR Review
                                </a>
                            <?php endif; ?>
                            <?php if ($canApproveStage3): ?>
                                <a class="dropdown-item<?php echo ri_item_active('/executive/approvals.php'); ?>" href="<?php echo APP_URL; ?>/modules/executive/approvals.php">
                                    <i class="ti-crown"></i>Stage 3 &middot; Executive Sign-Off
                                </a>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endif; ?>

                    <?php if ($showHr): ?>
                    <li class="nav-item dropdown<?php echo ri_nav_active(['/hr/allocations.php', '/hr/reports.php']); ?>">
                        <a class="nav-link dropdown-toggle" href="#" id="riNavHr" data-toggle="dropdown"
                           aria-haspopup="true" aria-expanded="false">
                            <i class="ti-pie-chart"></i>HR Management
                        </a>
                        <div class="dropdown-menu" aria-labelledby="riNavHr">
                            <a class="dropdown-item<?php echo ri_item_active('/hr/allocations.php'); ?>" href="<?php echo APP_URL; ?>/modules/hr/allocations.php">
                                <i class="ti-pie-chart"></i>Leave Allocations
                            </a>
                            <a class="dropdown-item<?php echo ri_item_active('/hr/reports.php'); ?>" href="<?php echo APP_URL; ?>/modules/hr/reports.php">
                                <i class="ti-files"></i>Leave Reports &amp; Export
                            </a>
                        </div>
                    </li>
                    <?php endif; ?>

                </ul>

                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo APP_URL; ?>/modules/leave/apply.php">
                            <i class="ti-plus"></i>Apply
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</div>
