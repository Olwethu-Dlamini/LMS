<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/LeaveCalculator.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthenticated']);
    exit;
}

$userId = $_SESSION['user_id'];
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$leaveTypeId = (int)($_GET['leave_type_id'] ?? 0);
$dayType = $_GET['day_type'] ?? 'full';

if (empty($startDate) || empty($endDate) || $leaveTypeId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$calculator = new LeaveCalculator();
$validation = $calculator->validateEligibility($userId, $leaveTypeId, $startDate, $endDate, null, $dayType);

$holidays = $calculator->getHolidaysArray($startDate, $endDate);

echo json_encode([
    'success' => true,
    'working_days' => $validation['days'],
    'valid' => $validation['valid'],
    'available_balance' => $validation['available_balance'] ?? 0,
    'holidays_in_range' => count($holidays),
    'errors' => $validation['errors']
]);
