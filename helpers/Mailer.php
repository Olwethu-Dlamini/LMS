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
     * Whether APP_URL still points at a development machine.
     *
     * Worth a loud warning rather than a silent success, because it produces the
     * one failure that looks like everything working: mail is accepted, marked
     * sent and delivered, and every link in it points the recipient at their own
     * computer. Nobody reports that as a mail problem.
     */
    public static function hasLocalAppUrl(): bool {
        $host = parse_url(APP_URL, PHP_URL_HOST) ?? '';
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', ''], true);
    }

    /** Whether every message is being diverted to a single mailbox. */
    public static function isRedirecting(): bool {
        return MAIL_REDIRECT_TO !== '';
    }

    /**
     * The address to blind-copy this message to, or null for no copy.
     *
     * Pure, and takes its inputs explicitly, so the decision can be tested
     * without a mail server and without constants that are frozen at include
     * time.
     *
     * Four reasons to send no copy, and each is a real case:
     *   - archiving is switched off;
     *   - a redirect is active, so this is UAT and the trail should not be
     *     filled with notices about leave nobody applied for;
     *   - the address is unusable, which is logged by the caller and never
     *     allowed to fail the send;
     *   - the archive mailbox is already the recipient, which would otherwise
     *     deliver the same message to it twice.
     */
    public static function archiveRecipientFor(
        string $envelopeTo,
        ?string $archiveTo = null,
        ?bool $redirecting = null
    ): ?string {
        $archiveTo   = $archiveTo ?? MAIL_ARCHIVE_TO;
        $redirecting = $redirecting ?? self::isRedirecting();

        if ($archiveTo === '' || $redirecting) {
            return null;
        }
        if (!self::isSendableAddress($archiveTo)) {
            return null;
        }
        if (strcasecmp($archiveTo, $envelopeTo) === 0) {
            return null;
        }
        return $archiveTo;
    }

    /**
     * Send one message.
     *
     * The recipient is checked before MAIL_REDIRECT_TO is applied, so a bad
     * address is still reported as a bad address during UAT rather than being
     * hidden by the redirect. Diverting a message that would have failed would
     * make UAT prove something untrue.
     *
     * @param string $toEmail the real recipient, as recorded in the outbox
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

        $envelopeTo   = $toEmail;
        $envelopeName = (string)$toName;

        if (self::isRedirecting() && !self::isSendableAddress(MAIL_REDIRECT_TO)) {
            // Fail rather than fall back to the real recipient. Somebody who set
            // this meant "do not mail the actual staff", and honouring a typo by
            // delivering to them anyway is the one outcome they were trying to
            // prevent. The message stays queued and the reason is recorded.
            throw new RuntimeException(
                'MAIL_REDIRECT_TO is set to "' . MAIL_REDIRECT_TO . '", which is not a valid '
                . 'address. Refusing to send to the real recipient instead.'
            );
        }

        if (self::isRedirecting()) {
            // Say who it was for in the subject. During UAT the interesting
            // question about thirty near-identical messages is which one is
            // which, and the subject is the only part visible in a list.
            $subject      = '[to ' . $toEmail . '] ' . $subject;
            $envelopeTo   = MAIL_REDIRECT_TO;
            $envelopeName = 'Redirected from ' . $toEmail;

            $notice = 'Redirected: this message was addressed to ' . $toEmail
                . ' and was sent here instead because MAIL_REDIRECT_TO is set.';

            $bodyText = $notice . "\n\n" . $bodyText;
            $bodyHtml = self::prependNotice($bodyHtml, $notice);
        }

        $archiveTo = self::archiveRecipientFor($envelopeTo);
        if ($archiveTo === null && MAIL_ARCHIVE_TO !== '' && !self::isRedirecting()
            && !self::isSendableAddress(MAIL_ARCHIVE_TO)) {
            // Logged, not thrown. See the note on MAIL_ARCHIVE_TO in
            // config/constants.php: losing the copy is better than not telling
            // somebody about their own leave.
            error_log('Mailer: MAIL_ARCHIVE_TO is not a usable address ('
                . MAIL_ARCHIVE_TO . '); the message was sent with no copy kept.');
        }

        call_user_func($this->transport, [
            'to_email'   => $envelopeTo,
            'to_name'    => $envelopeName,
            'subject'    => $subject,
            'body_html'  => $bodyHtml,
            'body_text'  => $bodyText,
            'archive_to' => $archiveTo,
        ]);
    }

    /**
     * Put the redirect notice where it will actually be seen.
     *
     * Inserted after <body> when there is one, so it lands above the rendered
     * message rather than before the doctype where most clients would drop it.
     */
    private static function prependNotice(string $html, string $notice): string {
        $banner = '<div style="background:#fdf3e0;border:1px solid #9a6200;color:#9a6200;'
            . 'padding:10px 14px;margin:0 0 12px;font:bold 12px Arial,Helvetica,sans-serif;">'
            . htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';

        $position = stripos($html, '<body');
        if ($position !== false) {
            $close = strpos($html, '>', $position);
            if ($close !== false) {
                return substr($html, 0, $close + 1) . $banner . substr($html, $close + 1);
            }
        }
        return $banner . $html;
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

            // Bcc rather than a second send: one transaction, one message, so
            // the copy is the message the recipient got rather than a rebuild
            // of it. The To: header still names the real person.
            if (!empty($message['archive_to'])) {
                $mail->addBCC($message['archive_to']);
            }

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
