<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/constants.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . APP_URL . '/modules/auth/login.php');
    exit;
} else {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}
