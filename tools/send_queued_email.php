<?php
/**
 * Send whatever is waiting in the email outbox.
 *
 *   php tools/send_queued_email.php              send what is due, quietly
 *   php tools/send_queued_email.php --verbose    say what happened to each one
 *   php tools/send_queued_email.php --status     report the queue, send nothing
 *   php tools/send_queued_email.php --limit 5    send at most five
 *   php tools/send_queued_email.php --prune 90   delete mail sent over 90 days ago
 *
 * Meant for cron, once a minute:
 *
 *   * * * * * cd /var/www/html && php tools/send_queued_email.php >> /var/log/ri-leave-mail.log 2>&1
 *
 * A minute is the right interval because the queue exists to keep SMTP off the
 * critical path, not to delay mail. Nobody notices sixty seconds; everybody
 * notices an approval page that hangs.
 *
 * Quiet by default and quiet on success, so a cron entry with no redirection
 * does not mail root every minute about nothing. Failures always print.
 *
 * Only one copy runs at a time. The lock is not about correctness - EmailQueue's
 * claim already makes concurrent workers safe - but a mail server that has begun
 * timing out will hold each run open for MAIL_TIMEOUT seconds, and without a lock
 * a minutely cron would pile up dozens of stalled workers all waiting on the same
 * unreachable host.
 *
 * Exit codes: 0 nothing wrong, 1 something could not be sent, 2 could not run
 * at all. Cron and any monitoring can read those.
 */

require_once __DIR__ . '/../helpers/EmailQueue.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** Read --flag value from the command line. */
function mail_arg(array $argv, string $flag, ?string $default = null): ?string {
    $index = array_search($flag, $argv, true);
    if ($index === false || !isset($argv[$index + 1]) || strpos($argv[$index + 1], '--') === 0) {
        return $default;
    }
    return $argv[$index + 1];
}

function mail_has(array $argv, string $flag): bool {
    return in_array($flag, $argv, true);
}

$verbose = mail_has($argv, '--verbose');
$stamp   = date('Y-m-d H:i:s');

function say(string $line): void {
    echo $line . PHP_EOL;
}

/* ---------------------------------------------------------------------- *
 * --status: what is in the queue, and why anything is stuck.
 * ---------------------------------------------------------------------- */
if (mail_has($argv, '--status')) {
    $queue = new EmailQueue();
    $counts = $queue->summary();

    say('Outgoing email');
    say('  sending enabled : ' . (MAIL_ENABLED ? 'yes' : 'no  (MAIL_ENABLED is false)'));
    say('  server          : ' . MAIL_HOST . ':' . MAIL_PORT
        . ' (' . (MAIL_ENCRYPTION === '' ? 'no encryption configured' : MAIL_ENCRYPTION) . ')');
    say('  sending as      : ' . MAIL_FROM_ADDRESS
        . (MAIL_USERNAME === '' ? ' (no authentication)' : ' (as ' . MAIL_USERNAME . ')'));
    say('  password set    : ' . (MAIL_PASSWORD === '' ? 'NO - set MAIL_PASSWORD in the environment' : 'yes'));
    say('');
    say('  queued  : ' . $counts[EmailQueue::STATUS_QUEUED]);
    say('  sending : ' . $counts[EmailQueue::STATUS_SENDING]);
    say('  sent    : ' . $counts[EmailQueue::STATUS_SENT]);
    say('  failed  : ' . $counts[EmailQueue::STATUS_FAILED]);

    $failures = $queue->recentFailures(10);
    if (!empty($failures)) {
        say('');
        say('Most recent problems:');
        foreach ($failures as $row) {
            say('  #' . $row['id'] . ' to ' . $row['to_email']
                . ' (attempt ' . $row['attempts'] . ', next ' . $row['next_attempt_at'] . ')');
            say('      ' . $row['last_error']);
        }
    }
    exit(0);
}

/* ---------------------------------------------------------------------- *
 * --prune: let go of old delivery records.
 * ---------------------------------------------------------------------- */
if (mail_has($argv, '--prune')) {
    $days  = (int)(mail_arg($argv, '--prune', '90'));
    $queue = new EmailQueue();
    $gone  = $queue->prune($days);
    say('[' . $stamp . '] pruned ' . $gone . ' message(s) sent more than ' . max(1, $days) . ' day(s) ago.');
    say('Failed messages are never pruned automatically - they are the ones somebody still has to read.');
    exit(0);
}

/* ---------------------------------------------------------------------- *
 * Sending.
 * ---------------------------------------------------------------------- */

if (!Mailer::isConfigured()) {
    // Not an error. An installation that has not been given a mail server yet
    // queues nothing, so there is nothing here to do.
    if ($verbose) {
        say('[' . $stamp . '] email is switched off (MAIL_ENABLED is false); nothing to do.');
    }
    exit(0);
}

// One worker at a time. See the note at the top of the file.
$lockPath   = sys_get_temp_dir() . '/ri-leave-mail-worker.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    say('[' . $stamp . '] could not open the worker lock at ' . $lockPath);
    exit(2);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($verbose) {
        say('[' . $stamp . '] another worker is already running; leaving it to finish.');
    }
    exit(0);
}

try {
    $queue = new EmailQueue();

    // Anything a dead worker left holding a claim, before deciding what is due.
    $recovered = $queue->recoverStalled(15);
    if ($recovered > 0) {
        say('[' . $stamp . '] requeued ' . $recovered . ' message(s) stranded by a worker that stopped.');
    }

    $limit  = mail_arg($argv, '--limit');
    $result = $queue->run(null, $limit !== null ? max(1, (int)$limit) : null);

    if ($result['claimed'] === 0) {
        if ($verbose) {
            say('[' . $stamp . '] nothing due.');
        }
        exit(0);
    }

    if ($verbose || $result['retry'] > 0 || $result['failed'] > 0) {
        say('[' . $stamp . '] sent ' . $result['sent']
            . ', will retry ' . $result['retry']
            . ', gave up on ' . $result['failed']
            . ' (of ' . $result['claimed'] . ' attempted).');
    }

    foreach ($result['errors'] as $error) {
        say('  ' . $error);
    }

    // Anything that could not be sent is worth a non-zero exit, including the
    // ones that will be retried: a mail server that is refusing everything looks
    // exactly like this, and cron should be able to notice.
    exit($result['retry'] > 0 || $result['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    say('[' . $stamp . '] worker could not run: ' . $e->getMessage());
    exit(2);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
