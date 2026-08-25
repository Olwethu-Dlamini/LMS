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
 * Two rules shape the content:
 *
 *   The email says everything. The link is an offer, not an instruction.
 *   Somebody reading a leave decision on a phone at the weekend should learn the
 *   outcome from the message and never have to sign in to find out what
 *   happened.
 *
 *   The facts are a table, not a sentence. The first draft put the whole request
 *   on one line - reference, category, day count and dates, comma-separated -
 *   which is readable once and unscannable afterwards. An approver working
 *   through eleven of these wants to find the dates without reading anything, so
 *   the dates get their own labelled row.
 *
 * Email HTML is not web HTML. Tables and inline styles throughout, because
 * Outlook renders through Word, Gmail strips <style> blocks, and neither
 * supports custom properties - so the brand colours from ri-theme.css are
 * inlined here as constants rather than referenced.
 */
class EmailTemplate {
    const NAVY    = '#0a1035';
    const NAVY_2  = '#17203f';
    const TEAL    = '#004668';
    const GREY    = '#eff1f5';
    const LINE    = '#e2e6ee';
    const INK     = '#1f2433';
    const MUTED   = '#6b7280';
    const FAINT   = '#9aa0ae';
    const SUCCESS = '#17864a';
    const DANGER  = '#b3261e';
    const WARNING = '#9a6200';
    const INFO    = '#005394';

    const WIDTH = 600;

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
     * One or two words for the status pill, so the outcome is legible before the
     * message is read. Deliberately shorter than the title: the pill is scanned,
     * the title is read.
     */
    public static function statusLabel(string $type): string {
        switch ($type) {
            case Notifier::TYPE_APPROVED:
                return 'Approved';
            case Notifier::TYPE_REJECTED:
                return 'Declined';
            case Notifier::TYPE_AWAITING:
                return 'Action needed';
            case Notifier::TYPE_CANCELLED:
                return 'Cancelled';
            case Notifier::TYPE_SUBMITTED:
                return 'Submitted';
            default:
                return 'Updated';
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
     * The line inboxes show in grey beside the subject.
     *
     * Given the detail rather than a repeat of the title, which is already the
     * line above it. Two identical lines waste the only preview a recipient gets
     * before deciding whether to open the message.
     */
    public static function preheader(string $title, ?string $body, array $details = []): string {
        if (!empty($details)) {
            $parts = [];
            foreach ($details as $label => $value) {
                if ((string)$value !== '') {
                    $parts[] = $label . ': ' . $value;
                }
            }
            if (!empty($parts)) {
                return implode('  |  ', $parts);
            }
        }
        return trim((string)$body) !== '' ? (string)$body : $title;
    }

    /**
     * The closing line: why this arrived, and what to do about the fact it did.
     */
    public static function footerNote(): string {
        return 'You are receiving this because ' . APP_SHORT_NAME
            . ' recorded activity on a leave request involving you. '
            . 'This mailbox is not monitored; replies go to ' . MAIL_REPLY_TO . '.';
    }

    /* ------------------------------------------------------------------ *
     * Plain text
     * ------------------------------------------------------------------ */

    /**
     * The plain-text alternative.
     *
     * Written as the message somebody would be content to receive on its own,
     * not as a stripped copy of the HTML. Some staff read mail in clients that
     * refuse HTML outright, and a message with no text part scores worse with
     * spam filters.
     *
     * @param array  $details label => value pairs, shown as aligned rows
     * @param string $remarks an approver's comments, quoted separately
     */
    public static function renderText(
        string $type,
        string $title,
        ?string $body,
        ?string $link,
        ?string $firstName,
        array $details = [],
        ?string $remarks = null
    ): string {
        $lines = [];
        $lines[] = self::greeting($firstName);
        $lines[] = '';
        $lines[] = $title;
        $lines[] = str_repeat('=', min(72, max(8, strlen($title))));

        if (!empty($details)) {
            $lines[] = '';
            // Labels padded to a common width so the values line up in a
            // fixed-width mail client, which is where plain text gets read.
            $width = 0;
            foreach (array_keys($details) as $label) {
                $width = max($width, strlen($label));
            }
            foreach ($details as $label => $value) {
                if ((string)$value === '') {
                    continue;
                }
                $lines[] = '  ' . str_pad($label, $width) . '   ' . $value;
            }
        } elseif (!empty($body)) {
            $lines[] = '';
            $lines[] = $body;
        }

        if (!empty($remarks)) {
            $lines[] = '';
            $lines[] = 'Remarks from the approver:';
            foreach (preg_split('/\R/', trim($remarks)) as $remarkLine) {
                $lines[] = '  " ' . $remarkLine;
            }
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

    /* ------------------------------------------------------------------ *
     * HTML
     * ------------------------------------------------------------------ */

    /**
     * The HTML alternative.
     *
     * Single column, so it needs no media queries to survive a phone, and every
     * width is a percentage with a max-width above it.
     */
    public static function renderHtml(
        string $type,
        string $title,
        ?string $body,
        ?string $link,
        ?string $firstName,
        array $details = [],
        ?string $remarks = null
    ): string {
        $accent = self::accent($type);
        $safeTitle = self::e($title);

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<!-- Tell Outlook to use the real rendering engine rather than a scaled one. -->
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<title>' . $safeTitle . '</title>
<!--[if mso]>
<style type="text/css">
  /* Word has no web fonts and ignores line-height on tables. Arial keeps the
     metrics predictable rather than falling back to Times. */
  body, table, td, p, a, h1 { font-family: Arial, Helvetica, sans-serif !important; }
  table { border-collapse: collapse !important; }
</style>
<![endif]-->
</head>
<body style="margin:0;padding:0;width:100%;background-color:' . self::GREY . ';-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

<!-- Preheader: the grey line beside the subject in an inbox list. Hidden in the
     body, and padded so a client cannot pull following text into the preview. -->
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
    . self::e(self::preheader($title, $body, $details))
    . str_repeat('&#847;&zwnj;&nbsp;', 30) . '</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . self::GREY . ';">
    <tr>
        <td align="center" style="padding:24px 12px;">

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="' . self::WIDTH . '" style="width:100%;max-width:' . self::WIDTH . 'px;background-color:#ffffff;border:1px solid ' . self::LINE . ';">

                <!-- Masthead -->
                <tr>
                    <td style="padding:22px 28px 18px;background-color:' . self::NAVY . ';">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:Arial,Helvetica,sans-serif;">
                                    <div style="font-size:17px;font-weight:bold;color:#ffffff;letter-spacing:.2px;line-height:1.2;">' . self::e(ORG_NAME) . '</div>
                                    <div style="font-size:11px;color:#9ba2bd;letter-spacing:.8px;text-transform:uppercase;padding-top:3px;">' . self::e(APP_SHORT_NAME) . '</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <!-- A colour bar rather than coloured text: it survives the
                     clients that ignore text colour, and it is the fastest way
                     to tell an approval from a rejection at a glance. -->
                <tr><td style="height:4px;line-height:4px;font-size:0;background-color:' . $accent . ';">&nbsp;</td></tr>

                <!-- Body -->
                <tr>
                    <td style="padding:28px 28px 8px;font-family:Arial,Helvetica,sans-serif;">
                        ' . self::statusPill($type, $accent) . '
                        <h1 style="margin:14px 0 6px;font-size:20px;line-height:1.3;font-weight:bold;color:' . self::NAVY . ';">' . $safeTitle . '</h1>
                        <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:' . self::MUTED . ';">' . self::e(self::greeting($firstName)) . '</p>
                        ' . self::detailBlock($details, $body) . '
                        ' . self::remarksBlock($remarks) . '
                    </td>
                </tr>

                ' . self::buttonRow($type, $link) . '

                <!-- Footer -->
                <tr>
                    <td style="padding:18px 28px 22px;background-color:#fafbfc;border-top:1px solid ' . self::LINE . ';font-family:Arial,Helvetica,sans-serif;">
                        <p style="margin:0 0 8px;font-size:11px;line-height:1.6;color:' . self::MUTED . ';">' . self::e(self::footerNote()) . '</p>
                        <p style="margin:0;font-size:11px;line-height:1.6;color:' . self::FAINT . ';">'
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
     * The status pill: the outcome in one or two words, before anything is read.
     *
     * A tinted background would need a second colour per status, so it borrows
     * the accent for the text and border instead. Padding is on the cell rather
     * than the span, because Word ignores padding on inline elements.
     */
    private static function statusPill(string $type, string $accent): string {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding:5px 12px;border:1px solid ' . $accent . ';border-radius:20px;
                                    font-family:Arial,Helvetica,sans-serif;font-size:10.5px;font-weight:bold;
                                    letter-spacing:.9px;text-transform:uppercase;color:' . $accent . ';">'
                                    . self::e(self::statusLabel($type)) . '</td>
                            </tr>
                        </table>';
    }

    /**
     * The facts, as labelled rows.
     *
     * Falls back to the prose body when no structured detail was supplied, so a
     * notification raised by anything that does not build a detail list still
     * produces a complete message rather than an empty frame.
     */
    private static function detailBlock(array $details, ?string $body): string {
        $rows = '';
        foreach ($details as $label => $value) {
            if ((string)$value === '') {
                continue;
            }
            $rows .= '
                            <tr>
                                <td width="34%" style="padding:9px 14px;border-bottom:1px solid ' . self::LINE . ';
                                    font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:bold;
                                    letter-spacing:.4px;text-transform:uppercase;color:' . self::MUTED . ';
                                    vertical-align:top;">' . self::e((string)$label) . '</td>
                                <td style="padding:9px 14px;border-bottom:1px solid ' . self::LINE . ';
                                    font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;
                                    color:' . self::INK . ';vertical-align:top;">' . self::e((string)$value) . '</td>
                            </tr>';
        }

        if ($rows === '') {
            if (empty($body)) {
                return '';
            }
            return '<p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:' . self::INK . ';">'
                . nl2br(self::e($body)) . '</p>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                            style="width:100%;margin:0 0 20px;border:1px solid ' . self::LINE . ';border-bottom:none;background-color:#fcfcfd;">'
                            . $rows . '
                        </table>';
    }

    /**
     * An approver's comments, set apart from the facts.
     *
     * Quoted rather than merged into the detail table because it is the one part
     * written by a person, and the sentence a declined applicant most wants to
     * read. nl2br runs after escaping, never before.
     */
    private static function remarksBlock(?string $remarks): string {
        if (empty(trim((string)$remarks))) {
            return '';
        }
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 20px;">
                            <tr>
                                <td style="padding:14px 16px;background-color:#f6f8fb;border-left:3px solid ' . self::TEAL . ';">
                                    <div style="font-family:Arial,Helvetica,sans-serif;font-size:10.5px;font-weight:bold;
                                        letter-spacing:.7px;text-transform:uppercase;color:' . self::MUTED . ';padding-bottom:6px;">Remarks from the approver</div>
                                    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:' . self::INK . ';">'
                                        . nl2br(self::e(trim((string)$remarks))) . '</div>
                                </td>
                            </tr>
                        </table>';
    }

    /**
     * The button, and the address underneath it for when the button is stripped.
     *
     * The VML block is what makes it a rounded button in Outlook, which ignores
     * border-radius entirely and would otherwise render a square block or, worse,
     * a bare link. Everything else uses the anchor.
     */
    private static function buttonRow(string $type, ?string $link): string {
        if (empty($link)) {
            return '';
        }

        $safeLink = self::e($link);
        $label    = self::e(self::callToAction($type));

        return '<tr>
                    <td style="padding:4px 28px 26px;font-family:Arial,Helvetica,sans-serif;">
                        <!--[if mso]>
                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"
                            href="' . $safeLink . '" style="height:44px;v-text-anchor:middle;width:220px;" arcsize="50%"
                            strokecolor="' . self::TEAL . '" fillcolor="' . self::TEAL . '">
                            <w:anchorlock/>
                            <center style="color:#ffffff;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;">'
                                . $label . '</center>
                        </v:roundrect>
                        <![endif]-->
                        <!--[if !mso]><!-->
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="border-radius:50px;background-color:' . self::TEAL . ';">
                                    <a href="' . $safeLink . '" style="display:inline-block;padding:13px 30px;
                                        font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:bold;
                                        color:#ffffff;text-decoration:none;border-radius:50px;">' . $label . '</a>
                                </td>
                            </tr>
                        </table>
                        <!--<![endif]-->

                        <p style="margin:16px 0 0;font-size:11px;line-height:1.6;color:' . self::FAINT . ';">
                            If the button does not work, paste this into your browser:<br />
                            <span style="color:' . self::INFO . ';word-break:break-all;">' . $safeLink . '</span>
                        </p>
                    </td>
                </tr>';
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
