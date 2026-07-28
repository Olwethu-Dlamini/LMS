<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $name = sanitize($_POST['name'] ?? '');
    $code = strtoupper(sanitize($_POST['code'] ?? ''));
    $maxDays = (int)($_POST['max_days_per_year'] ?? 0);
    $requiresAttachment = isset($_POST['requires_attachment']) ? 1 : 0;
    $isPaid = isset($_POST['is_paid']) ? 1 : 0;

    if (!verify_csrf_token($csrfToken)) {
        $error = 'Invalid security token.';
    } elseif (empty($name) || empty($code)) {
        $error = 'Category name and code are mandatory.';
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO leave_types (name, code, max_days_per_year, requires_attachment, is_paid)
                VALUES (:name, :code, :max_days, :req_att, :is_paid)
            ");
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'max_days' => $maxDays,
                'req_att' => $requiresAttachment,
                'is_paid' => $isPaid
            ]);
            set_flash('success', "Leave Category '{$name}' created!");
            header('Location: ' . APP_URL . '/modules/admin/leave_types.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Error adding leave category: ' . $e->getMessage();
        }
    }
}

$types = $db->query("SELECT * FROM leave_types ORDER BY name ASC")->fetchAll();

ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="font-weight-bold text-dark mb-1"><i class="ti-settings text-primary"></i> Leave Categories & Rules</h3>
        <p class="text-muted mb-0">Configure leave types, annual allowances, and attachment policies.</p>
    </div>
    <button type="button" class="btn btn-primary font-weight-bold" data-toggle="modal" data-target="#newTypeModal">
        <i class="ti-plus"></i> Add Category
    </button>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- New Type Modal -->
<div class="modal fade" id="newTypeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold">Create Leave Category</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 form-group mb-3">
                            <label class="font-weight-bold text-dark">Category Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Study Leave" required>
                        </div>
                        <div class="col-md-4 form-group mb-3">
                            <label class="font-weight-bold text-dark">Code *</label>
                            <input type="text" name="code" class="form-control" placeholder="STD" required>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Default Days Allocation / Year *</label>
                        <input type="number" name="max_days_per_year" class="form-control" placeholder="10" required>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="requires_attachment" class="form-check-input" id="reqAtt" value="1">
                        <label class="form-check-label font-weight-bold text-dark" for="reqAtt">Mandatory Supporting File Attachment</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_paid" class="form-check-input" id="isPaid" value="1" checked>
                        <label class="form-check-label font-weight-bold text-dark" for="isPaid">Paid Leave Category</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white">
        <span class="font-weight-bold text-dark">Configured Categories</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Max Days / Year</th>
                        <th>File Attachment Policy</th>
                        <th>Payment Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $t): ?>
                    <tr>
                        <td class="font-weight-bold text-primary"><?php echo htmlspecialchars($t['code']); ?></td>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($t['name']); ?></td>
                        <td><span class="badge badge-light border"><?php echo $t['max_days_per_year']; ?> Days</span></td>
                        <td>
                            <?php if ($t['requires_attachment']): ?>
                                <span class="badge badge-warning text-dark">Mandatory for >2 Days</span>
                            <?php else: ?>
                                <span class="badge badge-light">Optional</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['is_paid']): ?>
                                <span class="badge badge-success">Paid Leave</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Unpaid Leave</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Leave Categories | ' . APP_NAME;
require_once __DIR__ . '/../../includes/layout.php';
?>
