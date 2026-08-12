<?php
require_once __DIR__ . '/functions.php';

/** Admin console navigation. This is the admin's only navigation - there is no
 *  switch back to the staff portal, because an admin account holds no leave
 *  entitlement and cannot apply for leave.
 *
 *  Approvals and HR tooling appear under Leave Oversight. The three stage
 *  screens are the admin's break-glass override for applications stranded when
 *  the designated approver is unavailable; the pages themselves warn when an
 *  admin is the one acting. */

if (!function_exists('ri_nav_on')) {
    function ri_nav_on(array $paths): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        foreach ($paths as $p) {
            if (strpos($uri, $p) !== false) {
                return true;
            }
        }
        return false;
    }
}
function ri_admin_active(string $path): string { return ri_nav_on([$path]) ? ' active' : ''; }

$adminLinks = [
    ['/admin/index.php',       'ti-dashboard',      'Overview'],
    ['/admin/users.php',       'ti-user',           'Users'],
    ['/admin/departments.php', 'ti-layout-grid2',   'Departments'],
    ['/admin/leave_types.php', 'ti-clipboard',      'Leave Types'],
    ['/admin/holidays.php',    'ti-calendar',       'Holidays'],
    ['/admin/audit_log.php',   'ti-files',          'Audit Log'],
];

// Break-glass approval plus HR reporting, grouped so they read as oversight
// rather than as part of the admin's own workflow.
$oversightLinks = [
    ['/manager/approvals.php',   'ti-check-box', 'Stage 1 · Line Manager'],
    ['/hr/approvals.php',        'ti-shield',    'Stage 2 · HR Review'],
    ['/executive/approvals.php', 'ti-crown',     'Stage 3 · Executive'],
    ['/hr/allocations.php',      'ti-pie-chart', 'Leave Allocations'],
    ['/hr/reports.php',          'ti-files',     'Leave Reports'],
];
$onOversight = ri_nav_on(array_column($oversightLinks, 0));
?>
<div class="ri-nav ri-nav-admin">
    <div class="container">
        <nav class="navbar navbar-expand-lg p-0">
            <span class="ri-admin-tag"><i class="ti-settings"></i> Admin Console</span>

            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#riAdminNav"
                    aria-controls="riAdminNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="riAdminNav">
                <ul class="navbar-nav mr-auto">
                    <?php foreach ($adminLinks as [$path, $icon, $label]): ?>
                        <li class="nav-item<?php echo ri_admin_active($path); ?>">
                            <a class="nav-link" href="<?php echo APP_URL . '/modules' . $path; ?>">
                                <i class="<?php echo $icon; ?>"></i><?php echo $label; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>

                    <li class="nav-item dropdown<?php echo $onOversight ? ' active' : ''; ?>">
                        <a class="nav-link dropdown-toggle" href="#" id="riAdminOversight" data-toggle="dropdown"
                           aria-haspopup="true" aria-expanded="false">
                            <i class="ti-eye"></i>Leave Oversight
                        </a>
                        <div class="dropdown-menu" aria-labelledby="riAdminOversight">
                            <h6 class="dropdown-header">Break-glass approval</h6>
                            <?php foreach ($oversightLinks as $i => [$path, $icon, $label]): ?>
                                <?php if ($i === 3): ?>
                                    <div class="dropdown-divider"></div>
                                    <h6 class="dropdown-header">HR management</h6>
                                <?php endif; ?>
                                <a class="dropdown-item<?php echo ri_admin_active($path); ?>"
                                   href="<?php echo APP_URL . '/modules' . $path; ?>">
                                    <i class="<?php echo $icon; ?>"></i><?php echo $label; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</div>
