-- MySQL dump 10.19  Distrib 10.3.39-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: lms_db
-- ------------------------------------------------------
-- Server version	10.3.39-MariaDB-0ubuntu0.20.04.2

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `lms_db`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `lms_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `lms_db`;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `line_manager_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_departments_manager` (`line_manager_id`),
  CONSTRAINT `fk_departments_manager` FOREIGN KEY (`line_manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'NOC',23),(2,'Human Resources',46),(3,'Finance',8),(4,'Executive Management',6),(6,'Web Development',34),(7,'CompuShop',26),(8,'Sales',20),(9,'Customer Service',NULL),(10,'Revenue Assure',NULL);
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `holidays`
--

DROP TABLE IF EXISTS `holidays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `holiday_date` date NOT NULL,
  `is_recurring` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `holiday_date` (`holiday_date`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `holidays`
--

LOCK TABLES `holidays` WRITE;
/*!40000 ALTER TABLE `holidays` DISABLE KEYS */;
INSERT INTO `holidays` VALUES (1,'New Year\'s Day','2026-01-01',1),(2,'Good Friday','2026-04-03',0),(3,'Easter Monday','2026-04-06',0),(4,'Workers\' Day','2026-05-01',1),(5,'Freedom Day','2026-05-25',1),(6,'Christmas Day','2026-12-25',1),(7,'Boxing Day','2026-12-26',1);
/*!40000 ALTER TABLE `holidays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_applications`
--

DROP TABLE IF EXISTS `leave_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `application_no` varchar(30) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` decimal(5,2) NOT NULL,
  `reason` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` enum('pending_manager','pending_hr','pending_executive','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_manager',
  `current_approver_role` varchar(50) NOT NULL DEFAULT 'manager',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_no` (`application_no`),
  KEY `fk_applications_user` (`user_id`),
  KEY `fk_applications_type` (`leave_type_id`),
  CONSTRAINT `fk_applications_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_applications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_applications`
--

LOCK TABLES `leave_applications` WRITE;
/*!40000 ALTER TABLE `leave_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_approval_logs`
--

DROP TABLE IF EXISTS `leave_approval_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_approval_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `leave_application_id` int(11) NOT NULL,
  `approver_id` int(11) NOT NULL,
  `approver_role` varchar(50) NOT NULL,
  `stage` varchar(50) NOT NULL,
  `action` enum('approved','rejected') NOT NULL,
  `comments` text DEFAULT NULL,
  `action_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_logs_application` (`leave_application_id`),
  KEY `fk_logs_approver` (`approver_id`),
  CONSTRAINT `fk_logs_application` FOREIGN KEY (`leave_application_id`) REFERENCES `leave_applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_logs_approver` FOREIGN KEY (`approver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_approval_logs`
--

LOCK TABLES `leave_approval_logs` WRITE;
/*!40000 ALTER TABLE `leave_approval_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_approval_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_entitlements`
--

DROP TABLE IF EXISTS `leave_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_entitlements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `leave_type_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  `pending_days` decimal(5,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_type_year` (`user_id`,`leave_type_id`,`year`),
  KEY `fk_entitlements_type` (`leave_type_id`),
  CONSTRAINT `fk_entitlements_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_entitlements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=409 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_entitlements`
--

LOCK TABLES `leave_entitlements` WRITE;
/*!40000 ALTER TABLE `leave_entitlements` DISABLE KEYS */;
INSERT INTO `leave_entitlements` VALUES (6,6,1,2026,20.00,0.00,0.00),(7,6,2,2026,10.00,0.00,0.00),(9,6,4,2026,90.00,0.00,0.00),(10,6,5,2026,30.00,0.00,0.00),(11,7,1,2026,20.00,0.00,0.00),(12,7,2,2026,10.00,0.00,0.00),(14,7,4,2026,90.00,0.00,0.00),(15,7,5,2026,30.00,0.00,0.00),(16,8,1,2026,20.00,0.00,0.00),(17,8,2,2026,10.00,0.00,0.00),(19,8,4,2026,90.00,0.00,0.00),(20,8,5,2026,30.00,0.00,0.00),(21,9,1,2026,20.00,0.00,0.00),(22,9,2,2026,10.00,0.00,0.00),(24,9,4,2026,90.00,0.00,0.00),(25,9,5,2026,30.00,0.00,0.00),(31,11,1,2026,20.00,0.00,0.00),(32,11,2,2026,10.00,0.00,0.00),(34,11,4,2026,90.00,0.00,0.00),(35,11,5,2026,30.00,0.00,0.00),(36,12,1,2026,20.00,0.00,0.00),(37,12,2,2026,10.00,0.00,0.00),(39,12,4,2026,90.00,0.00,0.00),(40,12,5,2026,30.00,0.00,0.00),(41,13,1,2026,20.00,0.00,0.00),(42,13,2,2026,10.00,0.00,0.00),(44,13,4,2026,90.00,0.00,0.00),(45,13,5,2026,30.00,0.00,0.00),(46,14,1,2026,20.00,0.00,0.00),(47,14,2,2026,10.00,0.00,0.00),(49,14,4,2026,90.00,0.00,0.00),(50,14,5,2026,30.00,0.00,0.00),(51,15,1,2026,20.00,0.00,0.00),(52,15,2,2026,10.00,0.00,0.00),(54,15,4,2026,90.00,0.00,0.00),(55,15,5,2026,30.00,0.00,0.00),(56,16,1,2026,20.00,0.00,0.00),(57,16,2,2026,10.00,0.00,0.00),(59,16,4,2026,90.00,0.00,0.00),(60,16,5,2026,30.00,0.00,0.00),(61,17,1,2026,20.00,0.00,0.00),(62,17,2,2026,10.00,0.00,0.00),(64,17,4,2026,90.00,0.00,0.00),(65,17,5,2026,30.00,0.00,0.00),(66,18,1,2026,20.00,0.00,0.00),(67,18,2,2026,10.00,0.00,0.00),(69,18,4,2026,90.00,0.00,0.00),(70,18,5,2026,30.00,0.00,0.00),(71,19,1,2026,20.00,0.00,0.00),(72,19,2,2026,10.00,0.00,0.00),(74,19,4,2026,90.00,0.00,0.00),(75,19,5,2026,30.00,0.00,0.00),(76,20,1,2026,20.00,0.00,0.00),(77,20,2,2026,10.00,0.00,0.00),(79,20,4,2026,90.00,0.00,0.00),(80,20,5,2026,30.00,0.00,0.00),(86,22,1,2026,20.00,0.00,0.00),(87,22,2,2026,10.00,0.00,0.00),(89,22,4,2026,90.00,0.00,0.00),(90,22,5,2026,30.00,0.00,0.00),(91,23,1,2026,20.00,0.00,0.00),(92,23,2,2026,10.00,0.00,0.00),(94,23,4,2026,90.00,0.00,0.00),(95,23,5,2026,30.00,0.00,0.00),(96,24,1,2026,20.00,0.00,0.00),(97,24,2,2026,10.00,0.00,0.00),(99,24,4,2026,90.00,0.00,0.00),(100,24,5,2026,30.00,0.00,0.00),(101,25,1,2026,20.00,0.00,0.00),(102,25,2,2026,10.00,0.00,0.00),(104,25,4,2026,90.00,0.00,0.00),(105,25,5,2026,30.00,0.00,0.00),(106,26,1,2026,20.00,0.00,0.00),(107,26,2,2026,10.00,0.00,0.00),(109,26,4,2026,90.00,0.00,0.00),(110,26,5,2026,30.00,0.00,0.00),(111,27,1,2026,20.00,0.00,0.00),(112,27,2,2026,10.00,0.00,0.00),(114,27,4,2026,90.00,0.00,0.00),(115,27,5,2026,30.00,0.00,0.00),(116,28,1,2026,20.00,0.00,0.00),(117,28,2,2026,10.00,0.00,0.00),(119,28,4,2026,90.00,0.00,0.00),(120,28,5,2026,30.00,0.00,0.00),(121,29,1,2026,20.00,0.00,0.00),(122,29,2,2026,10.00,0.00,0.00),(124,29,4,2026,90.00,0.00,0.00),(125,29,5,2026,30.00,0.00,0.00),(126,30,1,2026,20.00,0.00,0.00),(127,30,2,2026,10.00,0.00,0.00),(129,30,4,2026,90.00,0.00,0.00),(130,30,5,2026,30.00,0.00,0.00),(131,31,1,2026,20.00,0.00,0.00),(132,31,2,2026,10.00,0.00,0.00),(134,31,4,2026,90.00,0.00,0.00),(135,31,5,2026,30.00,0.00,0.00),(146,34,1,2026,20.00,0.00,0.00),(147,34,2,2026,10.00,0.00,0.00),(149,34,4,2026,90.00,0.00,0.00),(150,34,5,2026,30.00,0.00,0.00),(151,35,1,2026,20.00,0.00,0.00),(152,35,2,2026,10.00,0.00,0.00),(154,35,4,2026,90.00,0.00,0.00),(155,35,5,2026,30.00,0.00,0.00),(156,36,1,2026,20.00,0.00,0.00),(157,36,2,2026,10.00,0.00,0.00),(159,36,4,2026,90.00,0.00,0.00),(160,36,5,2026,30.00,0.00,0.00),(161,37,1,2026,20.00,0.00,0.00),(162,37,2,2026,10.00,0.00,0.00),(164,37,4,2026,90.00,0.00,0.00),(165,37,5,2026,30.00,0.00,0.00),(361,39,1,2026,20.00,0.00,0.00),(362,39,2,2026,10.00,0.00,0.00),(364,39,4,2026,90.00,0.00,0.00),(365,39,5,2026,30.00,0.00,0.00),(405,46,1,2026,21.00,0.00,0.00),(406,46,2,2026,10.00,0.00,0.00),(407,46,4,2026,90.00,0.00,0.00),(408,46,5,2026,30.00,0.00,0.00);
/*!40000 ALTER TABLE `leave_entitlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_types`
--

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `code` varchar(10) NOT NULL,
  `max_days_per_year` int(11) NOT NULL DEFAULT 0,
  `requires_attachment` tinyint(1) NOT NULL DEFAULT 0,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `min_days_per_request` decimal(4,1) NOT NULL DEFAULT 0.5,
  `max_days_per_request` decimal(5,1) DEFAULT NULL,
  `allow_half_day` tinyint(1) NOT NULL DEFAULT 1,
  `min_notice_days` int(11) NOT NULL DEFAULT 0,
  `attachment_threshold_days` decimal(4,1) NOT NULL DEFAULT 0.0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_types`
--

LOCK TABLES `leave_types` WRITE;
/*!40000 ALTER TABLE `leave_types` DISABLE KEYS */;
INSERT INTO `leave_types` VALUES (1,'Annual Leave','ANN',21,0,1,0.5,NULL,1,7,0.0,1),(2,'Sick Leave','SCK',10,1,1,0.5,NULL,1,0,2.0,1),(4,'Maternity / Paternity','MAT',90,1,1,1.0,NULL,0,0,0.0,1),(5,'Unpaid Leave','UNP',30,0,0,1.0,NULL,0,14,0.0,1);
/*!40000 ALTER TABLE `leave_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'employee','Standard Employee - Can apply for leave and view balance'),(2,'manager','Line Manager - Approves Stage 1 team leave requests'),(3,'hr','HR Manager - Approves Stage 2 leave requests and manages policies'),(4,'executive','Executive / Boss - Final Stage 3 approval authority'),(5,'admin','System Administrator - Manages users, roles, system settings');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `emp_id` varchar(20) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_changed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `emp_id` (`emp_id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_department` (`department_id`),
  KEY `fk_users_manager` (`manager_id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_manager` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'EMP-1001','Admin','User','admin@lms.com','$2y$10$DpUB8FTRFAemkrgK47LZ8.g2WD1.AZxo3kIaZot8Zb7x/lfJLo4/K',5,1,NULL,'active',0,NULL,'2026-08-11 12:01:42'),(6,'EMP-1006','Natasha','Williamson','natasha@realnet.co.sz','$2y$10$hQUW0Gfkn3II5ez0cM5G7.TlRCfAmxMqlfQjA2b/B3pM5/YcB8owC',4,4,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(7,'EMP-1007','Ali','Resting','ali@realnet.co.sz','$2y$10$SoiGXAEEQh8m4Tgvk.pn4O1CQNtzU1DLcNwvAU1p2KC/dsLt1E02W',2,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(8,'EMP-1008','Celiwe','Ngwenya','celiwen@realnet.co.sz','$2y$10$6KvBoPfVJVC9CaQPIupO2ezno6fvjzH.Qm8vV2yWIbVil27n3cVv2',2,3,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(9,'EMP-1009','Vamile','Sikhondze','vamile@realnet.co.sz','$2y$10$ZpNZj8mv/pKatnLQ/jWCAOnhjQtoElw508sd5DarlnHl3hELnhqOK',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(11,'EMP-1011','Ntombenhle','Dlamini','ntombenhle@realnet.co.sz','$2y$10$weGgF0McDbsCQcDjzgWzH.vJ9rVTABAOq.AO76AnKdwDVH2U2adA2',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(12,'EMP-1012','Sky','Manyatsi','sky@realnet.co.sz','$2y$10$tA6GkFU/4ktvmafHJLeP..5ZNt0DGtf0gVlvT2ouP2cWYdaGL2fke',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(13,'EMP-1013','Max','Mavuso','max@realnet.co.sz','$2y$10$g5kkzdV/Ds4IkHGa6PHZ9OlIAt3re0PAUeMBjE6wy8.52VUfXGRcy',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(14,'EMP-1014','Coach','Dlamini','coach@realnet.co.sz','$2y$10$9iCF7kYYaKeyYT5H0RDHN.p022.kRgwHScefT1S5tjT3sJ.7d3/0i',1,1,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(15,'EMP-1015','Sandile','Warren Dlamini','sandilew@realnet.co.sz','$2y$10$4nzeObcdWx8YUccmmJk4gOaB1t23a3zX4I19Hm4.41hgYY7GfjPii',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(16,'EMP-1016','Mduduzi','Gumedze','mduduzig@realnet.co.sz','$2y$10$9yRRoXKCFinDxGptEfacROJq4qwF.NSyWZdqx9P1qLbBFcQ7nQnNu',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(17,'EMP-1017','Lindani','Masilela','lindani@realnet.co.sz','$2y$10$WDmE20209TrIDpYmZWK8W.ms3EzgUc7YcRPwM22s746QTPuV04ZIi',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:01'),(18,'EMP-1018','Sisimo','Seyama','sisimo@realnet.co.sz','$2y$10$RL2AcQYIh18I0Z83SKjRguW.tQNLV9IfvyfIubGBhikPgd6NaVM.i',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(19,'EMP-1019','Sihle','Masilela','sihle@realnet.co.sz','$2y$10$OBZcoAqgqMJh2KHdLaQMO.tqqUwMLNp4gui.q5EQVFIB18zJ.mku2',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(20,'EMP-1020','Sandile','Masuku','sandilem@realnet.co.sz','$2y$10$qxC9.CoaeHA4zw2406BJ8eXH1vtAWXGwm9g8Yd6ceB3kTooW3mDzC',2,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(22,'EMP-1022','Monde','Radebe','monde@realnet.co.sz','$2y$10$kAWzxsY8FyMHi1yJes2L6uni0fbZGSgZeADrmE3WC//anzDASwvka',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(23,'EMP-1023','Muzi','Phiri','muzip@realnet.co.sz','$2y$10$PLYiYY98D.Xg0COPUj/Io.Ug1oV1.iX/OzxGP0.mKhb2nKzjxF.uW',2,1,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(24,'EMP-1024','Reno','Willamson','reno@realnet.co.sz','$2y$10$KBxQ16gXJ3CvbFKcUVUBS.14lvNmzT7u091N9omo3f8Jk9Hfgv41S',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(25,'EMP-1025','Thanda','Mndzebele','thanda@realnet.co.sz','$2y$10$CRBzeEvYwr807Ug61Z26R.rMkpQ8yl4Cv3i6mkjzbjs229dOJzg0G',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(26,'EMP-1026','Nick','Mdluli','nickm@realnet.co.sz','$2y$10$QEfPdU93xhMSYwLJOVsEqe2pm/k8EOuFXzhGID5C7H3cpgXuHWHhe',2,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(27,'EMP-1027','Cydalia','Smith','cydalia@realnet.co.sz','$2y$10$wKk3MKTOoYSE3ASpI4sIhOmbA4kPwYbx4bh0Wf3Lj63ZN2QKET0DW',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(28,'EMP-1028','Tania','Middleton','tania@realnet.co.sz','$2y$10$kVSAeVCgEgvyTkNlO.QkbeTxl4ZKwDhi5bJWb.QGLvRJ89AxiPXOS',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(29,'EMP-1029','Janine','Middleton','janinem@realnet.co.sz','$2y$10$Xq5v7yOKSrCZ4qAL9Qw/iuHN20MrsEEfKWeRPYSUnIMo/8C2Wx0gW',2,10,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(30,'EMP-1030','Sandile','Dlamini','sandiled@realnet.co.sz','$2y$10$CZkNNRfiR9/2mddwOBrCbORb9TrwtXZzbVhxGWioBwtg2TMy49xfu',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(31,'EMP-1031','Anele','Dlamini','anele@realnet.co.sz','$2y$10$7fn3AvauoauZ7A3QBpmiYurm4AECNCzBRNljZZXdrzVhN8h688SD6',1,8,NULL,'active',1,NULL,'2026-08-11 13:13:02'),(34,'EMP-1034','Mbongeni','Sibandze','mbongeni@realnet.co.sz','$2y$10$1Z.M4efZ8tnFKM2n2.ZDVuCObvANq7ywzUGshiyXLohpjBp6LTNHi',2,6,NULL,'active',1,NULL,'2026-08-11 13:13:03'),(35,'EMP-1035','Ndumiso','Mavimbela','ndumiso@realnet.co.sz','$2y$10$CmUv98twYQZL/1xOknjRZ.TTEUDNsVMEKwggXeS05zbb5hLC8hlwK',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:03'),(36,'EMP-1036','Banele','Tshabalala','banelet@realnet.co.sz','$2y$10$Kapj.vf1ZWAjfIDFs3OT1.9x7HTlzbyViRm7f/sxNOYpEU7g0wQqS',1,7,NULL,'active',1,NULL,'2026-08-11 13:13:03'),(37,'EMP-1037','Goodluck','Siyaya','goodluck@realnet.co.sz','$2y$10$dCpFSCT4yitHENNDzug0neTTQ5.l5pDDDA14vzH63WWk2JxZxYSsK',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:13:03'),(39,'EMP-1038','Sphila','Simelane','sphila@realnet.co.sz','$2y$10$alaNq3JZaUJwDQY0E0tiuu2/zD6Zn1vVR1rqVCuFfrT2CYyfOntsK',1,NULL,NULL,'active',1,NULL,'2026-08-11 13:55:43'),(46,'EMP-1039','Welile','Dlamini','welilefdlamini@realnet.co.sz','$2y$10$qVojDmsPu5jpPK.aLttJ/OoVKdyA8f4QwJti.v4WkEX8c.kGBL49C',3,2,NULL,'active',0,NULL,'2026-08-12 11:49:00');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'lms_db'
--

--
-- Dumping routines for database 'lms_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-12 11:57:26
