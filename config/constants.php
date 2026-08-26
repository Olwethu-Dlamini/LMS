<?php
/**
 * Application Global Constants
 *
 * Three layers, in order of precedence:
 *
 *   1. config/local.php, if it exists. Untracked, created once per server, and
 *      never touched by a pull. This is the file to edit on a live host.
 *   2. Environment variables, for anything local.php has not already set.
 *   3. The defaults below, which are development values.
 *
 * Every define() is guarded with defined(), so whatever an earlier layer set
 * wins. The point is that deploying is `git pull` and nothing else: the settings
 * that differ per host live outside the repository, so no tracked file has to be
 * edited on a server and no pull can ever conflict with, or quietly revert, a
 * production setting.
 *
 * Copy config/local.php.example to config/local.php to start one.
 */

// Layer 1. Loaded first so its values take precedence over everything below.
if (is_file(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

/**
 * Define a constant from the environment, unless something already has.
 *
 * Exists so the three layers do not have to be spelled out thirty times. A
 * variable set to an empty string counts as set, because "" is a meaningful
 * value for MAIL_USERNAME and MAIL_REDIRECT_TO.
 */
function config_default(string $name, $default): void {
    if (defined($name)) {
        return;
    }
    $fromEnv = getenv($name);
    if ($fromEnv === false) {
        define($name, $default);
        return;
    }
    if (is_bool($default)) {
        define($name, filter_var($fromEnv, FILTER_VALIDATE_BOOLEAN));
    } elseif (is_int($default)) {
        define($name, (int)$fromEnv);
    } else {
        define($name, $fromEnv);
    }
}

config_default('APP_NAME', 'RI Leave Management System');
config_default('APP_SHORT_NAME', 'Leave Management System');

// The address staff reach the portal on. This is not decoration: it is the base
// of every link in every notification email, and those are rendered by a cron
// worker that has no HTTP request to infer a hostname from. Left as localhost on
// a live server, every recipient gets a link to their own machine.
config_default('APP_URL', 'http://localhost:8000');

define('UPLOAD_DIR', __DIR__ . '/../uploads/attachments/');

// Organisation identity (shown in the header strip and footer)
config_default('ORG_NAME', 'Real Image Internet');
config_default('ORG_PHONE', '[+268] 2409 1000');
config_default('ORG_EMAIL', 'info@realnet.co.sz');
config_default('ORG_ADDRESS', 'Plot 168, Tsekwane Street, Mbabane');
config_default('ORG_WEBSITE', 'https://realimageservices.com/');

// Sign-in rate limiting.
//
// OFF by default. It counts failed sign-ins and stops answering after five in
// fifteen minutes, which is protection against somebody working through a
// password list against staff addresses that follow a predictable pattern.
// During testing, where accounts are shared and passwords are guessed at on
// purpose, it mostly locks out the person doing the testing.
//
// Turn it on for a live server in config/local.php. Nothing else needs changing,
// the table and the logic stay in place either way.
config_default('LOGIN_THROTTLE_ENABLED', false);

// Outgoing email.
//
// OFF. Nothing is queued and nothing is sent while MAIL_ENABLED is false, which
// is deliberate: an installation with no credentials should build no backlog.
// Turn it on once tools/test_email.php has actually delivered a message from the
// machine the application runs on.
//
// Every value can be overridden by an environment variable, the same way
// config/database.php takes its connection details, so a container can be
// pointed at a different mail server without editing a tracked file.
//
// MAIL_PASSWORD is the exception: it has no default and no literal here. A
// mailbox password in a committed file is a mailbox password on GitHub. It comes
// from the environment only - see the Dockerfile's PassEnv line, and the email
// section of the README for setting it under Apache or on the command line.
// Nothing needs it as things stand; see the note on MAIL_USERNAME below.
config_default('MAIL_ENABLED', false);

config_default('MAIL_HOST', 'mail.realnet.co.sz');
config_default('MAIL_PORT', 25);

// '' none, 'tls' STARTTLS on a plain port, 'ssl' TLS from the first byte.
//
// Empty, because the server does not offer encryption. mail.realnet.co.sz is an
// IMail 8.22 server and answers EHLO on port 25 with SIZE, 8BITMIME, DSN, ETRN
// and EXPN - no STARTTLS - while 587 and 465 both refuse the connection
// outright. Asking for encryption it does not advertise would fail every send.
config_default('MAIL_ENCRYPTION', '');

// Empty username means send without authenticating.
//
// That is not a shortcut, it is what this server supports: its EHLO response
// advertises no AUTH at all, so there is nothing to authenticate against. It
// decides what to relay by the address the connection comes from, and it accepts
// mail from this host. The consequence worth remembering is that the
// application must keep running from an address the mail server trusts - moving
// it to a host outside Realnet's own ranges will have mail refused, and the
// symptom will be a relay error in the outbox rather than anything obvious.
//
// The lms@realnet.co.sz password is therefore not used for sending. It is the
// password for reading that mailbox over POP3, which is a different job that
// this application does not do. If AUTH is ever enabled on the server, set
// MAIL_USERNAME and MAIL_PASSWORD and the client starts authenticating with no
// other change.
config_default('MAIL_USERNAME', '');
config_default('MAIL_PASSWORD', '');

// The envelope sender must be a mailbox the server will send as, because the
// realnet.co.sz SPF record ends in -all: mail leaving from anywhere it does not
// list is rejected outright rather than sent to a junk folder.
config_default('MAIL_FROM_ADDRESS', 'lms@realnet.co.sz');
config_default('MAIL_FROM_NAME', APP_SHORT_NAME);

// Where a staff member's reply lands when they answer a leave notice.
//
// The default sends them to the organisation's general address, on the
// reasoning that somebody reads it and lms@ historically nobody did. Set it to
// MAIL_FROM_ADDRESS instead to keep replies with the sending mailbox, which is
// where bounces already arrive: one mailbox to watch rather than two. Mailer
// omits the header entirely when the two match, so replies fall back to From:
// and the result is the same with one header fewer.
//
// Either is defensible. What is not is pointing this at a mailbox nobody opens:
// a reply to a leave notice is usually somebody asking a question about their
// own leave, and it fails silently, looking to them like it was ignored.
config_default('MAIL_REPLY_TO', ORG_EMAIL);

// Send everything to one address instead of to the real recipients.
//
// This exists for UAT. The test database is seeded from the real staff roster,
// so almost every account carries a colleague's actual address, and the UAT
// machine can reach the mail server - which means a single test approval is
// enough to send thirty people a leave notice about a request that does not
// exist. Setting this redirects every message to one mailbox instead.
//
// The redirect happens at the moment of sending, not when the message is
// queued, so email_outbox still records who the mail was really for. The
// delivery log stays truthful and the subject line says who each one would have
// gone to.
//
// Empty in production. Anything else there would silently stop staff being
// notified, so tools/send_queued_email.php --status and tools/test_email.php
// both say loudly when it is set.
config_default('MAIL_REDIRECT_TO', '');

// Blind-copy every outgoing message to one mailbox, as a delivery trail.
//
// The copy rides the same SMTP transaction as a Bcc, so what lands in the
// archive is byte-identical to what the recipient received, headers included -
// not a re-rendering of it. The To: header still names the real person, so the
// mailbox reads as a record of who was told what.
//
// Skipped entirely while MAIL_REDIRECT_TO is set. During UAT every message is
// already going to one mailbox, and filing test notices about leave that does
// not exist alongside the real trail would make the trail worth less than no
// trail at all.
//
// An unusable value here is logged and ignored rather than raised. Failing the
// send would mean a colleague is not told about their own leave because the
// address for the copy has a typo, and the notice matters more than the record
// of it. This is the opposite of how MAIL_REDIRECT_TO handles a bad address,
// deliberately: there, delivering anyway is the harm.
//
// Note this doubles the volume the mail server handles, and email_outbox
// already stores every message in full. Set it when somebody wants the trail in
// a mailbox they can search, not as a substitute for the outbox.
config_default('MAIL_ARCHIVE_TO', '');

// Seconds to wait on the mail server before giving up and requeueing. Short on
// purpose: the worker has a whole queue to get through.
config_default('MAIL_TIMEOUT', 15);

// How many messages one worker run sends, and how many times a single message
// is retried before it is marked failed and left alone.
config_default('MAIL_BATCH_SIZE', 20);
config_default('MAIL_MAX_ATTEMPTS', 5);

// Minutes to wait before retrying, indexed by attempts already made. Past the
// end of the list the last value repeats until MAIL_MAX_ATTEMPTS is reached.
config_default('MAIL_RETRY_BACKOFF', '1,5,15,60');

// Role Codes
define('ROLE_EMPLOYEE', 'employee');
define('ROLE_MANAGER', 'manager');
define('ROLE_HR', 'hr');
define('ROLE_EXECUTIVE', 'executive');
define('ROLE_ADMIN', 'admin');

// Application Status Codes
define('STATUS_PENDING_MANAGER', 'pending_manager');
define('STATUS_PENDING_HR', 'pending_hr');
define('STATUS_PENDING_EXECUTIVE', 'pending_executive');
define('STATUS_APPROVED', 'approved');
define('STATUS_REJECTED', 'rejected');
define('STATUS_CANCELLED', 'cancelled');
