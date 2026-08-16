-- Leave Management System (LMS) Database Schema & Seed Data
CREATE DATABASE IF NOT EXISTS `lms_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lms_db`;

-- 1. Roles Table
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `description` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Default Roles
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'employee', 'Standard Employee - Can apply for leave and view balance'),
(2, 'manager', 'Line Manager - Approves Stage 1 team leave requests'),
(3, 'hr', 'HR Manager - Approves Stage 2 leave requests and manages policies'),
(4, 'executive', 'Executive / Boss - Final Stage 3 approval authority'),
(5, 'admin', 'System Administrator - Manages users, roles, system settings')
ON DUPLICATE KEY UPDATE `name`=`name`;

-- 2. Departments Table
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `line_manager_id` INT NULL,
    -- How many members may be away on the same working day before the team is
    -- understaffed. NULL = no limit configured, so no coverage warning is shown.
    `max_concurrent_absences` INT NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Default Departments
INSERT INTO `departments` (`id`, `name`, `max_concurrent_absences`) VALUES
(1, 'Information Technology', 2),
(2, 'Human Resources', 1),
(3, 'Finance & Operations', 2),
(4, 'Executive Management', NULL)
ON DUPLICATE KEY UPDATE `name`=`name`;

-- 3. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `emp_id` VARCHAR(20) NOT NULL UNIQUE,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role_id` INT NOT NULL,
    `department_id` INT NULL,
    `manager_id` INT NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
    `password_changed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`),
    CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `departments` 
ADD CONSTRAINT `fk_departments_manager` FOREIGN KEY (`line_manager_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

-- Seed Default Accounts (Default password for all seed users: "password123")
-- Hash generated via password_hash('password123', PASSWORD_BCRYPT)
-- $2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K
INSERT INTO `users` (`id`, `emp_id`, `first_name`, `last_name`, `email`, `password_hash`, `role_id`, `department_id`, `manager_id`, `status`) VALUES
(1, 'EMP-1001', 'Admin', 'User', 'admin@lms.com', '$2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K', 5, 1, NULL, 'active'),
(2, 'EMP-1002', 'Boss', 'Executive', 'boss@lms.com', '$2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K', 4, 4, NULL, 'active'),
(3, 'EMP-1003', 'Sarah', 'HR Manager', 'hr@lms.com', '$2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K', 3, 2, 2, 'active'),
(4, 'EMP-1004', 'David', 'Line Manager', 'manager@lms.com', '$2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K', 2, 1, 3, 'active'),
(5, 'EMP-1005', 'John', 'Employee', 'employee@lms.com', '$2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K', 1, 1, 4, 'active')
ON DUPLICATE KEY UPDATE `email`=`email`;

UPDATE `departments` SET `line_manager_id` = 4 WHERE `id` = 1;
UPDATE `departments` SET `line_manager_id` = 3 WHERE `id` = 2;

-- 4. Leave Types Table
CREATE TABLE IF NOT EXISTS `leave_types` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL,
    `code` VARCHAR(10) NOT NULL UNIQUE,
    `max_days_per_year` INT NOT NULL DEFAULT 0,
    `requires_attachment` TINYINT(1) NOT NULL DEFAULT 0,
    `is_paid` TINYINT(1) NOT NULL DEFAULT 1,
    -- Duration rules applied to a single request (a request is one contiguous
    -- date range, so max_days_per_request also caps consecutive days per request)
    `min_days_per_request` DECIMAL(4,1) NOT NULL DEFAULT 0.5,
    `max_days_per_request` DECIMAL(5,1) NULL DEFAULT NULL,
    `allow_half_day` TINYINT(1) NOT NULL DEFAULT 1,
    -- Whole days of notice required between today and the start date
    `min_notice_days` INT NOT NULL DEFAULT 0,
    -- When requires_attachment = 1, a document is only demanded once the
    -- request exceeds this many working days
    `attachment_threshold_days` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    -- Retired types stay for historical reporting but disappear from the form
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `leave_types`
    (`id`, `name`, `code`, `max_days_per_year`, `requires_attachment`, `is_paid`,
     `min_days_per_request`, `max_days_per_request`, `allow_half_day`,
     `min_notice_days`, `attachment_threshold_days`, `is_active`) VALUES
(1, 'Annual Leave',          'ANN', 20, 0, 1, 0.5, NULL, 1, 7, 0.0, 1),
(2, 'Sick Leave',            'SCK', 10, 1, 1, 0.5, NULL, 1, 0, 2.0, 1),
(3, 'Casual Leave',          'CSL',  5, 0, 1, 0.5,  3.0, 1, 1, 0.0, 1),
(4, 'Maternity / Paternity', 'MAT', 90, 1, 1, 1.0, NULL, 0, 0, 0.0, 1),
(5, 'Unpaid Leave',          'UNP', 30, 0, 0, 1.0, NULL, 0, 14, 0.0, 1)
ON DUPLICATE KEY UPDATE `code`=`code`;

-- 5. Leave Entitlements Table (Year 2026 allocations)
CREATE TABLE IF NOT EXISTS `leave_entitlements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `leave_type_id` INT NOT NULL,
    `year` INT NOT NULL,
    `total_days` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `used_days` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `pending_days` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT `fk_entitlements_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_entitlements_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_type_year` (`user_id`, `leave_type_id`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed entitlements for John Employee & David Manager
INSERT INTO `leave_entitlements` (`user_id`, `leave_type_id`, `year`, `total_days`, `used_days`, `pending_days`) VALUES
(5, 1, 2026, 20.00, 0.00, 0.00),
(5, 2, 2026, 10.00, 0.00, 0.00),
(5, 3, 2026, 5.00, 0.00, 0.00),
(4, 1, 2026, 20.00, 0.00, 0.00),
(4, 2, 2026, 10.00, 0.00, 0.00)
ON DUPLICATE KEY UPDATE `year`=`year`;

-- 6. Public Holidays Table
CREATE TABLE IF NOT EXISTS `holidays` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(100) NOT NULL,
    `holiday_date` DATE NOT NULL UNIQUE,
    `is_recurring` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `holidays` (`id`, `title`, `holiday_date`, `is_recurring`) VALUES
(1, 'New Year\'s Day', '2026-01-01', 1),
(2, 'Good Friday', '2026-04-03', 0),
(3, 'Easter Monday', '2026-04-06', 0),
(4, 'Workers\' Day', '2026-05-01', 1),
(5, 'Freedom Day', '2026-05-25', 1),
(6, 'Christmas Day', '2026-12-25', 1),
(7, 'Boxing Day', '2026-12-26', 1)
ON DUPLICATE KEY UPDATE `holiday_date`=`holiday_date`;

-- 7. Leave Applications Table
CREATE TABLE IF NOT EXISTS `leave_applications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `application_no` VARCHAR(30) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `leave_type_id` INT NOT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `total_days` DECIMAL(5,2) NOT NULL,
    `reason` TEXT NOT NULL,
    `attachment_path` VARCHAR(255) NULL,
    `status` ENUM('pending_manager', 'pending_hr', 'pending_executive', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending_manager',
    `current_approver_role` VARCHAR(50) NOT NULL DEFAULT 'manager',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_applications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_applications_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Leave Approval Logs Table
CREATE TABLE IF NOT EXISTS `leave_approval_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `leave_application_id` INT NOT NULL,
    `approver_id` INT NOT NULL,
    `approver_role` VARCHAR(50) NOT NULL,
    `stage` VARCHAR(50) NOT NULL,
    `action` ENUM('approved', 'rejected') NOT NULL,
    `comments` TEXT NULL,
    `action_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_logs_application` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_logs_approver` FOREIGN KEY (`approver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. In-App Notifications Table
-- One row per person per event. read_at stays NULL until the recipient opens it,
-- which is what the unread badge in the navigation counts.
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `body` TEXT NULL,
    `link` VARCHAR(255) NULL,
    `leave_application_id` INT NULL,
    `read_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notifications_application` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications`(`id`) ON DELETE CASCADE,
    KEY `idx_notifications_unread` (`user_id`, `read_at`),
    KEY `idx_notifications_recent` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. Failed Sign-In Attempts
-- Feeds the rate limit on the login form: too many failures for one email from
-- one address inside the window and the door closes for a while. Successful
-- sign-ins delete that caller's rows, and every write sweeps away expired ones,
-- so this table stays small and is never a record of who signed in.
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(150) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_login_attempts_lookup` (`email`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
