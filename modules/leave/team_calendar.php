<?php
/**
 * Team leave calendar.
 *
 * A month at a glance for one department, so a line manager can see a clash
 * before approving it rather than after.
 *
 * Approved leave and requests still waiting on a decision are drawn, counted and
 * labelled apart. They used to sit together in one count, so a day reading
 * "3 away" might have been one person off and two who had only asked - which is
 * not something a rota can be planned from. Approved entries are solid and
 * counted against the headcount; applied-for entries are dashed, italic, and
 * counted separately as "+n".
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

if ($selectedDept !== null) {
    $byDay     = $capacity->absencesInRange([$selectedDept], $monthStart, $monthEnd);
    $headcount = $capacity->headcounts([$selectedDept])[$selectedDept] ?? 0;
}

/**
 * Split a day's absences into settled and still-to-be-decided.
 *
 * The two were counted as one, so a day showing "3 away" might have been one
 * person actually off and two who had merely asked. A rota cannot be read that
 * way: what is booked and what is proposed have to be told apart at a glance.
 */
function split_by_status(array $absences): array {
    $approved = [];
    $pending  = [];
    foreach ($absences as $absence) {
        if (($absence['status'] ?? '') === STATUS_APPROVED) {
            $approved[] = $absence;
        } else {
            $pending[] = $absence;
        }
    }
    return [$approved, $pending];
}

// Month totals for the summary strip, counting people rather than requests -
// somebody off twice in a month is still one person short from the rota.
$peopleApproved = [];
$peoplePending  = [];
foreach ($byDay as $absences) {
    [$approved, $pending] = split_by_status($absences);
    foreach ($approved as $a) { $peopleApproved[(int)$a['user_id']] = true; }
    foreach ($pending as $p)  { $peoplePending[(int)$p['user_id']]  = true; }
}
$monthApproved = count($peopleApproved);
$monthPending  = count($peoplePending);

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

        <!-- The legend describes what is actually drawn in the grid. It used to
             describe cover states, which left the difference between booked
             leave and a request nobody has decided yet unexplained - the one
             distinction people most need when reading a rota. -->
        <div class="ri-cal-legend">
            <span><i class="ri-cal-key ri-cal-key-approved"></i> Approved: they will be off</span>
            <span><i class="ri-cal-key ri-cal-key-pending"></i> Requested: asked for, not yet approved</span>
            <span><i class="ri-cal-key ri-cal-key-holiday"></i> Public holiday</span>
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
            <span class="small ri-cal-summary">
                <?php if ($monthApproved === 0 && $monthPending === 0): ?>
                    <span class="badge badge-light">Nobody away this month</span>
                <?php else: ?>
                    <?php if ($monthApproved > 0): ?>
                        <span class="badge badge-success"><?php echo $monthApproved; ?> approved off</span>
                    <?php endif; ?>
                    <?php if ($monthPending > 0): ?>
                        <span class="badge badge-warning text-dark"><?php echo $monthPending; ?> awaiting approval</span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($deptLimit !== null): ?>
                    <span class="badge badge-light border">Cover limit <?php echo (int)$deptLimit; ?> at a time</span>
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
                        [$dayApproved, $dayPending] = split_by_status($absences);
                        $awayCount    = count($dayApproved);
                        $pendingCount = count($dayPending);

                        $classes = ['ri-cal-cell'];
                        if (!$inMonth)  { $classes[] = 'ri-cal-outside'; }
                        if ($isWeekend) { $classes[] = 'ri-cal-weekend'; }
                        if ($isHoliday) { $classes[] = 'ri-cal-holiday'; }
                        if ($date === $today) { $classes[] = 'ri-cal-today'; }
                    ?>
                        <td class="<?php echo implode(' ', $classes); ?>">
                            <div class="ri-cal-daytop">
                                <span class="ri-cal-daynum"><?php echo (int)$day->format('j'); ?></span>
                                <?php // Only settled leave is counted. A request nobody has decided
                                      // is not a number the rota can rely on, and it says so on its
                                      // own entry below. ?>
                                <?php if ($inMonth && $isWorkday && $awayCount > 0): ?>
                                    <span class="ri-cal-count" title="<?php echo $awayCount; ?> of <?php echo (int)$headcount; ?> approved off">
                                        <?php echo $awayCount; ?>/<?php echo (int)$headcount; ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($isHoliday): ?>
                                <div class="ri-cal-note"><i class="ti-flag-alt"></i> <?php echo htmlspecialchars($holidays[$date]); ?></div>
                            <?php endif; ?>

                            <?php if ($inMonth && $isWorkday): ?>
                                <?php
                                // Approved first, then the requests still waiting: what is
                                // settled reads top-down before what is only proposed.
                                foreach (array_merge($dayApproved, $dayPending) as $absence):
                                    $isPending = $absence['status'] !== STATUS_APPROVED;
                                    $isHalf    = (float)$absence['total_days'] === 0.5;
                                    $isSelf    = (int)$absence['user_id'] === $userId;
                                    $label     = $isSelf ? 'You' : $absence['name'];

                                    $personClasses = ['ri-cal-person'];
                                    if ($isPending) { $personClasses[] = 'ri-cal-person-pending'; }
                                    if ($isSelf)    { $personClasses[] = 'ri-cal-person-you'; }
                                ?>
                                    <div class="<?php echo implode(' ', $personClasses); ?>"
                                         title="<?php echo htmlspecialchars(
                                             $absence['name']
                                             . ($showLeaveType ? ' - ' . $absence['leave_name'] : '')
                                             . ($isPending
                                                 ? ' - requested leave, not yet approved'
                                                 : ' - approved, they are off')
                                         ); ?>">
                                        <span class="ri-cal-person-line">
                                            <span class="ri-cal-person-name"><?php echo htmlspecialchars($label); ?></span>
                                            <?php if ($showLeaveType): ?>
                                                <span class="ri-cal-person-tag"><?php echo htmlspecialchars($absence['leave_code']); ?><?php echo $isHalf ? ' &frac12;' : ''; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php // Said in words, not just in styling: somebody reading a
                                              // colleague's name on a rota needs to know whether that
                                              // person is actually off or has only asked to be. ?>
                                        <span class="ri-cal-person-state">
                                            <?php echo $isPending ? 'Requested &middot; not yet approved' : 'Approved'; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
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
            The count on each day is how many people are <strong>approved</strong> off out of the team.
            Requests still waiting on a decision are listed and labelled but never counted,
            because nothing about them is settled yet.
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
