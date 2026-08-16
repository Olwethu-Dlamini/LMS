<?php
/**
 * Everything the signed-in user has been told, newest first.
 *
 * Reachable by every role including admin, so it authenticates rather than
 * demanding staff status - an administrator holds no leave entitlement but does
 * receive notices about requests waiting on their break-glass override.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../helpers/Notifier.php';
check_auth();

$db       = getDBConnection();
$notifier = new Notifier($db);
$userId   = (int)$_SESSION['user_id'];
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $marked = $notifier->markAllRead($userId);
        set_flash('success', $marked > 0
            ? "{$marked} notification(s) marked as read."
            : 'Nothing was unread.');
        header('Location: ' . APP_URL . '/modules/notifications/index.php');
        exit;
    }
}

$items  = $notifier->latest($userId, 60);
$unread = $notifier->unreadCount($userId);

/** Icon and colour matching the kind of event. */
function notification_icon(string $type): string {
    switch ($type) {
        case Notifier::TYPE_APPROVED:
            return '<i class="ti-check text-success"></i>';
        case Notifier::TYPE_REJECTED:
            return '<i class="ti-close text-danger"></i>';
        case Notifier::TYPE_AWAITING:
            return '<i class="ti-time text-warning"></i>';
        case Notifier::TYPE_CANCELLED:
            return '<i class="ti-na text-muted"></i>';
        case Notifier::TYPE_SUBMITTED:
            return '<i class="ti-pencil-alt text-primary"></i>';
        default:
            return '<i class="ti-arrow-right text-info"></i>';
    }
}

ob_start();
?>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger mb-4"><?php echo $error; ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="font-weight-bold text-dark">
            <i class="ti-bell text-primary"></i> Notifications
            <?php if ($unread > 0): ?>
                <span class="badge badge-danger"><?php echo $unread; ?> unread</span>
            <?php endif; ?>
        </span>
        <?php if ($unread > 0): ?>
            <form method="POST" action="" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="ti-check-box"></i> Mark all as read
                </button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($items)): ?>
            <div class="ri-empty">
                <i class="ti-bell"></i>
                Nothing yet. Submitting leave, or having a request reach your queue,
                will show up here.
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush ri-notif-list">
                <?php foreach ($items as $item): ?>
                    <a class="list-group-item list-group-item-action ri-notif<?php echo $item['read_at'] === null ? ' ri-notif-unread' : ''; ?>"
                       href="<?php echo APP_URL . '/modules/notifications/open.php?id=' . (int)$item['id']; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="ri-notif-main">
                                <div class="ri-notif-title">
                                    <?php echo notification_icon($item['type']); ?>
                                    <?php echo htmlspecialchars($item['title']); ?>
                                    <?php if ($item['read_at'] === null): ?>
                                        <span class="badge badge-danger">New</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($item['body'])): ?>
                                    <div class="ri-notif-body"><?php echo htmlspecialchars($item['body']); ?></div>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted text-nowrap ml-3">
                                <?php echo htmlspecialchars(date('D j M Y, H:i', strtotime($item['created_at']))); ?>
                            </small>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if (count($items) >= 60): ?>
        <div class="card-footer bg-white small text-muted">
            Showing the 60 most recent notifications.
        </div>
    <?php endif; ?>
</div>

<?php
$pageContent = ob_get_clean();
$pageTitle = 'Notifications | ' . APP_NAME;
$pageHeading = 'Notifications';
$pageSubtitle = 'Leave activity that needs your attention, newest first.';
$pageIcon = 'ti-bell';
require_once __DIR__ . '/../../includes/layout.php';
?>
