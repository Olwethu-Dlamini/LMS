<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/LeaveCalculator.php';
require_once __DIR__ . '/../../helpers/ApprovalWorkflow.php';

check_auth();

$userId = $_SESSION['user_id'];
$db = getDBConnection();
$calculator = new LeaveCalculator($db);
$workflow = new ApprovalWorkflow($db);

$stmtTypes = $db->query("SELECT * FROM leave_types ORDER BY name ASC");
$leaveTypes = $stmtTypes->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
    $startDate = sanitize($_POST['start_date'] ?? '');
    $endDate = sanitize($_POST['end_date'] ?? '');
    $reason = sanitize($_POST['reason'] ?? '');
    $file = $_FILES['attachment'] ?? null;

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Run Business Rules Validation Engine
        $validation = $calculator->validateEligibility($userId, $leaveTypeId, $startDate, $endDate, $file);

        if (!$validation['valid']) {
            $error = implode('<br>', $validation['errors']);
        } else {
            $attachmentPath = null;
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['pdf', 'png', 'jpg', 'jpeg'];
                if (!in_array($ext, $allowed)) {
                    $error = 'Invalid file type. Only PDF, PNG, and JPG files are permitted.';
                } else {
                    $uploadDir = UPLOAD_DIR;
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $filename = 'med_' . $userId . '_' . time() . '.' . $ext;
                    $target = $uploadDir . $filename;
                    if (move_uploaded_file($file['tmp_name'], $target)) {
                        $attachmentPath = 'uploads/attachments/' . $filename;
                    }
                }
            }

            if (empty($error)) {
                $res = $workflow->submitApplication($userId, $leaveTypeId, $startDate, $endDate, $validation['days'], $reason, $attachmentPath);
                if ($res['success']) {
                    set_flash('success', "Leave Application {$res['application_no']} submitted successfully! It is now pending Stage 1 (Line Manager) approval.");
                    header('Location: ' . APP_URL . '/modules/leave/my_history.php');
                    exit;
                } else {
                    $error = 'Error submitting leave application: ' . $res['error'];
                }
            }
        }
    }
}

ob_start();
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 font-weight-bold"><i class="ti-pencil-alt"></i> Apply for Leave</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Leave Category <span class="text-danger">*</span></label>
                        <select name="leave_type_id" class="form-control" required>
                            <option value="">-- Select Leave Category --</option>
                            <?php foreach ($leaveTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo (isset($_POST['leave_type_id']) && $_POST['leave_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?> (Max: <?php echo $type['max_days_per_year']; ?> Days/Year)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" required value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group mb-3">
                            <label class="font-weight-bold text-dark">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control" required value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="alert alert-info py-2 small">
                        <i class="ti-info-alt"></i> <strong>Note:</strong> Weekends (Saturday & Sunday) and official company public holidays are automatically excluded from requested working days.
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Reason for Leave <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="4" placeholder="Provide details regarding your leave request..." required><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">File Attachment (Medical Note / Supporting Document)</label>
                        <input type="file" name="attachment" class="form-control-file">
                        <small class="form-text text-muted">Required for Sick Leave exceeding 2 working days (Allowed formats: PDF, JPG, PNG).</small>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <a href="<?php echo APP_URL; ?>/modules/dashboard/index.php" class="btn btn-secondary font-weight-bold">Cancel</a>
                        <button type="submit" class="btn btn-primary font-weight-bold px-4">
                            <i class="ti-check"></i> Submit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Apply for Leave | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
