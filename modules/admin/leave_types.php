<?php
require_once __DIR__ . '/../../includes/functions.php';
require_role(ROLE_ADMIN);

$db = getDBConnection();
$error = '';

/** Read and normalise the rule fields shared by create and edit. */
function ri_read_type_input(): array {
    $maxPerReq = trim($_POST['max_days_per_request'] ?? '');
    return [
        'name'      => sanitize($_POST['name'] ?? ''),
        'code'      => strtoupper(sanitize($_POST['code'] ?? '')),
        'max_days'  => max(0, (int)($_POST['max_days_per_year'] ?? 0)),
        'req_att'   => isset($_POST['requires_attachment']) ? 1 : 0,
        'is_paid'   => isset($_POST['is_paid']) ? 1 : 0,
        'min_req'   => max(0, (float)($_POST['min_days_per_request'] ?? 0)),
        'max_req'   => ($maxPerReq === '' ? null : max(0, (float)$maxPerReq)),
        'half'      => isset($_POST['allow_half_day']) ? 1 : 0,
        'notice'    => max(0, (int)($_POST['min_notice_days'] ?? 0)),
        'att_over'  => max(0, (float)($_POST['attachment_threshold_days'] ?? 0)),
        'active'    => isset($_POST['is_active']) ? 1 : 0,
    ];
}

/** Validate the rule combination. Returns a list of problems. */
function ri_validate_type(array $v): array {
    $problems = [];
    if ($v['name'] === '' || $v['code'] === '') {
        $problems[] = 'Category name and code are mandatory.';
    }
    if ($v['max_req'] !== null && $v['max_req'] > 0 && $v['max_req'] < $v['min_req']) {
        $problems[] = 'Maximum days per request cannot be less than the minimum.';
    }
    if ($v['max_req'] !== null && $v['max_req'] > 0 && $v['max_days'] > 0 && $v['max_req'] > $v['max_days']) {
        $problems[] = 'Maximum days per request cannot exceed the annual allocation.';
    }
    if (!$v['half'] && $v['min_req'] > 0 && fmod($v['min_req'], 1.0) !== 0.0) {
        $problems[] = 'Minimum days per request must be a whole number when half-days are not allowed.';
    }
    return $problems;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($action === 'create') {
        $v = ri_read_type_input();
        $problems = ri_validate_type($v);
        if ($problems) {
            $error = implode('<br>', $problems);
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO leave_types
                        (name, code, max_days_per_year, requires_attachment, is_paid,
                         min_days_per_request, max_days_per_request, allow_half_day,
                         min_notice_days, attachment_threshold_days, is_active)
                    VALUES
                        (:name, :code, :max_days, :req_att, :is_paid,
                         :min_req, :max_req, :half, :notice, :att_over, :active)
                ");
                $stmt->execute([
                    'name' => $v['name'], 'code' => $v['code'], 'max_days' => $v['max_days'],
                    'req_att' => $v['req_att'], 'is_paid' => $v['is_paid'],
                    'min_req' => $v['min_req'], 'max_req' => $v['max_req'], 'half' => $v['half'],
                    'notice' => $v['notice'], 'att_over' => $v['att_over'], 'active' => $v['active'],
                ]);
                set_flash('success', "Leave category '{$v['name']}' created.");
                header('Location: ' . APP_URL . '/modules/admin/leave_types.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error adding leave category: ' . escape_html($e->getMessage());
            }
        }
    } elseif ($action === 'edit') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $v = ri_read_type_input();
        $problems = ri_validate_type($v);
        if ($typeId <= 0) {
            $problems[] = 'Invalid leave category.';
        }
        if ($problems) {
            $error = implode('<br>', $problems);
        } else {
            try {
                $stmt = $db->prepare("
                    UPDATE leave_types SET
                        name = :name, code = :code, max_days_per_year = :max_days,
                        requires_attachment = :req_att, is_paid = :is_paid,
                        min_days_per_request = :min_req, max_days_per_request = :max_req,
                        allow_half_day = :half, min_notice_days = :notice,
                        attachment_threshold_days = :att_over, is_active = :active
                    WHERE id = :id
                ");
                $stmt->execute([
                    'name' => $v['name'], 'code' => $v['code'], 'max_days' => $v['max_days'],
                    'req_att' => $v['req_att'], 'is_paid' => $v['is_paid'],
                    'min_req' => $v['min_req'], 'max_req' => $v['max_req'], 'half' => $v['half'],
                    'notice' => $v['notice'], 'att_over' => $v['att_over'], 'active' => $v['active'],
                    'id' => $typeId,
                ]);
                set_flash('success', "Leave category '{$v['name']}' updated.");
                header('Location: ' . APP_URL . '/modules/admin/leave_types.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error updating leave category: ' . escape_html($e->getMessage());
            }
        }
    } elseif ($action === 'retire') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $stmt = $db->prepare("UPDATE leave_types SET is_active = 1 - is_active WHERE id = :id");
        $stmt->execute(['id' => $typeId]);
        set_flash('success', 'Leave category availability updated.');
        header('Location: ' . APP_URL . '/modules/admin/leave_types.php');
        exit;
    } elseif ($action === 'delete') {
        $typeId = (int)($_POST['type_id'] ?? 0);

        $inUse = $db->prepare("SELECT COUNT(*) FROM leave_applications WHERE leave_type_id = :id");
        $inUse->execute(['id' => $typeId]);
        $appCount = (int)$inUse->fetchColumn();

        if ($typeId <= 0) {
            $error = 'Invalid leave category.';
        } elseif ($appCount > 0) {
            // Never destroy a category that historical applications point at.
            $stmt = $db->prepare("UPDATE leave_types SET is_active = 0 WHERE id = :id");
            $stmt->execute(['id' => $typeId]);
            set_flash('warning', "That category is used by {$appCount} leave application(s), so it was retired instead of deleted. Historical records stay intact and it no longer appears on the apply form.");
            header('Location: ' . APP_URL . '/modules/admin/leave_types.php');
            exit;
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM leave_types WHERE id = :id");
                $stmt->execute(['id' => $typeId]);
                set_flash('success', 'Leave category deleted permanently.');
                header('Location: ' . APP_URL . '/modules/admin/leave_types.php');
                exit;
            } catch (PDOException $e) {
                $error = 'Error deleting leave category: ' . escape_html($e->getMessage());
            }
        }
    }
}

// Category list with a usage count so the UI can explain what delete will do.
$types = $db->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM leave_applications a WHERE a.leave_type_id = t.id) AS app_count
    FROM leave_types t
    ORDER BY t.is_active DESC, t.name ASC
")->fetchAll();

/** Render the shared rule fields for a create/edit form. */
function ri_type_fields(?array $t = null): void {
    $val = function (string $key, $default = '') use ($t) {
        if ($t === null) return $default;
        return $t[$key] ?? $default;
    };
    $checked = function (string $key, bool $default) use ($t): string {
        $on = $t === null ? $default : (int)($t[$key] ?? 0) === 1;
        return $on ? 'checked' : '';
    };
    $sfx = $t === null ? 'New' : (int)$t['id'];
    ?>
    <div class="row">
        <div class="col-md-8 form-group mb-3">
            <label>Category Name *</label>
            <input type="text" name="name" class="form-control" required
                   placeholder="e.g. Study Leave" value="<?php echo htmlspecialchars((string)$val('name')); ?>">
        </div>
        <div class="col-md-4 form-group mb-3">
            <label>Code *</label>
            <input type="text" name="code" class="form-control" required maxlength="10"
                   placeholder="STD" value="<?php echo htmlspecialchars((string)$val('code')); ?>">
        </div>
    </div>

    <div class="ri-section-title mt-2">Annual Allocation</div>
    <div class="form-group mb-3">
        <label>Default Days per Year *</label>
        <input type="number" name="max_days_per_year" class="form-control" min="0" required
               value="<?php echo htmlspecialchars((string)$val('max_days_per_year', 0)); ?>">
        <small class="form-text text-muted">Used when seeding entitlements for new staff.</small>
    </div>

    <div class="ri-section-title mt-3">Duration Rules (per request)</div>
    <div class="row">
        <div class="col-md-4 form-group mb-3">
            <label>Minimum Days</label>
            <input type="number" step="0.5" min="0" name="min_days_per_request" class="form-control"
                   value="<?php echo htmlspecialchars((string)$val('min_days_per_request', '0.5')); ?>">
        </div>
        <div class="col-md-4 form-group mb-3">
            <label>Maximum Days</label>
            <input type="number" step="0.5" min="0" name="max_days_per_request" class="form-control"
                   placeholder="No limit"
                   value="<?php echo htmlspecialchars((string)$val('max_days_per_request', '')); ?>">
            <small class="form-text text-muted">Blank = no cap.</small>
        </div>
        <div class="col-md-4 form-group mb-3">
            <label>Advance Notice (days)</label>
            <input type="number" min="0" name="min_notice_days" class="form-control"
                   value="<?php echo htmlspecialchars((string)$val('min_notice_days', 0)); ?>">
            <small class="form-text text-muted">0 = same day, and allows backdating.</small>
        </div>
    </div>
    <div class="form-check mb-3">
        <input type="checkbox" name="allow_half_day" class="form-check-input" value="1"
               id="half<?php echo $sfx; ?>" <?php echo $checked('allow_half_day', true); ?>>
        <label class="form-check-label" for="half<?php echo $sfx; ?>">Allow half-day requests</label>
    </div>

    <div class="ri-section-title mt-3">Documents &amp; Payment</div>
    <div class="form-check mb-2">
        <input type="checkbox" name="requires_attachment" class="form-check-input" value="1"
               id="att<?php echo $sfx; ?>" <?php echo $checked('requires_attachment', false); ?>>
        <label class="form-check-label" for="att<?php echo $sfx; ?>">Requires a supporting document</label>
    </div>
    <div class="form-group mb-3">
        <label>Only required above (working days)</label>
        <input type="number" step="0.5" min="0" name="attachment_threshold_days" class="form-control"
               value="<?php echo htmlspecialchars((string)$val('attachment_threshold_days', '0')); ?>">
        <small class="form-text text-muted">e.g. 2 means a document is demanded once a request exceeds 2 days. 0 means always.</small>
    </div>
    <div class="form-check mb-2">
        <input type="checkbox" name="is_paid" class="form-check-input" value="1"
               id="paid<?php echo $sfx; ?>" <?php echo $checked('is_paid', true); ?>>
        <label class="form-check-label" for="paid<?php echo $sfx; ?>">Paid leave</label>
    </div>
    <div class="form-check mb-2">
        <input type="checkbox" name="is_active" class="form-check-input" value="1"
               id="active<?php echo $sfx; ?>" <?php echo $checked('is_active', true); ?>>
        <label class="form-check-label" for="active<?php echo $sfx; ?>">Available on the apply form</label>
    </div>
    <?php
}

ob_start();
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Create -->
<div class="modal fade" id="newTypeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title">Create Leave Category</h5>
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body"><?php ri_type_fields(null); ?></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="ti-clipboard"></i> Configured Categories (<?php echo count($types); ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Per Year</th>
                        <th>Per Req.</th>
                        <th>Notice</th>
                        <th>Half Day</th>
                        <th>Document</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>In Use</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($types as $t): ?>
                    <tr<?php echo (int)$t['is_active'] === 0 ? ' class="text-muted"' : ''; ?>>
                        <td class="font-weight-bold text-primary ri-nowrap"><?php echo htmlspecialchars($t['code']); ?></td>
                        <td class="font-weight-bold text-dark"><?php echo htmlspecialchars($t['name']); ?></td>
                        <td><span class="badge badge-light"><?php echo (int)$t['max_days_per_year']; ?> days</span></td>
                        <td class="ri-nowrap">
                            <small>
                                min <?php echo rtrim(rtrim(number_format((float)$t['min_days_per_request'], 1), '0'), '.'); ?>
                                &middot;
                                max <?php echo $t['max_days_per_request'] === null
                                        ? '&infin;'
                                        : rtrim(rtrim(number_format((float)$t['max_days_per_request'], 1), '0'), '.'); ?>
                            </small>
                        </td>
                        <td class="ri-nowrap"><small><?php echo (int)$t['min_notice_days'] === 0 ? 'none' : (int)$t['min_notice_days'] . ' day(s)'; ?></small></td>
                        <td>
                            <?php echo (int)$t['allow_half_day'] === 1
                                ? '<span class="badge badge-info">Allowed</span>'
                                : '<span class="badge badge-secondary">Whole days</span>'; ?>
                        </td>
                        <td>
                            <?php if ((int)$t['requires_attachment'] === 1): ?>
                                <span class="badge badge-warning">
                                    <?php echo (float)$t['attachment_threshold_days'] > 0
                                        ? 'Over ' . rtrim(rtrim(number_format((float)$t['attachment_threshold_days'], 1), '0'), '.') . ' days'
                                        : 'Always'; ?>
                                </span>
                            <?php else: ?>
                                <span class="badge badge-light">Optional</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo (int)$t['is_paid'] === 1
                                ? '<span class="badge badge-success">Paid</span>'
                                : '<span class="badge badge-secondary">Unpaid</span>'; ?>
                        </td>
                        <td>
                            <?php echo (int)$t['is_active'] === 1
                                ? '<span class="badge badge-success">Active</span>'
                                : '<span class="badge badge-danger">Retired</span>'; ?>
                        </td>
                        <td><small class="text-muted"><?php echo (int)$t['app_count']; ?></small></td>
                        <td class="ri-actions">
                            <button type="button" class="btn btn-xs btn-outline-primary mb-1"
                                    data-toggle="modal" data-target="#editType<?php echo (int)$t['id']; ?>">
                                <i class="ti-pencil"></i> Edit
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-danger mb-1"
                                    data-toggle="modal" data-target="#delType<?php echo (int)$t['id']; ?>">
                                <i class="ti-trash"></i> Delete
                            </button>

                            <!-- Edit -->
                            <div class="modal fade text-left" id="editType<?php echo (int)$t['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog modal-lg" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="type_id" value="<?php echo (int)$t['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit: <?php echo htmlspecialchars($t['name']); ?></h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body"><?php ri_type_fields($t); ?></div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Delete / retire -->
                            <div class="modal fade text-left" id="delType<?php echo (int)$t['id']; ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog" role="document">
                                    <div class="modal-content">
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="type_id" value="<?php echo (int)$t['id']; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Delete <?php echo htmlspecialchars($t['name']); ?>?</h5>
                                                <button type="button" class="close" data-dismiss="modal">&times;</button>
                                            </div>
                                            <div class="modal-body">
                                                <?php if ((int)$t['app_count'] > 0): ?>
                                                    <div class="alert alert-warning mb-3">
                                                        <strong><?php echo (int)$t['app_count']; ?></strong> leave application(s)
                                                        reference this category, so it cannot be deleted without destroying
                                                        those records.
                                                    </div>
                                                    <p class="mb-0">Confirming will <strong>retire</strong> it instead: history
                                                    and reports stay intact, and it stops appearing on the apply form.</p>
                                                <?php else: ?>
                                                    <p>No leave applications use this category, so it can be removed permanently.</p>
                                                    <div class="alert alert-danger mb-0">
                                                        This also deletes any staff entitlement allocations for
                                                        <strong><?php echo htmlspecialchars($t['code']); ?></strong>. This cannot be undone.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger font-weight-bold">
                                                    <?php echo (int)$t['app_count'] > 0 ? 'Retire Category' : 'Delete Permanently'; ?>
                                                </button>
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
    </div>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Leave Categories | ' . APP_NAME;
$pageHeading = 'Leave Categories & Rules';
$pageSubtitle = 'Allocations, duration limits, notice periods and document policy per leave type.';
$pageIcon = 'ti-clipboard';
$pageActions = '<button type="button" class="btn btn-light font-weight-bold" data-toggle="modal" data-target="#newTypeModal">'
             . '<i class="ti-plus"></i> Add Category</button>';
require_once __DIR__ . '/../../includes/admin_layout.php';
?>
