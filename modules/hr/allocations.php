<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/LeaveCalculator.php';
require_role([ROLE_HR, ROLE_ADMIN]);

$db = getDBConnection();
$calculator = new LeaveCalculator($db);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? 'single';

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif ($action === 'bulk_init') {
        $targetYear = (int)($_POST['target_year'] ?? date('Y'));
        if ($targetYear < 2000 || $targetYear > 2100) {
            $error = 'Please enter a valid target year.';
        } else {
            try {
                $users = $db->query("SELECT id FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
                // Categories that spend another's balance are not allocated:
                // emergency leave comes off annual leave, so initialising it
                // would give everybody a second row that is always zero and is
                // never the row a request touches.
                $types = $calculator->allocatableTypes();
                
                $stmtBulk = $db->prepare("
                    INSERT INTO leave_entitlements (user_id, leave_type_id, year, total_days, used_days, pending_days)
                    VALUES (:user_id, :type_id, :year, :total_days, 0, 0)
                    ON DUPLICATE KEY UPDATE total_days = VALUES(total_days)
                ");
                
                $count = 0;
                foreach ($users as $uid) {
                    foreach ($types as $lt) {
                        $stmtBulk->execute([
                            'user_id' => $uid,
                            'type_id' => $lt['id'],
                            'year' => $targetYear,
                            'total_days' => $lt['max_days_per_year']
                        ]);
                        $count++;
                    }
                }
                set_flash('success', "Bulk initialization complete for year {$targetYear}! Allocated {$count} entitlement records across all active employees.");
                header('Location: ' . APP_URL . '/modules/hr/allocations.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error performing bulk initialization: ' . escape_html($e->getMessage());
            }
        }
    } else {
        $userId = (int)($_POST['user_id'] ?? 0);
        $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
        $year = (int)($_POST['year'] ?? date('Y'));
        $totalDays = (float)($_POST['total_days'] ?? 0);

        if ($userId <= 0 || $leaveTypeId <= 0 || $totalDays < 0) {
            $error = 'Please provide valid user, leave type, and day allocation.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO leave_entitlements (user_id, leave_type_id, year, total_days)
                VALUES (:user_id, :type_id, :year, :total_days)
                ON DUPLICATE KEY UPDATE total_days = VALUES(total_days)
            ");
            $stmt->execute(['user_id' => $userId, 'type_id' => $leaveTypeId, 'year' => $year, 'total_days' => $totalDays]);
            set_flash('success', 'Leave allocation updated successfully!');
            header('Location: ' . APP_URL . '/modules/hr/allocations.php');
            exit;
        }
    }
}

// ---------------------------------------------------------------------------
// One row per person, one column per category.
//
// This screen used to list one row per entitlement, so every member of staff
// appeared once per leave category with their name and employee number
// reprinted each time: thirty-two people rendered as ninety-six rows, of which
// two thirds were the zeroes left by migration 006 withdrawing sick and unpaid
// leave. The figures were all correct and none of them could be read.
//
// Pivoted instead. The question this page answers is "what does this person
// have", and that is one row.
// ---------------------------------------------------------------------------

// Which leave year. Anything unparseable falls back to the current one, so a
// hand-edited URL cannot empty the page.
$years = $db->query("
    SELECT DISTINCT year FROM leave_entitlements ORDER BY year DESC
")->fetchAll(PDO::FETCH_COLUMN);
$thisYear = (int)date('Y');
if (empty($years)) {
    $years = [$thisYear];
}
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : $thisYear;
if (!in_array($selectedYear, array_map('intval', $years), true)) {
    $selectedYear = in_array($thisYear, array_map('intval', $years), true)
        ? $thisYear
        : (int)$years[0];
}

// Driven from the staff list rather than from the entitlement rows, which is
// the other half of the problem with the old screen: somebody with no
// allocation at all held no rows, so they did not appear - on the one page
// where HR can give them one. They cannot apply for anything until they do.
//
// Active staff always appear. An archived account appears only if it still
// holds an allocation for this year, so leavers do not fill the page. Admins
// never do: they hold no leave entitlement.
$stmt = $db->prepare("
    SELECT u.id AS user_id, u.first_name, u.last_name, u.emp_id, u.status,
           e.leave_type_id, e.total_days, e.used_days, e.pending_days,
           t.name AS leave_name, t.code AS leave_code
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN leave_entitlements e ON e.user_id = u.id AND e.year = :year
    LEFT JOIN leave_types t ON t.id = e.leave_type_id
    WHERE r.name <> 'admin'
      AND (u.status = 'active' OR e.id IS NOT NULL)
    ORDER BY u.first_name ASC, u.last_name ASC, t.name ASC
");
$stmt->execute(['year' => $selectedYear]);

// Columns are the categories that actually hold an allocation this year, so a
// category nobody has - emergency leave, which spends annual leave - never
// appears as a column of zeroes.
$columns        = [];
$columnHasDays  = [];
$staff          = [];
$needsAttention = 0;

foreach ($stmt->fetchAll() as $row) {
    $userId = (int)$row['user_id'];

    if (!isset($staff[$userId])) {
        $staff[$userId] = [
            'name'     => $row['first_name'] . ' ' . $row['last_name'],
            'emp_id'   => $row['emp_id'],
            'archived' => ($row['status'] ?? 'active') !== 'active',
            'cells'    => [],
            'flags'    => [],
        ];
    }

    // A person with no allocation this year arrives as one row of NULLs from
    // the left join. They belong on the page; they just have no cells.
    if ($row['leave_type_id'] === null) {
        continue;
    }

    $typeId = (int)$row['leave_type_id'];
    $columns[$typeId] = $row['leave_name'];

    $total     = (float)$row['total_days'];
    $used      = (float)$row['used_days'];
    $pending   = (float)$row['pending_days'];
    $available = $total - $used - $pending;

    $staff[$userId]['cells'][$typeId] = [
        'total'     => $total,
        'used'      => $used,
        'pending'   => $pending,
        'available' => $available,
    ];

    // A category where nobody holds a day, has taken one or has one pending is
    // a column of zeroes. Sick and unpaid leave are exactly that since
    // migration 006 withdrew them, and they were two thirds of this page.
    $columnHasDays[$typeId] = !empty($columnHasDays[$typeId])
        || $total > 0 || $used > 0 || $pending > 0;

    // Worth surfacing rather than leaving to be spotted: a negative balance,
    // which emergency leave can now produce, and a category somebody holds a
    // row for but no days in while they have leave booked against it.
    if ($available < 0) {
        $staff[$userId]['flags'][] = $row['leave_code'] . ' negative';
    }
}
foreach ($staff as $userId => $person) {
    // No allocation at all is the loudest case: that person cannot submit a
    // request for anything until somebody here gives them one.
    if (empty($person['cells'])) {
        $staff[$userId]['flags'][] = 'no allocation';
    }
    if (!empty($staff[$userId]['flags'])) {
        $needsAttention++;
    }
}

// Widest first: the category people actually draw on is the one shown on the
// row itself, and the rest are behind the row's drop-down.
uksort($columns, function ($a, $b) use ($staff) {
    $sum = function ($typeId) use ($staff) {
        $t = 0.0;
        foreach ($staff as $person) {
            $t += $person['cells'][$typeId]['total'] ?? 0.0;
        }
        return $t;
    };
    return $sum($b) <=> $sum($a);
});

// Fetch Users & Leave Types for dropdown
$users = $db->query("SELECT id, emp_id, first_name, last_name FROM users ORDER BY first_name ASC")->fetchAll();
// Only categories that hold their own balance can be allocated by hand, for the
// same reason they are not bulk initialised.
$leaveTypes = $calculator->allocatableTypes();

ob_start();
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Bulk Init Modal -->
<div class="modal fade" id="bulkInitModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="bulk_init">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold">Bulk Initialize Annual Allocations</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-dark">This will automatically assign standard default leave category allowances (`max_days_per_year`) to all active employees for the selected year.</p>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Target Year *</label>
                        <input type="number" name="target_year" class="form-control form-control-lg" value="<?php echo date('Y'); ?>" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info font-weight-bold">Run Bulk Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Allocation Modal -->
<div class="modal fade" id="allocationModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Leave Entitlement Allocation</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Employee</label>
                        <select name="user_id" id="allocUser" class="form-control" required>
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['emp_id'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Leave Type</label>
                        <select name="leave_type_id" id="allocType" class="form-control" required>
                            <option value="">-- Select Leave Category --</option>
                            <?php foreach ($leaveTypes as $lt): ?>
                                <option value="<?php echo $lt['id']; ?>"><?php echo htmlspecialchars($lt['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Year</label>
                            <input type="number" name="year" id="allocYear" class="form-control"
                                   value="<?php echo (int)$selectedYear; ?>" required>
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Total Days</label>
                            <input type="number" step="0.5" name="total_days" id="allocDays" class="form-control"
                                   placeholder="e.g. 20" required>
                            <small class="form-text text-muted">
                                This sets the allowance. Days already taken or awaiting approval are
                                not touched, so a figure below them leaves the balance negative.
                            </small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Allocation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white ri-alloc-toolbar">
        <span class="font-weight-bold text-dark">
            Allocations
            <span class="text-muted font-weight-normal">&middot; <?php echo count($staff); ?> staff</span>
            <?php if ($needsAttention > 0): ?>
                <span class="badge badge-danger ml-1"
                      title="People with no allocation at all, or a category in the negative">
                    <?php echo (int)$needsAttention; ?> need attention
                </span>
            <?php endif; ?>
        </span>

        <div class="ri-alloc-controls">
            <input type="search" class="form-control form-control-sm" id="allocFilter"
                   placeholder="Filter by name or EMP ID" autocomplete="off"
                   aria-label="Filter allocations">

            <?php if (count($years) > 1): ?>
                <form method="GET" action="" class="form-inline mb-0">
                    <label class="mr-2 mb-0 small font-weight-bold text-muted" for="allocYearPick">Year</label>
                    <select name="year" id="allocYearPick" class="form-control form-control-sm"
                            onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo (int)$y; ?>" <?php echo (int)$y === $selectedYear ? 'selected' : ''; ?>>
                                <?php echo (int)$y; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="btn btn-sm btn-primary ml-2">Show</button></noscript>
                </form>
            <?php else: ?>
                <span class="badge badge-light border">Leave year <?php echo $selectedYear; ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($staff)): ?>
            <div class="ri-empty">
                <i class="ti-pie-chart"></i>
                No staff to allocate for <?php echo $selectedYear; ?>.
            </div>
        <?php else: ?>
        <?php
        // The category people actually draw on sits on the row; every other
        // category is one click away, in the row's own drop-down, instead of
        // being a second and third row for the same person.
        $primaryTypeId   = array_key_first($columns);
        $primaryTypeName = $columns[$primaryTypeId] ?? 'Leave';

        /** One figure, as the clickable cell that opens the allocation form. */
        $allocFigure = function (?array $cell, int $userId, int $typeId, string $typeName, bool $withCategory = false): void {
            if ($cell === null) {
                ?>
                <button type="button" class="ri-alloc-box ri-alloc-none" data-alloc-open
                        data-user="<?php echo $userId; ?>" data-type="<?php echo $typeId; ?>"
                        title="No <?php echo htmlspecialchars($typeName); ?> allocation. Click to set one.">
                    <?php if ($withCategory): ?>
                        <span class="ri-alloc-cat"><?php echo htmlspecialchars($typeName); ?></span>
                    <?php endif; ?>
                    <span class="ri-alloc-top"><span class="ri-alloc-avail">&mdash;</span></span>
                    <span class="ri-alloc-detail">not allocated</span>
                </button>
                <?php
                return;
            }

            $tone = 'ri-alloc-ok';
            if ($cell['available'] < 0)        { $tone = 'ri-alloc-neg'; }
            elseif ($cell['total'] <= 0)       { $tone = 'ri-alloc-zero'; }
            elseif ($cell['available'] == 0.0) { $tone = 'ri-alloc-spent'; }

            // The same used/pending bar the dashboard draws, so a balance reads
            // the same way wherever it appears.
            $usedPct    = $cell['total'] > 0 ? min(100, ($cell['used'] / $cell['total']) * 100) : 0;
            $pendingPct = $cell['total'] > 0 ? min(100 - $usedPct, ($cell['pending'] / $cell['total']) * 100) : 0;
            ?>
            <button type="button" class="ri-alloc-box <?php echo $tone; ?>" data-alloc-open
                    data-user="<?php echo $userId; ?>" data-type="<?php echo $typeId; ?>"
                    data-total="<?php echo number_format($cell['total'], 1, '.', ''); ?>"
                    title="<?php echo htmlspecialchars(sprintf(
                        '%s available of %s allocated. %s taken, %s awaiting approval. Click to change the allocation.',
                        number_format($cell['available'], 1), number_format($cell['total'], 1),
                        number_format($cell['used'], 1), number_format($cell['pending'], 1)
                    )); ?>">
                <?php if ($withCategory): ?>
                    <span class="ri-alloc-cat"><?php echo htmlspecialchars($typeName); ?></span>
                <?php endif; ?>
                <span class="ri-alloc-top">
                    <span class="ri-alloc-avail"><?php echo number_format($cell['available'], 1); ?></span>
                    <span class="ri-alloc-of">of <?php echo number_format($cell['total'], 1); ?></span>
                </span>
                <?php if ($cell['total'] > 0): ?>
                    <span class="ri-usebar">
                        <span class="ri-usebar-used" style="width: <?php echo round($usedPct, 1); ?>%"></span>
                        <span class="ri-usebar-pending" style="width: <?php echo round($pendingPct, 1); ?>%"></span>
                    </span>
                <?php endif; ?>
                <span class="ri-alloc-detail">
                    <?php if ($cell['used'] > 0 || $cell['pending'] > 0): ?>
                        <?php echo number_format($cell['used'], 1); ?> taken<?php
                            if ($cell['pending'] > 0) {
                                echo ' &middot; ' . number_format($cell['pending'], 1) . ' pending';
                            }
                        ?>
                    <?php elseif ($cell['total'] <= 0): ?>
                        no days allocated
                    <?php else: ?>
                        nothing booked
                    <?php endif; ?>
                </span>
            </button>
            <?php
        };
        ?>
        <div class="table-responsive">
            <table class="table mb-0 ri-alloc">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th><?php echo htmlspecialchars($primaryTypeName); ?></th>
                        <th>Other categories</th>
                        <th class="ri-alloc-expandhead"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staff as $userId => $person): ?>
                    <?php
                    $key      = strtolower($person['name'] . ' ' . $person['emp_id']);
                    $detailId = 'alloc-detail-' . (int)$userId;
                    ?>
                    <tr data-alloc-row="<?php echo htmlspecialchars($key); ?>">
                        <td class="ri-alloc-who">
                            <span class="ri-alloc-name"><?php echo htmlspecialchars($person['name']); ?></span>
                            <span class="ri-alloc-emp">
                                <?php echo htmlspecialchars($person['emp_id']); ?>
                                <?php if ($person['archived']): ?>
                                    <span class="ri-alloc-tag ri-alloc-tag-muted">Archived</span>
                                <?php endif; ?>
                                <?php if (empty($person['cells'])): ?>
                                    <span class="ri-alloc-tag ri-alloc-tag-bad">No allocation &middot; cannot apply</span>
                                <?php endif; ?>
                            </span>
                        </td>

                        <td class="ri-alloc-cell">
                            <?php $allocFigure($person['cells'][$primaryTypeId] ?? null, (int)$userId, (int)$primaryTypeId, $primaryTypeName); ?>
                        </td>

                        <td class="ri-alloc-rest">
                            <?php foreach ($columns as $typeId => $typeName): ?>
                                <?php if ($typeId === $primaryTypeId) { continue; } ?>
                                <?php
                                $other = $person['cells'][$typeId] ?? null;
                                $chipTone = 'ri-alloc-chip-zero';
                                if ($other === null)                  { $chipTone = 'ri-alloc-chip-none'; }
                                elseif ($other['available'] < 0)      { $chipTone = 'ri-alloc-chip-neg'; }
                                elseif ($other['available'] > 0)      { $chipTone = 'ri-alloc-chip-ok'; }
                                ?>
                                <span class="ri-alloc-chip <?php echo $chipTone; ?>">
                                    <?php echo htmlspecialchars($typeName); ?>
                                    <strong><?php echo $other === null ? '&mdash;' : number_format($other['available'], 1); ?></strong>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($columns) <= 1): ?>
                                <span class="text-muted small">No other categories</span>
                            <?php endif; ?>
                        </td>

                        <td class="ri-alloc-expandcell">
                            <button type="button" class="ri-alloc-expand"
                                    data-alloc-toggle="<?php echo $detailId; ?>"
                                    aria-expanded="false" aria-controls="<?php echo $detailId; ?>">
                                <i class="ti-angle-down" aria-hidden="true"></i>
                                <span class="ri-alloc-expand-label">All categories</span>
                            </button>
                        </td>
                    </tr>

                    <tr class="ri-alloc-detailrow" id="<?php echo $detailId; ?>"
                        data-alloc-detail="<?php echo htmlspecialchars($key); ?>" data-expanded="0" hidden>
                        <td colspan="4">
                            <div class="ri-alloc-grid">
                                <?php foreach ($columns as $typeId => $typeName): ?>
                                    <?php $allocFigure($person['cells'][$typeId] ?? null, (int)$userId, (int)$typeId, $typeName, true); ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-footer bg-white small text-muted">
        One row per person. The figure on the row is <strong><?php echo htmlspecialchars($primaryTypeName ?? 'Leave'); ?></strong>
        days available - allocated, minus taken, minus awaiting approval - and the bar is how
        much of it is committed. <strong>All categories</strong> opens the rest for that person.
        Click any figure to change that allocation.
        Emergency leave has no figure of its own: it is deducted from Annual Leave.
    </div>
</div>

<script>
/**
 * Filter by name or employee number, in the page.
 *
 * Thirty-odd rows is small enough that asking the server per keystroke would be
 * the slower answer, and the filter has to survive somebody with JavaScript off:
 * without it the box simply does nothing and the full list is still there.
 */
(function () {
    'use strict';
    var box = document.getElementById('allocFilter');
    if (!box) { return; }

    var rows    = Array.prototype.slice.call(document.querySelectorAll('[data-alloc-row]'));
    var details = Array.prototype.slice.call(document.querySelectorAll('[data-alloc-detail]'));

    box.addEventListener('input', function () {
        var needle = box.value.trim().toLowerCase();

        rows.forEach(function (row) {
            row.hidden = needle !== '' && row.getAttribute('data-alloc-row').indexOf(needle) === -1;
        });

        // A drop-down that is open stays open through a filter, and one that is
        // shut stays shut: without the second half, clearing the filter would
        // reveal every person's categories at once.
        details.forEach(function (detail) {
            var filteredOut = needle !== '' && detail.getAttribute('data-alloc-detail').indexOf(needle) === -1;
            detail.hidden = filteredOut || detail.dataset.expanded !== '1';
        });
    });
}());

/**
 * The drop-down: one row per person, their other categories a click away.
 *
 * Plain DOM rather than Bootstrap's collapse, which animates height on a block
 * element and has no sensible behaviour on a table row.
 */
(function () {
    'use strict';
    document.querySelectorAll('[data-alloc-toggle]').forEach(function (button) {
        var detail = document.getElementById(button.getAttribute('data-alloc-toggle'));
        if (!detail) { return; }

        button.addEventListener('click', function () {
            var open = detail.dataset.expanded === '1';

            detail.dataset.expanded = open ? '0' : '1';
            detail.hidden = open;
            button.setAttribute('aria-expanded', open ? 'false' : 'true');
            button.classList.toggle('ri-alloc-expanded', !open);

            var label = button.querySelector('.ri-alloc-expand-label');
            if (label) { label.textContent = open ? 'All categories' : 'Hide'; }
        });
    });
}());

/**
 * Clicking a figure opens the allocation form with that person and category
 * already chosen, because every edit starts by finding them in two dropdowns
 * that are thirty entries long.
 */
(function () {
    'use strict';
    document.querySelectorAll('[data-alloc-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var user = document.getElementById('allocUser'),
                type = document.getElementById('allocType'),
                year = document.getElementById('allocYear'),
                days = document.getElementById('allocDays');

            if (user) { user.value = button.dataset.user || ''; }
            if (type) { type.value = button.dataset.type || ''; }
            if (year) { year.value = <?php echo (int)$selectedYear; ?>; }
            if (days) { days.value = button.dataset.total || ''; }

            if (window.jQuery) {
                window.jQuery('#allocationModal').modal('show');
            }
        });
    });
}());
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Leave Allocations | ' . APP_NAME;
$pageHeading = 'Leave Entitlements Allocation';
$pageSubtitle = 'Manage annual leave balances for all employees.';
$pageIcon = 'ti-pie-chart';
$pageActions = '<button type="button" class="btn btn-outline-light font-weight-bold mr-2" data-toggle="modal" data-target="#bulkInitModal">'
             . '<i class="ti-bolt"></i> Bulk Initialize Year</button>'
             . '<button type="button" class="btn btn-light font-weight-bold" data-toggle="modal" data-target="#allocationModal">'
             . '<i class="ti-plus"></i> Allocate / Update</button>';
require_once __DIR__ . '/../../includes/layout.php';
?>
