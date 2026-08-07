<?php
/**
 * LMS Automated CLI Test Suite
 * Tests LeaveCalculator, ApprovalWorkflow State Machine, Security Helpers & Business Rules
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/LeaveCalculator.php';
require_once __DIR__ . '/../helpers/ApprovalWorkflow.php';

class LMS_TestCase {
    private int $passed = 0;
    private int $failed = 0;

    public function assert(bool $condition, string $testName, string $message = ''): void {
        if ($condition) {
            $this->passed++;
            echo "  [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$testName}" . ($message ? " - {$message}" : "") . "\n";
        }
    }

    public function summary(): int {
        echo "\n=========================================\n";
        echo " Test Suite Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "=========================================\n";
        return $this->failed === 0 ? 0 : 1;
    }
}

// Lightweight Standalone Mock PDO for PHP CLI Testing without SQLite dependency
class ArrayMockPDO extends PDO {
    public array $applications = [];
    public array $logs = [];
    public array $entitlements = [
        '5_1_2026' => ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0]
    ];
    public array $holidays = [
        '2026-05-01' => 'Workers Day'
    ];

    public function __construct() {
        // Dummy constructor
    }

    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function lastInsertId($name = null): string {
        return (string)count($this->applications);
    }

    public function prepare($query, $options = null) {
        $self = $this;
        return new class($query, $self) {
            private string $query;
            private ArrayMockPDO $pdo;
            private ?array $lastParams = null;

            public function __construct(string $query, ArrayMockPDO $pdo) {
                $this->query = $query;
                $this->pdo = $pdo;
            }

            public function execute(?array $params = null): bool {
                $this->lastParams = $params;

                // Handle INSERT INTO leave_applications
                if (stripos($this->query, 'INSERT INTO leave_applications') !== false) {
                    $id = count($this->pdo->applications) + 1;
                    $this->pdo->applications[$id] = [
                        'id' => $id,
                        'application_no' => $params['app_no'] ?? 'LV-2026-123456',
                        'user_id' => $params['user_id'] ?? 5,
                        'leave_type_id' => $params['type_id'] ?? 1,
                        'start_date' => $params['start_date'] ?? '2026-05-04',
                        'end_date' => $params['end_date'] ?? '2026-05-08',
                        'total_days' => $params['days'] ?? 5.0,
                        'reason' => $params['reason'] ?? '',
                        'attachment_path' => $params['attachment'] ?? null,
                        'status' => 'pending_manager',
                        'current_approver_role' => 'manager'
                    ];
                }

                // Handle UPDATE leave_entitlements SET pending_days = pending_days + ...
                if (stripos($this->query, 'pending_days +') !== false) {
                    $days = (float)$params['days'];
                    $key = ($params['user_id'] ?? 5) . '_' . ($params['type_id'] ?? 1) . '_' . ($params['year'] ?? 2026);
                    if (isset($this->pdo->entitlements[$key])) {
                        $this->pdo->entitlements[$key]['pending_days'] += $days;
                    }
                }

                // Handle UPDATE leave_entitlements release pending_days
                if (stripos($this->query, 'pending_days -') !== false && stripos($this->query, 'used_days +') === false) {
                    $days = (float)$params['days'];
                    $key = ($params['user_id'] ?? 5) . '_' . ($params['type_id'] ?? 1) . '_' . ($params['year'] ?? 2026);
                    if (isset($this->pdo->entitlements[$key])) {
                        $this->pdo->entitlements[$key]['pending_days'] = max(0.0, $this->pdo->entitlements[$key]['pending_days'] - $days);
                    }
                }

                // Handle UPDATE leave_entitlements deduct used_days
                if (stripos($this->query, 'used_days +') !== false) {
                    $days = (float)$params['days'];
                    $key = ($params['user_id'] ?? 5) . '_' . ($params['type_id'] ?? 1) . '_' . ($params['year'] ?? 2026);
                    if (isset($this->pdo->entitlements[$key])) {
                        $this->pdo->entitlements[$key]['pending_days'] = max(0.0, $this->pdo->entitlements[$key]['pending_days'] - $days);
                        $this->pdo->entitlements[$key]['used_days'] += $days;
                    }
                }

                // Handle UPDATE leave_applications SET status = ...
                if (stripos($this->query, 'UPDATE leave_applications') !== false) {
                    $id = (int)($params['id'] ?? 0);
                    if (isset($this->pdo->applications[$id])) {
                        if (isset($params['status'])) $this->pdo->applications[$id]['status'] = $params['status'];
                        if (isset($params['role'])) $this->pdo->applications[$id]['current_approver_role'] = $params['role'];
                    }
                }

                return true;
            }

            public function fetchAll(int $mode = PDO::FETCH_ASSOC): array {
                if (stripos($this->query, 'FROM holidays') !== false) {
                    $start = $this->lastParams['start'] ?? '';
                    $end = $this->lastParams['end'] ?? '';
                    $res = [];
                    foreach ($this->pdo->holidays as $date => $title) {
                        if ($date >= $start && $date <= $end) {
                            $res[] = $date;
                        }
                    }
                    return $res;
                }
                return [];
            }

            public function fetch(int $mode = PDO::FETCH_ASSOC) {
                if (stripos($this->query, 'FROM leave_applications') !== false) {
                    $id = (int)($this->lastParams['id'] ?? 1);
                    return $this->pdo->applications[$id] ?? false;
                }
                if (stripos($this->query, 'FROM leave_entitlements') !== false) {
                    $key = ($this->lastParams['user_id'] ?? 5) . '_' . ($this->lastParams['type_id'] ?? 1) . '_' . ($this->lastParams['year'] ?? 2026);
                    return $this->pdo->entitlements[$key] ?? false;
                }
                if (stripos($this->query, 'FROM leave_types') !== false) {
                    return ['code' => 'ANN', 'requires_attachment' => 0];
                }
                if (stripos($this->query, 'FROM users') !== false) {
                    return ['manager_id' => 4, 'line_manager_id' => 4];
                }
                return false;
            }

            public function fetchColumn(int $column = 0) {
                if (stripos($this->query, 'COUNT(*)') !== false) {
                    return 0;
                }
                return false;
            }
        };
    }
}

$tester = new LMS_TestCase();
$mockDb = new ArrayMockPDO();

echo "\n--- 1. Testing Security & Helper Functions ---\n";
$token = generate_csrf_token();
$tester->assert(!empty($token), "CSRF Token Generation");
$tester->assert(verify_csrf_token($token), "CSRF Token Verification Success");
$tester->assert(!verify_csrf_token("invalid_token"), "CSRF Token Verification Rejection");
$tester->assert(sanitize("<script>alert('xss');</script>") === "&lt;script&gt;alert(&#039;xss&#039;);&lt;/script&gt;", "Input XSS Sanitization");

echo "\n--- 2. Testing LeaveCalculator Engine ---\n";
$calc = new LeaveCalculator($mockDb);

// Monday to Friday (5 days)
$days1 = $calc->calculateWorkingDays("2026-05-04", "2026-05-08");
$tester->assert($days1 === 5.0, "Standard 5 Weekday Working Days", "Got {$days1}");

// Range containing weekend (Friday to Monday: 2026-05-08 to 2026-05-11 = 2 working days: Fri, Mon)
$days2 = $calc->calculateWorkingDays("2026-05-08", "2026-05-11");
$tester->assert($days2 === 2.0, "Weekend Exclusion (Fri-Mon = 2 days)", "Got {$days2}");

// Range containing public holiday (2026-05-01 is Workers Day holiday, Friday)
$days3 = $calc->calculateWorkingDays("2026-04-30", "2026-05-01");
$tester->assert($days3 === 1.0, "Public Holiday Exclusion (Thu-Fri with Fri holiday = 1 day)", "Got {$days3}");

// Half-day option
$daysHalf = $calc->calculateWorkingDays("2026-05-04", "2026-05-04", "half_morning");
$tester->assert($daysHalf === 0.5, "Half Day Duration Calculation", "Got {$daysHalf}");

// Validation eligibility check
$valValid = $calc->validateEligibility(5, 1, "2026-05-04", "2026-05-08");
$tester->assert($valValid['valid'] === true, "Leave Balance Eligibility Check - Valid");

$valOver = $calc->validateEligibility(5, 1, "2026-05-01", "2026-06-30"); // > 20 days
$tester->assert($valOver['valid'] === false, "Leave Balance Eligibility Check - Over Balance Rejection");

echo "\n--- 3. Testing 3-Tier Approval Workflow Engine ---\n";
$workflow = new ApprovalWorkflow($mockDb);

// A. Submit Application
$submitRes = $workflow->submitApplication(5, 1, "2026-05-04", "2026-05-08", 5.0, "Vacation request", null);
$tester->assert($submitRes['success'] === true, "Submit Application Initializer");
$appId = (int)$submitRes['id'];

// Check pending_days updated to 5.0
$ent1 = $mockDb->entitlements['5_1_2026']['pending_days'];
$tester->assert((float)$ent1 === 5.0, "Pending Days Reserved in Entitlements", "Got {$ent1}");

// B. Self-Approval Block
$selfApproveRes = $workflow->processAction($appId, 5, 'manager', 'approve', 'Self approve attempt');
$tester->assert($selfApproveRes['success'] === false && strpos($selfApproveRes['error'], 'Self-approval') !== false, "Self Approval Restriction Blocked", "Got " . ($selfApproveRes['error'] ?? 'success'));

// C. Stage 1 Approval by Line Manager (User 4 - David)
$stage1 = $workflow->processAction($appId, 4, 'manager', 'approve', 'Manager approved');
$tester->assert($stage1['success'] === true && $stage1['new_status'] === STATUS_PENDING_HR, "Stage 1 Manager Approval -> Transition to pending_hr");

// D. Stage 2 Approval by HR (User 3 - Sarah)
$stage2 = $workflow->processAction($appId, 3, 'hr', 'approve', 'HR approved');
$tester->assert($stage2['success'] === true && $stage2['new_status'] === STATUS_PENDING_EXECUTIVE, "Stage 2 HR Approval -> Transition to pending_executive");

// E. Stage 3 Approval by Executive (User 2 - Boss)
$stage3 = $workflow->processAction($appId, 2, 'executive', 'approve', 'Final boss signoff');
$tester->assert($stage3['success'] === true && $stage3['new_status'] === STATUS_APPROVED, "Stage 3 Executive Approval -> Status APPROVED");

// Check finalized entitlement balance (pending_days = 0, used_days = 5)
$entFinal = $mockDb->entitlements['5_1_2026'];
$tester->assert((float)$entFinal['pending_days'] === 0.0 && (float)$entFinal['used_days'] === 5.0, "Final Entitlement Deduction (pending: 0, used: 5)", "Pending: {$entFinal['pending_days']}, Used: {$entFinal['used_days']}");

echo "\n--- 4. Testing Application Cancellation & Balance Restoration ---\n";
// Reset entitlement balance for test
$mockDb->entitlements['5_1_2026'] = ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];

// Submit another request and cancel it
$sub2 = $workflow->submitApplication(5, 1, "2026-06-01", "2026-06-02", 2.0, "To be cancelled", null);
$appId2 = (int)$sub2['id'];

// Check pending_days updated to 2.0
$entPendingBefore = $mockDb->entitlements['5_1_2026']['pending_days'];
$tester->assert((float)$entPendingBefore === 2.0, "Pending Days Reserved for Second Request");

// Cancel request by employee
$cancelRes = $workflow->cancelApplication($appId2, 5, 'employee', 'Changed mind');
$tester->assert($cancelRes['success'] === true, "Cancel Pending Leave Application");

$entPendingAfter = $mockDb->entitlements['5_1_2026']['pending_days'];
$tester->assert((float)$entPendingAfter === 0.0, "Pending Days Released Back to Entitlements upon Cancellation", "Got {$entPendingAfter}");

exit($tester->summary());
