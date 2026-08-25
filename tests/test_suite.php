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
require_once __DIR__ . '/../helpers/AttachmentStore.php';
require_once __DIR__ . '/../helpers/LoginThrottle.php';
require_once __DIR__ . '/../helpers/Mailer.php';
require_once __DIR__ . '/../helpers/EmailTemplate.php';
require_once __DIR__ . '/../helpers/EmailQueue.php';

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
    /**
     * The leave type every rule lookup returns. Mirrors the configurable columns
     * on leave_types so the calculator exercises the production code path, and is
     * public so a test can reconfigure the policy - a category with no notice
     * period, say - without a second mock.
     */
    public array $leaveType = [
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
                    return $this->pdo->leaveType;
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
// Escaping happens on output, not on input: escaping in both places is what
// made "Sales & Marketing" render as "Sales &amp; Marketing" on every screen.
$tester->assert(
    escape_html("<script>alert('xss');</script>") === "&lt;script&gt;alert(&#039;xss&#039;);&lt;/script&gt;",
    "Output escaping neutralises markup"
);
$tester->assert(
    sanitize("  Sales & Marketing  ") === "Sales & Marketing",
    "Stored text keeps the characters people typed",
    sanitize("  Sales & Marketing  ")
);
$tester->assert(
    sanitize("Mum's 60th") === "Mum's 60th",
    "An apostrophe survives storage instead of arriving as an entity"
);
$tester->assert(
    escape_html(sanitize("<b>hi</b>")) === "&lt;b&gt;hi&lt;/b&gt;",
    "Markup typed into a form is still inert once escaped for display"
);
$tester->assert(
    sanitize("line one\nline two") === "line one\nline two",
    "A multi-line reason keeps its line breaks"
);
$tester->assert(
    sanitize("bad\x00value\x07") === "badvalue",
    "Control characters are stripped before storage",
    sanitize("bad\x00value\x07")
);

// Sign-in rate limiting. The window runs from the most recent failure, so
// somebody still guessing keeps the door shut and somebody who walked away
// finds it open again.
$tester->assert(
    LoginThrottle::secondsToWait(4, '2026-09-09 10:00:00', '2026-09-09 10:00:30') === 0,
    "Failures under the limit do not hold anybody up"
);
$tester->assert(
    LoginThrottle::secondsToWait(5, '2026-09-09 10:00:00', '2026-09-09 10:00:30') === 870,
    "The limit closes the door for the rest of the window",
    (string)LoginThrottle::secondsToWait(5, '2026-09-09 10:00:00', '2026-09-09 10:00:30')
);
$tester->assert(
    LoginThrottle::secondsToWait(9, '2026-09-09 10:00:00', '2026-09-09 10:20:00') === 0,
    "Once the window has passed the door opens again"
);
$tester->assert(
    LoginThrottle::secondsToWait(9, null, '2026-09-09 10:00:00') === 0,
    "No recorded failure means nothing to wait for"
);
$tester->assert(
    LoginThrottle::waitLabel(30) === 'a minute' && LoginThrottle::waitLabel(870) === '15 minutes',
    "The wait is worded in whole minutes"
);
$tester->assert(
    LoginThrottle::callerAddress(['REMOTE_ADDR' => '10.0.0.4']) === '10.0.0.4'
    && LoginThrottle::callerAddress(['HTTP_X_FORWARDED_FOR' => '1.2.3.4']) === 'unknown',
    "A forwarded-for header is never trusted as the caller's address"
);
// The switch, and the fact that the rules survive being switched off: the
// arithmetic above still answers, it is simply never consulted.
$tester->assert(
    LoginThrottle::enabled() === LOGIN_THROTTLE_ENABLED,
    "Rate limiting follows the LOGIN_THROTTLE_ENABLED switch"
);
$tester->assert(
    LoginThrottle::enabled() === false,
    "Rate limiting ships switched off for testing",
    "LOGIN_THROTTLE_ENABLED is " . var_export(LOGIN_THROTTLE_ENABLED, true)
);

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

// A half day belongs to one day. Across a range it used to quietly subtract
// half a day from the total, so a Mon-Fri request came to 4.5.
$daysHalfRange = $calc->calculateWorkingDays("2026-05-04", "2026-05-08", "half_morning");
$tester->assert(
    $daysHalfRange === 5.0,
    "A half day across a multi-day range is counted in full, not as a week minus half a day",
    "Got {$daysHalfRange}"
);
$valHalfRange = $calc->validateEligibility(5, 1, $futStart, $futEnd, null, 'half_morning');
$tester->assert(
    $valHalfRange['valid'] === false,
    "A half day spanning several days is refused rather than silently reinterpreted"
);
$valHalfSingle = $calc->validateEligibility(5, 1, $futStart, $futStart, null, 'half_afternoon');
$tester->assert(
    $valHalfSingle['valid'] === true && $valHalfSingle['days'] === 0.5,
    "A half day on a single day is still accepted",
    implode(' | ', $valHalfSingle['errors'])
);

// Validation eligibility check
$valValid = $calc->validateEligibility(5, 1, $futStart, $futEnd);
$tester->assert($valValid['valid'] === true, "Leave Balance Eligibility Check - Valid");

$valOver = $calc->validateEligibility(5, 1, "2026-05-01", "2026-06-30"); // > 20 days
$tester->assert($valOver['valid'] === false, "Leave Balance Eligibility Check - Over Balance Rejection");

// Notice periods, and what a zero-notice category means. Sick leave is recorded
// after the fact, so a type demanding no notice must accept a start date that
// has already passed - the apply form's date picker is driven by the same rule.
$noticeType = $mockDb->leaveType;

$pastStart = date('Y-m-d', strtotime('last monday -1 week'));
$pastEnd   = date('Y-m-d', strtotime($pastStart . ' +1 day'));
$mockDb->entitlements['5_1_' . date('Y', strtotime($pastStart))] =
    ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];

$valBackdatedBlocked = $calc->validateEligibility(5, 1, $pastStart, $pastEnd);
$tester->assert(
    $valBackdatedBlocked['valid'] === false,
    "A category demanding notice refuses a start date that has already passed"
);

$mockDb->leaveType = ['min_notice_days' => 0, 'name' => 'Sick Leave', 'code' => 'SICK'] + $noticeType;
$valBackdated = $calc->validateEligibility(5, 1, $pastStart, $pastEnd);
$tester->assert(
    $valBackdated['valid'] === true,
    "A category with no notice period accepts leave recorded after the fact",
    implode(' | ', $valBackdated['errors'])
);

$mockDb->leaveType = $noticeType;

echo "\n--- 3. Testing 3-Tier Approval Workflow Engine ---\n";
$workflow = new ApprovalWorkflow($mockDb);

// A. Submit Application
$submitRes = $workflow->submitApplication(5, 1, $futStart, $futEnd, 5.0, "Vacation request", null);
$tester->assert($submitRes['success'] === true, "Submit Application Initializer");
$appId = (int)$submitRes['id'];

// Check pending_days updated to 5.0
$ent1 = $mockDb->entitlements[$entKey]['pending_days'];
$tester->assert((float)$ent1 === 5.0, "Pending Days Reserved in Entitlements", "Got {$ent1}");

// A2. The balance is re-checked inside the transaction, not just before it.
// Two submissions racing each other both pass the pre-check against the same
// snapshot; only the one that reads the row first may reserve against it.
$raceKey = '5_1_' . date('Y', strtotime($futStart2));
$mockDb->entitlements[$raceKey] = ['total_days' => 5.0, 'used_days' => 0.0, 'pending_days' => 4.0];
$raceRes = $workflow->submitApplication(5, 1, $futStart2, $futEnd2, 2.0, "Second half of a race", null);
$tester->assert(
    $raceRes['success'] === false && strpos($raceRes['error'], 'Insufficient balance') !== false,
    "A submission whose balance was spent while it was in flight is refused",
    $raceRes['error'] ?? 'succeeded'
);
$tester->assert(
    (float)$mockDb->entitlements[$raceKey]['pending_days'] === 4.0,
    "A refused submission reserves nothing",
    (string)$mockDb->entitlements[$raceKey]['pending_days']
);
$mockDb->entitlements[$raceKey] = ['total_days' => 20.0, 'used_days' => 0.0, 'pending_days' => 0.0];

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

// Who may cancel, and until when.
$approvedFuture = ['status' => STATUS_APPROVED, 'start_date' => '2026-09-14'];
$approvedStarted = ['status' => STATUS_APPROVED, 'start_date' => '2026-09-07'];
$pendingStarted = ['status' => STATUS_PENDING_MANAGER, 'start_date' => '2026-09-07'];
$today = '2026-09-09';

$tester->assert(
    ApprovalWorkflow::cancellationRefusal($approvedFuture, true, ROLE_EMPLOYEE, $today) === null,
    "An applicant can cancel approved leave they have not started"
);
$tester->assert(
    ApprovalWorkflow::cancellationRefusal($approvedStarted, true, ROLE_EMPLOYEE, $today) !== null,
    "An applicant cannot hand back leave they are already taking"
);
$tester->assert(
    ApprovalWorkflow::cancellationRefusal(
        ['status' => STATUS_APPROVED, 'start_date' => $today], true, ROLE_EMPLOYEE, $today
    ) !== null,
    "Leave starting today counts as under way, not as still cancellable"
);
$tester->assert(
    ApprovalWorkflow::cancellationRefusal($approvedStarted, false, ROLE_HR, $today) === null
    && ApprovalWorkflow::cancellationRefusal($approvedStarted, false, ROLE_ADMIN, $today) === null,
    "HR and administrators can still correct leave that has started"
);
$tester->assert(
    ApprovalWorkflow::cancellationRefusal($pendingStarted, true, ROLE_EMPLOYEE, $today) === null,
    "A request still awaiting a decision can always be withdrawn"
);
$tester->assert(
    ApprovalWorkflow::cancellationRefusal($approvedFuture, false, ROLE_MANAGER, $today) !== null,
    "A line manager cannot cancel somebody else's approved leave"
);
$tester->assert(
    ApprovalWorkflow::cancellationRefusal(
        ['status' => STATUS_CANCELLED, 'start_date' => '2026-09-14'], true, ROLE_HR, $today
    ) !== null,
    "An already cancelled application cannot be cancelled twice"
);

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

// Costing a request that does not exist yet: the applicant is added to every
// working day they are asking for, then measured like any stored application.
$prospective = LeaveCapacity::withExtraAbsence($byDay, [
    'application_id' => 0, 'user_id' => 9, 'department_id' => 1, 'status' => STATUS_PENDING_MANAGER,
]);
$tester->assert(
    count($prospective['2026-08-17']) === 2 && count($prospective['2026-08-18']) === 4,
    "A prospective request adds one person to every day of its range",
    "17th: " . count($prospective['2026-08-17']) . ", 18th: " . count($prospective['2026-08-18'])
);
$tester->assert(
    count($byDay['2026-08-17']) === 1,
    "Costing a prospective request does not disturb the day map it was given"
);

$projected = LeaveCapacity::capacityWarnings($prospective, 1, 1);
$projectedDates = [];
foreach ($projected as $w) {
    $projectedDates[$w['date']] = $w['state'];
}
$tester->assert(
    ($projectedDates['2026-08-17'] ?? null) === LeaveCapacity::OVER_LIMIT,
    "A day that was exactly on the limit tips over once the applicant is added",
    json_encode($projectedDates)
);
$tester->assert(
    ($projectedDates['2026-08-20'] ?? null) === LeaveCapacity::OVER_LIMIT
    && ($byDate['2026-08-20']['state'] ?? null) === LeaveCapacity::AT_LIMIT,
    "The days this request is responsible for breaking are distinguishable from the ones already broken"
);
$tester->assert(
    LeaveCapacity::withExtraAbsence([], ['department_id' => 1]) === [],
    "A range with no working days has nothing to add an absence to"
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

/* ============================================================
 * 13. SUPPORTING DOCUMENT STORAGE & ACCESS
 * ============================================================ */
echo "\n--- 13. Testing Supporting Document Storage & Access ---\n";

// Who may open a medical certificate. Applicant 5 reports to manager 4, and
// sits in a department headed by manager 7.
$sickNote = ['user_id' => 5, 'manager_id' => 4, 'line_manager_id' => 7];

$tester->assert(
    AttachmentStore::viewableBy($sickNote, 5, ROLE_EMPLOYEE) === true,
    "An applicant can open their own supporting document"
);
$tester->assert(
    AttachmentStore::viewableBy($sickNote, 4, ROLE_MANAGER) === true,
    "The applicant's own line manager can open it"
);
$tester->assert(
    AttachmentStore::viewableBy($sickNote, 7, ROLE_MANAGER) === true,
    "The head of the applicant's department can open it"
);
$tester->assert(
    AttachmentStore::viewableBy($sickNote, 9, ROLE_MANAGER) === false,
    "A manager from elsewhere in the organisation cannot"
);
$tester->assert(
    AttachmentStore::viewableBy($sickNote, 6, ROLE_EMPLOYEE) === false,
    "A colleague who can see the absence on the calendar cannot open the certificate"
);
$tester->assert(
    AttachmentStore::viewableBy($sickNote, 3, ROLE_HR) === true
    && AttachmentStore::viewableBy($sickNote, 2, ROLE_EXECUTIVE) === true
    && AttachmentStore::viewableBy($sickNote, 1, ROLE_ADMIN) === true,
    "HR, executives and administrators can open it"
);

// Stored names must give nothing away and must not collide.
$firstName  = AttachmentStore::storedName('pdf');
$secondName = AttachmentStore::storedName('pdf');
$tester->assert(
    preg_match('/^att_[0-9a-f]{32}\.pdf$/', $firstName) === 1,
    "A stored document is named from random bytes, not the applicant",
    $firstName
);
$tester->assert(
    $firstName !== $secondName,
    "Two documents stored in the same second do not collide"
);
$tester->assert(
    AttachmentStore::storedName('PDF', 'abc') === 'att_abc.pdf',
    "The extension is normalised to lower case"
);

// Path handling: only files genuinely inside the upload directory resolve.
$sandbox = sys_get_temp_dir() . '/lms-attachment-test-' . getmypid();
@mkdir($sandbox, 0750, true);
file_put_contents($sandbox . '/att_real.pdf', '%PDF-1.4 test');

$tester->assert(
    AttachmentStore::resolve('uploads/attachments/att_real.pdf', $sandbox) !== null,
    "A document that exists resolves to a readable path"
);
$tester->assert(
    AttachmentStore::resolve('../../../../etc/passwd', $sandbox) === null,
    "A traversal path recorded against an application resolves to nothing"
);
$tester->assert(
    AttachmentStore::resolve('att_missing.pdf', $sandbox) === null,
    "A path pointing at a deleted file resolves to nothing"
);
$tester->assert(
    AttachmentStore::resolve(null, $sandbox) === null
    && AttachmentStore::resolve('', $sandbox) === null,
    "An application with no attachment resolves to nothing"
);

@unlink($sandbox . '/att_real.pdf');
@rmdir($sandbox);

// Upload validation.
$tester->assert(
    AttachmentStore::rejectionReasons(
        ['name' => 'note.pdf', 'size' => 1024, 'error' => UPLOAD_ERR_OK]
    ) === [],
    "A small PDF is accepted"
);
$tester->assert(
    count(AttachmentStore::rejectionReasons(
        ['name' => 'shell.php', 'size' => 1024, 'error' => UPLOAD_ERR_OK]
    )) === 1,
    "An executable extension is refused"
);
$tester->assert(
    count(AttachmentStore::rejectionReasons(
        ['name' => 'scan.pdf', 'size' => AttachmentStore::MAX_BYTES + 1, 'error' => UPLOAD_ERR_OK]
    )) === 1,
    "A document over the size limit is refused"
);
$tester->assert(
    count(AttachmentStore::rejectionReasons(
        ['name' => 'empty.pdf', 'size' => 0, 'error' => UPLOAD_ERR_OK]
    )) === 1,
    "An empty document is refused"
);
$tester->assert(
    AttachmentStore::rejectionReasons(
        ['name' => 'huge.pdf', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]
    ) !== [],
    "A document rejected by PHP's own size limit is reported, not ignored"
);
$tester->assert(
    AttachmentStore::contentTypeFor('pdf') === 'application/pdf'
    && AttachmentStore::contentTypeFor('JPG') === 'image/jpeg'
    && AttachmentStore::contentTypeFor('svg') === 'application/octet-stream',
    "Documents are served as what they are, and unknown types are never guessed at"
);

/* ============================================================
   Outgoing email
   ------------------------------------------------------------
   The wording, the addressing rules and the retry schedule, all
   of which are pure. The queue's own mechanics need a database
   and live in tests/test_email_queue.php.
   ============================================================ */
echo "\n--- Outgoing Email ---\n";

$tester->assert(
    Mailer::isSendableAddress('thandi@realnet.co.sz') === true
    && Mailer::isSendableAddress('not-an-address') === false
    && Mailer::isSendableAddress('') === false
    && Mailer::isSendableAddress(null) === false,
    "Only an address worth attempting is treated as sendable"
);

$tester->assert(
    Mailer::singleLine("Approved\r\nBcc: somebody@example.com") === 'Approved Bcc: somebody@example.com',
    "A newline in a subject cannot open a second header"
);

$tester->assert(
    Mailer::singleLine("  Leave approved  \t ") === 'Leave approved',
    "Subjects are trimmed and internal tabs collapsed"
);

// The transport is injected, so this asserts what would go on the wire without
// needing a mail server, an inbox, or credentials.
$captured = [];
$testMailer = new Mailer(function (array $message) use (&$captured) { $captured[] = $message; });
$testMailer->send('thandi@realnet.co.sz', 'Thandi Mndzebele', 'Subject line', '<p>html</p>', 'text');
$tester->assert(
    count($captured) === 1
    && $captured[0]['to_email'] === 'thandi@realnet.co.sz'
    && $captured[0]['to_name'] === 'Thandi Mndzebele'
    && $captured[0]['body_html'] === '<p>html</p>'
    && $captured[0]['body_text'] === 'text',
    "A message reaches the transport with its recipient and both bodies intact"
);

$refused = false;
try {
    $testMailer->send('nonsense', null, 's', 'h', 't');
} catch (RuntimeException $e) {
    $refused = true;
}
$tester->assert($refused, "Sending to an unusable address raises rather than failing quietly");

// MAIL_REDIRECT_TO is empty in the test environment, which is the production
// shape. The redirect itself is exercised in tests/test_email_queue.php, where
// the constant can be set before config/constants.php is included.
$tester->assert(
    Mailer::isRedirecting() === false,
    "Mail is not being diverted by default, so production notifies real people"
);

$tester->assert(
    EmailQueue::backoffMinutes(1) === 1
    && EmailQueue::backoffMinutes(2) === 5
    && EmailQueue::backoffMinutes(3) === 15
    && EmailQueue::backoffMinutes(4) === 60,
    "Retries widen instead of hammering a mail server that is down"
);

$tester->assert(
    EmailQueue::backoffMinutes(9) === EmailQueue::backoffMinutes(4),
    "Past the end of the schedule the longest wait repeats"
);

$tester->assert(
    EmailQueue::shouldRetry(MAIL_MAX_ATTEMPTS - 1) === true
    && EmailQueue::shouldRetry(MAIL_MAX_ATTEMPTS) === false,
    "A message is abandoned once it has had all its attempts"
);

$tester->assert(
    strpos(EmailTemplate::subject(Notifier::TYPE_AWAITING, Notifier::awaitingTitle(STATUS_PENDING_HR)), APP_SHORT_NAME) === 0
    && strpos(EmailTemplate::subject(Notifier::TYPE_AWAITING, Notifier::awaitingTitle(STATUS_PENDING_HR)), 'Stage 2 HR review') !== false,
    "A subject says which system it came from, then what happened"
);

$tester->assert(
    EmailTemplate::accent(Notifier::TYPE_APPROVED) !== EmailTemplate::accent(Notifier::TYPE_REJECTED),
    "An approval does not look like a rejection"
);

$tester->assert(
    EmailTemplate::callToAction(Notifier::TYPE_AWAITING) === 'Review the request'
    && EmailTemplate::callToAction(Notifier::TYPE_APPROVED) === 'View in the portal',
    "An approver is asked to act; everybody else is offered the detail"
);

$tester->assert(
    EmailTemplate::greeting('Thandi') === 'Hello Thandi,'
    && EmailTemplate::greeting(null) === 'Hello,'
    && EmailTemplate::greeting('   ') === 'Hello,',
    "The greeting works whether or not a first name is known"
);

// The message has to stand on its own: somebody reading it on a phone at the
// weekend should learn the outcome without signing in.
$plain = EmailTemplate::renderText(
    Notifier::TYPE_REJECTED,
    'Your leave request was declined',
    'LR-2026-0041 - Annual Leave, 3 working day(s). Remarks: cover is thin.',
    'http://localhost:8000/modules/leave/my_history.php',
    'Thandi'
);
$tester->assert(
    strpos($plain, 'Hello Thandi,') !== false
    && strpos($plain, 'Your leave request was declined') !== false
    && strpos($plain, 'Remarks: cover is thin.') !== false
    && strpos($plain, 'http://localhost:8000/modules/leave/my_history.php') !== false,
    "The plain-text message carries the outcome, the detail and the link"
);

$tester->assert(
    strpos(EmailTemplate::renderText(Notifier::TYPE_SUBMITTED, 'Submitted', null, null, 'Ali'), 'http') === false,
    "A notification with no link produces no empty link section"
);

$hostile = EmailTemplate::renderHtml(
    Notifier::TYPE_REJECTED,
    'Declined <script>alert(1)</script>',
    "Remarks: <img src=x onerror=alert(1)>\nsecond line",
    'http://localhost:8000/x.php?a=1&b=2',
    'Thandi'
);
$tester->assert(
    strpos($hostile, '<script>alert(1)</script>') === false
    && strpos($hostile, '&lt;script&gt;') !== false,
    "A title is escaped before it reaches the HTML body"
);
$tester->assert(
    strpos($hostile, '<img src=x') === false,
    "Approver remarks cannot introduce elements"
);
$tester->assert(
    strpos($hostile, 'second line') !== false && strpos($hostile, '<br') !== false,
    "Newlines in remarks survive as line breaks"
);
$tester->assert(
    strpos($hostile, 'a=1&amp;b=2') !== false,
    "An ampersand in a link is escaped rather than left to be guessed at"
);
$tester->assert(
    strpos($hostile, '<!DOCTYPE html PUBLIC') === 0
    && strpos($hostile, 'charset=utf-8') !== false
    && strpos($hostile, '</html>') !== false,
    "The HTML part is a complete document mail clients can render"
);

// The XHTML transitional doctype is deliberate rather than dated: Outlook renders
// through Word, which treats an HTML5 doctype as a reason to fall back to its own
// defaults for table and cell spacing.
$tester->assert(
    strpos($hostile, 'PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"') !== false,
    "The doctype is the one Word needs, not the one a browser would prefer"
);

/* --- the detail table, which is what replaced a comma-separated sentence --- */

$app = [
    'application_no' => 'LV-2026-6E9B25',
    'leave_name'     => 'Annual Leave',
    'start_date'     => '2026-10-04',
    'end_date'       => '2026-10-06',
    'total_days'     => '3.0',
];

$details = Notifier::detailsFor($app);
$tester->assert(
    array_keys($details) === ['Reference', 'Leave type', 'Dates', 'Working days'],
    "An applicant's email lists the facts in reading order, without their own name",
    implode(',', array_keys($details))
);

$tester->assert(
    array_keys(Notifier::detailsFor($app, 'Vamile Sikhondze'))[0] === 'Requested by',
    "An approver's email leads with whose leave it is, because that is what they are deciding"
);

$tester->assert(
    $details['Dates'] === 'Sun 4 Oct 2026 to Tue 6 Oct 2026'
    && $details['Working days'] === '3 days',
    "Dates are spelled out with weekdays, since cover is a question about which days",
    $details['Dates'] . ' / ' . $details['Working days']
);

$tester->assert(
    Notifier::detailsFor(['application_no' => 'X', 'leave_name' => 'Sick Leave',
        'start_date' => '2026-10-04', 'end_date' => '2026-10-04', 'total_days' => '1.0'])['Dates']
        === 'Sun 4 Oct 2026',
    "A single-day request is not written as a range from itself to itself"
);

$tester->assert(
    Notifier::detailsFor(['application_no' => 'X', 'leave_name' => 'Annual',
        'start_date' => '2026-10-04', 'end_date' => '2026-10-04', 'total_days' => '1.0'])['Working days']
        === '1 day'
    && Notifier::detailsFor(['application_no' => 'X', 'leave_name' => 'Annual',
        'start_date' => '2026-10-04', 'end_date' => '2026-10-04', 'total_days' => '0.5'])['Working days']
        === '0.5 days',
    "Day counts read as English rather than as \"1 day(s)\""
);

$withTable = EmailTemplate::renderHtml(Notifier::TYPE_AWAITING, 'Awaiting you', null,
    'https://leave.example.co.sz/x.php', 'Celiwe', Notifier::detailsFor($app, 'Vamile Sikhondze'));

$tester->assert(
    strpos($withTable, 'LV-2026-6E9B25') !== false
    && strpos($withTable, 'Annual Leave') !== false
    && strpos($withTable, 'Sun 4 Oct 2026 to Tue 6 Oct 2026') !== false
    && strpos($withTable, 'Vamile Sikhondze') !== false,
    "Every detail reaches the rendered table"
);

$tester->assert(
    substr_count($withTable, 'REFERENCE') === 0
    && strpos($withTable, '>Reference<') !== false,
    "Labels are cased in the markup and uppercased by CSS, so they survive a client that strips it"
);

$tester->assert(
    strpos(EmailTemplate::renderHtml(Notifier::TYPE_APPROVED, 'T', 'a prose body', null, 'A'), 'a prose body') !== false,
    "A notification with no detail list still renders its prose body rather than an empty frame"
);

/* --- remarks, quoted separately --- */

$withRemarks = EmailTemplate::renderHtml(Notifier::TYPE_REJECTED, 'Declined', null, null, 'Vamile',
    $details, "Month-end cover is thin.\nTry January.");

$tester->assert(
    strpos($withRemarks, 'Remarks from the approver') !== false
    && strpos($withRemarks, 'Month-end cover is thin.<br') !== false,
    "Approver remarks get their own block, with their line breaks intact"
);

$tester->assert(
    strpos(EmailTemplate::renderHtml(Notifier::TYPE_APPROVED, 'T', null, null, 'A', $details, '   '), 'Remarks from') === false,
    "Whitespace-only remarks produce no empty remarks block"
);

$tester->assert(
    strpos(EmailTemplate::renderText(Notifier::TYPE_REJECTED, 'Declined', null, null, 'Vamile', $details, 'Cover is thin.'),
        'Remarks from the approver:') !== false,
    "The plain-text part carries the remarks too"
);

$tester->assert(
    strpos(EmailTemplate::renderText(Notifier::TYPE_AWAITING, 'Awaiting', null, null, 'C', $details), 'Reference    ') !== false,
    "Plain-text labels are padded so values line up in a fixed-width client"
);

/* --- the status pill and the Outlook button --- */

$tester->assert(
    EmailTemplate::statusLabel(Notifier::TYPE_APPROVED) === 'Approved'
    && EmailTemplate::statusLabel(Notifier::TYPE_REJECTED) === 'Declined'
    && EmailTemplate::statusLabel(Notifier::TYPE_AWAITING) === 'Action needed',
    "The pill says the outcome in one or two words, before anything is read"
);

$tester->assert(
    strpos($withTable, 'v:roundrect') !== false
    && strpos($withTable, '<!--[if mso]>') !== false
    && strpos($withTable, '<!--[if !mso]><!-->') !== false,
    "The button has a VML fallback, because Outlook ignores border-radius entirely"
);

$tester->assert(
    strpos(EmailTemplate::renderHtml(Notifier::TYPE_APPROVED, 'T', 'b', null, 'A'), 'v:roundrect') === false,
    "A notification with no link renders no button and no dead VML"
);

/* --- the preheader, the only preview a recipient gets --- */

$tester->assert(
    strpos(EmailTemplate::preheader('Awaiting you', null, $details), 'LV-2026-6E9B25') !== false
    && strpos(EmailTemplate::preheader('Awaiting you', null, $details), 'Awaiting you') === false,
    "The preheader carries the detail, not a second copy of the subject line"
);

$tester->assert(
    EmailTemplate::preheader('A title', 'a body', []) === 'a body'
    && EmailTemplate::preheader('A title', null, []) === 'A title',
    "With no details it falls back to the body, then to the title"
);

exit($tester->summary());
