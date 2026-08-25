<?php
/**
 * Application Global Constants
 */
define('APP_NAME', 'RI Leave Management System');
define('APP_SHORT_NAME', 'Leave Management System');
define('APP_URL', 'http://localhost:8000');
define('UPLOAD_DIR', __DIR__ . '/../uploads/attachments/');

// Organisation identity (shown in the header strip and footer)
define('ORG_NAME', 'Real Image Internet');
define('ORG_PHONE', '[+268] 2409 1000');
define('ORG_EMAIL', 'info@realnet.co.sz');
define('ORG_ADDRESS', 'Plot 168, Tsekwane Street, Mbabane');
define('ORG_WEBSITE', 'https://realimageservices.com/');

// Sign-in rate limiting.
//
// OFF. It counts failed sign-ins and stops answering after five in fifteen
// minutes, which is protection against somebody working through a password list
// against staff addresses that follow a predictable pattern. During testing,
// where accounts are shared and passwords are guessed at on purpose, it mostly
// locks out the person doing the testing.
//
// Set this to true before go-live. Nothing else needs changing - the table and
// the logic stay in place either way.
define('LOGIN_THROTTLE_ENABLED', false);

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
define('MAIL_ENABLED', filter_var(getenv('MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));

define('MAIL_HOST', getenv('MAIL_HOST') ?: 'mail.realnet.co.sz');
define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 25));

// '' none, 'tls' STARTTLS on a plain port, 'ssl' TLS from the first byte.
// Port 25 is usually plain or STARTTLS; 587 is STARTTLS; 465 is 'ssl'.
define('MAIL_ENCRYPTION', getenv('MAIL_ENCRYPTION') !== false ? getenv('MAIL_ENCRYPTION') : '');

// Empty username means send without authenticating, which only works when the
// mail server trusts this host by address.
define('MAIL_USERNAME', getenv('MAIL_USERNAME') !== false ? getenv('MAIL_USERNAME') : 'lms@realnet.co.sz');
define('MAIL_PASSWORD', getenv('MAIL_PASSWORD') !== false ? getenv('MAIL_PASSWORD') : '');

// The envelope sender must be a mailbox the server will send as, because the
// realnet.co.sz SPF record ends in -all: mail leaving from anywhere it does not
// list is rejected outright rather than sent to a junk folder.
define('MAIL_FROM_ADDRESS', getenv('MAIL_FROM_ADDRESS') ?: 'lms@realnet.co.sz');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: APP_SHORT_NAME);

// Replies go to a mailbox people read. lms@ is not one.
define('MAIL_REPLY_TO', getenv('MAIL_REPLY_TO') ?: ORG_EMAIL);

// Seconds to wait on the mail server before giving up and requeueing. Short on
// purpose: the worker has a whole queue to get through.
define('MAIL_TIMEOUT', (int)(getenv('MAIL_TIMEOUT') ?: 15));

// How many messages one worker run sends, and how many times a single message
// is retried before it is marked failed and left alone.
define('MAIL_BATCH_SIZE', (int)(getenv('MAIL_BATCH_SIZE') ?: 20));
define('MAIL_MAX_ATTEMPTS', (int)(getenv('MAIL_MAX_ATTEMPTS') ?: 5));

// Minutes to wait before retrying, indexed by attempts already made. Past the
// end of the list the last value repeats until MAIL_MAX_ATTEMPTS is reached.
define('MAIL_RETRY_BACKOFF', '1,5,15,60');

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
