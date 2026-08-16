<?php
/**
 * Notification bell for the navigation bars.
 *
 * Included by both the staff navbar and the admin console navbar, because an
 * administrator acting as break-glass approver needs to hear about a stranded
 * request as much as anybody. Renders nothing at all for a signed-out request or
 * an installation that has not run migration 002, so the navigation can include
 * it unconditionally.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../helpers/Notifier.php';

if (empty($_SESSION['user_id'])) {
    return;
}

$bellUserId  = (int)$_SESSION['user_id'];
$bellNotifier = new Notifier(getDBConnection());
$bellUnread  = $bellNotifier->unreadCount($bellUserId);
$bellItems   = $bellNotifier->latest($bellUserId, 6);
?>
<li class="nav-item dropdown ri-bell">
    <a class="nav-link dropdown-toggle" href="#" id="riNavBell" data-toggle="dropdown"
       aria-haspopup="true" aria-expanded="false"
       title="<?php echo $bellUnread > 0 ? $bellUnread . ' unread notification(s)' : 'Notifications'; ?>">
        <i class="ti-bell"></i>Alerts
        <?php if ($bellUnread > 0): ?>
            <span class="ri-bell-count"><?php echo $bellUnread > 99 ? '99+' : $bellUnread; ?></span>
        <?php endif; ?>
    </a>
    <div class="dropdown-menu dropdown-menu-right ri-bell-menu" aria-labelledby="riNavBell">
        <h6 class="dropdown-header">
            Notifications
            <?php if ($bellUnread > 0): ?>
                <span class="text-danger">&middot; <?php echo $bellUnread; ?> unread</span>
            <?php endif; ?>
        </h6>

        <?php if (empty($bellItems)): ?>
            <span class="dropdown-item-text text-muted small">Nothing yet. Leave activity will appear here.</span>
        <?php else: ?>
            <?php foreach ($bellItems as $item): ?>
                <a class="dropdown-item ri-bell-item<?php echo $item['read_at'] === null ? ' ri-bell-unread' : ''; ?>"
                   href="<?php echo APP_URL . '/modules/notifications/open.php?id=' . (int)$item['id']; ?>">
                    <span class="ri-bell-title"><?php echo htmlspecialchars($item['title']); ?></span>
                    <?php if (!empty($item['body'])): ?>
                        <span class="ri-bell-body"><?php echo htmlspecialchars($item['body']); ?></span>
                    <?php endif; ?>
                    <span class="ri-bell-when"><?php echo htmlspecialchars(date('D j M, H:i', strtotime($item['created_at']))); ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?php echo APP_URL; ?>/modules/notifications/index.php">
            <i class="ti-list"></i>View all notifications
        </a>
    </div>
</li>
