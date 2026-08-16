<?php
/**
 * Open a notification: mark it read, then send the user where it points.
 *
 * The id arrives in a query string, so Notifier::open() only marks a row that
 * belongs to the signed-in user and returns its link. An id that is not theirs
 * yields no link and lands them on the notification list, telling an id-guesser
 * nothing about whether the row exists.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/Notifier.php';
check_auth();

$notifier = new Notifier(getDBConnection());
$link = $notifier->open((int)$_SESSION['user_id'], (int)($_GET['id'] ?? 0));

// Only ever redirect within this installation. A link column is written by the
// application itself, but honouring an absolute URL from the database would turn
// any future write into an open redirect.
$fallback = APP_URL . '/modules/notifications/index.php';
if ($link === null || strpos($link, APP_URL) !== 0) {
    $link = $fallback;
}

header('Location: ' . $link);
exit;
