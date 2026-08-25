<?php
require_once __DIR__ . '/../config/constants.php';

/**
 * Sending one message over SMTP.
 *
 * This class is the only place that knows a mail server exists. It takes an
 * address, a subject and a body, and either sends them or throws. It does not
 * know what leave is, does not read the database, and does not decide who should
 * be told anything - EmailQueue owns the queue and EmailTemplate owns the
 * wording, so each of the three can be understood without the other two.
 *
 * Failure is reported by exception rather than a false return. A caller that
 * ignores a false return silently drops mail; one that ignores an exception
 * cannot. EmailQueue catches it and writes the message into last_error, which is
 * how "why did this not arrive" becomes an answerable question.
 *
 * The transport is injectable. Every test that exercised sending against a real
 * server would need credentials, a network and somebody's inbox, so tests pass a
 * closure that records the message instead. The default closure is the only code
 * here that touches PHPMailer.
 */
class Mailer {
    /** @var callable(array):void throws on failure */
    private $transport;

    /**
     * @param callable|null $transport fn(array $message): void - throws to fail.
     *                                 Defaults to real SMTP delivery.
     */
    public function __construct(?callable $transport = null) {
        $this->transport = $transport ?? [$this, 'sendOverSmtp'];
    }

    /**
     * True when the application is configured to send at all.
     *
     * A host with no username is a legitimate configuration - some servers relay
     * for trusted addresses without authentication - so only MAIL_ENABLED and a
     * host are required.
     */
    public static function isConfigured(): bool {
        return MAIL_ENABLED && MAIL_HOST !== '' && MAIL_PORT > 0;
    }

    /**
     * Whether an address is worth attempting.
     *
     * Checked before queueing as well as before sending: a malformed address is
     * a permanent failure, and retrying one five times helps nobody.
     */
    public static function isSendableAddress(?string $email): bool {
        return $email !== null
            && $email !== ''
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Send one message.
     *
     * @param string $toEmail
     * @param string|null $toName
     * @param string $subject
     * @param string $bodyHtml
     * @param string $bodyText plain-text alternative, for mail clients that
     *                        refuse HTML and for spam scores
     * @throws RuntimeException when the message could not be handed over
     */
    public function send(string $toEmail, ?string $toName, string $subject, string $bodyHtml, string $bodyText): void {
        if (!self::isSendableAddress($toEmail)) {
            throw new RuntimeException('Not a usable email address: ' . $toEmail);
        }

        // Headers are built from a subject that may carry a person's name. A
        // newline in it would let the rest of the line be read as another
        // header, so it is collapsed here rather than trusted downstream.
        $subject = self::singleLine($subject);

        call_user_func($this->transport, [
            'to_email'  => $toEmail,
            'to_name'   => (string)$toName,
            'subject'   => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
        ]);
    }

    /**
     * Strip anything that would break out of a header line.
     */
    public static function singleLine(string $value): string {
        return trim(preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    /**
     * The real thing.
     *
     * @param array $message
     * @throws RuntimeException
     */
    private function sendOverSmtp(array $message): void {
        require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
        require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->Port       = MAIL_PORT;
            $mail->Timeout    = MAIL_TIMEOUT;
            $mail->CharSet    = 'UTF-8';
            $mail->Encoding   = 'base64';

            // An empty username means the server relays for this host without a
            // login. Setting SMTPAuth true with no credentials would fail on a
            // server that never asked for any.
            if (MAIL_USERNAME !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = MAIL_USERNAME;
                $mail->Password = MAIL_PASSWORD;
            } else {
                $mail->SMTPAuth = false;
            }

            switch (MAIL_ENCRYPTION) {
                case 'tls':
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    break;
                case 'ssl':
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                    break;
                default:
                    // No encryption asked for. SMTPAutoTLS stays on, so a server
                    // that advertises STARTTLS is still upgraded to it - the
                    // password should not cross the network in the clear just
                    // because the configuration did not insist.
                    $mail->SMTPSecure = false;
                    break;
            }

            $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
            if (MAIL_REPLY_TO !== '' && MAIL_REPLY_TO !== MAIL_FROM_ADDRESS) {
                $mail->addReplyTo(MAIL_REPLY_TO, ORG_NAME);
            }
            $mail->addAddress($message['to_email'], $message['to_name']);

            $mail->Subject = $message['subject'];
            $mail->isHTML(true);
            $mail->Body    = $message['body_html'];
            $mail->AltBody = $message['body_text'];

            // Leave notices are transactional, not marketing. Saying so keeps
            // out-of-office replies and bulk filters off the lms@ mailbox.
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');

            $mail->send();
        } catch (Throwable $e) {
            // PHPMailer's own error string is more specific than the exception
            // message for connection and authentication problems, which are the
            // two failures anybody setting this up will actually hit.
            $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
            throw new RuntimeException(self::singleLine($detail), 0, $e);
        }
    }
}
