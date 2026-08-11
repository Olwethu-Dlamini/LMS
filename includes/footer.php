    <footer class="ri-footer">
        <div class="container">
            <div class="ri-footer-top">
                <img src="<?php echo APP_URL; ?>/assets/images/ri-logo-white.png"
                     alt="<?php echo htmlspecialchars(ORG_NAME); ?>" class="ri-footer-logo">
                <div class="ri-util-group">
                    <span><i class="ti-mobile"></i><?php echo htmlspecialchars(ORG_PHONE); ?></span>
                    <span><i class="ti-email"></i><a href="mailto:<?php echo htmlspecialchars(ORG_EMAIL); ?>"><?php echo htmlspecialchars(ORG_EMAIL); ?></a></span>
                    <a href="<?php echo htmlspecialchars(ORG_WEBSITE); ?>" target="_blank" rel="noopener"><i class="ti-world"></i>realimageservices.com</a>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(ORG_NAME); ?>. All Rights Reserved.</span>
                <span><?php echo htmlspecialchars(APP_SHORT_NAME); ?></span>
            </div>
        </div>
    </footer>
</div><!-- /.ri-shell -->

    <script src="<?php echo APP_URL; ?>/assets/plugins/jQuery/jquery.min.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/plugins/bootstrap/bootstrap.min.js"></script>
</body>
</html>
