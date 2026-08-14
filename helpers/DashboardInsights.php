<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * The numbers behind the dashboard widgets.
 *
 * The dashboard is a view; the questions it asks are not trivial, so they live
 * here where they can be read and tested on their own. The aggregation is split
 * from the reading: queries return one row per person, and pure functions fold
 * them into per-department figures.
 */
class DashboardInsights {
    /** Below this share of their entitlement, somebody is banking leave. */
    const LOW_USAGE_SHARE = 0.25;

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /* ------------------------------------------------------------------ *
     * Pure helpers
     * ------------------------------------------------------------------ */

    /**
     * Whole days from today until a date. Negative once it is in the past.
     * $today is injectable so the countdown can be tested on a fixed date.
     */
    public static function daysUntil(string $date, ?string $today = null): int {
        $from = new DateTime($today ?? 'today');
        $from->setTime(0, 0, 0);
        $to = new DateTime($date);
        $to->setTime(0, 0, 0);
        return (int)$from->diff($to)->format('%r%a');
    }

    /**
     * How a countdown reads to somebody looking at it today.
     */
    public static function countdownLabel(int $daysUntil): string {
        if ($daysUntil < 0) {
            return 'in progress';
        }
        if ($daysUntil === 0) {
            return 'starts today';
        }
        if ($daysUntil === 1) {
            return 'starts tomorrow';
        }
        return "starts in {$daysUntil} days";
    }

    /**
     * Share of an entitlement that is committed, counting days already taken and
     * days awaiting approval. Zero when nothing is allocated, rather than a
     * division by zero, so an unallocated department reads as untouched.
     */
    public static function committedShare(float $total, float $used, float $pending): float {
        if ($total <= 0) {
            return 0.0;
        }
        return min(1.0, ($used + $pending) / $total);
    }

    /**
     * Fold one-row-per-person entitlement rows into per-department figures.
     *
     * Somebody with no allocation is counted as a member but not as banking
     * leave: they have nothing to bank, and flagging them would send a manager
     * chasing an entitlement problem dressed up as a booking problem.
     *
     * @param array $rows each with department_id, department_name, user_id, total, used, pending
     * @return array<int, array{department_id:int, name:string, members:int, total:float,
     *                          used:float, pending:float, share:float, low_usage:int, unallocated:int}>
     */
    public static function summarise(array $rows): array {
        $byDept = [];
        foreach ($rows as $row) {
            $deptId = (int)$row['department_id'];
            if (!isset($byDept[$deptId])) {
                $byDept[$deptId] = [
                    'department_id' => $deptId,
                    'name'          => $row['department_name'],
                    'members'       => 0,
                    'total'         => 0.0,
                    'used'          => 0.0,
                    'pending'       => 0.0,
                    'share'         => 0.0,
                    'low_usage'     => 0,
                    'unallocated'   => 0,
                ];
            }

            $total   = (float)$row['total'];
            $used    = (float)$row['used'];
            $pending = (float)$row['pending'];

            $byDept[$deptId]['members']++;
            $byDept[$deptId]['total']   += $total;
            $byDept[$deptId]['used']    += $used;
            $byDept[$deptId]['pending'] += $pending;

            if ($total <= 0) {
                $byDept[$deptId]['unallocated']++;
            } elseif (self::committedShare($total, $used, $pending) < self::LOW_USAGE_SHARE) {
                $byDept[$deptId]['low_usage']++;
            }
        }

        foreach ($byDept as $deptId => $dept) {
            $byDept[$deptId]['share'] = self::committedShare($dept['total'], $dept['used'], $dept['pending']);
        }

        return $byDept;
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * The id of the annual leave type, used to keep utilisation about the leave
     * people plan rather than sick days they cannot. Null when no such type
     * exists, in which case callers fall back to every type.
     */
    public function annualLeaveTypeId(): ?int {
        $id = $this->db->query("SELECT id FROM leave_types WHERE code = 'ANN' LIMIT 1")->fetchColumn();
        return $id === false || $id === null ? null : (int)$id;
    }

    /**
     * The signed-in user's next approved leave that has not finished yet.
     */
    public function nextApprovedLeave(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT a.id, a.application_no, a.start_date, a.end_date, a.total_days,
                   t.name AS leave_name
            FROM leave_applications a
            JOIN leave_types t ON t.id = a.leave_type_id
            WHERE a.user_id = :id
              AND a.status = 'approved'
              AND a.end_date >= CURDATE()
            ORDER BY a.start_date ASC
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['days_until'] = self::daysUntil($row['start_date']);
        return $row;
    }

    /**
     * Per-department leave utilisation for a year.
     *
     * @param int[] $departmentIds ignored when $unrestricted is true
     */
    public function utilisation(array $departmentIds, bool $unrestricted, int $year, ?int $leaveTypeId = null): array {
        if (!$unrestricted && empty($departmentIds)) {
            return [];
        }

        $params = ['year' => $year];
        $where  = ["u.status = 'active'", "r.name <> 'admin'", 'u.department_id IS NOT NULL'];

        if (!$unrestricted) {
            $placeholders = [];
            foreach (array_values($departmentIds) as $i => $deptId) {
                $key = 'dept_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$deptId;
            }
            $where[] = 'u.department_id IN (' . implode(', ', $placeholders) . ')';
        }

        // The leave type filter belongs on the join, not in WHERE: moving it
        // would drop members who hold no entitlement row, and they are exactly
        // the people a manager needs to see.
        $typeJoin = '';
        if ($leaveTypeId !== null) {
            $typeJoin = ' AND e.leave_type_id = :type_id';
            $params['type_id'] = $leaveTypeId;
        }

        $stmt = $this->db->prepare("
            SELECT u.department_id, d.name AS department_name, u.id AS user_id,
                   COALESCE(SUM(e.total_days), 0)   AS total,
                   COALESCE(SUM(e.used_days), 0)    AS used,
                   COALESCE(SUM(e.pending_days), 0) AS pending
            FROM users u
            JOIN roles r ON r.id = u.role_id
            JOIN departments d ON d.id = u.department_id
            LEFT JOIN leave_entitlements e
                   ON e.user_id = u.id AND e.year = :year {$typeJoin}
            WHERE " . implode(' AND ', $where) . "
            GROUP BY u.department_id, d.name, u.id
            ORDER BY d.name ASC
        ");
        $stmt->execute($params);

        return self::summarise($stmt->fetchAll());
    }
}
