<?php
require_once __DIR__ . '/header.php';
?>
<div class="main-wrapper">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <div class="d-flex flex-column flex-grow-1">
        <?php require_once __DIR__ . '/navbar.php'; ?>
        <div class="content-area">
            <?php echo display_flash(); ?>
            <?php echo $pageContent ?? ''; ?>
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
?>
