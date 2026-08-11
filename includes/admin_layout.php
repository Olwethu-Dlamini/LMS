<?php
/**
 * Admin console shell. Same brand header as the staff portal so the identity is
 * consistent, but with the admin-only navigation in place of the staff nav.
 *
 * Pages set $pageContent plus, optionally, $pageHeading / $pageSubtitle /
 * $pageIcon / $pageActions, exactly as with includes/layout.php.
 */
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/admin_navbar.php';
?>
<div class="ri-content">
    <?php if (!empty($pageHeading)): ?>
        <div class="ri-pagehead ri-pagehead-admin">
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
