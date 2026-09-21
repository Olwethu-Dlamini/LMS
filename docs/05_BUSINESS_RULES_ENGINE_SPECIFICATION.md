# Business Rules Engine Specification
## Leave Management System (LMS)

---

## 1. Business Rules Overview

The Business Rules Engine (`LeaveCalculator.php`) validates leave eligibility, enforces company policies, and computes net working days before any leave application can be processed by the system.

---

## 2. Calculation & Validation Formulae

### 2.1 Net Working Days Calculation Rule
When a user selects a `start_date` and `end_date`, the system must:
1. Iterate day-by-day from `start_date` to `end_date` (inclusive).
2. Check day of week: If day is Saturday (6) or Sunday (7), exclude from count.
3. Check `holidays` database table: If date matches a registered public holiday, exclude from count.
4. Increment working days counter for remaining valid days.

$$\text{Net Working Days} = \sum_{d = \text{start\_date}}^{\text{end\_date}} \left[ \text{is\_weekday}(d) \land \neg \text{is\_public\_holiday}(d) \right]$$

### 2.2 Balance Calculation Rule
Each user has a leave entitlement record per leave type per year:

$$\text{Available Balance} = \text{total\_days} - \text{used\_days} - \text{pending\_days}$$

- **Rule BR-BAL-01**: A leave request is **REJECTED AT SUBMISSION** if $\text{Net Working Days} > \text{Available Balance}$, unless the category carries `allow_negative_balance`.
- **Rule BR-BAL-02**: Pending requests immediately reserve days by adding to `pending_days` to prevent double-booking balance.
- **Rule BR-BAL-03**: The entitlement row read, reserved, deducted, released and restored is the one named by `deducts_from_type_id` when the category has it, resolved in a single hop. Emergency Leave therefore spends Annual Leave, while the application still records Emergency Leave as the category requested. A category pointing at itself, or at a row that no longer exists, falls back to its own balance.
- **Rule BR-BAL-04**: `allow_negative_balance` exempts a category from BR-BAL-01 only. A **missing** entitlement row is still refused, because there is no row to reserve against and nowhere to record the days. The refusal names the category the days would have come from.
- **Rule BR-BAL-05**: The balance is re-checked inside the submitting transaction with the entitlement row held `FOR UPDATE`, whether or not the category may overdraw, so two requests submitted at once cannot both reserve against the same snapshot.

### 2.3 Date Overlap Rule
A leave request is invalid if the requested date range overlaps with any existing request for the same user with status `pending_manager`, `pending_hr`, `pending_executive`, or `approved`:

$$\neg \exists R \in \text{Applications} : \left( R.\text{user\_id} = U \land R.\text{status} \notin \{\text{'rejected'}, \text{'cancelled'}\} \land R.\text{start\_date} \le \text{end\_date} \land R.\text{end\_date} \ge \text{start\_date} \right)$$

### 2.4 Attachment Requirements Rule
- **Rule BR-ATT-01**: If the category carries `requires_attachment` AND $\text{Net Working Days} > \text{attachment\_threshold\_days}$, a file attachment (PDF/PNG/JPG max 5MB) is strictly required. Shipped configuration puts Sick Leave at a threshold of 2 days and Maternity / Paternity at 0, i.e. always. This replaced a hardcoded rule naming the `SCK` code.
- **Rule BR-ATT-02**: A document that fails to store fails the whole submission, rather than leaving a request in a queue that looks complete without the certificate it depends on.

---

## 3. Leave Calculator Pseudo-Code (`LeaveCalculator.php`)

> **Illustrative only, and now behind the implementation.** The real
> `validateEligibility()` also enforces duration limits, notice periods, half-day
> rules and the attachment threshold per category, resolves the balance category
> per BR-BAL-03, and returns half-days as a float. Read the file for the
> authority; this block is kept to show the shape.

```php
class LeaveCalculator {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getWorkingDays(string $startDate, string $endDate): int {
        $start = new DateTime($startDate);
        $end = new DateTime($endDate);
        $end->modify('+1 day'); // Inclusive end date

        $holidays = $this->getHolidaysArray($startDate, $endDate);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $workingDays = 0;
        foreach ($period as $date) {
            $formattedDate = $date->format('Y-m-d');
            $dayOfWeek = $date->format('N'); // 1 (Mon) to 7 (Sun)

            // Exclude Saturday (6) and Sunday (7)
            if ($dayOfWeek >= 6) {
                continue;
            }

            // Exclude Public Holidays
            if (in_array($formattedDate, $holidays)) {
                continue;
            }

            $workingDays++;
        }

        return $workingDays;
    }

    public function validateEligibility(int $userId, int $leaveTypeId, string $startDate, string $endDate, ?array $file): array {
        $errors = [];

        // 1. Valid Dates Check
        if (strtotime($startDate) > strtotime($endDate)) {
            $errors[] = "End date cannot be earlier than start date.";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        // 2. Working Days Calculation
        $workingDays = $this->getWorkingDays($startDate, $endDate);
        if ($workingDays <= 0) {
            $errors[] = "Selected date range contains no working days (only weekends/holidays).";
            return ['valid' => false, 'days' => 0, 'errors' => $errors];
        }

        // 3. Balance Check
        $stmt = $this->pdo->prepare("
            SELECT total_days, used_days, pending_days 
            FROM leave_entitlements 
            WHERE user_id = :user_id AND leave_type_id = :type_id AND year = YEAR(:start_date)
        ");
        $stmt->execute(['user_id' => $userId, 'type_id' => $leaveTypeId, 'start_date' => $startDate]);
        $entitlement = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$entitlement) {
            $errors[] = "No leave balance allocation found for the selected year.";
            return ['valid' => false, 'days' => $workingDays, 'errors' => $errors];
        }

        $available = $entitlement['total_days'] - $entitlement['used_days'] - $entitlement['pending_days'];
        if ($workingDays > $available) {
            $errors[] = "Insufficient balance. Requested: {$workingDays} days, Available: {$available} days.";
        }

        // 4. Overlap Check
        $stmtOverlap = $this->pdo->prepare("
            SELECT COUNT(*) FROM leave_applications 
            WHERE user_id = :user_id 
              AND status NOT IN ('rejected', 'cancelled')
              AND start_date <= :end_date 
              AND end_date >= :start_date
        ");
        $stmtOverlap->execute(['user_id' => $userId, 'start_date' => $startDate, 'end_date' => $endDate]);
        if ($stmtOverlap->fetchColumn() > 0) {
            $errors[] = "You already have a pending or approved leave request during this date range.";
        }

        return [
            'valid' => empty($errors),
            'days' => $workingDays,
            'available_balance' => $available ?? 0,
            'errors' => $errors
        ];
    }
}
```
