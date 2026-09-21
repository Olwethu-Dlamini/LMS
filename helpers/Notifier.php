<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/EmailQueue.php';
require_once __DIR__ . '/LeaveCalculator.php';
// Circular by design, and safe: ApprovalWorkflow requires this file in turn,
// and PHP treats the second require_once as a no-op because the first is still
// in progress. Neither class touches the other while its own body is being
// defined - the only call is at runtime, for the routing wording below - so
// whichever file a caller loads first, both are defined before anything runs.
require_once __DIR__ . '/ApprovalWorkflow.php';

/**
 * In-app notifications.
 *
 * Leave routing already works out who has to act next; until now nobody was
 * told. An approver had to visit their queue to discover a request was waiting,
 * and an applicant had to keep re-opening their history to find out whether it
 * had moved. This turns each of those events into a row somebody sees.
 *
 * Two rules shape the implementation:
 *
 *   Notifications never break leave. Every write is wrapped so that a failure -
 *   most likely an installation that has not run migration 002 yet - is logged
 *   and swallowed rather than rolling back an approval. Being told late is a
 *   nuisance; a lost approval is a payroll problem.
 *
 *   Recipients are derived from the workflow, not stored alongside it. Who
 *   decides an employee's leave changes when an administrator reassigns a
 *   department, and a notification aimed at whoever held the role last week
 *   would be wrong.
 *
 * Every notification is also queued as an email, in push() - the one place all
 * of them pass through, so the two can never drift apart and start telling
 * different stories. The bell reaches whoever is signed in; the email reaches
 * everybody else, which is the point. Queueing follows the first rule above: it
 * happens after the notification row is safely written, and it cannot fail
 * loudly enough to matter.
 *
 * The channels are not identical, and shouldEmail() is the rule:
 *
 *   Email is for news about you. The bell and the screens are for work waiting
 *   on you.
 *
 * HR decides every manager's and every executive's leave, and the executive
 * decides HR's. A message per waiting request would fill the mailboxes of the
 * two roles who can least afford to start ignoring their mail, to tell them
 * something their own queue and the company overview on their dashboard already
 * show. So those two roles get no queue email. Everything about their own
 * leave still reaches them, because that is news they cannot look up by
 * opening a screen they had no reason to open.
 *
 * One exception, and it is the reason the urgent flag exists at all: a request
 * in a category marked urgent is emailed to whoever it is waiting on, whatever
 * their role. An emergency that waits for somebody to open a screen is an
 * emergency the system failed to raise, and there are few enough of them that
 * they cannot bury anything.
 */
class Notifier {
    const TYPE_SUBMITTED  = 'leave_submitted';
    const TYPE_AWAITING   = 'leave_awaiting_you';
    /**
     * Raised by the old multi-stage chain, when an approval moved a request on
     * to the next approver instead of deciding it.
     *
     * Nothing produces it any more. It is kept because the notifications table
     * and email_outbox on a live installation already hold rows under it, and
     * they should keep rendering with their own icon and accent rather than
     * falling through to a generic "Updated".
     */
    const TYPE_ADVANCED   = 'leave_advanced';
    const TYPE_APPROVED   = 'leave_approved';
    const TYPE_REJECTED   = 'leave_rejected';
    const TYPE_CANCELLED  = 'leave_cancelled';

    /**
     * Said to an approver, because it is the thing that changed for them.
     *
     * They used to be one signature of three, with HR and an executive behind
     * them to catch a mistake. Their approval is now the whole decision and it
     * books the leave, so the notice says so rather than leaving them to find
     * out from the absence of a second stage.
     */
    const DECISION_IS_FINAL = 'Your decision is final: approving books the leave and deducts the days.';

    private PDO $db;
    private EmailQueue $emails;
    /**
     * Consulted for the leave category's own rules: whether approvers should be
     * told about it as urgent, and which category's balance it spends. Read
     * through getLeaveType(), which fills in defaults for the columns migration
     * 008 adds, so this works either side of that migration.
     */
    private LeaveCalculator $calculator;
    /** @var array<int, string> role name per user id, for this request only. */
    private array $roleCache = [];

    /**
     * @param EmailQueue|null $emails injected by the tests, which check that a
     *                                notification is mirrored to email without
     *                                needing a mail server to exist
     */
    public function __construct(?PDO $db = null, ?EmailQueue $emails = null) {
        $this->db         = $db ?? getDBConnection();
        $this->emails     = $emails ?? new EmailQueue($this->db);
        $this->calculator = new LeaveCalculator($this->db);
    }

    /* ------------------------------------------------------------------ *
     * Wording. Static and free of I/O so the phrasing can be tested.
     * ------------------------------------------------------------------ */

    /**
     * What an approver should be told is waiting for them.
     *
     * A category flagged urgent leads the title with its own name, so a queue
     * can be triaged from the bell or an inbox list without opening anything.
     * "Emergency Leave request awaiting your approval" is the whole point of
     * having an emergency category: one sitting unnoticed among ordinary
     * requests would defeat it.
     *
     * @param string|null $urgentLeaveName the category's name when it is
     *                                     flagged notify_as_urgent
     */
    public static function awaitingTitle(string $status, ?string $urgentLeaveName = null): string {
        $subject = ($urgentLeaveName !== null && trim($urgentLeaveName) !== '')
            ? trim($urgentLeaveName) . ' request'
            : 'Leave request';

        switch ($status) {
            case STATUS_PENDING_MANAGER:
                return $subject . ' awaiting your approval';
            case STATUS_PENDING_HR:
                return $subject . ' awaiting your HR approval';
            case STATUS_PENDING_EXECUTIVE:
                return $subject . ' awaiting your sign-off';
            default:
                return $subject . ' awaiting your review';
        }
    }

    /**
     * What the applicant should be told an approval or rejection means.
     *
     * The outcome is still read from the status the workflow actually produced
     * rather than assumed from the action, but with one approval deciding a
     * request there is only one approval outcome left. The two "cleared Stage 1
     * and is with HR" style titles have gone with the chain: no approval
     * produces a pending status any more, so nothing could reach them.
     *
     * "Approved" rather than "fully approved", which only meant anything while
     * there was a partial kind.
     *
     * @return array{0:string,1:string} [type, title]
     */
    public static function outcomeFor(string $action, string $newStatus): array {
        if ($action === 'reject') {
            return [self::TYPE_REJECTED, 'Your leave request was declined'];
        }
        if ($newStatus === STATUS_APPROVED) {
            return [self::TYPE_APPROVED, 'Your leave request is approved'];
        }
        return [self::TYPE_ADVANCED, 'Your leave request has been updated'];
    }

    /**
     * Whether a notification of this kind should also be emailed to somebody in
     * this role.
     *
     * Pure, so the rule can be asserted without a mail server, a database or an
     * inbox. See the note on channels at the top of this file for why the two
     * senior roles are treated differently: they are the ones whose queue is
     * fed by everybody else's seniority rather than by their own team, and the
     * overview they work from makes the mail redundant.
     *
     * Note this covers the "awaiting your approval" notice only. A request
     * withdrawn from their queue still emails them, because it is a thing that
     * happened rather than a thing waiting to be done, and it is rare.
     *
     * $urgent lifts the rule entirely. A category flagged notify_as_urgent is
     * emailed to every approver it is waiting on, including HR and executives:
     * the suppression exists so routine queue traffic does not train somebody
     * to ignore their mail, and an emergency is the one thing that must not
     * wait for them to open a screen.
     */
    public static function shouldEmail(string $type, string $recipientRole, bool $urgent = false): bool {
        if ($type !== self::TYPE_AWAITING || $urgent) {
            return true;
        }
        return !in_array(strtolower($recipientRole), [ROLE_HR, ROLE_EXECUTIVE], true);
    }

    /**
     * One-line description of a request, used as the body of most messages.
     */
    public static function describe(array $app): string {
        $dates = $app['start_date'] === $app['end_date']
            ? date('D j M Y', strtotime($app['start_date']))
            : date('j M', strtotime($app['start_date'])) . ' to ' . date('j M Y', strtotime($app['end_date']));

        return sprintf(
            '%s - %s, %s working day(s), %s',
            $app['application_no'],
            $app['leave_name'] ?? 'Leave',
            rtrim(rtrim(number_format((float)$app['total_days'], 1), '0'), '.'),
            $dates
        );
    }

    /**
     * The same facts as describe(), but as labelled fields for the email.
     *
     * describe() stays as it is because the bell needs one short line: the
     * dropdown gives it two rows of small text and a table would not fit. Email
     * has room, and an approver working through eleven notices wants to find the
     * dates without reading a sentence, so the two formats are built separately
     * rather than one being derived from the other.
     *
     * Insertion order is display order.
     *
     * @param string|null $requestedBy     the applicant's name, for an approver's
     *                                     copy; omitted from the applicant's own
     * @param bool        $decisionIsFinal adds the row that tells an approver
     *                                     nobody reviews this after them
     * @return array<string,string> label => value
     */
    public static function detailsFor(array $app, ?string $requestedBy = null, bool $decisionIsFinal = false): array {
        $details = [];

        if ($requestedBy !== null && $requestedBy !== '') {
            // Only for approvers. Telling applicants their own name back would
            // be padding, and the first row is the most valuable one.
            $details['Requested by'] = $requestedBy;
        }

        $details['Reference']  = (string)$app['application_no'];
        $details['Leave type'] = (string)($app['leave_name'] ?? 'Leave');

        $start = strtotime($app['start_date']);
        $end   = strtotime($app['end_date']);
        $details['Dates'] = $app['start_date'] === $app['end_date']
            ? date('D j M Y', $start)
            : date('D j M Y', $start) . ' to ' . date('D j M Y', $end);

        $days = rtrim(rtrim(number_format((float)$app['total_days'], 1), '0'), '.');
        $details['Working days'] = $days . ($days === '1' ? ' day' : ' days');

        if (!empty($app['balance_from'])) {
            // Emergency leave has no allowance of its own, so an email that
            // named only the category would list one the recipient holds no
            // balance for and the day count would look invented.
            $details['Deducted from'] = (string)$app['balance_from'];
        }

        if ($decisionIsFinal) {
            // Last row rather than first: the facts of the request are what an
            // approver is deciding on, and this is the consequence of deciding.
            // It earns a labelled row because the email's prose body is not
            // rendered at all once there is a detail table to show instead.
            $details['Decision'] = 'Yours, and final - it books the leave';
        }

        return $details;
    }

    /* ------------------------------------------------------------------ *
     * Writing
     * ------------------------------------------------------------------ */

    /**
     * Record one notification, and queue the email that says the same thing.
     *
     * Returns false when the notification could not be stored; callers treat
     * that as unremarkable rather than as a failure to handle. The return value
     * describes the notification only - a stored notification whose email could
     * not be queued is still a success, because the bell will show it and the
     * queue failure has been logged. Reporting it as a failure would tell the
     * caller to do something about a problem it has no way to fix.
     *
     * Whether an email is queued at all is shouldEmail()'s decision, taken
     * here because this is the single place both channels pass through. A
     * notice that is deliberately not emailed leaves no email_outbox row, so
     * the bell row existing without one is the evidence that the rule applied
     * rather than that the queue is broken.
     *
     * $emailExtras['urgent'] therefore does two things from one flag: it makes
     * the message look urgent, and it makes sure the message is sent at all.
     */
    public function push(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?int $applicationId = null,
        array $emailExtras = []
    ): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, type, title, body, link, leave_application_id)
                VALUES (:user_id, :type, :title, :body, :link, :app_id)
            ");
            $stored = $stmt->execute([
                'user_id' => $userId,
                'type'    => $type,
                'title'   => $title,
                'body'    => $body,
                'link'    => $link,
                'app_id'  => $applicationId,
            ]);
        } catch (Throwable $e) {
            error_log('Notifier: could not store notification - ' . $e->getMessage());
            return false;
        }

        $urgent = (bool)($emailExtras['urgent'] ?? false);

        if ($stored && self::shouldEmail($type, $this->roleOf($userId), $urgent)) {
            // Separate try/catch, deliberately. The notification is already
            // written and this method has already succeeded; a mail problem from
            // here on must not turn that into a false return.
            try {
                $this->emails->enqueueNotification(
                    $userId,
                    (int)$this->db->lastInsertId() ?: null,
                    $type,
                    $title,
                    $body,
                    $link,
                    // The bell gets the one-line body; the email gets the same
                    // facts as a table, plus the approver's remarks quoted on
                    // their own. Both fall back gracefully when absent.
                    $emailExtras['details'] ?? [],
                    $emailExtras['remarks'] ?? null,
                    $urgent
                );
            } catch (Throwable $e) {
                error_log('Notifier: could not queue notification email - ' . $e->getMessage());
            }
        }

        return $stored;
    }

    /**
     * The recipient's role, cached for the life of the request.
     *
     * push() needs it to decide whether to email, and pushMany() would
     * otherwise ask the same question again for each of a handful of people.
     *
     * An account that cannot be read falls back to employee, which errs towards
     * sending: a message that arrives when it need not have is a nuisance,
     * while one silently dropped is an approval nobody hears about.
     */
    private function roleOf(int $userId): string {
        if (!array_key_exists($userId, $this->roleCache)) {
            $role = ROLE_EMPLOYEE;
            try {
                $stmt = $this->db->prepare("
                    SELECT r.name AS role_name
                    FROM users u JOIN roles r ON r.id = u.role_id
                    WHERE u.id = :id
                ");
                $stmt->execute(['id' => $userId]);
                $row = $stmt->fetch();
                if (!empty($row['role_name'])) {
                    $role = strtolower((string)$row['role_name']);
                }
            } catch (Throwable $e) {
                // Left as employee, so the message is sent rather than lost.
            }
            $this->roleCache[$userId] = $role;
        }
        return $this->roleCache[$userId];
    }

    /**
     * The same notification to several people, skipping duplicates and zeros.
     */
    public function pushMany(
        array $userIds,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        ?int $applicationId = null,
        array $emailExtras = []
    ): int {
        $sent = 0;
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            if ($this->push($userId, $type, $title, $body, $link, $applicationId, $emailExtras)) {
                $sent++;
            }
        }
        return $sent;
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    public function unreadCount(int $userId): int {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM notifications WHERE user_id = :id AND read_at IS NULL
            ");
            $stmt->execute(['id' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // A missing table must not take every page down with it.
            return 0;
        }
    }

    public function latest(int $userId, int $limit = 8): array {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM notifications
                WHERE user_id = :id
                ORDER BY created_at DESC, id DESC
                LIMIT " . max(1, (int)$limit)
            );
            $stmt->execute(['id' => $userId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function markAllRead(int $userId): int {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications SET read_at = NOW()
                WHERE user_id = :id AND read_at IS NULL
            ");
            $stmt->execute(['id' => $userId]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Mark one notification read, but only if it belongs to this user - the id
     * arrives from a query string and cannot be trusted on its own.
     *
     * @return string|null the notification's link, when it has one
     */
    public function open(int $userId, int $notificationId): ?string {
        try {
            $stmt = $this->db->prepare("
                SELECT link FROM notifications WHERE id = :id AND user_id = :user_id
            ");
            $stmt->execute(['id' => $notificationId, 'user_id' => $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }

            $mark = $this->db->prepare("
                UPDATE notifications SET read_at = NOW()
                WHERE id = :id AND user_id = :user_id AND read_at IS NULL
            ");
            $mark->execute(['id' => $notificationId, 'user_id' => $userId]);

            return $row['link'] ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ *
     * Recipients
     * ------------------------------------------------------------------ */

    /**
     * Everything the message needs about an application, in one read.
     */
    private function applicationContext(int $applicationId): ?array {
        $stmt = $this->db->prepare("
            SELECT a.id, a.application_no, a.user_id, a.start_date, a.end_date,
                   a.total_days, a.status, a.leave_type_id,
                   t.name AS leave_name,
                   u.first_name, u.last_name, u.manager_id, u.department_id,
                   r.name AS applicant_role,
                   d.line_manager_id
            FROM leave_applications a
            JOIN leave_types t ON t.id = a.leave_type_id
            JOIN users u ON u.id = a.user_id
            JOIN roles r ON r.id = u.role_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // The category's own rules, read separately rather than joined in.
        // getLeaveType() selects the whole row and fills in defaults for the
        // columns migration 008 adds, so naming them here would be the one
        // thing that broke notifications on an installation that has pulled
        // this code and not yet run it.
        $leaveType = $this->calculator->getLeaveType((int)$row['leave_type_id']);
        $row['notify_as_urgent'] = $leaveType !== null
            && (int)($leaveType['notify_as_urgent'] ?? 0) === 1;

        $row['balance_from'] = null;
        if ($leaveType !== null) {
            $balanceType = $this->calculator->balanceTypeFor($leaveType);
            if ((int)($balanceType['id'] ?? 0) !== (int)($leaveType['id'] ?? 0)) {
                $row['balance_from'] = (string)$balanceType['name'];
            }
        }

        return $row;
    }

    /**
     * Who has to act on an application sitting at a given status.
     *
     * An employee's request goes to their own line manager and to whoever heads
     * their department - the same pair that queue is scoped to, so everybody who
     * can see the request is told about it. A manager's, an executive's or HR's
     * own request goes to every active holder of the role that decides it,
     * because any of them may pick it up.
     *
     * The applicant is never notified that they are waiting on themselves.
     *
     * @return int[] user ids
     */
    public function approversFor(array $app): array {
        $status = $app['status'];
        $ids = [];

        if ($status === STATUS_PENDING_MANAGER) {
            $ids[] = (int)($app['manager_id'] ?? 0);
            $ids[] = (int)($app['line_manager_id'] ?? 0);
        } elseif (in_array($status, [STATUS_PENDING_HR, STATUS_PENDING_EXECUTIVE], true)) {
            $role = $status === STATUS_PENDING_HR ? ROLE_HR : ROLE_EXECUTIVE;
            $stmt = $this->db->prepare("
                SELECT u.id FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.name = :role AND u.status = 'active'
            ");
            $stmt->execute(['role' => $role]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (int)$id;
            }
        }

        $applicantId = (int)$app['user_id'];
        return array_values(array_filter(array_unique($ids), function ($id) use ($applicantId) {
            return $id > 0 && $id !== $applicantId;
        }));
    }

    /* ------------------------------------------------------------------ *
     * Events
     * ------------------------------------------------------------------ */

    /** Link to the queue an approver should open for a given status. */
    private static function queueLink(string $status): string {
        switch ($status) {
            case STATUS_PENDING_MANAGER:
                return APP_URL . '/modules/manager/approvals.php';
            case STATUS_PENDING_HR:
                return APP_URL . '/modules/hr/approvals.php';
            case STATUS_PENDING_EXECUTIVE:
                return APP_URL . '/modules/executive/approvals.php';
            default:
                return APP_URL . '/modules/dashboard/index.php';
        }
    }

    private static function historyLink(): string {
        return APP_URL . '/modules/leave/my_history.php';
    }

    /**
     * A new request: confirm receipt to the applicant and put it in front of
     * whoever has to act on it.
     */
    public function applicationSubmitted(int $applicationId): void {
        try {
            $app = $this->applicationContext($applicationId);
            if (!$app) {
                return;
            }
            $summary = self::describe($app);
            $who     = $app['first_name'] . ' ' . $app['last_name'];

            // Who to expect a decision from, named by role. Falls back to the
            // employee route on an installation whose applicationContext()
            // predates the applicant_role column being selected.
            $decider = ApprovalWorkflow::deciderLabelFor(
                strtolower((string)($app['applicant_role'] ?? ROLE_EMPLOYEE))
            );

            $this->push(
                (int)$app['user_id'],
                self::TYPE_SUBMITTED,
                'Leave request submitted for approval',
                $summary . '. ' . ucfirst($decider) . ' decides it, and that decision is final.',
                self::historyLink(),
                $applicationId,
                ['details' => self::detailsFor($app)]
            );

            $urgent = !empty($app['notify_as_urgent']);

            $this->pushMany(
                $this->approversFor($app),
                self::TYPE_AWAITING,
                self::awaitingTitle($app['status'], $urgent ? (string)$app['leave_name'] : null),
                $who . ' - ' . $summary . '. ' . self::DECISION_IS_FINAL,
                self::queueLink($app['status']),
                $applicationId,
                ['details' => self::detailsFor($app, $who, true), 'urgent' => $urgent]
            );
        } catch (Throwable $e) {
            error_log('Notifier: submission notice failed - ' . $e->getMessage());
        }
    }

    /**
     * An approval or rejection: tell the applicant what happened.
     *
     * Nobody downstream is told, because there is no downstream. This method
     * used to notify the stage a request had just reached; one approval decides
     * a request now, so an approval is the end of it and the only person with
     * anything left to learn is the applicant.
     */
    public function decisionRecorded(int $applicationId, string $action, string $newStatus, ?string $comments = null): void {
        try {
            $app = $this->applicationContext($applicationId);
            if (!$app) {
                return;
            }
            $summary = self::describe($app);
            [$type, $title] = self::outcomeFor($action, $newStatus);

            $body = $summary;
            if ($newStatus === STATUS_APPROVED) {
                // Worth saying outright now that it happens on the first
                // approval: the balance has already moved.
                $body .= '. The days have been deducted from your balance.';
            }
            if (!empty($comments)) {
                $body .= '. Remarks: ' . $comments;
            }

            $this->push(
                (int)$app['user_id'],
                $type,
                $title,
                $body,
                self::historyLink(),
                $applicationId,
                // The remarks go through as their own field rather than glued to
                // the summary. This is the sentence a declined applicant most
                // wants to read, and in the email it gets its own quoted block.
                ['details' => self::detailsFor($app), 'remarks' => $comments]
            );
        } catch (Throwable $e) {
            error_log('Notifier: decision notice failed - ' . $e->getMessage());
        }
    }

    /**
     * A cancellation, told to whoever did not do it.
     *
     * When HR or an administrator cancels, the applicant needs to hear about it.
     * When the applicant withdraws a request that was still in a queue, the
     * approvers waiting on it need to know it has gone, or they will go looking
     * for something that is no longer there. $previousStatus is what it was
     * cancelled from, since by now the row reads 'cancelled' and no longer says
     * who was waiting.
     */
    public function applicationCancelled(int $applicationId, bool $byOwner, ?string $previousStatus = null, ?string $reason = null): void {
        try {
            $app = $this->applicationContext($applicationId);
            if (!$app) {
                return;
            }
            $body = self::describe($app);
            if (!empty($reason)) {
                $body .= '. Reason: ' . $reason;
            }

            if (!$byOwner) {
                $this->push(
                    (int)$app['user_id'],
                    self::TYPE_CANCELLED,
                    'Your leave request was cancelled',
                    $body . '. Any reserved days have been returned to your balance.',
                    self::historyLink(),
                    $applicationId,
                    ['details' => self::detailsFor($app), 'remarks' => $reason]
                );
                return;
            }

            $wasPending = in_array(
                $previousStatus,
                [STATUS_PENDING_MANAGER, STATUS_PENDING_HR, STATUS_PENDING_EXECUTIVE],
                true
            );
            if ($wasPending) {
                $waiting = $app;
                $waiting['status'] = $previousStatus;
                $who = $app['first_name'] . ' ' . $app['last_name'];
                $this->pushMany(
                    $this->approversFor($waiting),
                    self::TYPE_CANCELLED,
                    'A leave request in your queue was withdrawn',
                    $who . ' - ' . $body,
                    self::queueLink($previousStatus),
                    $applicationId,
                    ['details' => self::detailsFor($app, $who), 'remarks' => $reason]
                );
            }
        } catch (Throwable $e) {
            error_log('Notifier: cancellation notice failed - ' . $e->getMessage());
        }
    }
}
