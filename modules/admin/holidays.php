<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $title = sanitize($_POST['title'] ?? '');
    $date = sanitize($_POST['holiday_date'] ?? '');

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif (empty($title) || empty($date)) {
        $error = 'Title and holiday date are mandatory.';
    } else {
        try {
            $stmt = $db->prepare("INSERT INTO holidays (title, holiday_date) VALUES (:title, :date)");
            $stmt->execute(['title' => $title, 'date' => $date]);
            set_flash('success', "Public Holiday '{$title}' added!");
            header('Location: ' . APP_URL . '/modules/admin/holidays.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Error adding holiday date: ' . $e->getMessage();
        }
    }
}

$holidays = $db->query("SELECT * FROM holidays ORDER BY holiday_date ASC")->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-calendar text-primary"></i> Public Holiday Calendar</h3>
        <p class="text-muted mb-0">Holidays defined here are automatically excluded from working day calculations.</p>
    </div>
    <button type="button" class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#newHolidayModal">
        <i class="ti-plus"></i> Add Holiday Date
    </button>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- New Holiday Modal -->
<div class="modal fade" id="newHolidayModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Register Public Holiday</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Holiday Name / Title *</label>
                        <input type="text" name="title" class="form-control" placeholder="e.g. Workers' Day" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Holiday Date *</label>
                        <input type="date" name="holiday_date" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Holiday</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark">Official Company & Public Holidays</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Holiday Title</th>
                        <th>Day of Week</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holidays as $h): ?>
                    <tr>
                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($h['holiday_date']); ?></td>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($h['title']); ?></td>
                        <td><?php echo date('l', strtotime($h['holiday_date'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Public Holidays | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
