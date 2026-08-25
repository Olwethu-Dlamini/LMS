<?php
/**
 * What the notification bell should be showing right now.
 *
 * The bell is rendered server-side when a page loads, which was fine for a
 * system nobody watched and wrong for the one place people do: an approver
 * sitting on their queue waiting for something to arrive saw nothing until they
 * thought to reload. This endpoint lets the bell keep itself current.
 *
 * Read-only, so it takes no CSRF token - there is nothing here to forge into an
 * action. It answers only for the signed-in user and never takes a user id from
 * the request, which is what stops it becoming a way to read somebody else's
 * notifications.
 */
header('Content-Type: application/json');
// A count from a moment ago is worse than useless, since it would be shown as
// current. No caching anywhere.
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/Notifier.php';

function poll_respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['user_id'])) {
    // 401 rather than an empty list: the caller stops polling on this, which is
    // the right response to a session that has expired. An empty list would look
    // like "nothing new" and be retried for ever.
    poll_respond(['success' => false, 'error' => 'Unauthenticated'], 401);
}

try {
    $userId   = (int)$_SESSION['user_id'];
    $notifier = new Notifier(getDBConnection());

    $items = array_map(function (array $item): array {
        return [
            'id'     => (int)$item['id'],
            'title'  => (string)$item['title'],
            'body'   => (string)($item['body'] ?? ''),
            'when'   => date('D j M, H:i', strtotime($item['created_at'])),
            'unread' => $item['read_at'] === null,
            'url'    => APP_URL . '/modules/notifications/open.php?id=' . (int)$item['id'],
        ];
    }, $notifier->latest($userId, 6));

    poll_respond([
        'success' => true,
        'unread'  => $notifier->unreadCount($userId),
        'items'   => $items,
    ]);
} catch (Throwable $e) {
    // The bell is an aid, not a gate. A failure here leaves whatever was last
    // rendered on screen, which is better than blanking it.
    poll_respond(['success' => false, 'error' => 'Unable to read notifications.'], 500);
}
