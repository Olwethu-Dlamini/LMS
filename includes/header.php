<?php
require_once __DIR__ . '/functions.php';
$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- Theme Plugins -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/plugins/themify-icons/themify-icons.css">
    
    <!-- Custom Theme Stylesheet -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/style.css">
    
    <!-- Custom LMS Component Overrides -->
    <style>
        body {
            background-color: #f4f7f6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 260px;
            background: #1e293b;
            color: #f8fafc;
            min-height: 100vh;
            flex-shrink: 0;
            transition: all 0.3s;
        }
        .sidebar .nav-link {
            color: #94a3b8;
            padding: 12px 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            border-left: 3px solid transparent;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #ffffff;
            background: #334155;
            border-left-color: #3b82f6;
        }
        .sidebar .nav-link i {
            margin-right: 12px;
            font-size: 1.1rem;
        }
        .content-area {
            flex-grow: 1;
            padding: 30px;
            background-color: #f8fafc;
        }
        .top-navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            margin-bottom: 25px;
        }
        .card-header {
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            font-weight: 600;
        }
        .badge {
            padding: 6px 12px;
            font-size: 0.825rem;
            border-radius: 6px;
        }
    </style>
</head>
<body>
