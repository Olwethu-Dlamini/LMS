<?php
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? APP_NAME;
$userName  = $_SESSION['user_name'] ?? '';
$userRole  = strtoupper($_SESSION['user_role'] ?? '');
$userEmpId = $_SESSION['user_emp_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- Montserrat: the corporate typeface used on realimageservices.com -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300..900;1,300..900&display=swap">

    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/themify-icons/themify-icons.css">

    <!-- Real Image brand theme (replaces the bundled blog stylesheet) -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/ri-theme.css">
</head>
<body>
<div class="ri-shell">

    <!-- utility strip -->
    <div class="ri-utility">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div class="ri-util-group">
                    <span><i class="ti-mobile"></i><?php echo htmlspecialchars(ORG_PHONE); ?></span>
                    <span><i class="ti-email"></i><a href="mailto:<?php echo htmlspecialchars(ORG_EMAIL); ?>"><?php echo htmlspecialchars(ORG_EMAIL); ?></a></span>
                    <span class="d-none d-lg-inline"><i class="ti-location-pin"></i><?php echo htmlspecialchars(ORG_ADDRESS); ?></span>
                </div>
                <div class="ri-util-group">
                    <?php if ($userName !== ''): ?>
                        <span><i class="ti-user"></i><?php echo htmlspecialchars($userName); ?></span>
                        <span class="ri-util-sep">|</span>
                        <a href="<?php echo APP_URL; ?>/modules/auth/change_password.php"><i class="ti-key"></i>Change Password</a>
                        <span class="ri-util-sep">|</span>
                        <a href="<?php echo APP_URL; ?>/modules/auth/logout.php"><i class="ti-power-off"></i>Logout</a>
                    <?php else: ?>
                        <a href="<?php echo htmlspecialchars(ORG_WEBSITE); ?>" target="_blank" rel="noopener"><i class="ti-world"></i>realimageservices.com</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- brand band -->
    <div class="ri-brandbar">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div class="d-flex align-items-center flex-wrap">
                    <a href="<?php echo landing_url(); ?>">
                        <img src="<?php echo APP_URL; ?>/assets/images/ri-logo-navy.png"
                             alt="<?php echo htmlspecialchars(ORG_NAME); ?>" class="ri-logo">
                    </a>
                    <div class="ri-sysname">
                        <strong><?php echo htmlspecialchars(APP_SHORT_NAME); ?></strong>
                        <span>Staff Leave Portal</span>
                    </div>
                </div>
                <?php if ($userName !== ''): ?>
                <div class="ri-whoami">
                    <span class="ri-whoami-name"><?php echo htmlspecialchars($userName); ?></span>
                    <span class="ri-whoami-meta"><?php echo htmlspecialchars($userEmpId); ?></span>
                    <span class="ri-rolechip ml-1"><?php echo htmlspecialchars($userRole); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
