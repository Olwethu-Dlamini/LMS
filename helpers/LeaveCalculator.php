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
     * Supports half-day calculation ('full', 'half_morning', 'half_afternoon', 'half')
     */
    public function calculateWorkingDays(string $startDate, string $endDate, string $dayType = 'full'): float {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day'); // Inclusive end

        $holidays = $this->getHolidaysArray($startDate, $endDate);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $workingDays = 0.0;
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

            $workingDays += 1.0;
        }

        if ($workingDays > 0 && in_array($dayType, ['half_morning', 'half_afternoon', 'half'])) {
            $workingDays -= 0.5;
            if ($workingDays <= 0) {
                $workingDays = 0.5;
            }
        }

        return $workingDays;
    }

    /**
     * Fetch a leave type together with its configurable rules.
     * Missing columns fall back to permissive defaults so the engine keeps
     * working against an older schema.
     */
    public function getLeaveType(int $leaveTypeId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM leave_types WHERE id = :id");
        $stmt->execute(['id' => $leaveTypeId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row + [
            'name'                      => 'Leave',
            'code'                      => '',
            'requires_attachment'       => 0,
            'min_days_per_request'      => 0,
            'max_days_per_request'      => null,
            'allow_half_day'            => 1,
            'min_notice_days'           => 0,
            'attachment_threshold_days' => 0,
            'is_active'                 => 1,
        ];
    }

    /**
     * Whole days between today and the requested start date.
     * Negative when the start date is in the past.
     */
    public function daysOfNotice(string $startDate): int {
        $today = new DateTime('today');
        $start = new DateTime($startDate);
        $start->setTime(0, 0, 0);
        return (int)$today->diff($start)->format('%r%a');
    }

    /**
     * Validate leave eligibility before application submission
     */
    public function validateEligibility(int $userId, int $leaveTypeId, string $startDate, string $endDate, ?array $file = null, string $dayType = 'full'): array {
        $errors = [];

        // 1. Date logic check
        if (strtotime($startDate) > strtotime($endDate)) {
            $errors[] = "End date cannot be earlier than start date.";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        $leaveType = $this->getLeaveType($leaveTypeId);
        if (!$leaveType) {
            $errors[] = "The selected leave category no longer exists.";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }
        if ((int)$leaveType['is_active'] !== 1) {
            $errors[] = "{$leaveType['name']} has been retired and can no longer be requested.";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        $isHalfDay = in_array($dayType, ['half_morning', 'half_afternoon', 'half'], true);
        if ($isHalfDay && (int)$leaveType['allow_half_day'] !== 1) {
            $errors[] = "{$leaveType['name']} must be taken as whole days.";
            $dayType = 'full';
        }

        // 2. Compute working days
        $workingDays = $this->calculateWorkingDays($startDate, $endDate, $dayType);
        if ($workingDays <= 0) {
            $errors[] = "Selected date range contains no working days (weekends or public holidays).";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        // 2a. Duration rules for a single request
        $minPerRequest = (float)$leaveType['min_days_per_request'];
        if ($minPerRequest > 0 && $workingDays < $minPerRequest) {
            $errors[] = sprintf(
                "%s must be at least %s working day(s) per request. This request is %s.",
                $leaveType['name'], rtrim(rtrim(number_format($minPerRequest, 1), '0'), '.'), $workingDays
            );
        }
        if ($leaveType['max_days_per_request'] !== null && $leaveType['max_days_per_request'] !== '') {
            $maxPerRequest = (float)$leaveType['max_days_per_request'];
            if ($maxPerRequest > 0 && $workingDays > $maxPerRequest) {
                $errors[] = sprintf(
                    "%s is limited to %s consecutive working day(s) per request. This request is %s.",
                    $leaveType['name'], rtrim(rtrim(number_format($maxPerRequest, 1), '0'), '.'), $workingDays
                );
            }
        }

        // 2b. Notice period. Only enforced when the type demands notice, so
        // zero-notice types (e.g. sick leave) can still be recorded after the fact.
        $minNotice = (int)$leaveType['min_notice_days'];
        if ($minNotice > 0) {
            $notice = $this->daysOfNotice($startDate);
            if ($notice < $minNotice) {
                $errors[] = sprintf(
                    "%s requires at least %d day(s) notice. This request starts %s.",
                    $leaveType['name'], $minNotice,
                    $notice < 0 ? abs($notice) . " day(s) ago" : "in {$notice} day(s)"
                );
            }
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

        // 5. Supporting document, demanded once the request passes the
        //    threshold configured against this leave type.
        if ((int)$leaveType['requires_attachment'] === 1) {
            $threshold = (float)$leaveType['attachment_threshold_days'];
            if ($workingDays > $threshold) {
                if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = $threshold > 0
                        ? sprintf(
                            "A supporting document is mandatory for %s exceeding %s working day(s).",
                            $leaveType['name'],
                            rtrim(rtrim(number_format($threshold, 1), '0'), '.')
                          )
                        : sprintf("A supporting document is mandatory for %s.", $leaveType['name']);
                }
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
