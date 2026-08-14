<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/LeaveCalculator.php';

/**
 * Who is away, when, and whether that leaves a department short-handed.
 *
 * One class serves three screens: the team calendar, the coverage warning on the
 * approval queues, and the "away this week" strip on the dashboard. They all ask
 * the same two questions - which working days does an absence actually cover,
 * and how many people does that put out of the office at once - so the answer
 * lives in one place.
 *
 * Pending applications count as absences alongside approved ones. A manager
 * deciding today needs to see the requests already in the queue, not just the
 * ones that have cleared it, or two clashing requests both look safe.
 */
class LeaveCapacity {
    /** Exactly on the department's limit: allowed, but no slack left. */
    const AT_LIMIT = 'at_limit';
    /** Past the department's limit: understaffed. */
    const OVER_LIMIT = 'over_limit';

    /** Statuses that put somebody out of the office, or would if approved. */
    const COUNTED_STATUSES = [
        STATUS_PENDING_MANAGER,
        STATUS_PENDING_HR,
        STATUS_PENDING_EXECUTIVE,
        STATUS_APPROVED,
    ];

    private PDO $db;
    private LeaveCalculator $calculator;
    /** Cached answer to "has migration 002 run?", per request. */
    private ?bool $hasLimitColumn = null;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
        $this->calculator = new LeaveCalculator($this->db);
    }

    /**
     * Whether departments.max_concurrent_absences exists.
     *
     * The dashboard is the landing page and reads capacity, so an installation
     * that has pulled this code without running migration 002 would otherwise
     * fatal on the first screen after login and lock everybody out. Instead the
     * limit reads as unset: calendars and dashboards work, and no coverage
     * warnings appear until the migration is applied.
     */
    private function limitColumnAvailable(): bool {
        if ($this->hasLimitColumn === null) {
            try {
                $stmt = $this->db->query("SHOW COLUMNS FROM departments LIKE 'max_concurrent_absences'");
                $this->hasLimitColumn = $stmt !== false && $stmt->fetch() !== false;
            } catch (Throwable $e) {
                $this->hasLimitColumn = false;
            }
        }
        return $this->hasLimitColumn;
    }

    /**
     * The working dates in a range, weekends and public holidays removed.
     * Pure so the calendar's day list can be tested without a database.
     *
     * @param string[] $holidays Y-m-d dates to exclude
     * @return string[] Y-m-d dates, in order
     */
    public static function workingDatesBetween(string $startDate, string $endDate, array $holidays = []): array {
        if (strtotime($startDate) > strtotime($endDate)) {
            return [];
        }

        $cursor = new DateTime($startDate);
        $end    = new DateTime($endDate);
        $dates  = [];

        while ($cursor <= $end) {
            $formatted = $cursor->format('Y-m-d');
            $dayOfWeek = (int)$cursor->format('N'); // 1 = Mon, 7 = Sun
            if ($dayOfWeek !== 6 && $dayOfWeek !== 7 && !in_array($formatted, $holidays, true)) {
                $dates[] = $formatted;
            }
            $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Spread each application across the working days it covers.
     *
     * Pure: hand it rows and a list of working dates and it returns the day map
     * the calendar renders. An application contributes to a date only when that
     * date is a working day inside both the application's range and the window
     * being viewed, so a request running over a weekend or a public holiday
     * never shows a phantom absence.
     *
     * @param array  $applications rows carrying start_date, end_date and whatever
     *                            the caller wants echoed back per day
     * @param string[] $workingDates window to map onto, from workingDatesBetween()
     * @return array<string, array<int, array>> Y-m-d => list of absences
     */
    public static function spreadAcrossDays(array $applications, array $workingDates): array {
        $byDay = array_fill_keys($workingDates, []);

        foreach ($applications as $app) {
            $start = $app['start_date'];
            $end   = $app['end_date'];
            foreach ($workingDates as $date) {
                if ($date >= $start && $date <= $end) {
                    $byDay[$date][] = $app;
                }
            }
        }

        return $byDay;
    }

    /**
     * Days where a department is under staffing pressure.
     *
     * max_concurrent_absences is the most people allowed away at once, so
     * sitting exactly on it is permitted but leaves no slack - that is reported
     * as AT_LIMIT, in amber. Going past it is OVER_LIMIT, in red. Keeping the
     * two apart is what lets the approval screen say whether this particular
     * request is the one that tips the department over.
     *
     * Pure. A NULL limit means no threshold is configured, so nothing is
     * flagged. $excludeApplicationId drops one request from the count, which is
     * how a caller asks "what would this department look like without it?".
     *
     * @param array<string, array> $byDay from spreadAcrossDays()
     * @return array<int, array{date:string, away:int, limit:int, state:string, people:array}>
     */
    public static function capacityWarnings(array $byDay, int $departmentId, ?int $limit, ?int $excludeApplicationId = null): array {
        if ($limit === null || $limit < 0) {
            return [];
        }

        $warnings = [];
        foreach ($byDay as $date => $absences) {
            $people = [];
            foreach ($absences as $absence) {
                if ((int)($absence['department_id'] ?? 0) !== $departmentId) {
                    continue;
                }
                if ($excludeApplicationId !== null
                    && (int)($absence['application_id'] ?? 0) === $excludeApplicationId) {
                    continue;
                }
                $people[] = $absence;
            }

            $away = count($people);
            // A day nobody is away on is never a warning, which also keeps a
            // limit of zero from flagging every empty day on the calendar.
            if ($away === 0 || $away < $limit) {
                continue;
            }

            $warnings[] = [
                'date'   => $date,
                'away'   => $away,
                'limit'  => $limit,
                'state'  => $away > $limit ? self::OVER_LIMIT : self::AT_LIMIT,
                'people' => $people,
            ];
        }

        return $warnings;
    }

    /**
     * The warnings from capacityWarnings() that are outright breaches.
     */
    public static function breachesOnly(array $warnings): array {
        return array_values(array_filter($warnings, function (array $w) {
            return $w['state'] === self::OVER_LIMIT;
        }));
    }

    /**
     * Absences per working day for the given departments.
     *
     * @param int[] $departmentIds empty with $unrestricted = true means every department
     * @return array<string, array<int, array>> Y-m-d => list of absences
     */
    public function absencesInRange(array $departmentIds, string $startDate, string $endDate, bool $unrestricted = false): array {
        $workingDates = $this->workingDatesInRange($startDate, $endDate);
        if (empty($workingDates)) {
            return [];
        }
        if (!$unrestricted && empty($departmentIds)) {
            // Scoped to nothing: a user with no department sees an empty calendar
            // rather than the whole organisation.
            return array_fill_keys($workingDates, []);
        }

        $params = ['range_start' => $startDate, 'range_end' => $endDate];
        $deptFilter = '';
        if (!$unrestricted) {
            $placeholders = [];
            foreach (array_values($departmentIds) as $i => $deptId) {
                $key = 'dept_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$deptId;
            }
            $deptFilter = ' AND u.department_id IN (' . implode(', ', $placeholders) . ')';
        }

        $statusPlaceholders = [];
        foreach (self::COUNTED_STATUSES as $i => $status) {
            $key = 'status_' . $i;
            $statusPlaceholders[] = ':' . $key;
            $params[$key] = $status;
        }

        $stmt = $this->db->prepare("
            SELECT a.id AS application_id, a.user_id, a.start_date, a.end_date,
                   a.total_days, a.status,
                   t.name AS leave_name, t.code AS leave_code,
                   u.first_name, u.last_name, u.emp_id, u.department_id,
                   d.name AS department_name
            FROM leave_applications a
            JOIN users u ON u.id = a.user_id
            JOIN leave_types t ON t.id = a.leave_type_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE a.status IN (" . implode(', ', $statusPlaceholders) . ")
              AND a.start_date <= :range_end
              AND a.end_date >= :range_start
              {$deptFilter}
            ORDER BY u.first_name ASC, a.start_date ASC
        ");
        $stmt->execute($params);

        $applications = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['name']     = $row['first_name'] . ' ' . $row['last_name'];
            $row['initials'] = strtoupper(substr($row['first_name'], 0, 1) . substr($row['last_name'], 0, 1));
            $applications[] = $row;
        }

        return self::spreadAcrossDays($applications, $workingDates);
    }

    /**
     * Working dates in a range with this installation's public holidays applied.
     */
    public function workingDatesInRange(string $startDate, string $endDate): array {
        if (strtotime($startDate) > strtotime($endDate)) {
            return [];
        }
        return self::workingDatesBetween(
            $startDate,
            $endDate,
            $this->calculator->getHolidaysArray($startDate, $endDate)
        );
    }

    /**
     * Active headcount per department, used as the "N of M away" denominator.
     * Administrators are left out: they hold no leave entitlement, so counting
     * them would understate how thin a department really is.
     *
     * @param int[] $departmentIds empty means every department
     * @return array<int, int> department_id => headcount
     */
    public function headcounts(array $departmentIds = []): array {
        $params = [];
        $filter = '';
        if (!empty($departmentIds)) {
            $placeholders = [];
            foreach (array_values($departmentIds) as $i => $deptId) {
                $key = 'dept_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$deptId;
            }
            $filter = ' AND u.department_id IN (' . implode(', ', $placeholders) . ')';
        }

        $stmt = $this->db->prepare("
            SELECT u.department_id, COUNT(*) AS headcount
            FROM users u
            JOIN roles r ON r.id = u.role_id
            WHERE u.status = 'active'
              AND u.department_id IS NOT NULL
              AND r.name <> 'admin'
              {$filter}
            GROUP BY u.department_id
        ");
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int)$row['department_id']] = (int)$row['headcount'];
        }
        return $counts;
    }

    /**
     * Configured absence limits, keyed by department.
     *
     * @return array<int, array{name:string, limit:int|null}>
     */
    public function departmentLimits(array $departmentIds = []): array {
        $limitColumn = $this->limitColumnAvailable()
            ? 'max_concurrent_absences'
            : 'NULL AS max_concurrent_absences';
        $sql = "SELECT id, name, {$limitColumn} FROM departments";
        $params = [];
        if (!empty($departmentIds)) {
            $placeholders = [];
            foreach (array_values($departmentIds) as $i => $deptId) {
                $key = 'dept_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int)$deptId;
            }
            $sql .= ' WHERE id IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $limits = [];
        foreach ($stmt->fetchAll() as $row) {
            $limits[(int)$row['id']] = [
                'name'  => $row['name'],
                'limit' => $row['max_concurrent_absences'] === null
                    ? null
                    : (int)$row['max_concurrent_absences'],
            ];
        }
        return $limits;
    }

    /**
     * Coverage impact of approving one application: the working days on which
     * its department would sit at or over the limit, and how it looked before.
     *
     * Returns empty warning lists when the department has no limit configured or
     * the application belongs to nobody's department.
     *
     * 'without_this' is the same calculation with the request removed, so a
     * caller can tell a department that was already stretched from one this
     * approval would stretch.
     *
     * @return array{limit:int|null, department_id:int|null, department_name:string|null,
     *               headcount:int, warnings:array, without_this:array, tips_over:array}
     */
    public function coverageImpact(int $applicationId): array {
        $limitColumn = $this->limitColumnAvailable()
            ? 'd.max_concurrent_absences'
            : 'NULL AS max_concurrent_absences';
        $stmt = $this->db->prepare("
            SELECT a.start_date, a.end_date, u.department_id, d.name AS department_name,
                   {$limitColumn}
            FROM leave_applications a
            JOIN users u ON u.id = a.user_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $app = $stmt->fetch();

        $empty = [
            'limit'           => null,
            'department_id'   => null,
            'department_name' => null,
            'headcount'       => 0,
            'warnings'        => [],
            'without_this'    => [],
            'tips_over'       => [],
        ];
        if (!$app || $app['department_id'] === null || $app['max_concurrent_absences'] === null) {
            return $empty;
        }

        $departmentId = (int)$app['department_id'];
        $limit        = (int)$app['max_concurrent_absences'];
        $byDay        = $this->absencesInRange([$departmentId], $app['start_date'], $app['end_date']);
        $headcounts   = $this->headcounts([$departmentId]);

        // With this request counted, and without it, so the screen can say
        // whether approving is what tips the department over.
        $warnings    = self::capacityWarnings($byDay, $departmentId, $limit);
        $withoutThis = self::capacityWarnings($byDay, $departmentId, $limit, $applicationId);

        $breachedWithout = [];
        foreach (self::breachesOnly($withoutThis) as $warning) {
            $breachedWithout[$warning['date']] = true;
        }
        // Days this request is responsible for breaking, rather than ones the
        // department was already over on before it arrived.
        $tipsOver = array_values(array_filter(
            self::breachesOnly($warnings),
            function (array $warning) use ($breachedWithout) {
                return !isset($breachedWithout[$warning['date']]);
            }
        ));

        return [
            'limit'           => $limit,
            'department_id'   => $departmentId,
            'department_name' => $app['department_name'],
            'headcount'       => $headcounts[$departmentId] ?? 0,
            'warnings'        => $warnings,
            'without_this'    => $withoutThis,
            'tips_over'       => $tipsOver,
        ];
    }
}
