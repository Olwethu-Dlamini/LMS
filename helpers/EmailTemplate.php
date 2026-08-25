<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/Notifier.php';

/**
 * What a notification looks like once it is an email.
 *
 * Every method is static and free of I/O, which is the point: the wording is the
 * part most likely to be wrong and the part hardest to check by sending yourself
 * test messages. Here it can be asserted on.
 *
 * The design follows one rule - the email says everything, and the link is an
 * offer rather than an instruction. Somebody reading a leave decision on a phone
 * at the weekend should learn the outcome from the message itself and not have to
 * sign in to find out what happened.
 *
 * Colours are the brand tokens from assets/css/ri-theme.css, inlined because
 * mail clients drop stylesheets and do not support custom properties.
 */
class EmailTemplate {
    const NAVY    = '#0a1035';
    const TEAL    = '#004668';
    const GREY    = '#efefef';
    const LINE    = '#e2e6ee';
    const MUTED   = '#6b7280';
    const SUCCESS = '#17864a';
    const DANGER  = '#b3261e';
    const WARNING = '#9a6200';
    const INFO    = '#005394';

    /**
     * Subject line for a notification.
     *
     * The notification title is already a complete sentence written for a person
     * ("Leave request awaiting your Stage 1 approval"), so it is used as-is
     * rather than reworded. It is prefixed with the application short name,
     * because a subject arriving in a full inbox has to say which system it came
     * from before it says anything else.
     */
    public static function subject(string $type, string $title): string {
        return APP_SHORT_NAME . ': ' . $title;
    }

    /**
     * The accent colour for the kind of event, matching the icon colours the
     * in-app notification list already uses so the two do not disagree.
     */
    public static function accent(string $type): string {
        switch ($type) {
            case Notifier::TYPE_APPROVED:
                return self::SUCCESS;
            case Notifier::TYPE_REJECTED:
                return self::DANGER;
            case Notifier::TYPE_AWAITING:
                return self::WARNING;
            case Notifier::TYPE_CANCELLED:
                return self::MUTED;
            default:
                return self::INFO;
        }
    }

    /**
     * The wording on the button, which depends on what the reader is expected to
     * do. An approver is being asked to act; everybody else is being told
     * something and may want the detail.
     */
    public static function callToAction(string $type): string {
        return $type === Notifier::TYPE_AWAITING
            ? 'Review the request'
            : 'View in the portal';
    }

    /**
     * A greeting that works whether or not a first name is known.
     */
    public static function greeting(?string $firstName): string {
        $name = trim((string)$firstName);
        return $name === '' ? 'Hello,' : 'Hello ' . $name . ',';
    }

    /**
     * The closing line: why this arrived, and what to do about the fact it did.
     */
    public static function footerNote(): string {
        return 'You are receiving this because ' . APP_SHORT_NAME
            . ' recorded activity on a leave request involving you. '
            . 'This mailbox is not monitored; replies go to ' . MAIL_REPLY_TO . '.';
    }

    /**
     * The plain-text alternative.
     *
     * Written as the message somebody would be content to receive on its own,
     * not as a stripped copy of the HTML. Some staff read mail in clients that
     * refuse HTML outright, and a message with no text part scores worse with
     * spam filters.
     */
    public static function renderText(string $type, string $title, ?string $body, ?string $link, ?string $firstName): string {
        $lines = [];
        $lines[] = self::greeting($firstName);
        $lines[] = '';
        $lines[] = $title;

        if (!empty($body)) {
            $lines[] = '';
            $lines[] = $body;
        }

        if (!empty($link)) {
            $lines[] = '';
            $lines[] = self::callToAction($type) . ':';
            $lines[] = $link;
        }

        $lines[] = '';
        $lines[] = '--';
        $lines[] = ORG_NAME . ' - ' . APP_SHORT_NAME;
        $lines[] = self::footerNote();

        return implode("\n", $lines) . "\n";
    }

    /**
     * The HTML alternative.
     *
     * Tables and inline styles, which is not how anybody would write a web page
     * and is exactly how email has to be written: Outlook renders with Word's
     * engine, and Gmail strips <style> blocks. Kept to a single column so it
     * needs no media queries to survive a phone.
     */
    public static function renderHtml(string $type, string $title, ?string $body, ?string $link, ?string $firstName): string {
        $accent   = self::accent($type);
        $greeting = self::e(self::greeting($firstName));
        $safeTitle = self::e($title);

        $bodyBlock = '';
        if (!empty($body)) {
            // Notification bodies are single-line summaries built by Notifier,
            // but approver remarks are pasted in by a person and may contain
            // newlines. nl2br after escaping, never before.
            $bodyBlock = '
                        <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:' . self::NAVY . ';">'
                            . nl2br(self::e($body)) . '</p>';
        }

        $buttonBlock = '';
        if (!empty($link)) {
            $safeLink = self::e($link);
            $buttonBlock = '
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;">
                            <tr>
                                <td bgcolor="' . self::TEAL . '" style="border-radius:50px;">
                                    <a href="' . $safeLink . '" style="display:inline-block;padding:12px 28px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:50px;">'
                                        . self::e(self::callToAction($type)) . '</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:' . self::MUTED . ';">
                            If the button does not work, copy this address into your browser:<br>
                            <span style="color:' . self::INFO . ';word-break:break-all;">' . $safeLink . '</span>
                        </p>';
        }

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>' . $safeTitle . '</title>
</head>
<body style="margin:0;padding:0;background-color:' . self::GREY . ';">
<!-- Preheader: the grey line inboxes show beside the subject. Hidden in the body. -->
<div style="display:none;font-size:1px;color:' . self::GREY . ';max-height:0;overflow:hidden;">' . $safeTitle . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . self::GREY . ';">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid ' . self::LINE . ';">

                <tr>
                    <td bgcolor="' . self::NAVY . '" style="padding:20px 28px;font-family:Arial,Helvetica,sans-serif;">
                        <span style="font-size:17px;font-weight:bold;color:#ffffff;letter-spacing:.3px;">' . self::e(ORG_NAME) . '</span><br>
                        <span style="font-size:12px;color:#b9bed2;letter-spacing:.6px;text-transform:uppercase;">' . self::e(APP_SHORT_NAME) . '</span>
                    </td>
                </tr>

                <!-- A colour bar rather than a coloured heading: it survives the
                     clients that ignore text colour, and it is the fastest way to
                     tell an approval from a rejection at a glance. -->
                <tr><td bgcolor="' . $accent . '" style="height:4px;line-height:4px;font-size:0;">&nbsp;</td></tr>

                <tr>
                    <td style="padding:28px;font-family:Arial,Helvetica,sans-serif;">
                        <p style="margin:0 0 16px;font-size:15px;color:' . self::NAVY . ';">' . $greeting . '</p>
                        <h1 style="margin:0 0 16px;font-size:19px;line-height:1.35;font-weight:bold;color:' . self::NAVY . ';">' . $safeTitle . '</h1>' . $bodyBlock . $buttonBlock . '
                    </td>
                </tr>

                <tr>
                    <td bgcolor="#fafbfc" style="padding:18px 28px;border-top:1px solid ' . self::LINE . ';font-family:Arial,Helvetica,sans-serif;">
                        <p style="margin:0 0 6px;font-size:11px;line-height:1.6;color:' . self::MUTED . ';">' . self::e(self::footerNote()) . '</p>
                        <p style="margin:0;font-size:11px;line-height:1.6;color:' . self::MUTED . ';">'
                            . self::e(ORG_NAME) . ' &middot; ' . self::e(ORG_ADDRESS) . ' &middot; ' . self::e(ORG_PHONE) . '</p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>';
    }

    /**
     * Escaping for email. Same rules as the web: every value interpolated above
     * passes through here, including the ones that look safe, because a leave
     * type or a department name is only ever an administrator away from
     * containing an apostrophe.
     */
    private static function e(?string $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
