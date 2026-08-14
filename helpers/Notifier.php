<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

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
 *   approves Stage 1 can change when an administrator reassigns a department, and
 *   a notification aimed at whoever held the role last week would be wrong.
 */
class Notifier {
    const TYPE_SUBMITTED  = 'leave_submitted';
    const TYPE_AWAITING   = 'leave_awaiting_you';
    const TYPE_ADVANCED   = 'leave_advanced';
    const TYPE_APPROVED   = 'leave_approved';
    const TYPE_REJECTED   = 'leave_rejected';
    const TYPE_CANCELLED  = 'leave_cancelled';

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /* ------------------------------------------------------------------ *
     * Wording. Static and free of I/O so the phrasing can be tested.
     * ------------------------------------------------------------------ */

    /**
     * What an approver at a given stage should be told is waiting for them.
     */
    public static function awaitingTitle(string $status): string {
        switch ($status) {
            case STATUS_PENDING_MANAGER:
                return 'Leave request awaiting your Stage 1 approval';
            case STATUS_PENDING_HR:
                return 'Leave request awaiting your Stage 2 HR review';
            case STATUS_PENDING_EXECUTIVE:
                return 'Leave request awaiting your Stage 3 sign-off';
            default:
                return 'Leave request awaiting your review';
        }
    }

    /**
     * What the applicant should be told an approval or rejection means.
     *
     * The outcome is read from the status the workflow actually produced, so
     * "fully approved" is never claimed for a request that still has a stage to
     * clear - and HR sign-off on an executive's own leave is correctly reported
     * as final.
     *
     * @return array{0:string,1:string} [type, title]
     */
    public static function outcomeFor(string $action, string $newStatus): array {
        if ($action === 'reject') {
            return [self::TYPE_REJECTED, 'Your leave request was declined'];
        }
        switch ($newStatus) {
            case STATUS_APPROVED:
                return [self::TYPE_APPROVED, 'Your leave request is fully approved'];
            case STATUS_PENDING_HR:
                return [self::TYPE_ADVANCED, 'Your leave request cleared Stage 1 and is with HR'];
            case STATUS_PENDING_EXECUTIVE:
                return [self::TYPE_ADVANCED, 'Your leave request cleared Stage 2 and awaits sign-off'];
            default:
                return [self::TYPE_ADVANCED, 'Your leave request has been updated'];
        }
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

    /* ------------------------------------------------------------------ *
     * Writing
     * ------------------------------------------------------------------ */

    /**
     * Record one notification. Returns false when it could not be stored;
     * callers treat that as unremarkable rather than as a failure to handle.
     */
    public function push(int $userId, string $type, string $title, ?string $body = null, ?string $link = null, ?int $applicationId = null): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, type, title, body, link, leave_application_id)
                VALUES (:user_id, :type, :title, :body, :link, :app_id)
            ");
            return $stmt->execute([
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
    }

    /**
     * The same notification to several people, skipping duplicates and zeros.
     */
    public function pushMany(array $userIds, string $type, string $title, ?string $body = null, ?string $link = null, ?int $applicationId = null): int {
        $sent = 0;
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            if ($this->push($userId, $type, $title, $body, $link, $applicationId)) {
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
                   a.total_days, a.status,
                   t.name AS leave_name,
                   u.first_name, u.last_name, u.manager_id, u.department_id,
                   d.line_manager_id
            FROM leave_applications a
            JOIN leave_types t ON t.id = a.leave_type_id
            JOIN users u ON u.id = a.user_id
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE a.id = :id
        ");
        $stmt->execute(['id' => $applicationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Who has to act on an application sitting at a given status.
     *
     * Stage 1 goes to the applicant's own line manager and to whoever heads
     * their department - the same pair the Stage 1 queue is scoped to, so
     * everybody who can see the request is told about it. Later stages go to
     * every active holder of the responsible role, because either of them may
     * pick the request up.
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

            $this->push(
                (int)$app['user_id'],
                self::TYPE_SUBMITTED,
                'Leave request submitted for approval',
                $summary . '. You will be notified as it moves through the approval chain.',
                self::historyLink(),
                $applicationId
            );

            $this->pushMany(
                $this->approversFor($app),
                self::TYPE_AWAITING,
                self::awaitingTitle($app['status']),
                $who . ' - ' . $summary,
                self::queueLink($app['status']),
                $applicationId
            );
        } catch (Throwable $e) {
            error_log('Notifier: submission notice failed - ' . $e->getMessage());
        }
    }

    /**
     * An approval or rejection: tell the applicant what happened, and if the
     * request moved on, tell the next stage it has arrived.
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
            if (!empty($comments)) {
                $body .= '. Remarks: ' . $comments;
            }

            $this->push((int)$app['user_id'], $type, $title, $body, self::historyLink(), $applicationId);

            if (in_array($newStatus, [STATUS_PENDING_HR, STATUS_PENDING_EXECUTIVE], true)) {
                // applicationContext() read the row after the status was written,
                // so approversFor() resolves the stage it has just reached.
                $this->pushMany(
                    $this->approversFor($app),
                    self::TYPE_AWAITING,
                    self::awaitingTitle($newStatus),
                    $app['first_name'] . ' ' . $app['last_name'] . ' - ' . $summary,
                    self::queueLink($newStatus),
                    $applicationId
                );
            }
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
                    $applicationId
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
                $this->pushMany(
                    $this->approversFor($waiting),
                    self::TYPE_CANCELLED,
                    'A leave request in your queue was withdrawn',
                    $app['first_name'] . ' ' . $app['last_name'] . ' - ' . $body,
                    self::queueLink($previousStatus),
                    $applicationId
                );
            }
        } catch (Throwable $e) {
            error_log('Notifier: cancellation notice failed - ' . $e->getMessage());
        }
    }
}
