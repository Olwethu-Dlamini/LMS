<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? 'create';
    $title     = sanitize($_POST['title'] ?? '');
    $date      = sanitize($_POST['holiday_date'] ?? '');
    $recurring = isset($_POST['is_recurring']) ? 1 : 0;
    $holidayId = (int)($_POST['holiday_id'] ?? 0);

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($action === 'create') {
        if ($title === '' || $date === '') {
            $error = 'Title and holiday date are mandatory.';
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO holidays (title, holiday_date, is_recurring) VALUES (:title, :date, :rec)");
                $stmt->execute(['title' => $title, 'date' => $date, 'rec' => $recurring]);
                set_flash('success', "Public holiday '{$title}' added.");
                header('Location: ' . APP_URL . '/modules/admin/holidays.php');
                exit;
            } catch (PDOException $e) {
                $error = strpos($e->getMessage(), 'Duplicate') !== false
                    ? 'A holiday is already registered on that date.'
                    : 'Error adding holiday date: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'edit') {
        if ($holidayId <= 0 || $title === '' || $date === '') {
            $error = 'Please provide a valid title and date.';
        } else {
            try {
                $stmt = $db->prepare("UPDATE holidays SET title = :title, holiday_date = :date, is_recurring = :rec WHERE id = :id");
                $stmt->execute(['title' => $title, 'date' => $date, 'rec' => $recurring, 'id' => $holidayId]);
                set_flash('success', "Public holiday '{$title}' updated.");
                header('Location: ' . APP_URL . '/modules/admin/holidays.php');
                exit;
            } catch (PDOException $e) {
                $error = strpos($e->getMessage(), 'Duplicate') !== false
                    ? 'A holiday is already registered on that date.'
                    : 'Error updating holiday: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        if ($holidayId <= 0) {
            $error = 'Invalid holiday.';
        } else {
            $stmt = $db->prepare("DELETE FROM holidays WHERE id = :id");
            $stmt->execute(['id' => $holidayId]);
            set_flash('warning', 'Public holiday removed. Working-day calculations from now on will treat that date as a normal working day.');
            header('Location: ' . APP_URL . '/modules/admin/holidays.php');
            exit;
        }
    }
}

$holidays = $db->query("SELECT * FROM holidays ORDER BY holiday_date ASC")->fetchAll();
$thisYear = (int)date('Y');

ob_start();
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<div class="alert alert-info mb-4">
    <i class="ti-info-alt"></i> Dates listed here are excluded from every working-day
    calculation. Editing or removing a holiday changes the duration of
    <strong>future</strong> calculations only. Leave already submitted keeps the
    day count it was approved with.
</div>

<!-- Create -->
<div class="modal fade" id="newHolidayModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Register Public Holiday</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label>Holiday Name *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Workers' Day" required>
                    </div>
                    <div class="form-group mb-3">
                        <label>Date *</label>
                        <input type="date" name="holiday_date" class="form-control" required>
                    </div>
                    <div class="form-check mb-0">
                        <input type="checkbox" name="is_recurring" class="form-check-input" id="recNew" value="1">
                        <label class="form-check-label" for="recNew">Falls on the same date every year</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Holiday</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="ti-calendar"></i> Company &amp; Public Holidays (<?php echo count($holidays); ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($holidays)): ?>
            <div class="ri-empty"><i class="ti-calendar"></i>No holidays registered. Every weekday will count as a working day.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Holiday</th>
                        <th>Recurring</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($holidays as $h): ?>
                    <?php $isPast = strtotime($h['holiday_date']) < strtotime('today'); ?>
                    <tr<?php echo $isPast ? ' class="text-muted"' : ''; ?>>
                        <td class="font-weight-bold text-primary ri-nowrap"><?php echo htmlspecialchars($h['holiday_date']); ?></td>
                        <td class="ri-nowrap"><small><?php echo date('l', strtotime($h['holiday_date'])); ?></small></td>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($h['title']); ?></td>
                        <td>
                            <?php echo (int)$h['is_recurring'] === 1
                                ? '<span class="badge badge-info">Annual</span>'
                                : '<span class="badge badge-light">One-off</span>'; ?>
                        </td>
                        <td class="ri-actions">
                            <button type="button" class="btn btn-xs btn-outline-primary mb-1"
                                    data-toggle="modal" data-target="#editHol<?php echo (int)$h['id']; ?>">
                                <i class="ti-pencil"></i> Edit
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-danger mb-1"
                                    data-toggle="modal" data-target="#delHol<?php echo (int)$h['id']; ?>">
                                <i class="ti-trash"></i> Delete
                            </button>

                            <div class="modal fade text-left" id="editHol<?php echo (int)$h['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="holiday_id" value="<?php echo (int)$h['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit Holiday</h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="form-group mb-3">
                                                    <label>Holiday Name *</label>
                                                    <input type="text" name="title" class="form-control" required
                                                           value="<?php echo htmlspecialchars($h['title']); ?>">
                                                </div>
                                                <div class="form-group mb-3">
                                                    <label>Date *</label>
                                                    <input type="date" name="holiday_date" class="form-control" required
                                                           value="<?php echo htmlspecialchars($h['holiday_date']); ?>">
                                                </div>
                                                <div class="form-check mb-0">
                                                    <input type="checkbox" name="is_recurring" class="form-check-input"
                                                           id="rec<?php echo (int)$h['id']; ?>" value="1"
                                                           <?php echo (int)$h['is_recurring'] === 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="rec<?php echo (int)$h['id']; ?>">Falls on the same date every year</label>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <div class="modal fade text-left" id="delHol<?php echo (int)$h['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="holiday_id" value="<?php echo (int)$h['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Remove Holiday?</h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="mb-0">
                                                    <strong><?php echo htmlspecialchars($h['title']); ?></strong>
                                                    (<?php echo htmlspecialchars($h['holiday_date']); ?>) will no longer be
                                                    excluded from working-day calculations.
                                                </p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger font-weight-bold">Remove Holiday</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Public Holidays | ' . APP_NAME;
$pageHeading = 'Public Holiday Calendar';
$pageSubtitle = 'Holidays defined here are automatically excluded from working day calculations.';
$pageIcon = 'ti-calendar';
$pageActions = '<button type="button" class="btn btn-light font-weight-bold" data-toggle="modal" data-target="#newHolidayModal">'
             . '<i class="ti-plus"></i> Add Holiday Date</button>';
require_once __DIR__ . '/../../includes/admin_layout.php';
?>
