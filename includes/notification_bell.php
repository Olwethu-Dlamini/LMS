<?php
/**
 * Notification bell for the navigation bars.
 *
 * Included by both the staff navbar and the admin console navbar, because an
 * administrator acting as break-glass approver needs to hear about a stranded
 * request as much as anybody. Renders nothing at all for a signed-out request or
 * an installation that has not run migration 002, so the navigation can include
 * it unconditionally.
 *
 * The bell keeps itself current. It is rendered server-side on page load and then
 * refreshed from api/notifications_poll.php, because the one place people wait
 * for a notification is an approval queue they are already looking at - and
 * until now that page had to be reloaded before it would admit anything had
 * arrived. The script travels with the markup rather than living in a footer, so
 * both navbars get the behaviour without either of them knowing about it.
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
            <span class="ri-bell-count" data-ri-bell-count><?php echo $bellUnread > 99 ? '99+' : $bellUnread; ?></span>
        <?php else: ?>
            <span class="ri-bell-count" data-ri-bell-count hidden></span>
        <?php endif; ?>
    </a>
    <div class="dropdown-menu dropdown-menu-right ri-bell-menu" aria-labelledby="riNavBell">
        <h6 class="dropdown-header">
            Notifications
            <span class="text-danger" data-ri-bell-unread<?php echo $bellUnread > 0 ? '' : ' hidden'; ?>>&middot; <?php echo $bellUnread; ?> unread</span>
        </h6>

        <div data-ri-bell-items>
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
        </div>

        <div class="dropdown-divider"></div>
        <a class="dropdown-item" href="<?php echo APP_URL; ?>/modules/notifications/index.php">
            <i class="ti-list"></i>View all notifications
        </a>
    </div>
</li>
<script>
/**
 * Keep the bell current without reloading the page.
 *
 * Plain DOM on purpose. jQuery and Bootstrap load at the end of the body and
 * this markup sits in the navbar at the top, so depending on either would mean
 * depending on script order in two different layouts.
 */
(function () {
    'use strict';

    // The bell appears once per page, but the guard costs nothing and a second
    // copy would poll twice as often for the same answer.
    if (window.riBellWatching) { return; }
    window.riBellWatching = true;

    var ENDPOINT = <?php echo json_encode(APP_URL . '/api/notifications_poll.php'); ?>;

    // A minute. Leave approval is not a chat window, and this runs on every open
    // tab of every signed-in member of staff - a few seconds would multiply into
    // real load for an answer almost always identical to the last one.
    var EVERY_MS = 60000;

    var bell = document.querySelector('.ri-bell');
    if (!bell) { return; }

    var badge     = bell.querySelector('[data-ri-bell-count]');
    var unreadTag = bell.querySelector('[data-ri-bell-unread]');
    var list      = bell.querySelector('[data-ri-bell-items]');
    var link      = bell.querySelector('.nav-link');
    var timer     = null;

    function setBadge(unread) {
        if (badge) {
            badge.textContent = unread > 99 ? '99+' : String(unread);
            badge.hidden = unread === 0;
        }
        if (unreadTag) {
            unreadTag.textContent = '· ' + unread + ' unread';
            unreadTag.hidden = unread === 0;
        }
        if (link) {
            link.title = unread > 0 ? unread + ' unread notification(s)' : 'Notifications';
        }
    }

    /**
     * Rebuild the dropdown contents.
     *
     * Every value goes in through textContent, never innerHTML. Notification
     * bodies carry approver remarks typed by a person, and the server escapes
     * them for the page it renders - repeating that escaping here, by hand, in
     * a second language, is how the two eventually disagree.
     */
    function setItems(items) {
        if (!list) { return; }

        list.textContent = '';

        if (!items.length) {
            var empty = document.createElement('span');
            empty.className = 'dropdown-item-text text-muted small';
            empty.textContent = 'Nothing yet. Leave activity will appear here.';
            list.appendChild(empty);
            return;
        }

        items.forEach(function (item) {
            var row = document.createElement('a');
            row.className = 'dropdown-item ri-bell-item' + (item.unread ? ' ri-bell-unread' : '');
            row.href = item.url;

            var title = document.createElement('span');
            title.className = 'ri-bell-title';
            title.textContent = item.title;
            row.appendChild(title);

            if (item.body) {
                var body = document.createElement('span');
                body.className = 'ri-bell-body';
                body.textContent = item.body;
                row.appendChild(body);
            }

            var when = document.createElement('span');
            when.className = 'ri-bell-when';
            when.textContent = item.when;
            row.appendChild(when);

            list.appendChild(row);
        });
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    function refresh() {
        // Nothing to see and nothing worth asking for while the tab is in the
        // background. The visibility handler catches up on the way back.
        if (document.hidden) { return; }

        fetch(ENDPOINT, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                if (response.status === 401) {
                    // The session has ended. The timer stops, because polling a
                    // login page every minute until the tab is closed helps
                    // nobody.
                    //
                    // Coming back to the tab still tries once - deliberately.
                    // Signing in again in another tab is the ordinary way this
                    // happens, and the bell should recover on its own when it
                    // does rather than stay dead until a reload.
                    stop();
                    return null;
                }
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data || !data.success) { return; }

                setBadge(data.unread);

                // Never rearrange the list while somebody has it open and is
                // reading it. The count still updates; the items wait.
                var open = bell.querySelector('.dropdown-menu.show')
                        || (bell.classList.contains('show') ? bell.querySelector('.dropdown-menu') : null);
                if (!open) {
                    setItems(data.items || []);
                }
            })
            .catch(function () {
                // Offline, or the server is unhappy. Leave what is on screen and
                // try again on the next tick.
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refresh(); }
    });

    timer = setInterval(refresh, EVERY_MS);
}());
</script>
