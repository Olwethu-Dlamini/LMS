<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/LeaveCapacity.php';
require_once __DIR__ . '/../../helpers/DashboardInsights.php';
require_staff();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$db = getDBConnection();
$year = (int)date('Y');

// Fetch Leave Balances for User
$stmtEnt = $db->prepare("
    SELECT e.*, t.name as leave_name, t.code 
    FROM leave_entitlements e
    JOIN leave_types t ON e.leave_type_id = t.id
    WHERE e.user_id = :user_id AND e.year = :year
");
$stmtEnt->execute(['user_id' => $userId, 'year' => date('Y')]);
$entitlements = $stmtEnt->fetchAll();

// Fetch Pending Approvals count depending on Role
$pendingStage1Count = 0;
$pendingStage2Count = 0;
$pendingStage3Count = 0;

// Admins never reach this page (require_staff sends them to the console), so
// these queues are scoped to the staff roles that own each of them. They are
// three separate queues holding three kinds of applicant, not three stages of
// one request: a manager decides their team's leave, HR decides managers' and
// executives', the executive decides HR's, and each decision is final.
if (has_role(ROLE_MANAGER, false)) {
    $stmtCount = $db->prepare("
        SELECT COUNT(*)
        FROM leave_applications a
        JOIN users u ON a.user_id = u.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE a.status = 'pending_manager' AND " . manager_scope_clause() . "
    ");
    $stmtCount->execute(manager_scope_params($userId));
    $pendingStage1Count = (int)$stmtCount->fetchColumn();
}

if (has_role(ROLE_HR, false)) {
    $stmtCount = $db->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending_hr'");
    $pendingStage2Count = (int)$stmtCount->fetchColumn();
}

if (has_role(ROLE_EXECUTIVE, false)) {
    $stmtCount = $db->query("SELECT COUNT(*) FROM leave_applications WHERE status = 'pending_executive'");
    $pendingStage3Count = (int)$stmtCount->fetchColumn();
}

// Fetch User's Recent Leave Applications
$stmtApps = $db->prepare("
    SELECT a.*, t.name as leave_name 
    FROM leave_applications a
    JOIN leave_types t ON a.leave_type_id = t.id
    WHERE a.user_id = :user_id
    ORDER BY a.created_at DESC LIMIT 5
");
$stmtApps->execute(['user_id' => $userId]);
$myApplications = $stmtApps->fetchAll();

// ---- Insight widgets -----------------------------------------------------
$insights = new DashboardInsights($db);
$capacity = new LeaveCapacity($db);
$ownDept  = isset($_SESSION['department_id']) && $_SESSION['department_id'] !== null
    ? (int)$_SESSION['department_id']
    : null;
[$unrestricted, $scopedDeptIds] = visible_department_ids($db, (int)$userId, $userRole, $ownDept);

// The signed-in user's next approved leave.
$nextLeave = $insights->nextApprovedLeave((int)$userId);

// Who is away over the coming week, scoped to what this viewer is responsible
// for: their own department as an employee, every department they approve for
// as a line manager, and the whole company for HR, an executive or an
// administrator. visible_department_ids() already draws exactly that boundary
// for the calendar, so the dashboard borrows it rather than inventing a second
// rule that could disagree.
//
// Approved leave and requests are counted apart per department; see
// LeaveCapacity::weekByDepartment().
$weekStart = date('Y-m-d');
$weekEnd   = date('Y-m-d', strtotime('+6 days'));

$weekDeptIds  = $unrestricted ? [] : $scopedDeptIds;
$weekRows     = [];
$weekAwayRows = [];
$weekQuiet    = [];
$weekAwayToday    = 0;
$weekHeadcountAll = 0;

if ($unrestricted || !empty($weekDeptIds)) {
    $weekRows = LeaveCapacity::weekByDepartment(
        $capacity->absencesInRange($weekDeptIds, $weekStart, $weekEnd, $unrestricted),
        $capacity->departmentLimits($weekDeptIds),
        $capacity->headcounts($weekDeptIds)
    );

    // Departments with nobody away are listed by name rather than drawn as
    // seven empty cells each. On a company-wide view most of them usually are.
    foreach ($weekRows as $row) {
        $weekHeadcountAll += $row['headcount'];
        if ($row['has_absence']) {
            $weekAwayRows[] = $row;
        } else {
            $weekQuiet[] = $row['name'];
        }
        $today = $row['days'][0] ?? null;
        if ($today !== null && $today['date'] === $weekStart) {
            $weekAwayToday += count($today['approved']);
        }
    }
}

$weekFirstRow        = empty($weekRows) ? null : reset($weekRows);
$weekHasWorkingDays  = $weekFirstRow !== null && !empty($weekFirstRow['days']);

// Where the "full calendar" link lands: on the one department when that is all
// the viewer has, otherwise on the calendar's own default.
$weekCalendarUrl = APP_URL . '/modules/leave/team_calendar.php'
    . (count($weekRows) === 1 ? '?dept=' . (int)array_key_first($weekRows) : '');

if ($unrestricted) {
    $weekScopeLabel = 'whole company';
} elseif (count($weekRows) === 1 && $weekFirstRow !== null) {
    $weekScopeLabel = (string)$weekFirstRow['name'];
} else {
    $weekScopeLabel = count($weekRows) . ' departments';
}

// Department utilisation, for the people who carry staffing responsibility.
$showUtilisation = has_role([ROLE_MANAGER, ROLE_HR, ROLE_EXECUTIVE], false);
$utilisation = $showUtilisation
    ? $insights->utilisation($scopedDeptIds, $unrestricted, $year, $insights->annualLeaveTypeId())
    : [];

ob_start();
?>

<!-- Role Approval Notifications -->
<?php if (has_role([ROLE_MANAGER, ROLE_HR, ROLE_EXECUTIVE], false)): ?>
<div class="row mb-4">
    <?php if (has_role(ROLE_MANAGER, false)): ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-warning bg-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-uppercase small font-weight-bold text-muted">My team's leave</span>
                        <h2 class="font-weight-bold text-warning mb-0"><?php echo $pendingStage1Count; ?></h2>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/modules/manager/approvals.php" class="btn btn-warning text-dark font-weight-bold btn-sm">
                            View Requests <i class="ti-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (has_role(ROLE_HR, false)): ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-info bg-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-uppercase small font-weight-bold text-muted">Manager &amp; executive leave</span>
                        <h2 class="font-weight-bold text-info mb-0"><?php echo $pendingStage2Count; ?></h2>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/modules/hr/approvals.php" class="btn btn-info font-weight-bold btn-sm">
                            View Requests <i class="ti-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (has_role(ROLE_EXECUTIVE, false)): ?>
    <div class="col-md-4 mb-3">
        <div class="card border-left-primary bg-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-uppercase small font-weight-bold text-muted">HR leave</span>
                        <h2 class="font-weight-bold text-primary mb-0"><?php echo $pendingStage3Count; ?></h2>
                    </div>
                    <div>
                        <a href="<?php echo APP_URL; ?>/modules/executive/approvals.php" class="btn btn-primary font-weight-bold btn-sm">
                            View Requests <i class="ti-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row mb-4">
    <!-- My next approved leave -->
    <div class="col-lg-4 mb-3">
        <div class="card h-100">
            <div class="card-header bg-white font-weight-bold text-dark">
                <i class="ti-plane text-primary"></i> Your Next Leave
            </div>
            <div class="card-body">
                <?php if ($nextLeave === null): ?>
                    <p class="text-muted mb-2 small">You have no approved leave coming up.</p>
                    <a href="<?php echo APP_URL; ?>/modules/leave/apply.php" class="btn btn-sm btn-outline-primary">
                        <i class="ti-plus"></i> Apply for leave
                    </a>
                <?php else: ?>
                    <div class="ri-nextleave-when">
                        <?php echo htmlspecialchars(date('D j M', strtotime($nextLeave['start_date']))); ?>
                        <?php if ($nextLeave['end_date'] !== $nextLeave['start_date']): ?>
                            <span class="text-muted">&rarr; <?php echo htmlspecialchars(date('D j M', strtotime($nextLeave['end_date']))); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ri-nextleave-count">
                        <?php echo htmlspecialchars(DashboardInsights::countdownLabel((int)$nextLeave['days_until'])); ?>
                    </div>
                    <hr class="my-2">
                    <div class="small text-muted">
                        <?php echo htmlspecialchars($nextLeave['leave_name']); ?> &middot;
                        <?php echo number_format((float)$nextLeave['total_days'], 1); ?> day(s) &middot;
                        <?php echo htmlspecialchars($nextLeave['application_no']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Who is away over the next seven days, one row per department in scope -->
    <div class="col-lg-<?php echo $unrestricted ? '12' : '8'; ?> mb-3">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                <span class="font-weight-bold text-dark">
                    <i class="ti-user text-primary"></i> Away This Week
                    <?php if (!empty($weekRows)): ?>
                        <span class="text-muted font-weight-normal">&middot; <?php echo htmlspecialchars($weekScopeLabel); ?></span>
                    <?php endif; ?>
                </span>
                <?php if (!empty($weekRows)): ?>
                    <span>
                        <?php if ($weekHeadcountAll > 0): ?>
                            <span class="badge badge-light border mr-2"
                                  title="Approved leave only, today">
                                <?php echo (int)$weekAwayToday; ?> of <?php echo (int)$weekHeadcountAll; ?> away today
                            </span>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($weekCalendarUrl); ?>"
                           class="btn btn-xs btn-outline-primary">Full calendar</a>
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($weekRows)): ?>
                    <p class="text-muted small mb-0">
                        You are not assigned to a department, so there is no team view to show.
                        Ask an administrator to add you to one.
                    </p>
                <?php elseif (!$weekHasWorkingDays): ?>
                    <p class="text-muted small mb-0">No working days in the next seven, so nobody is scheduled away.</p>
                <?php elseif (empty($weekAwayRows)): ?>
                    <p class="text-muted small mb-0">
                        Nobody is approved off in the next seven working days
                        <?php echo $unrestricted ? 'anywhere in the company' : 'in your team'; ?>.
                    </p>
                <?php else: ?>
                    <?php foreach ($weekAwayRows as $row): ?>
                        <div class="ri-weekdept">
                            <div class="ri-weekdept-head">
                                <span class="font-weight-bold text-dark"><?php echo htmlspecialchars($row['name']); ?></span>
                                <span class="small text-muted">
                                    <?php echo (int)$row['headcount']; ?> staff
                                    <?php if ($row['limit'] !== null): ?>
                                        &middot; limit <?php echo (int)$row['limit']; ?> away at a time
                                    <?php else: ?>
                                        &middot; no limit set
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="ri-week">
                                <?php foreach ($row['days'] as $day): ?>
                                    <?php
                                    $dayClass = 'ri-week-day';
                                    if ($day['state'] === LeaveCapacity::AT_LIMIT)   { $dayClass .= ' ri-week-at'; }
                                    if ($day['state'] === LeaveCapacity::OVER_LIMIT) { $dayClass .= ' ri-week-over'; }
                                    ?>
                                    <div class="<?php echo $dayClass; ?>">
                                        <div class="ri-week-date">
                                            <?php echo htmlspecialchars(date('D', strtotime($day['date']))); ?>
                                            <span><?php echo htmlspecialchars(date('j', strtotime($day['date']))); ?></span>
                                        </div>
                                        <div class="ri-week-count">
                                            <?php echo count($day['approved']); ?>/<?php echo (int)$row['headcount']; ?>
                                            <?php if (!empty($day['pending'])): ?>
                                                <span class="ri-week-pending"
                                                      title="<?php echo count($day['pending']); ?> awaiting a decision, not counted">
                                                    +<?php echo count($day['pending']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php foreach ($day['approved'] as $absence): ?>
                                            <div class="ri-week-who" title="<?php echo htmlspecialchars($absence['name']); ?> - approved">
                                                <?php echo htmlspecialchars($absence['initials']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php foreach ($day['pending'] as $absence): ?>
                                            <div class="ri-week-who ri-week-who-pending"
                                                 title="<?php echo htmlspecialchars($absence['name']); ?> - requested, not yet approved">
                                                <?php echo htmlspecialchars($absence['initials']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="small text-muted mt-2">
                        The count is people <strong>approved</strong> off out of that department;
                        <span class="ri-week-pending">+n</span> is requests still awaiting a decision,
                        which are never counted. Amber days sit on a department's limit and red days
                        pass it, counting requests too.
                        <?php if (!empty($weekQuiet)): ?>
                            <br>Nobody away in: <?php echo htmlspecialchars(implode(', ', $weekQuiet)); ?>.
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Leave Entitlement Summary Cards -->
<h5 class="font-weight-bold mb-3 text-secondary"><i class="ti-pie-chart"></i> Your Leave Balances (<?php echo date('Y'); ?>)</h5>
<div class="row mb-4">
    <?php if (empty($entitlements)): ?>
        <div class="col-12">
            <div class="alert alert-warning">No leave entitlements allocated for your account for year <?php echo date('Y'); ?>.</div>
        </div>
    <?php else: ?>
        <?php foreach ($entitlements as $ent):
            $available = (float)$ent['total_days'] - (float)$ent['used_days'] - (float)$ent['pending_days'];
            // Taken and reserved as shares of the entitlement, so the card shows
            // how much of the year's allowance is already committed.
            $total      = (float)$ent['total_days'];
            $usedPct    = $total > 0 ? round((float)$ent['used_days'] / $total * 100, 1) : 0.0;
            $pendingPct = $total > 0 ? round((float)$ent['pending_days'] / $total * 100, 1) : 0.0;
        ?>
        <div class="col-md-3 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="font-weight-bold text-dark"><?php echo htmlspecialchars($ent['leave_name']); ?></h6>
                    <div class="d-flex justify-content-between align-items-baseline mt-2">
                        <span class="h3 font-weight-bold text-primary mb-0"><?php echo number_format($available, 1); ?></span>
                        <span class="small text-muted">Days Available</span>
                    </div>

                    <div class="ri-usebar" title="<?php echo number_format($ent['used_days'], 1); ?> taken, <?php echo number_format($ent['pending_days'], 1); ?> awaiting approval of <?php echo number_format($total, 1); ?>">
                        <span class="ri-usebar-used" style="width: <?php echo min(100, $usedPct); ?>%"></span>
                        <span class="ri-usebar-pending" style="width: <?php echo min(100 - min(100, $usedPct), $pendingPct); ?>%"></span>
                    </div>

                    <hr class="my-2">
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Total: <?php echo number_format($ent['total_days'], 1); ?></span>
                        <span>Used: <?php echo number_format($ent['used_days'], 1); ?></span>
                        <span>Pending: <?php echo number_format($ent['pending_days'], 1); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($showUtilisation && !empty($utilisation)): ?>
<!-- Annual leave utilisation across the departments this user is responsible for -->
<div class="card mb-4">
    <div class="card-header bg-white font-weight-bold text-dark">
        <i class="ti-bar-chart text-primary"></i> Annual Leave Utilisation <?php echo $year; ?>
    </div>
    <div class="card-body">
        <?php foreach ($utilisation as $dept): ?>
            <?php $pct = round($dept['share'] * 100); ?>
            <div class="ri-util-row">
                <div class="ri-util-head">
                    <span class="font-weight-bold text-dark"><?php echo htmlspecialchars($dept['name']); ?></span>
                    <span class="small text-muted">
                        <?php echo number_format($dept['used'], 1); ?> taken
                        <?php if ($dept['pending'] > 0): ?>
                            + <?php echo number_format($dept['pending'], 1); ?> pending
                        <?php endif; ?>
                        of <?php echo number_format($dept['total'], 1); ?> days
                        &middot; <?php echo (int)$dept['members']; ?> staff
                    </span>
                </div>
                <div class="ri-usebar">
                    <span class="ri-usebar-used" style="width: <?php echo min(100, $pct); ?>%"></span>
                </div>
                <div class="ri-util-foot small">
                    <span class="text-muted"><?php echo $pct; ?>% committed</span>
                    <?php if ($dept['low_usage'] > 0): ?>
                        <span class="text-warning">
                            <i class="ti-alert"></i>
                            <?php echo (int)$dept['low_usage']; ?> staff below a quarter of their allowance
                        </span>
                    <?php endif; ?>
                    <?php if ($dept['unallocated'] > 0): ?>
                        <span class="text-danger">
                            <i class="ti-info-alt"></i>
                            <?php echo (int)$dept['unallocated']; ?> with no <?php echo $year; ?> allocation
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <p class="small text-muted mb-0 mt-2">
            Staff who have booked almost nothing this far into the year tend to book it
            all at once later. Unallocated staff cannot apply at all until HR runs the
            annual entitlement.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- My Recent Applications Table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="font-weight-bold"><i class="ti-list"></i> My Recent Leave Applications</span>
        <a href="<?php echo APP_URL; ?>/modules/leave/my_history.php" class="btn btn-sm btn-outline-primary">View All History</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Application No</th>
                        <th>Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($myApplications)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">You have not submitted any leave applications yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($myApplications as $app): ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo htmlspecialchars($app['application_no']); ?></td>
                            <td><?php echo htmlspecialchars($app['leave_name']); ?></td>
                            <td><?php echo htmlspecialchars($app['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($app['end_date']); ?></td>
                            <td><span class="badge badge-light"><?php echo number_format($app['total_days'], 1); ?> Days</span></td>
                            <td><?php echo get_status_badge($app['status']); ?></td>
                            <td>
                                <a href="<?php echo APP_URL; ?>/modules/leave/my_history.php?view=<?php echo $app['id']; ?>" class="btn btn-xs btn-outline-info">
                                    Details
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Dashboard | ' . APP_NAME;
$pageHeading = 'Welcome back, ' . ($_SESSION['user_name'] ?? '');
$pageSubtitle = 'Overview of your leave entitlements and approval queues for ' . date('Y') . '.';
$pageActions = '<a href="' . APP_URL . '/modules/leave/apply.php" class="btn btn-light font-weight-bold">'
             . '<i class="ti-plus"></i> Apply for Leave</a>';
require_once __DIR__ . '/../../includes/layout.php';
?>
