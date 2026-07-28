<?php
/**
 * Application Global Constants
 */
define('APP_NAME', 'RI Leave Management System');
define('APP_URL', 'http://localhost:8000');
define('UPLOAD_DIR', __DIR__ . '/../uploads/attachments/');

// Role Codes
define('ROLE_EMPLOYEE', 'employee');
define('ROLE_MANAGER', 'manager');
define('ROLE_HR', 'hr');
define('ROLE_EXECUTIVE', 'executive');
define('ROLE_ADMIN', 'admin');

// Application Status Codes
define('STATUS_PENDING_MANAGER', 'pending_manager');
define('STATUS_PENDING_HR', 'pending_hr');
define('STATUS_PENDING_EXECUTIVE', 'pending_executive');
define('STATUS_APPROVED', 'approved');
define('STATUS_REJECTED', 'rejected');
define('STATUS_CANCELLED', 'cancelled');
