<?php
require_once __DIR__ . '/functions.php';

/** Admin console navigation. Contains system administration only - no Apply,
 *  My Leave or Approvals. Those stay in the staff portal, reachable via the
 *  switcher on the right. */

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
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link ri-switch" href="<?php echo APP_URL; ?>/modules/dashboard/index.php">
                            <i class="ti-arrow-right"></i>Staff Portal
                        </a>
                    </li>
                </ul>
            </div>
        </nav>
    </div>
</div>
