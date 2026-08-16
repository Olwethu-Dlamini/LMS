<?php
/**
 * Live preview behind the apply form: how many working days a range comes to,
 * what it leaves in the balance, and who in the applicant's department is
 * already away over it.
 *
 * Every rule here is re-run server-side on submission. This endpoint exists so
 * somebody can see the answer while they can still change the dates.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/LeaveCalculator.php';
require_once __DIR__ . '/../helpers/LeaveCapacity.php';

/**
 * Answer and stop. Always JSON: the caller is a fetch() that parses the body,
 * so an HTML error page would surface as an unreadable failure.
 */
function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    respond(['success' => false, 'error' => 'Unauthenticated'], 401);
}

$userId      = (int)$_SESSION['user_id'];
$startDate   = trim((string)($_GET['start_date'] ?? ''));
$endDate     = trim((string)($_GET['end_date'] ?? ''));
$leaveTypeId = (int)($_GET['leave_type_id'] ?? 0);
$dayType     = (string)($_GET['day_type'] ?? 'full');

/**
 * A date the calculator can safely construct a DateTime from.
 *
 * Without this, a hand-edited or half-typed date reached new DateTime(), threw,
 * and the fetch got a PHP error page where it expected JSON.
 */
function valid_date(string $value): bool {
    $parsed = DateTime::createFromFormat('Y-m-d', $value);
    return $parsed !== false && $parsed->format('Y-m-d') === $value;
}

if ($startDate === '' || $endDate === '' || $leaveTypeId <= 0) {
    respond(['success' => false, 'error' => 'Missing parameters'], 400);
}
if (!valid_date($startDate) || !valid_date($endDate)) {
    respond(['success' => false, 'error' => 'Dates must be in YYYY-MM-DD format.'], 400);
}
if (!in_array($dayType, ['full', 'half_morning', 'half_afternoon', 'half'], true)) {
    $dayType = 'full';
}
if (strtotime($startDate) > strtotime($endDate)) {
    respond(['success' => false, 'error' => 'End date cannot be earlier than start date.'], 400);
}

try {
    $db         = getDBConnection();
    $calculator = new LeaveCalculator($db);
    $capacity   = new LeaveCapacity($db);

    $validation = $calculator->validateEligibility($userId, $leaveTypeId, $startDate, $endDate, null, $dayType);
    $holidays   = $calculator->getHolidaysArray($startDate, $endDate);
    $impact     = $capacity->prospectiveImpact($userId, $startDate, $endDate);

    respond([
        'success'           => true,
        'working_days'      => $validation['days'],
        'valid'             => $validation['valid'],
        'available_balance' => $validation['available_balance'] ?? 0,
        'holidays_in_range' => count($holidays),
        'errors'            => $validation['errors'],
        'coverage'          => [
            'department'   => $impact['department_name'],
            'limit'        => $impact['limit'],
            'headcount'    => $impact['headcount'],
            'colleagues'   => array_map(function (array $person) {
                return [
                    'name'    => $person['name'],
                    'days'    => count($person['dates']),
                    'pending' => (bool)$person['pending'],
                ];
            }, $impact['colleagues']),
            'tips_over'    => array_column($impact['tips_over'], 'date'),
            'already_over' => array_column($impact['already_over'], 'date'),
            'at_limit'     => array_column($impact['warnings'], 'date'),
        ],
    ]);
} catch (Throwable $e) {
    // The preview is an aid, never the gate. If it cannot be produced the form
    // still submits and the server still validates.
    respond(['success' => false, 'error' => 'Unable to compute duration.'], 500);
}
