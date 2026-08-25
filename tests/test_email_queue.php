<?php
/**
 * The outbox, against a real database.
 *
 *   php tests/test_email_queue.php
 *
 * Separate from tests/test_suite.php because it needs a live connection and the
 * email_outbox table. The main suite runs anywhere on a mock PDO; this one has
 * to talk to MySQL, because what it checks is the behaviour of the SQL itself -
 * that a conditional UPDATE really does stop two workers claiming one message,
 * that DATE_ADD really does push a retry into the future. Asserting any of that
 * against a mock would only be asserting that the mock does what it was written
 * to do.
 *
 * Skips rather than fails when there is no database or no table, so a checkout
 * without a running MySQL still gets a clean run out of the main suite.
 *
 * Its own rows are the only ones it touches, tagged by a recognisable address,
 * and they are removed at the end whether it passed or not. It never sends
 * anything: the Mailer it uses records instead of connecting.
 */

// Before the require, not after. config/constants.php reads the environment as
// it is included and defines constants from it, so putenv() below that line
// changes nothing and every queueing test silently checks the switched-off path
// instead of the one it names.
putenv('MAIL_ENABLED=true');

require_once __DIR__ . '/../helpers/EmailQueue.php';

const TEST_TAG = 'outbox-test@example.invalid';

$passed = 0;
$failed = 0;

function check(bool $condition, string $name, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$name}" . ($detail !== '' ? " - {$detail}" : '') . "\n";
    }
}

/**
 * Report why these tests did not run, and stop.
 *
 * Exits 0. A missing database is a fact about the machine rather than a defect
 * in the code, and failing on it would mean nobody could run the main suite on a
 * fresh checkout.
 */
function skip(string $why, string $remedy = ''): void {
    echo "\n  Skipped: {$why}\n";
    if ($remedy !== '') {
        echo "  {$remedy}\n";
    }
    echo "  Not a failure - nothing here was exercised.\n\n";
    exit(0);
}

echo "\n--- Email outbox (database) ---\n";

// Connected here rather than through getDBConnection(), which calls die() when
// it cannot connect. die() exits 0 and prints its own message, so an unreachable
// database would look like a suite that ran and passed.
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    skip(
        'no database reachable at ' . DB_HOST . ':' . DB_PORT . ' (' . $e->getMessage() . ')',
        'Start it with ./uat.sh start, then re-run.'
    );
}

try {
    $db->query("SELECT 1 FROM email_outbox LIMIT 1");
} catch (Throwable $e) {
    skip(
        'the email_outbox table is missing',
        'Apply it with: mysql -u root -p ' . DB_NAME . ' < migrations/005-email-outbox.sql'
    );
}

/** A user to address mail to. Any active account will do. */
$userRow = $db->query("SELECT id, email, first_name FROM users WHERE status = 'active' ORDER BY id LIMIT 1")->fetch();
if (!$userRow) {
    skip(
        'there are no active user accounts to address a notification to',
        'Create one with: php tools/create_admin.php --email you@realnet.co.sz --name "Your Name"'
    );
}
$userId = (int)$userRow['id'];

$queue = new EmailQueue($db);

// These tests claim "the oldest due message" and count what a run sends, so they
// need the outbox to themselves. Real mail waiting to go out is never deleted to
// make room - the run is skipped instead, and on any development or UAT database
// the outbox is empty anyway.
$waiting = (int)$db->query(
    "SELECT COUNT(*) FROM email_outbox WHERE status IN ('queued', 'sending')"
)->fetchColumn();
if ($waiting > 0) {
    skip(
        $waiting . ' message(s) are already waiting to be sent, and these tests need the'
            . PHP_EOL . '  outbox to themselves. Mail they did not queue is never deleted.',
        'Drain it first: php tools/send_queued_email.php --verbose'
    );
}

/** Remove only what this file created. */
$cleanup = function () use ($db, $userId) {
    $db->prepare("DELETE FROM email_outbox WHERE to_email = :tag")->execute(['tag' => TEST_TAG]);
    $db->prepare("DELETE FROM email_outbox WHERE user_id = :id AND subject LIKE '%[outbox test]%'")
       ->execute(['id' => $userId]);
};
$cleanup();

try {
    /* ---------------------------------------------------------------- *
     * Queueing
     * ---------------------------------------------------------------- */

    $queued = $queue->enqueueNotification(
        $userId,
        null,
        Notifier::TYPE_AWAITING,
        '[outbox test] Leave request awaiting your Stage 1 approval',
        'A colleague - LR-TEST, 3 working day(s)',
        'http://localhost:8000/modules/manager/approvals.php'
    );
    check($queued === true, 'A notification for a real account is queued');

    $row = $db->prepare("SELECT * FROM email_outbox WHERE user_id = :id AND subject LIKE '%[outbox test]%' ORDER BY id DESC LIMIT 1");
    $row->execute(['id' => $userId]);
    $stored = $row->fetch();

    check(
        $stored && $stored['to_email'] === $userRow['email'],
        'It is addressed to the account holder',
        $stored ? 'got ' . $stored['to_email'] : 'no row'
    );
    check(
        $stored && $stored['status'] === EmailQueue::STATUS_QUEUED && (int)$stored['attempts'] === 0,
        'It starts queued, with no attempts spent'
    );
    check(
        $stored && $stored['body_html'] !== '' && $stored['body_text'] !== '',
        'Both an HTML and a plain-text body are stored'
    );
    check(
        $stored && strpos($stored['body_text'], 'LR-TEST') !== false,
        'The body carries the detail from the notification'
    );

    check(
        $queue->enqueueNotification(0, null, Notifier::TYPE_APPROVED, '[outbox test] nobody') === false,
        'A notification for an account that does not exist is not queued'
    );

    /* ---------------------------------------------------------------- *
     * Claiming - the part that has to be right
     * ---------------------------------------------------------------- */

    $first  = $queue->claimNext();
    check($first !== null, 'A due message can be claimed');
    check(
        $first !== null && (int)$first['attempts'] === 1,
        'Claiming spends an attempt, so a worker that dies cannot retry for ever'
    );
    check(
        $first !== null && $first['claimed_at'] !== null,
        'The claim is timestamped, so an abandoned message can be told from one in flight'
    );

    $second = $queue->claimNext();
    check(
        $second === null || (int)$second['id'] !== (int)$first['id'],
        'A claimed message cannot be claimed again',
        $second ? 'second claim returned the same row' : ''
    );

    /* ---------------------------------------------------------------- *
     * Failure and retry
     * ---------------------------------------------------------------- */

    $queue->markFailed((int)$first['id'], (int)$first['attempts'], 'SMTP Error: Could not connect to SMTP host.');
    $after = $db->query("SELECT status, last_error, TIMESTAMPDIFF(SECOND, NOW(), next_attempt_at) AS due_in FROM email_outbox WHERE id = " . (int)$first['id'])->fetch();

    check($after['status'] === EmailQueue::STATUS_QUEUED, 'A failure puts the message back in the queue');
    check(
        strpos($after['last_error'], 'Could not connect') !== false,
        'The reason is kept, so somebody can find out why nothing arrived'
    );
    check((int)$after['due_in'] > 0, 'The retry is scheduled in the future, not immediately');
    check($queue->claimNext() === null, 'A message not yet due is not claimable');

    // Spend the remaining attempts.
    $db->exec("UPDATE email_outbox SET attempts = " . (MAIL_MAX_ATTEMPTS - 1) . ", status = 'queued', next_attempt_at = NOW() WHERE id = " . (int)$first['id']);
    $last = $queue->claimNext();
    $queue->markFailed((int)$last['id'], (int)$last['attempts'], 'Mailbox unavailable');
    $exhausted = $db->query("SELECT status FROM email_outbox WHERE id = " . (int)$first['id'])->fetch();
    check(
        $exhausted['status'] === EmailQueue::STATUS_FAILED,
        'A message that has used every attempt is marked failed and left alone'
    );

    /* ---------------------------------------------------------------- *
     * Recovering what a dead worker left behind
     * ---------------------------------------------------------------- */

    $db->exec("UPDATE email_outbox SET status = 'sending', claimed_at = NOW() WHERE id = " . (int)$first['id']);
    check(
        $queue->recoverStalled(15) === 0,
        'A message claimed a moment ago is left alone - requeueing it would send it twice'
    );

    $db->exec("UPDATE email_outbox SET status = 'sending', claimed_at = DATE_SUB(NOW(), INTERVAL 60 MINUTE) WHERE id = " . (int)$first['id']);
    check(
        $queue->recoverStalled(15) === 1,
        'A message stranded by a worker that stopped is returned to the queue'
    );

    /* ---------------------------------------------------------------- *
     * run(), end to end
     * ---------------------------------------------------------------- */

    $cleanup();
    $queue->enqueueRaw(TEST_TAG, 'Outbox Test', '[outbox test] one', '<p>one</p>', 'one');
    $queue->enqueueRaw(TEST_TAG, 'Outbox Test', '[outbox test] two', '<p>two</p>', 'two');

    $sentTo = [];
    $recording = new Mailer(function (array $message) use (&$sentTo) { $sentTo[] = $message['subject']; });
    $result = $queue->run($recording, 10);

    check($result['sent'] === 2 && $result['failed'] === 0, 'run() sends everything due');
    check(count($sentTo) === 2, 'Each message reached the transport exactly once');
    check(
        (int)$db->query("SELECT COUNT(*) FROM email_outbox WHERE to_email = '" . TEST_TAG . "' AND status = 'sent' AND sent_at IS NOT NULL")->fetchColumn() === 2,
        'Delivered messages are marked sent and timestamped'
    );
    check($queue->run($recording, 10)['claimed'] === 0, 'A second run finds nothing left to do');

    /* ---------------------------------------------------------------- *
     * run() when the mail server refuses everything
     * ---------------------------------------------------------------- */

    $cleanup();
    $queue->enqueueRaw(TEST_TAG, null, '[outbox test] doomed', '<p>x</p>', 'x');
    $breaking = new Mailer(function (array $message) {
        throw new RuntimeException('SMTP Error: Could not authenticate.');
    });
    $failedRun = $queue->run($breaking, 10);

    check(
        $failedRun['sent'] === 0 && $failedRun['retry'] === 1 && count($failedRun['errors']) === 1,
        'A refusing mail server produces a retry and a reported error, not a lost message'
    );
    check(
        strpos($failedRun['errors'][0], 'Could not authenticate') !== false,
        'The error names what the server said'
    );

    /* ---------------------------------------------------------------- *
     * Housekeeping
     * ---------------------------------------------------------------- */

    $cleanup();
    $queue->enqueueRaw(TEST_TAG, null, '[outbox test] old', '<p>x</p>', 'x');
    $db->exec("UPDATE email_outbox SET status = 'sent', sent_at = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE to_email = '" . TEST_TAG . "'");
    check($queue->prune(90) === 1, 'Old delivered mail is pruned');

    $queue->enqueueRaw(TEST_TAG, null, '[outbox test] broken', '<p>x</p>', 'x');
    $db->exec("UPDATE email_outbox SET status = 'failed', sent_at = NULL, queued_at = DATE_SUB(NOW(), INTERVAL 200 DAY) WHERE to_email = '" . TEST_TAG . "'");
    check($queue->prune(90) === 0, 'Failed mail is never pruned - it is what somebody still has to read');

    /* ---------------------------------------------------------------- *
     * Mail switched off
     * ---------------------------------------------------------------- */

    // MAIL_ENABLED is a constant, so this is checked through the predicate the
    // queue itself consults rather than by redefining it.
    check(
        Mailer::isConfigured() === true,
        'isConfigured() agrees mail is on for these tests'
    );

} finally {
    $cleanup();
}

echo "\n=========================================\n";
echo " Email Outbox: {$passed} Passed, {$failed} Failed\n";
echo "=========================================\n";
exit($failed === 0 ? 0 : 1);
