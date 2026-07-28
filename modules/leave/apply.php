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
    <div class="col-md-9">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 font-weight-bold"><i class="ti-pencil-alt"></i> Apply for Leave</h5>
                <span class="badge badge-light text-primary font-weight-bold">Year <?php echo date('Y'); ?></span>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data" id="leaveForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Leave Category <span class="text-danger">*</span></label>
                        <select name="leave_type_id" id="leave_type_id" class="form-control form-control-lg" required>
                            <option value="">-- Select Leave Category --</option>
                            <?php foreach ($leaveTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>" data-attachment="<?php echo $type['requires_attachment']; ?>" <?php echo (isset($_POST['leave_type_id']) && $_POST['leave_type_id'] == $type['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?> (Max: <?php echo $type['max_days_per_year']; ?> Days/Year)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-4">
                            <label class="font-weight-bold text-dark">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="form-control form-control-lg" min="<?php echo date('Y-m-d'); ?>" required value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6 form-group mb-4">
                            <label class="font-weight-bold text-dark">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="form-control form-control-lg" min="<?php echo date('Y-m-d'); ?>" required value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Dynamic Real-Time Live Preview Card -->
                    <div id="calcPreviewCard" class="card bg-light border-info mb-4" style="display: none;">
                        <div class="card-body py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="font-weight-bold text-info mb-1"><i class="ti-calculator"></i> Real-Time Request Summary</h6>
                                    <p class="mb-0 small text-dark" id="calcPreviewText">Calculating working days...</p>
                                </div>
                                <div class="text-right">
                                    <span class="h3 font-weight-bold text-primary mb-0" id="calcDaysBadge">0</span>
                                    <small class="d-block text-muted">Working Days</small>
                                </div>
                            </div>
                            <div id="calcErrorBox" class="text-danger small mt-2" style="display: none;"></div>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Reason for Leave <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="4" placeholder="Provide clear justification for your leave request..." required><?php echo htmlspecialchars($_POST['reason'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Supporting File Attachment (Medical Note / Document)</label>
                        <input type="file" name="attachment" id="attachment" class="form-control-file">
                        <small class="form-text text-muted">Mandatory for Sick Leave requests exceeding 2 working days (PDF, JPG, PNG max 5MB).</small>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <a href="<?php echo APP_URL; ?>/modules/dashboard/index.php" class="btn btn-outline-secondary font-weight-bold px-4">Cancel</a>
                        <button type="submit" id="btnSubmit" class="btn btn-primary font-weight-bold px-5 py-2">
                            <i class="ti-check"></i> Submit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const startDateInput = document.getElementById("start_date");
    const endDateInput = document.getElementById("end_date");
    const leaveTypeSelect = document.getElementById("leave_type_id");
    const previewCard = document.getElementById("calcPreviewCard");
    const previewText = document.getElementById("calcPreviewText");
    const daysBadge = document.getElementById("calcDaysBadge");
    const errorBox = document.getElementById("calcErrorBox");

    function checkDays() {
        const start = startDateInput.value;
        const end = endDateInput.value;
        const typeId = leaveTypeSelect.value;

        if (start && end && typeId) {
            previewCard.style.display = "block";
            previewText.innerHTML = "<i class='ti-reload spin'></i> Calculating working days and verifying balance...";
            errorBox.style.display = "none";

            fetch("<?php echo APP_URL; ?>/api/calculate_days.php?start_date=" + start + "&end_date=" + end + "&leave_type_id=" + typeId)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        daysBadge.textContent = data.working_days;
                        let infoMsg = `Calculated Net Working Days: <strong>${data.working_days}</strong>. `;
                        infoMsg += `Available Balance: <strong>${data.available_balance}</strong> Days. `;
                        if (data.holidays_in_range > 0) {
                            infoMsg += `<span class='text-success'>(Excludes ${data.holidays_in_range} Public Holiday(s))</span>`;
                        }
                        previewText.innerHTML = infoMsg;

                        if (!data.valid && data.errors.length > 0) {
                            errorBox.style.display = "block";
                            errorBox.innerHTML = "⚠️ " + data.errors.join("<br>⚠️ ");
                        }
                    } else {
                        previewText.innerHTML = "Unable to compute duration.";
                    }
                })
                .catch(err => {
                    previewText.innerHTML = "Error calculating days.";
                });
        } else {
            previewCard.style.display = "none";
        }
    }

    startDateInput.addEventListener("change", checkDays);
    endDateInput.addEventListener("change", checkDays);
    leaveTypeSelect.addEventListener("change", checkDays);
});
</script>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Apply for Leave | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
