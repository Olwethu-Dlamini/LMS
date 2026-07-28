<?php
require_once __DIR__ . '/../config/database.php';

class LeaveCalculator {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Fetch list of public holiday dates between two dates
     */
    public function getHolidaysArray(string $startDate, string $endDate): array {
        $stmt = $this->db->prepare("
            SELECT holiday_date FROM holidays 
            WHERE holiday_date BETWEEN :start AND :end
        ");
        $stmt->execute(['start' => $startDate, 'end' => $endDate]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Calculate net working days between start and end date (inclusive)
     * Excludes Saturdays (6), Sundays (7), and Public Holidays
     */
    public function calculateWorkingDays(string $startDate, string $endDate): int {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day'); // Inclusive end

        $holidays = $this->getHolidaysArray($startDate, $endDate);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $workingDays = 0;
        foreach ($period as $date) {
            $formatted = $date->format('Y-m-d');
            $dayOfWeek = (int)$date->format('N'); // 1 = Mon, 7 = Sun

            // Skip weekends
            if ($dayOfWeek === 6 || $dayOfWeek === 7) {
                continue;
            }

            // Skip Public Holidays
            if (in_array($formatted, $holidays)) {
                continue;
            }

            $workingDays++;
        }

        return $workingDays;
    }

    /**
     * Validate leave eligibility before application submission
     */
    public function validateEligibility(int $userId, int $leaveTypeId, string $startDate, string $endDate, ?array $file = null): array {
        $errors = [];

        // 1. Date logic check
        if (strtotime($startDate) > strtotime($endDate)) {
            $errors[] = "End date cannot be earlier than start date.";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        // 2. Compute working days
        $workingDays = $this->calculateWorkingDays($startDate, $endDate);
        if ($workingDays <= 0) {
            $errors[] = "Selected date range contains no working days (weekends or public holidays).";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        // 3. Balance verification
        $year = (int)date('Y', strtotime($startDate));
        $stmt = $this->db->prepare("
            SELECT total_days, used_days, pending_days 
            FROM leave_entitlements 
            WHERE user_id = :user_id AND leave_type_id = :type_id AND year = :year
        ");
        $stmt->execute(['user_id' => $userId, 'type_id' => $leaveTypeId, 'year' => $year]);
        $entitlement = $stmt->fetch();

        if (!$entitlement) {
            $errors[] = "No leave balance allocation found for the year {$year}.";
            return ['valid' => false, 'days' => $workingDays, 'errors' => $errors];
        }

        $available = (float)$entitlement['total_days'] - (float)$entitlement['used_days'] - (float)$entitlement['pending_days'];
        if ($workingDays > $available) {
            $errors[] = "Insufficient balance. Requested: {$workingDays} days, Available: {$available} days.";
        }

        // 4. Overlap check
        $stmtOverlap = $this->db->prepare("
            SELECT COUNT(*) FROM leave_applications 
            WHERE user_id = :user_id 
              AND status NOT IN ('rejected', 'cancelled')
              AND start_date <= :end_date 
              AND end_date >= :start_date
        ");
        $stmtOverlap->execute(['user_id' => $userId, 'start_date' => $startDate, 'end_date' => $endDate]);
        if ($stmtOverlap->fetchColumn() > 0) {
            $errors[] = "You already have an active leave request overlapping with this date range.";
        }

        // 5. Medical certificate check if sick leave > 2 days
        $stmtType = $this->db->prepare("SELECT code, requires_attachment FROM leave_types WHERE id = :id");
        $stmtType->execute(['id' => $leaveTypeId]);
        $leaveType = $stmtType->fetch();

        if ($leaveType && (int)$leaveType['requires_attachment'] === 1 && $workingDays > 2) {
            if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Medical certificate attachment is mandatory for sick leave exceeding 2 days.";
            }
        }

        return [
            'valid' => empty($errors),
            'days' => $workingDays,
            'available_balance' => $available ?? 0,
            'errors' => $errors
        ];
    }
}
