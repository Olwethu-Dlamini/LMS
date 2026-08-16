<?php
/**
 * Team leave calendar.
 *
 * A month at a glance for one department, so a line manager can see a clash
 * before approving it rather than after. Every working day carries an "away of
 * headcount" count and is shaded once the department reaches the absence limit
 * configured against it in the admin console.
 *
 * Who sees what:
 *   employee            own department, names only - the leave type is withheld
 *                       because sick and maternity leave would otherwise disclose
 *                       a colleague's health to the whole team
 *   manager             departments they head or have reports in, in full detail
 *   hr / executive      any department, in full detail
 *   admin               any department, for oversight; read-only like everyone else
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/LeaveCapacity.php';
check_auth();

$db       = getDBConnection();
$capacity = new LeaveCapacity($db);
$userId   = (int)$_SESSION['user_id'];
$role     = $_SESSION['user_role'] ?? ROLE_EMPLOYEE;
$ownDept  = isset($_SESSION['department_id']) && $_SESSION['department_id'] !== null
    ? (int)$_SESSION['department_id']
    : null;

// Leave categories stay hidden from colleagues; approvers need them to judge.
$showLeaveType = $role !== ROLE_EMPLOYEE;

[$unrestricted, $scopedDeptIds] = visible_department_ids($db, $userId, $role, $ownDept);
$selectableDepts = $capacity->departmentLimits($unrestricted ? [] : $scopedDeptIds);

// Which month. Anything unparseable falls back to the current one rather than
// erroring, so a hand-edited URL cannot break the page.
$monthParam = $_GET['month'] ?? '';
$cursor = DateTime::createFromFormat('Y-m-d', $monthParam . '-01');
if (!$cursor || $cursor->format('Y-m') !== $monthParam) {
    $cursor = new DateTime('first day of this month');
}
$cursor->setDate((int)$cursor->format('Y'), (int)$cursor->format('n'), 1);
$cursor->setTime(0, 0, 0);

$monthStart = $cursor->format('Y-m-d');
$monthEnd   = (clone $cursor)->modify('last day of this month')->format('Y-m-d');
$prevMonth  = (clone $cursor)->modify('-1 month')->format('Y-m');
$nextMonth  = (clone $cursor)->modify('+1 month')->format('Y-m');

// Which department. Default to the viewer's own when it is in scope, otherwise
// the first they may look at, so the page always opens on something useful.
$requestedDept = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$selectedDept  = null;
if ($requestedDept > 0 && isset($selectableDepts[$requestedDept])) {
    $selectedDept = $requestedDept;
} elseif ($ownDept !== null && isset($selectableDepts[$ownDept])) {
    $selectedDept = $ownDept;
} elseif (!empty($selectableDepts)) {
    $selectedDept = (int)array_key_first($selectableDepts);
}

$deptName   = $selectedDept !== null ? $selectableDepts[$selectedDept]['name'] : null;
$deptLimit  = $selectedDept !== null ? $selectableDepts[$selectedDept]['limit'] : null;
$headcount  = 0;
$byDay      = [];
$dayState   = [];

if ($selectedDept !== null) {
    $byDay     = $capacity->absencesInRange([$selectedDept], $monthStart, $monthEnd);
    $headcount = $capacity->headcounts([$selectedDept])[$selectedDept] ?? 0;
    foreach ($capacity->capacityWarnings($byDay, $selectedDept, $deptLimit) as $warning) {
        $dayState[$warning['date']] = $warning['state'];
    }
}

// Public holidays are labelled in their cell so an empty day reads as a closure
// rather than as available cover.
$stmtHol = $db->prepare("
    SELECT holiday_date, title FROM holidays
    WHERE holiday_date BETWEEN :start AND :end
");
$stmtHol->execute(['start' => $monthStart, 'end' => $monthEnd]);
$holidays = [];
foreach ($stmtHol->fetchAll() as $row) {
    $holidays[$row['holiday_date']] = $row['title'];
}

// The grid runs Monday to Sunday and is padded out to whole weeks.
$gridStart = (clone $cursor);
$gridStart->modify('-' . ((int)$gridStart->format('N') - 1) . ' days');
$gridEnd = new DateTime($monthEnd);
$gridEnd->modify('+' . (7 - (int)$gridEnd->format('N')) . ' days');

$today = (new DateTime('today'))->format('Y-m-d');

/** Link back to this page keeping the other selection intact. */
function calendar_url(?string $month, ?int $deptId): string {
    $query = [];
    if ($month !== null) {
        $query['month'] = $month;
    }
    if ($deptId !== null) {
        $query['dept'] = $deptId;
    }
    return APP_URL . '/modules/leave/team_calendar.php'
        . (empty($query) ? '' : '?' . http_build_query($query));
}

ob_start();
?>

<div class="card mb-4">
    <div class="card-body ri-cal-toolbar">
        <div class="ri-cal-monthnav">
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(calendar_url($prevMonth, $selectedDept)); ?>">
                <i class="ti-angle-left"></i>
            </a>
            <strong class="ri-cal-monthlabel"><?php echo $cursor->format('F Y'); ?></strong>
            <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(calendar_url($nextMonth, $selectedDept)); ?>">
                <i class="ti-angle-right"></i>
            </a>
            <a class="btn btn-sm btn-link" href="<?php echo htmlspecialchars(calendar_url(null, $selectedDept)); ?>">This month</a>
        </div>

        <?php if (count($selectableDepts) > 1): ?>
        <form method="GET" action="" class="form-inline ri-cal-deptpick">
            <input type="hidden" name="month" value="<?php echo $cursor->format('Y-m'); ?>">
            <label class="mr-2 mb-0 small font-weight-bold text-muted">Department</label>
            <select name="dept" class="form-control form-control-sm" onchange="this.form.submit()">
                <?php foreach ($selectableDepts as $id => $dept): ?>
                    <option value="<?php echo (int)$id; ?>" <?php echo $id === $selectedDept ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-primary ml-2">Show</button></noscript>
        </form>
        <?php endif; ?>

        <div class="ri-cal-legend">
            <span><i class="ri-cal-dot ri-cal-dot-ok"></i> Cover in hand</span>
            <span><i class="ri-cal-dot ri-cal-dot-at"></i> At limit</span>
            <span><i class="ri-cal-dot ri-cal-dot-over"></i> Understaffed</span>
        </div>
    </div>
</div>

<?php if ($selectedDept === null): ?>
    <div class="alert alert-warning">
        You are not assigned to a department yet, so there is no team calendar to show.
        Ask an administrator to add you to one in User Management.
    </div>
<?php else: ?>

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
            <span class="font-weight-bold text-dark">
                <i class="ti-calendar text-primary"></i>
                <?php echo htmlspecialchars($deptName); ?>
                <span class="text-muted font-weight-normal">&middot; <?php echo (int)$headcount; ?> active member(s)</span>
            </span>
            <span class="small">
                <?php if ($deptLimit === null): ?>
                    <span class="badge badge-light">No absence limit configured</span>
                <?php else: ?>
                    <span class="badge badge-info">Limit: <?php echo (int)$deptLimit; ?> away at a time</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table ri-cal mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th>
                            <th class="ri-cal-weekend">Sat</th><th class="ri-cal-weekend">Sun</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $day = clone $gridStart;
                    while ($day <= $gridEnd):
                        if ((int)$day->format('N') === 1) {
                            echo '<tr>';
                        }

                        $date       = $day->format('Y-m-d');
                        $inMonth    = $day->format('Y-m') === $cursor->format('Y-m');
                        $isWeekend  = (int)$day->format('N') >= 6;
                        $isHoliday  = isset($holidays[$date]);
                        $isWorkday  = !$isWeekend && !$isHoliday;
                        $absences   = $byDay[$date] ?? [];
                        $away       = count($absences);
                        $state      = $dayState[$date] ?? null;

                        $classes = ['ri-cal-cell'];
                        if (!$inMonth)  { $classes[] = 'ri-cal-outside'; }
                        if ($isWeekend) { $classes[] = 'ri-cal-weekend'; }
                        if ($isHoliday) { $classes[] = 'ri-cal-holiday'; }
                        if ($date === $today) { $classes[] = 'ri-cal-today'; }
                        if ($state === LeaveCapacity::AT_LIMIT)   { $classes[] = 'ri-cal-at'; }
                        if ($state === LeaveCapacity::OVER_LIMIT) { $classes[] = 'ri-cal-over'; }
                    ?>
                        <td class="<?php echo implode(' ', $classes); ?>">
                            <div class="ri-cal-daytop">
                                <span class="ri-cal-daynum"><?php echo (int)$day->format('j'); ?></span>
                                <?php if ($inMonth && $isWorkday && $away > 0): ?>
                                    <span class="ri-cal-count" title="<?php echo $away; ?> of <?php echo (int)$headcount; ?> away">
                                        <?php echo $away; ?>/<?php echo (int)$headcount; ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isHoliday): ?>
                                <div class="ri-cal-note"><i class="ti-flag-alt"></i> <?php echo htmlspecialchars($holidays[$date]); ?></div>
                            <?php endif; ?>

                            <?php if ($inMonth && $isWorkday): ?>
                                <?php foreach ($absences as $absence): ?>
                                    <?php
                                    $isPending = $absence['status'] !== STATUS_APPROVED;
                                    $isHalf    = (float)$absence['total_days'] === 0.5;
                                    ?>
                                    <div class="ri-cal-person<?php echo $isPending ? ' ri-cal-person-pending' : ''; ?>"
                                         title="<?php echo htmlspecialchars(
                                             $absence['name']
                                             . ($showLeaveType ? ' - ' . $absence['leave_name'] : '')
                                             . ($isPending ? ' (awaiting approval)' : '')
                                         ); ?>">
                                        <span class="ri-cal-person-name"><?php echo htmlspecialchars($absence['name']); ?></span>
                                        <?php if ($showLeaveType): ?>
                                            <span class="ri-cal-person-tag"><?php echo htmlspecialchars($absence['leave_code']); ?><?php echo $isHalf ? ' &frac12;' : ''; ?></span>
                                        <?php else: ?>
                                            <span class="ri-cal-person-tag">Away</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>

                                <?php if ($state === LeaveCapacity::OVER_LIMIT): ?>
                                    <div class="ri-cal-flag"><i class="ti-alert"></i> Over limit</div>
                                <?php elseif ($state === LeaveCapacity::AT_LIMIT): ?>
                                    <div class="ri-cal-flag ri-cal-flag-soft"><i class="ti-info-alt"></i> At limit</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php
                        if ((int)$day->format('N') === 7) {
                            echo '</tr>';
                        }
                        $day->modify('+1 day');
                    endwhile;
                    ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white small text-muted">
            Pending requests are shown in outline and counted, so a clash is visible before it is approved.
            Weekends and public holidays are never counted as absence.
            <?php if (!$showLeaveType): ?>
                Leave categories are withheld from colleagues' entries.
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Team Leave Calendar | ' . APP_NAME;
$pageHeading = 'Team Leave Calendar';
$pageSubtitle = 'Who is away, and when the department is running short of cover.';
$pageIcon = 'ti-calendar';
require_once __DIR__ . '/../../includes/layout.php';
?>
