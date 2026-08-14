<?php
/**
 * LMS Automated CLI Test Suite
 * Tests LeaveCalculator, ApprovalWorkflow State Machine, Security Helpers & Business Rules
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/LeaveCalculator.php';
require_once __DIR__ . '/../helpers/ApprovalWorkflow.php';
require_once __DIR__ . '/../helpers/LeaveCapacity.php';
require_once __DIR__ . '/../helpers/Notifier.php';
require_once __DIR__ . '/../helpers/DashboardInsights.php';

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
    /** Notifications raised through the workflow, newest last. */
    public array $notifications = [];
    public array $entitlements = [
        '5_1_2026' => ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0]
    ];
    public array $holidays = [
        '2026-05-01' => 'Workers Day'
    ];
    /** user_id => role name, mirroring the seeded accounts in schema.sql */
    public array $roles = [
        1 => 'admin',
        2 => 'executive',
        3 => 'hr',
        4 => 'manager',
        5 => 'employee',
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
                        'status' => $params['status'] ?? 'pending_manager',
                        'current_approver_role' => $params['approver_role'] ?? 'manager'
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

                // Handle INSERT INTO notifications
                if (stripos($this->query, 'INSERT INTO notifications') !== false) {
                    $this->pdo->notifications[] = [
                        'user_id' => (int)($params['user_id'] ?? 0),
                        'type'    => $params['type'] ?? '',
                        'title'   => $params['title'] ?? '',
                        'body'    => $params['body'] ?? '',
                        'link'    => $params['link'] ?? '',
                        'app_id'  => $params['app_id'] ?? null,
                    ];
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
                // Notifier resolving the holders of an approving role.
                if (stripos($this->query, 'FROM users u') !== false
                    && stripos($this->query, 'JOIN roles r') !== false) {
                    $wanted = $this->lastParams['role'] ?? '';
                    $ids = [];
                    foreach ($this->pdo->roles as $userId => $roleName) {
                        if ($roleName === $wanted) {
                            $ids[] = $userId;
                        }
                    }
                    return $ids;
                }
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
                // Notifier's context read: the application joined to its type,
                // applicant and department. Checked before the plain
                // leave_applications branch below, which would otherwise answer
                // it without the applicant's name or approver ids.
                if (stripos($this->query, 'FROM leave_applications a') !== false
                    && stripos($this->query, 'JOIN leave_types t') !== false
                    && stripos($this->query, 'JOIN users u') !== false) {
                    $id = (int)($this->lastParams['id'] ?? 1);
                    $app = $this->pdo->applications[$id] ?? null;
                    if (!$app) {
                        return false;
                    }
                    return $app + [
                        'leave_name'      => 'Annual Leave',
                        'first_name'      => 'Test',
                        'last_name'       => 'Applicant',
                        'manager_id'      => 4,
                        'department_id'   => 1,
                        'line_manager_id' => 4,
                    ];
                }
                if (stripos($this->query, 'FROM leave_applications') !== false) {
                    $id = (int)($this->lastParams['id'] ?? 1);
                    return $this->pdo->applications[$id] ?? false;
                }
                if (stripos($this->query, 'FROM leave_entitlements') !== false) {
                    $key = ($this->lastParams['user_id'] ?? 5) . '_' . ($this->lastParams['type_id'] ?? 1) . '_' . ($this->lastParams['year'] ?? 2026);
                    return $this->pdo->entitlements[$key] ?? false;
                }
                if (stripos($this->query, 'FROM leave_types') !== false) {
                    // Mirrors the configurable rule columns on leave_types so the
                    // calculator exercises the same code path as production.
                    return [
                        'id'                        => 1,
                        'name'                      => 'Annual Leave',
                        'code'                      => 'ANN',
                        'max_days_per_year'         => 20,
                        'requires_attachment'       => 0,
                        'is_paid'                   => 1,
                        'min_days_per_request'      => 0.5,
                        'max_days_per_request'      => null,
                        'allow_half_day'            => 1,
                        'min_notice_days'           => 7,
                        'attachment_threshold_days' => 0.0,
                        'is_active'                 => 1,
                    ];
                }
                if (stripos($this->query, 'JOIN roles') !== false) {
                    $uid = (int)($this->lastParams['id'] ?? 0);
                    return isset($this->pdo->roles[$uid])
                        ? ['role_name' => $this->pdo->roles[$uid]]
                        : false;
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

// Notice periods are validated against today, so eligibility and submission
// tests use future Mon-Fri ranges instead of fixed calendar dates. The pure
// working-day arithmetic below keeps its fixed dates on purpose.
$futStart  = date('Y-m-d', strtotime('monday +3 weeks'));
$futEnd    = date('Y-m-d', strtotime($futStart . ' +4 days'));   // Mon-Fri = 5 days
$futStart2 = date('Y-m-d', strtotime('monday +6 weeks'));
$futEnd2   = date('Y-m-d', strtotime($futStart2 . ' +1 day'));   // Mon-Tue = 2 days

$entKey  = '5_1_' . date('Y', strtotime($futStart));
$entKey2 = '5_1_' . date('Y', strtotime($futStart2));
$mockDb->entitlements = [
    $entKey  => ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0],
    $entKey2 => ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0],
];

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
$valValid = $calc->validateEligibility(5, 1, $futStart, $futEnd);
$tester->assert($valValid['valid'] === true, "Leave Balance Eligibility Check - Valid");

$valOver = $calc->validateEligibility(5, 1, "2026-05-01", "2026-06-30"); // > 20 days
$tester->assert($valOver['valid'] === false, "Leave Balance Eligibility Check - Over Balance Rejection");

echo "\n--- 3. Testing 3-Tier Approval Workflow Engine ---\n";
$workflow = new ApprovalWorkflow($mockDb);

// A. Submit Application
$submitRes = $workflow->submitApplication(5, 1, $futStart, $futEnd, 5.0, "Vacation request", null);
$tester->assert($submitRes['success'] === true, "Submit Application Initializer");
$appId = (int)$submitRes['id'];

// Check pending_days updated to 5.0
$ent1 = $mockDb->entitlements[$entKey]['pending_days'];
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
$entFinal = $mockDb->entitlements[$entKey];
$tester->assert((float)$entFinal['pending_days'] === 0.0 && (float)$entFinal['used_days'] === 5.0, "Final Entitlement Deduction (pending: 0, used: 5)", "Pending: {$entFinal['pending_days']}, Used: {$entFinal['used_days']}");

echo "\n--- 4. Testing Application Cancellation & Balance Restoration ---\n";
// Reset entitlement balance for test
$mockDb->entitlements[$entKey2] = ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];

// Submit another request and cancel it
$sub2 = $workflow->submitApplication(5, 1, $futStart2, $futEnd2, 2.0, "To be cancelled", null);
$appId2 = (int)$sub2['id'];

// Check pending_days updated to 2.0
$entPendingBefore = $mockDb->entitlements[$entKey2]['pending_days'];
$tester->assert((float)$entPendingBefore === 2.0, "Pending Days Reserved for Second Request");

// Cancel request by employee
$cancelRes = $workflow->cancelApplication($appId2, 5, 'employee', 'Changed mind');
$tester->assert($cancelRes['success'] === true, "Cancel Pending Leave Application");

$entPendingAfter = $mockDb->entitlements[$entKey2]['pending_days'];
$tester->assert((float)$entPendingAfter === 0.0, "Pending Days Released Back to Entitlements upon Cancellation", "Got {$entPendingAfter}");

echo "\n--- 5. Testing Role-Aware Approval Routing ---\n";

// Where each role's own application enters the chain. Pure functions, no DB.
$tester->assert(
    ApprovalWorkflow::initialStageFor(ROLE_EMPLOYEE) === [STATUS_PENDING_MANAGER, ROLE_MANAGER],
    "Employee enters at Stage 1 (Line Manager)"
);
$tester->assert(
    ApprovalWorkflow::initialStageFor(ROLE_MANAGER) === [STATUS_PENDING_HR, ROLE_HR],
    "Manager skips Stage 1 and enters at HR"
);
$tester->assert(
    ApprovalWorkflow::initialStageFor(ROLE_HR) === [STATUS_PENDING_EXECUTIVE, ROLE_EXECUTIVE],
    "HR skips Stages 1-2 and enters at Executive"
);
$tester->assert(
    ApprovalWorkflow::initialStageFor(ROLE_EXECUTIVE) === [STATUS_PENDING_HR, ROLE_HR],
    "Executive skips Stage 1 and enters at HR"
);

$adminBlocked = false;
try {
    ApprovalWorkflow::initialStageFor(ROLE_ADMIN);
} catch (Exception $e) {
    $adminBlocked = strpos($e->getMessage(), 'cannot apply') !== false;
}
$tester->assert($adminBlocked, "Admin cannot apply for leave");

// HR sign-off is terminal for an executive, intermediate for everyone else.
$tester->assert(
    ApprovalWorkflow::nextStageFor(ROLE_EXECUTIVE, STATUS_PENDING_HR) === [STATUS_APPROVED, 'none'],
    "HR approval is FINAL on an executive's own leave"
);
$tester->assert(
    ApprovalWorkflow::nextStageFor(ROLE_MANAGER, STATUS_PENDING_HR) === [STATUS_PENDING_EXECUTIVE, ROLE_EXECUTIVE],
    "HR approval escalates a manager's leave to Stage 3"
);

echo "\n--- 6. Manager Leave End-to-End (deadlock regression) ---\n";
$mgrStart = date('Y-m-d', strtotime('monday +9 weeks'));
$mgrEnd   = date('Y-m-d', strtotime($mgrStart . ' +2 days'));
$mgrKey   = '4_1_' . date('Y', strtotime($mgrStart));
$mockDb->entitlements[$mgrKey] = ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];

$mgrSub = $workflow->submitApplication(4, 1, $mgrStart, $mgrEnd, 3.0, "Manager leave", null);
$mgrAppId = (int)$mgrSub['id'];
$tester->assert(
    $mgrSub['success'] === true && $mgrSub['status'] === STATUS_PENDING_HR,
    "Manager's application never lands in the Stage 1 queue it owns",
    "Got " . ($mgrSub['status'] ?? 'n/a')
);

$mgrStage2 = $workflow->processAction($mgrAppId, 3, 'hr', 'approve', 'HR ok');
$tester->assert(
    $mgrStage2['success'] === true && $mgrStage2['new_status'] === STATUS_PENDING_EXECUTIVE,
    "Manager leave: HR approval -> Stage 3 Executive",
    "Got " . ($mgrStage2['new_status'] ?? $mgrStage2['error'])
);

$mgrStage3 = $workflow->processAction($mgrAppId, 2, 'executive', 'approve', 'Boss ok');
$tester->assert(
    $mgrStage3['success'] === true && $mgrStage3['new_status'] === STATUS_APPROVED,
    "Manager leave: Executive approval -> APPROVED",
    "Got " . ($mgrStage3['new_status'] ?? $mgrStage3['error'])
);
$tester->assert(
    (float)$mockDb->entitlements[$mgrKey]['used_days'] === 3.0
    && (float)$mockDb->entitlements[$mgrKey]['pending_days'] === 0.0,
    "Manager leave: balance deducted once approved",
    "Used: {$mockDb->entitlements[$mgrKey]['used_days']}, Pending: {$mockDb->entitlements[$mgrKey]['pending_days']}"
);

echo "\n--- 7. Executive Leave End-to-End (HR sign-off is final) ---\n";
$exStart = date('Y-m-d', strtotime('monday +12 weeks'));
$exEnd   = date('Y-m-d', strtotime($exStart . ' +1 day'));
$exKey   = '2_1_' . date('Y', strtotime($exStart));
$mockDb->entitlements[$exKey] = ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];

$exSub = $workflow->submitApplication(2, 1, $exStart, $exEnd, 2.0, "Executive leave", null);
$exAppId = (int)$exSub['id'];
$tester->assert(
    $exSub['success'] === true && $exSub['status'] === STATUS_PENDING_HR,
    "Executive application enters at HR",
    "Got " . ($exSub['status'] ?? 'n/a')
);

$exFinal = $workflow->processAction($exAppId, 3, 'hr', 'approve', 'Recorded by HR');
$tester->assert(
    $exFinal['success'] === true && $exFinal['new_status'] === STATUS_APPROVED,
    "Executive leave: HR approval finalises the application",
    "Got " . ($exFinal['new_status'] ?? $exFinal['error'])
);
$tester->assert(
    (float)$mockDb->entitlements[$exKey]['used_days'] === 2.0
    && (float)$mockDb->entitlements[$exKey]['pending_days'] === 0.0,
    "Executive leave: balance deducted at HR sign-off",
    "Used: {$mockDb->entitlements[$exKey]['used_days']}, Pending: {$mockDb->entitlements[$exKey]['pending_days']}"
);

echo "\n--- 8. Testing Skipped-Stage Notices ---\n";
$tester->assert(
    ApprovalWorkflow::skippedStagesFor(ROLE_EMPLOYEE) === [],
    "Employees skip no stages"
);
$tester->assert(
    count(ApprovalWorkflow::skippedStagesFor(ROLE_HR)) === 2,
    "HR is shown two skipped stages"
);

echo "\n--- 9. Testing Auto-Assigned Employee IDs ---\n";
$tester->assert(format_emp_id(1006) === 'EMP-1006', "Employee ID format", format_emp_id(1006));
$tester->assert(next_emp_sequence(1005) === 1006, "Sequence increments from highest existing");
$tester->assert(next_emp_sequence(null) === 1001, "Empty table starts the sequence at 1001");
$tester->assert(next_emp_sequence(0) === 1001, "Sequence floor holds when no canonical IDs exist");
$tester->assert(next_emp_sequence(2500) === 2501, "Sequence follows IDs above the seed range");

echo "\n--- 10. Testing Team Capacity & Coverage Warnings ---\n";

// Mon 2026-08-17 to Fri 2026-08-21, with the weekend either side and one holiday.
$workingDates = LeaveCapacity::workingDatesBetween('2026-08-15', '2026-08-23', ['2026-08-19']);
$tester->assert(
    $workingDates === ['2026-08-17', '2026-08-18', '2026-08-20', '2026-08-21'],
    "Working dates exclude weekends and public holidays",
    implode(', ', $workingDates)
);
$tester->assert(
    LeaveCapacity::workingDatesBetween('2026-08-22', '2026-08-23') === [],
    "A weekend-only range contains no working dates"
);
$tester->assert(
    LeaveCapacity::workingDatesBetween('2026-08-21', '2026-08-17') === [],
    "An inverted range yields no dates rather than an error"
);

// Two IT staff (department 1) away, overlapping on the 18th, plus one in HR.
$absenceRows = [
    ['application_id' => 101, 'department_id' => 1, 'user_id' => 5,
     'start_date' => '2026-08-17', 'end_date' => '2026-08-18', 'status' => STATUS_APPROVED],
    ['application_id' => 102, 'department_id' => 1, 'user_id' => 6,
     'start_date' => '2026-08-18', 'end_date' => '2026-08-21', 'status' => STATUS_PENDING_MANAGER],
    ['application_id' => 103, 'department_id' => 2, 'user_id' => 7,
     'start_date' => '2026-08-18', 'end_date' => '2026-08-18', 'status' => STATUS_APPROVED],
];
$byDay = LeaveCapacity::spreadAcrossDays($absenceRows, $workingDates);

$tester->assert(
    count($byDay['2026-08-17']) === 1 && count($byDay['2026-08-18']) === 3,
    "Absences spread across every working day they cover",
    "17th: " . count($byDay['2026-08-17']) . ", 18th: " . count($byDay['2026-08-18'])
);
$tester->assert(
    array_key_exists('2026-08-19', $byDay) === false,
    "The public holiday never appears as an absence day"
);
$tester->assert(
    count($byDay['2026-08-20']) === 1 && count($byDay['2026-08-21']) === 1,
    "A request running past a weekend still covers the days after it"
);

// Department 1 permits 1 absence at a time: the 18th has 2 and is a breach.
$warnings = LeaveCapacity::capacityWarnings($byDay, 1, 1);
$byDate = [];
foreach ($warnings as $w) {
    $byDate[$w['date']] = $w;
}
$tester->assert(
    isset($byDate['2026-08-18']) && $byDate['2026-08-18']['state'] === LeaveCapacity::OVER_LIMIT
    && $byDate['2026-08-18']['away'] === 2,
    "Two away against a limit of one is flagged over limit",
    json_encode($byDate['2026-08-18'] ?? null)
);
$tester->assert(
    isset($byDate['2026-08-17']) && $byDate['2026-08-17']['state'] === LeaveCapacity::AT_LIMIT,
    "Sitting exactly on the limit is reported as at limit, not a breach"
);
$tester->assert(
    count($warnings) === 4,
    "Only the department under test is counted, not the whole organisation",
    "Warned on " . count($warnings) . " days"
);
$tester->assert(
    count(LeaveCapacity::breachesOnly($warnings)) === 1,
    "Breaches are the subset that exceed the limit"
);
$tester->assert(
    LeaveCapacity::capacityWarnings($byDay, 1, null) === [],
    "No configured limit means no warnings"
);
$tester->assert(
    LeaveCapacity::capacityWarnings($byDay, 1, 5) === [],
    "A generous limit leaves the calendar clean"
);
$tester->assert(
    LeaveCapacity::capacityWarnings([], 1, 0) === [],
    "A zero limit does not flag days on which nobody is away"
);

// Excluding the pending request shows what the department looked like before it.
$withoutPending = LeaveCapacity::capacityWarnings($byDay, 1, 1, 102);
$withoutDates = [];
foreach ($withoutPending as $w) {
    $withoutDates[$w['date']] = $w['state'];
}
$tester->assert(
    ($withoutDates['2026-08-18'] ?? null) === LeaveCapacity::AT_LIMIT,
    "Excluding a request drops it out of the count for that day",
    json_encode($withoutDates)
);
$tester->assert(
    isset($withoutDates['2026-08-17']) && !isset($withoutDates['2026-08-20']),
    "Excluding a request clears the days only it covered"
);

echo "\n--- 11. Testing Notification Wording & Routing ---\n";

$tester->assert(
    Notifier::outcomeFor('reject', STATUS_REJECTED)[0] === Notifier::TYPE_REJECTED,
    "A rejection is reported as a rejection"
);
$tester->assert(
    Notifier::outcomeFor('approve', STATUS_APPROVED)[1] === 'Your leave request is fully approved',
    "Final approval is announced as fully approved"
);
$tester->assert(
    Notifier::outcomeFor('approve', STATUS_PENDING_HR)[0] === Notifier::TYPE_ADVANCED,
    "A Stage 1 approval reads as progress, not as approval"
);
$tester->assert(
    strpos(Notifier::awaitingTitle(STATUS_PENDING_EXECUTIVE), 'Stage 3') !== false,
    "An approver is told which stage is waiting on them",
    Notifier::awaitingTitle(STATUS_PENDING_EXECUTIVE)
);
$tester->assert(
    strpos(Notifier::describe([
        'application_no' => 'LV-2026-ABC123',
        'leave_name'     => 'Annual Leave',
        'total_days'     => 3.0,
        'start_date'     => '2026-09-07',
        'end_date'       => '2026-09-09',
    ]), 'LV-2026-ABC123 - Annual Leave, 3 working day(s), 7 Sep to 9 Sep 2026') === 0,
    "A request describes itself in one line"
);
$tester->assert(
    strpos(Notifier::describe([
        'application_no' => 'LV-2026-ONE',
        'leave_name'     => 'Sick Leave',
        'total_days'     => 0.5,
        'start_date'     => '2026-09-07',
        'end_date'       => '2026-09-07',
    ]), '0.5 working day(s), Mon 7 Sep 2026') !== false,
    "A single half-day reads as one date, not a range"
);

// End to end: an employee's request notifies the applicant and the Stage 1
// approver, and the Stage 1 approval then notifies HR.
$notifyDb = new ArrayMockPDO();
$notifyFlow = new ApprovalWorkflow($notifyDb);
$notifyDb->entitlements['5_1_2026'] = ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];
$submitted = $notifyFlow->submitApplication(5, 1, '2026-09-07', '2026-09-09', 3.0, 'Family time', null);

$recipients = array_column($notifyDb->notifications, 'user_id');
$types = array_column($notifyDb->notifications, 'type');
$tester->assert(
    $submitted['success'] === true && in_array(5, $recipients, true)
    && in_array(Notifier::TYPE_SUBMITTED, $types, true),
    "Submitting notifies the applicant that it was received",
    json_encode($types)
);
$tester->assert(
    in_array(4, $recipients, true) && in_array(Notifier::TYPE_AWAITING, $types, true),
    "Submitting puts the request in front of the Stage 1 approver",
    json_encode($recipients)
);
$tester->assert(
    !in_array(3, $recipients, true) && !in_array(2, $recipients, true),
    "Later stages are not told about a request that has not reached them",
    json_encode($recipients)
);

$notifyDb->notifications = [];
$stage1 = $notifyFlow->processAction((int)$submitted['id'], 4, ROLE_MANAGER, 'approve', 'Cover arranged');
$afterRecipients = array_column($notifyDb->notifications, 'user_id');
$afterTitles = array_column($notifyDb->notifications, 'title');
$tester->assert(
    $stage1['success'] === true && in_array(5, $afterRecipients, true),
    "An approval tells the applicant their request moved",
    json_encode($afterTitles)
);
$tester->assert(
    in_array(3, $afterRecipients, true),
    "An approval tells the next stage the request has arrived",
    json_encode($afterRecipients)
);
$tester->assert(
    !in_array(4, $afterRecipients, true),
    "The approver who just acted is not notified about their own decision",
    json_encode($afterRecipients)
);
$remarksCarried = false;
foreach ($notifyDb->notifications as $n) {
    if ((int)$n['user_id'] === 5 && strpos((string)$n['body'], 'Cover arranged') !== false) {
        $remarksCarried = true;
    }
}
$tester->assert($remarksCarried, "Approver remarks reach the applicant");

// A rejection releases the reservation and says so, without claiming approval.
$rejectDb = new ArrayMockPDO();
$rejectFlow = new ApprovalWorkflow($rejectDb);
$rejected = $rejectFlow->submitApplication(5, 1, '2026-10-05', '2026-10-06', 2.0, 'Trip', null);
$rejectDb->notifications = [];
$rejectRes = $rejectFlow->processAction((int)$rejected['id'], 4, ROLE_MANAGER, 'reject', 'Peak week');
$rejectTypes = array_column($rejectDb->notifications, 'type');
$tester->assert(
    $rejectRes['success'] === true && in_array(Notifier::TYPE_REJECTED, $rejectTypes, true)
    && !in_array(Notifier::TYPE_AWAITING, $rejectTypes, true),
    "A rejection notifies the applicant and nobody downstream",
    json_encode($rejectTypes)
);

// A withdrawal tells the approver who was waiting on it.
$cancelDb = new ArrayMockPDO();
$cancelFlow = new ApprovalWorkflow($cancelDb);
$toCancel = $cancelFlow->submitApplication(5, 1, '2026-11-02', '2026-11-03', 2.0, 'Personal', null);
$cancelDb->notifications = [];
$cancelRes = $cancelFlow->cancelApplication((int)$toCancel['id'], 5, ROLE_EMPLOYEE, 'Plans changed');
$cancelRecipients = array_column($cancelDb->notifications, 'user_id');
$tester->assert(
    $cancelRes['success'] === true && in_array(4, $cancelRecipients, true)
    && !in_array(5, $cancelRecipients, true),
    "Withdrawing a pending request tells the waiting approver, not the applicant who did it",
    json_encode($cancelDb->notifications)
);

echo "\n--- 12. Testing Dashboard Insight Figures ---\n";

$tester->assert(
    DashboardInsights::daysUntil('2026-08-20', '2026-08-14') === 6,
    "Countdown counts whole days to the start date",
    (string)DashboardInsights::daysUntil('2026-08-20', '2026-08-14')
);
$tester->assert(
    DashboardInsights::daysUntil('2026-08-10', '2026-08-14') === -4,
    "Leave already under way counts back, not forward"
);
$tester->assert(
    DashboardInsights::countdownLabel(0) === 'starts today'
    && DashboardInsights::countdownLabel(1) === 'starts tomorrow'
    && DashboardInsights::countdownLabel(9) === 'starts in 9 days'
    && DashboardInsights::countdownLabel(-2) === 'in progress',
    "Countdown reads naturally at each boundary"
);

$tester->assert(
    DashboardInsights::committedShare(20.0, 5.0, 5.0) === 0.5,
    "Committed share counts taken and pending days together",
    (string)DashboardInsights::committedShare(20.0, 5.0, 5.0)
);
$tester->assert(
    DashboardInsights::committedShare(0.0, 0.0, 0.0) === 0.0,
    "An unallocated entitlement reads as untouched rather than dividing by zero"
);
$tester->assert(
    DashboardInsights::committedShare(10.0, 12.0, 0.0) === 1.0,
    "Over-drawn leave caps at a full bar instead of overflowing it"
);

// Two departments: one healthy, one where people are banking leave and one
// person has no allocation at all.
$utilRows = [
    ['department_id' => 1, 'department_name' => 'NOC',   'user_id' => 1, 'total' => 20, 'used' => 10, 'pending' => 0],
    ['department_id' => 1, 'department_name' => 'NOC',   'user_id' => 2, 'total' => 20, 'used' => 1,  'pending' => 1],
    ['department_id' => 1, 'department_name' => 'NOC',   'user_id' => 3, 'total' => 0,  'used' => 0,  'pending' => 0],
    ['department_id' => 2, 'department_name' => 'Sales', 'user_id' => 4, 'total' => 20, 'used' => 20, 'pending' => 0],
];
$summary = DashboardInsights::summarise($utilRows);

$tester->assert(
    $summary[1]['members'] === 3 && $summary[1]['total'] === 40.0 && $summary[1]['used'] === 11.0,
    "Departments are summed per member, including members with no allocation",
    json_encode($summary[1])
);
$tester->assert(
    round($summary[1]['share'], 4) === 0.3,
    "Department share is committed days over allocated days",
    (string)$summary[1]['share']
);
$tester->assert(
    $summary[1]['low_usage'] === 1,
    "Somebody on 10% of their allowance is flagged as banking leave",
    (string)$summary[1]['low_usage']
);
$tester->assert(
    $summary[1]['unallocated'] === 1 && $summary[1]['low_usage'] !== 2,
    "A member with no allocation is counted as unallocated, not as banking leave"
);
$tester->assert(
    $summary[2]['share'] === 1.0 && $summary[2]['low_usage'] === 0,
    "A fully booked department is neither flagged nor mis-summed"
);
$tester->assert(
    DashboardInsights::summarise([]) === [],
    "No staff in scope produces no rows rather than an error"
);

exit($tester->summary());
