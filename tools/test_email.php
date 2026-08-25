<?php
/**
 * Find out whether this machine can send email, and why not when it cannot.
 *
 *   php tools/test_email.php                     check the server, send nothing
 *   php tools/test_email.php --to you@realnet.co.sz    send one real message
 *   php tools/test_email.php --to you@realnet.co.sz --queue   queue it instead
 *
 * Run this on the machine the application runs on, before switching MAIL_ENABLED
 * on. Mail depends on things no amount of correct code can substitute for - a
 * reachable port, a server willing to relay, an address it will relay from - and
 * every one of them is a property of where the code is running rather than of
 * the code. A configuration that works on one host can fail on the next.
 *
 * The check runs without sending anything: it opens the port, reads the greeting,
 * asks EHLO what the server supports, and offers an envelope to see whether a
 * relay would be accepted - then hangs up before the message body, so nothing is
 * delivered. That is enough to diagnose almost every failure, and it can be run
 * against a live server without anybody receiving test mail.
 *
 * --to sends for real, straight out rather than through the outbox, so a failure
 * is reported here and now instead of being written into a queue row.
 */

require_once __DIR__ . '/../helpers/EmailQueue.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function tm_arg(array $argv, string $flag): ?string {
    $index = array_search($flag, $argv, true);
    if ($index === false || !isset($argv[$index + 1]) || strpos($argv[$index + 1], '--') === 0) {
        return null;
    }
    return $argv[$index + 1];
}

$to      = tm_arg($argv, '--to');
$viaQueue = in_array('--queue', $argv, true);

echo PHP_EOL;
echo "Mail configuration" . PHP_EOL;
echo "  MAIL_ENABLED   : " . (MAIL_ENABLED ? 'true' : 'false  <- queueing is off; this test ignores it') . PHP_EOL;
echo "  server         : " . MAIL_HOST . ':' . MAIL_PORT . PHP_EOL;
echo "  encryption     : " . (MAIL_ENCRYPTION === '' ? 'none configured' : MAIL_ENCRYPTION) . PHP_EOL;
echo "  authentication : " . (MAIL_USERNAME === '' ? 'none (relay by address)' : 'as ' . MAIL_USERNAME) . PHP_EOL;
echo "  password       : " . (MAIL_PASSWORD === '' ? 'not set' : 'set') . PHP_EOL;
echo "  sending as     : " . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . '>' . PHP_EOL;
echo "  replies to     : " . MAIL_REPLY_TO . PHP_EOL;

echo "  links point to : " . APP_URL . PHP_EOL;

if (Mailer::hasLocalAppUrl()) {
    echo PHP_EOL;
    echo "  *** APP_URL IS A LOCAL ADDRESS ***" . PHP_EOL;
    echo "      Links in the test message will point at your own machine." . PHP_EOL;
    echo "      Harmless here; on a live server it means every staff member is" . PHP_EOL;
    echo "      mailed a link that cannot work. Set APP_URL in config/local.php." . PHP_EOL;
}

if (Mailer::isRedirecting()) {
    echo PHP_EOL;
    echo "  *** REDIRECTING: every message goes to " . MAIL_REDIRECT_TO . " ***" . PHP_EOL;
    echo "      Real recipients are not mailed. Correct for UAT, wrong in production." . PHP_EOL;
}

echo PHP_EOL;

/* ---------------------------------------------------------------------- *
 * 1. The conversation, without a message.
 * ---------------------------------------------------------------------- */

echo "Talking to the server" . PHP_EOL;

$started = microtime(true);
$socket  = @fsockopen(MAIL_HOST, MAIL_PORT, $errno, $errstr, MAIL_TIMEOUT);
$tookMs  = (int)round((microtime(true) - $started) * 1000);

if (!$socket) {
    echo "  could not connect after {$tookMs}ms: [$errno] $errstr" . PHP_EOL . PHP_EOL;
    echo "  Things worth checking, roughly in order:" . PHP_EOL;
    echo "    - does the firewall allow outbound " . MAIL_PORT . " from this machine?" . PHP_EOL;
    echo "      A blocked port and a stopped server look identical from here." . PHP_EOL;
    echo "    - is " . MAIL_HOST . " the right name, and does it resolve?" . PHP_EOL;
    echo "    - if this host sits outside the office network, it may need to reach" . PHP_EOL;
    echo "      the mail server over the VPN." . PHP_EOL;
    exit(2);
}

stream_set_timeout($socket, MAIL_TIMEOUT);

/** Read one SMTP reply, which may run over several lines. */
$readReply = function () use ($socket): array {
    $lines = [];
    while (($line = fgets($socket, 2048)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    $code = empty($lines) ? '000' : substr($lines[0], 0, 3);
    return [$code, $lines];
};

$send = function (string $command) use ($socket, $readReply): array {
    fwrite($socket, $command . "\r\n");
    return $readReply();
};

[$code, $lines] = $readReply();
echo "  connected in {$tookMs}ms" . PHP_EOL;
echo "  greeting: " . ($lines[0] ?? '(none)') . PHP_EOL;

$helloName = gethostname() ?: 'localhost';
[$code, $ehlo] = $send('EHLO ' . $helloName);

// The first line of an EHLO reply is the server greeting itself ("example.com
// says hello"), not something it can do. Only the lines after it are
// capabilities, and treating the greeting as one makes the list read as though
// the server supports its own name.
$capabilities = [];
foreach (array_slice($ehlo, 1) as $line) {
    $capability = strtoupper(trim(substr($line, 4)));
    if ($capability !== '') {
        $capabilities[] = $capability;
    }
}
echo "  EHLO: " . $code . ' ' . trim(substr($ehlo[0] ?? '', 4)) . PHP_EOL;
echo "  the server offers:" . PHP_EOL;
foreach ($capabilities as $capability) {
    echo "      " . $capability . PHP_EOL;
}
if (empty($capabilities)) {
    echo "      (nothing beyond the basics)" . PHP_EOL;
}

$offersAuth     = (bool)preg_grep('/^AUTH\b/', $capabilities);
$offersStartTls = (bool)preg_grep('/^STARTTLS$/', $capabilities);

echo PHP_EOL;
echo "  authentication offered : " . ($offersAuth ? 'yes' : 'no') . PHP_EOL;
echo "  STARTTLS offered       : " . ($offersStartTls ? 'yes' : 'no') . PHP_EOL;

// The two mismatches that produce a confusing error rather than a clear one.
if (MAIL_USERNAME !== '' && !$offersAuth) {
    echo PHP_EOL;
    echo "  MAIL_USERNAME is set but this server does not offer AUTH." . PHP_EOL;
    echo "  Every send will fail with \"Could not authenticate\". Clear" . PHP_EOL;
    echo "  MAIL_USERNAME to let the client relay without logging in." . PHP_EOL;
}
if (MAIL_ENCRYPTION !== '' && !$offersStartTls && MAIL_ENCRYPTION !== 'ssl') {
    echo PHP_EOL;
    echo "  MAIL_ENCRYPTION is '" . MAIL_ENCRYPTION . "' but this server does not offer" . PHP_EOL;
    echo "  STARTTLS. Set MAIL_ENCRYPTION to empty, or use a port that does." . PHP_EOL;
}
if (!$offersStartTls && !$offersAuth) {
    echo PHP_EOL;
    echo "  Note: this connection is unencrypted and unauthenticated. That is the" . PHP_EOL;
    echo "  server's choice rather than a misconfiguration here, but it does mean" . PHP_EOL;
    echo "  leave notices cross the network in the clear. Worth raising with" . PHP_EOL;
    echo "  whoever runs the mail server if it can offer STARTTLS." . PHP_EOL;
}

// Would it relay for us? Asked with an envelope and no message.
echo PHP_EOL;
echo "Would it relay?" . PHP_EOL;
$probeRecipient = $to ?: MAIL_FROM_ADDRESS;

[$code, $lines] = $send('MAIL FROM:<' . MAIL_FROM_ADDRESS . '>');
echo "  MAIL FROM <" . MAIL_FROM_ADDRESS . ">: " . ($lines[0] ?? $code) . PHP_EOL;
$envelopeAccepted = $code[0] === '2';

if ($envelopeAccepted) {
    [$code, $lines] = $send('RCPT TO:<' . $probeRecipient . '>');
    echo "  RCPT TO <" . $probeRecipient . ">: " . ($lines[0] ?? $code) . PHP_EOL;
    $relayAccepted = $code[0] === '2';
} else {
    $relayAccepted = false;
}

$send('RSET');
$send('QUIT');
fclose($socket);

echo PHP_EOL;
if ($relayAccepted) {
    echo "  The server accepted the envelope. Nothing was delivered - the" . PHP_EOL;
    echo "  conversation stopped before the message body." . PHP_EOL;
} else {
    echo "  The server would not accept that envelope, so sending will fail." . PHP_EOL;
    echo "  Usually this means it does not relay for this machine's address, or" . PHP_EOL;
    echo "  will not send as " . MAIL_FROM_ADDRESS . " without authentication." . PHP_EOL;
    exit(1);
}

/* ---------------------------------------------------------------------- *
 * 2. A real message, only when asked for one.
 * ---------------------------------------------------------------------- */

if ($to === null) {
    echo PHP_EOL;
    echo "No message sent. Add --to <address> to send a real one:" . PHP_EOL;
    echo "  php tools/test_email.php --to you@realnet.co.sz" . PHP_EOL . PHP_EOL;
    exit(0);
}

if (!Mailer::isSendableAddress($to)) {
    echo PHP_EOL . "  '" . $to . "' is not a valid address." . PHP_EOL;
    exit(1);
}

$subject = APP_SHORT_NAME . ': email delivery test';
$title   = 'Email delivery is working';
$body    = 'This is a test message from ' . APP_NAME . ' on ' . (gethostname() ?: 'this server')
         . ', sent at ' . date('D j M Y, H:i') . '. If you are reading it, leave notices '
         . 'will reach staff at their work addresses. No leave request was involved.';
$link    = APP_URL . '/modules/notifications/index.php';

$html = EmailTemplate::renderHtml('test', $title, $body, $link, null);
$text = EmailTemplate::renderText('test', $title, $body, $link, null);

if ($viaQueue) {
    echo PHP_EOL . "Queueing a message for " . $to . PHP_EOL;
    $queue = new EmailQueue();
    $id    = $queue->enqueueRaw($to, null, $subject, $html, $text);
    echo "  queued as outbox row #" . $id . "." . PHP_EOL;
    echo "  Send it with: php tools/send_queued_email.php --verbose" . PHP_EOL . PHP_EOL;
    exit(0);
}

echo PHP_EOL . "Sending to " . $to . PHP_EOL;
try {
    (new Mailer())->send($to, null, $subject, $html, $text);
    echo "  sent." . PHP_EOL . PHP_EOL;
    echo "  Check the inbox, and the junk folder - a first message from a new" . PHP_EOL;
    echo "  sending address is the one most likely to be filtered. If it landed" . PHP_EOL;
    echo "  in junk, that is a matter for whoever runs the mail server rather" . PHP_EOL;
    echo "  than for this application." . PHP_EOL . PHP_EOL;
    echo "  Set MAIL_ENABLED=true and add the cron entry from the README to" . PHP_EOL;
    echo "  start sending leave notices." . PHP_EOL . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo "  failed: " . $e->getMessage() . PHP_EOL . PHP_EOL;
    exit(1);
}
