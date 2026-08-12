<?php
/**
 * Page shell. Pages set $pageContent (buffered markup) plus, optionally:
 *   $pageHeading   heading shown in the gradient page-head band
 *   $pageSubtitle  supporting line under the heading
 *   $pageIcon      themify icon class for the heading, e.g. 'ti-pie-chart'
 *   $pageActions   raw markup for buttons rendered on the right of the band
 * When $pageHeading is unset the band is skipped and the page keeps whatever
 * heading it renders inside $pageContent.
 *
 * Admins get the admin console navigation on every page they can reach, so the
 * staff portal's My Leave / Apply items never appear for an account that holds
 * no leave entitlement. The leave screens themselves are closed to admins by
 * require_staff(); what remains reachable is their break-glass approval and HR
 * tooling, and those keep the console's own nav.
 */
require_once __DIR__ . '/header.php';
if (is_admin()) {
    require_once __DIR__ . '/admin_navbar.php';
} else {
    require_once __DIR__ . '/navbar.php';
}
?>
<div class="ri-content">
    <?php if (!empty($pageHeading)): ?>
        <div class="ri-pagehead">
            <div class="container">
                <div class="ri-pagehead-inner">
                    <div>
                        <h1>
                            <?php if (!empty($pageIcon)): ?><i class="<?php echo htmlspecialchars($pageIcon); ?>"></i> <?php endif; ?>
                            <?php echo htmlspecialchars($pageHeading); ?>
                        </h1>
                        <?php if (!empty($pageSubtitle)): ?>
                            <p><?php echo htmlspecialchars($pageSubtitle); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($pageActions)): ?>
                        <div class="ri-pagehead-actions"><?php echo $pageActions; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="container">
        <?php echo display_flash(); ?>
        <?php echo $pageContent ?? ''; ?>
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
?>
