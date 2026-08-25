<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/EmailTemplate.php';

/**
 * The outbox: what is waiting to be sent, and what happened to it.
 *
 * Notifier calls enqueue() as it writes each notification row. A worker
 * (tools/send_queued_email.php, on a minutely cron) calls run() to drain what is
 * due. Nothing in between holds a request open, which is the whole reason the
 * queue exists: an approver pressing Approve should not wait on a mail server,
 * and should certainly not wait out a socket timeout when that server cannot be
 * reached.
 *
 * This class owns the table and nothing else. It does not speak SMTP - Mailer
 * does - and it does not decide wording - EmailTemplate does. It is handed a
 * Mailer, so the tests drive a real queue against a transport that records
 * instead of sends.
 *
 * Claiming, which is the only subtle part:
 *
 *   Rows are claimed one at a time with a conditional UPDATE, and the claim is
 *   only ours if that UPDATE reports one affected row. Two workers racing for
 *   the same message means one of them sees zero and moves on. This is done
 *   instead of SELECT ... FOR UPDATE SKIP LOCKED because the application runs on
 *   MySQL 8 in the container and MariaDB on the UAT machine, and a compare-and-
 *   swap on a status column behaves the same on both and on older versions of
 *   either.
 *
 *   attempts is incremented at claim time, not after sending. A worker killed
 *   mid-send has therefore already spent the attempt, which is the safe
 *   direction to be wrong in: the alternative is a message that crashes the
 *   worker being retried for ever.
 */
class EmailQueue {
    const STATUS_QUEUED  = 'queued';
    const STATUS_SENDING = 'sending';
    const STATUS_SENT    = 'sent';
    const STATUS_FAILED  = 'failed';

    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /* ------------------------------------------------------------------ *
     * Retry policy. Pure, so the schedule can be asserted on.
     * ------------------------------------------------------------------ */

    /**
     * Minutes to wait before the next attempt, given how many have been made.
     *
     * The list widens (1, 5, 15, 60) so a mail server that is briefly busy is
     * retried almost at once, while one that is down for the afternoon is not
     * hammered every minute. Past the end of the list the last value repeats.
     */
    public static function backoffMinutes(int $attemptsMade): int {
        $steps = array_values(array_filter(array_map('intval', explode(',', MAIL_RETRY_BACKOFF))));
        if (empty($steps)) {
            return 5;
        }
        $index = max(0, $attemptsMade - 1);
        return $steps[min($index, count($steps) - 1)];
    }

    /**
     * Whether a message that has just failed should be retried or abandoned.
     */
    public static function shouldRetry(int $attemptsMade): bool {
        return $attemptsMade < MAIL_MAX_ATTEMPTS;
    }

    /* ------------------------------------------------------------------ *
     * Queueing
     * ------------------------------------------------------------------ */

    /**
     * Queue the email form of a notification.
     *
     * Returns false for every reason a message should not be queued rather than
     * throwing, because the caller is Notifier, and Notifier's contract is that
     * nothing it does can disturb an approval. The reasons are all ordinary:
     * mail is switched off, the recipient has no usable address, or migration
     * 005 has not been run.
     *
     * @param int      $userId         recipient
     * @param int|null $notificationId the in-app row this mirrors, for tracing
     */
    public function enqueueNotification(
        int $userId,
        ?int $notificationId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null
    ): bool {
        if (!Mailer::isConfigured()) {
            return false;
        }

        try {
            $person = $this->recipient($userId);
            if ($person === null || !Mailer::isSendableAddress($person['email'])) {
                // Worth a log line: an active account that cannot be emailed is
                // something an administrator can fix, but only if told.
                error_log('EmailQueue: no usable address for user ' . $userId . ', notification not emailed');
                return false;
            }

            $stmt = $this->db->prepare("
                INSERT INTO email_outbox
                    (user_id, notification_id, to_email, to_name, subject, body_html, body_text)
                VALUES
                    (:user_id, :notification_id, :to_email, :to_name, :subject, :body_html, :body_text)
            ");

            return $stmt->execute([
                'user_id'         => $userId,
                'notification_id' => $notificationId,
                'to_email'        => $person['email'],
                'to_name'         => trim($person['first_name'] . ' ' . $person['last_name']),
                'subject'         => Mailer::singleLine(EmailTemplate::subject($type, $title)),
                'body_html'       => EmailTemplate::renderHtml($type, $title, $body, $link, $person['first_name']),
                'body_text'       => EmailTemplate::renderText($type, $title, $body, $link, $person['first_name']),
            ]);
        } catch (Throwable $e) {
            error_log('EmailQueue: could not queue email - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Queue a message written by hand, addressed directly. Used by
     * tools/test_email.php to prove a configuration without inventing a
     * notification to hang it off.
     */
    public function enqueueRaw(string $toEmail, ?string $toName, string $subject, string $bodyHtml, string $bodyText): ?int {
        $stmt = $this->db->prepare("
            INSERT INTO email_outbox (to_email, to_name, subject, body_html, body_text)
            VALUES (:to_email, :to_name, :subject, :body_html, :body_text)
        ");
        $stmt->execute([
            'to_email'  => $toEmail,
            'to_name'   => $toName,
            'subject'   => Mailer::singleLine($subject),
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
        ]);
        return (int)$this->db->lastInsertId() ?: null;
    }

    /** Name and address of a recipient, or null when the account is gone. */
    private function recipient(int $userId): ?array {
        $stmt = $this->db->prepare("
            SELECT email, first_name, last_name FROM users WHERE id = :id
        ");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /* ------------------------------------------------------------------ *
     * Claiming and sending
     * ------------------------------------------------------------------ */

    /**
     * Take ownership of the oldest due message, or return null when there is
     * none left to take.
     *
     * The UPDATE is the claim. Selecting first and updating second would let two
     * workers agree on the same row; here the second one is told it changed
     * nothing and asks for another.
     */
    public function claimNext(): ?array {
        $find = $this->db->prepare("
            SELECT id FROM email_outbox
            WHERE status = :queued AND next_attempt_at <= NOW()
            ORDER BY queued_at ASC, id ASC
            LIMIT 10
        ");

        $claim = $this->db->prepare("
            UPDATE email_outbox
            SET status = :sending, attempts = attempts + 1, claimed_at = NOW()
            WHERE id = :id AND status = :queued
        ");

        $read = $this->db->prepare("SELECT * FROM email_outbox WHERE id = :id");

        // Ten candidates rather than one: under contention the first few may
        // already be taken, and re-running the ordered scan for each of them is
        // wasted work.
        $find->execute(['queued' => self::STATUS_QUEUED]);
        foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $claim->execute([
                'id'      => (int)$id,
                'sending' => self::STATUS_SENDING,
                'queued'  => self::STATUS_QUEUED,
            ]);

            if ($claim->rowCount() === 1) {
                $read->execute(['id' => (int)$id]);
                $row = $read->fetch();
                if ($row) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function markSent(int $id): void {
        $stmt = $this->db->prepare("
            UPDATE email_outbox
            SET status = :sent, sent_at = NOW(), last_error = NULL
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id, 'sent' => self::STATUS_SENT]);
    }

    /**
     * Record a failure, and decide whether the message gets another chance.
     *
     * The error text is kept whichever way that goes. A message marked failed
     * with no reason recorded tells whoever investigates nothing at all.
     */
    public function markFailed(int $id, int $attemptsMade, string $error): void {
        $retry = self::shouldRetry($attemptsMade);

        $stmt = $this->db->prepare("
            UPDATE email_outbox
            SET status = :status,
                last_error = :error,
                next_attempt_at = DATE_ADD(NOW(), INTERVAL :minutes MINUTE)
            WHERE id = :id
        ");
        $stmt->execute([
            'id'      => $id,
            'status'  => $retry ? self::STATUS_QUEUED : self::STATUS_FAILED,
            // TEXT column, but the text comes from a mail server and there is no
            // reason to store a kilobyte of it.
            'error'   => mb_substr($error, 0, 500),
            'minutes' => $retry ? self::backoffMinutes($attemptsMade) : 0,
        ]);
    }

    /**
     * Return messages stranded in 'sending' to the queue.
     *
     * A worker killed between claiming and finishing leaves a row nobody owns
     * and nothing will ever pick up again. Anything held in 'sending' for longer
     * than a send could possibly take was abandoned, so it goes back - having
     * already spent its attempt, which is what stops a message that kills
     * workers from doing so for ever.
     *
     * The age is measured from claimed_at, not queued_at. Measuring from
     * queued_at would requeue a message that was queued last week and claimed a
     * second ago, while a worker was still busy sending it - and that is a
     * message delivered twice.
     */
    public function recoverStalled(int $olderThanMinutes = 15): int {
        $stmt = $this->db->prepare("
            UPDATE email_outbox
            SET status = :queued,
                last_error = 'Worker stopped before this message was sent; requeued.'
            WHERE status = :sending
              AND (claimed_at IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE))
        ");
        $stmt->execute([
            'queued'  => self::STATUS_QUEUED,
            'sending' => self::STATUS_SENDING,
            'minutes' => max(1, $olderThanMinutes),
        ]);
        return $stmt->rowCount();
    }

    /**
     * Send what is due, up to a limit.
     *
     * @return array{claimed:int,sent:int,retry:int,failed:int,errors:string[]}
     */
    public function run(?Mailer $mailer = null, ?int $limit = null): array {
        $mailer = $mailer ?? new Mailer();
        $limit  = $limit ?? MAIL_BATCH_SIZE;

        $result = ['claimed' => 0, 'sent' => 0, 'retry' => 0, 'failed' => 0, 'errors' => []];

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->claimNext();
            if ($row === null) {
                break;
            }

            $result['claimed']++;
            $attempts = (int)$row['attempts'];

            try {
                $mailer->send(
                    $row['to_email'],
                    $row['to_name'],
                    $row['subject'],
                    $row['body_html'],
                    $row['body_text']
                );
                $this->markSent((int)$row['id']);
                $result['sent']++;
            } catch (Throwable $e) {
                $this->markFailed((int)$row['id'], $attempts, $e->getMessage());
                if (self::shouldRetry($attempts)) {
                    $result['retry']++;
                } else {
                    $result['failed']++;
                }
                $result['errors'][] = '#' . $row['id'] . ' to ' . $row['to_email'] . ': ' . $e->getMessage();
            }
        }

        return $result;
    }

    /* ------------------------------------------------------------------ *
     * Reporting and housekeeping
     * ------------------------------------------------------------------ */

    /** How many messages sit in each state. */
    public function summary(): array {
        $counts = [
            self::STATUS_QUEUED => 0, self::STATUS_SENDING => 0,
            self::STATUS_SENT   => 0, self::STATUS_FAILED  => 0,
        ];
        try {
            $stmt = $this->db->query("SELECT status, COUNT(*) AS n FROM email_outbox GROUP BY status");
            foreach ($stmt->fetchAll() as $row) {
                $counts[$row['status']] = (int)$row['n'];
            }
        } catch (Throwable $e) {
            // A missing table reports zeros rather than taking a page down.
        }
        return $counts;
    }

    /** The most recent failures, for whoever is asking why mail is not arriving. */
    public function recentFailures(int $limit = 10): array {
        try {
            $stmt = $this->db->prepare("
                SELECT id, to_email, subject, attempts, last_error, next_attempt_at
                FROM email_outbox
                WHERE status IN (:failed, :queued) AND last_error IS NOT NULL
                ORDER BY id DESC
                LIMIT " . max(1, (int)$limit)
            );
            $stmt->execute(['failed' => self::STATUS_FAILED, 'queued' => self::STATUS_QUEUED]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Delete delivered mail older than a number of days.
     *
     * Sent rows are the delivery log, so they are kept for a while and then let
     * go. Failed rows are never pruned automatically: they are the ones somebody
     * still has to look at.
     */
    public function prune(int $olderThanDays = 90): int {
        $stmt = $this->db->prepare("
            DELETE FROM email_outbox
            WHERE status = :sent AND sent_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute(['sent' => self::STATUS_SENT, 'days' => max(1, $olderThanDays)]);
        return $stmt->rowCount();
    }
}
