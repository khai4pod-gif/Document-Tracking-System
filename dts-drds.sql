-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               8.4.3 - MySQL Community Server - GPL
-- Server OS:                    Win64
-- HeidiSQL Version:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for dts_drds
DROP DATABASE IF EXISTS `dts_drds`;
CREATE DATABASE IF NOT EXISTS `dts_drds` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `dts_drds`;

-- Dumping structure for table dts_drds.departments
DROP TABLE IF EXISTS `departments`;
CREATE TABLE IF NOT EXISTS `departments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.departments: ~4 rows (approximately)
INSERT INTO `departments` (`id`, `name`, `code`, `description`, `is_active`, `created_at`) VALUES
	(1, 'Office of the Administrator', 'ADMIN', 'Executive / central administration', 1, '2026-07-24 12:20:22'),
	(2, 'Logistics & Relief Operations', 'LOGISTICS', 'Handles relief goods and distribution', 1, '2026-07-24 12:20:22'),
	(3, 'Records Management Office', 'RECORDS', 'Central document receiving/releasing unit', 1, '2026-07-24 12:20:22'),
	(4, 'Office of the Secretary', 'MAIN', NULL, 1, '2026-07-24 15:39:42');

-- Dumping structure for table dts_drds.distributions
DROP TABLE IF EXISTS `distributions`;
CREATE TABLE IF NOT EXISTS `distributions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `reference_no` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `evacuation_center_id` int unsigned NOT NULL,
  `document_id` int unsigned DEFAULT NULL COMMENT 'Linked DTS manifest document, if routed for approval',
  `distributed_by` int unsigned NOT NULL,
  `distribution_date` date NOT NULL,
  `total_beneficiaries` int unsigned NOT NULL DEFAULT '0',
  `status` enum('Draft','Pending Approval','Approved','Completed','Cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `fk_dist_center` (`evacuation_center_id`),
  KEY `fk_dist_document` (`document_id`),
  KEY `fk_dist_user` (`distributed_by`),
  KEY `idx_dist_date` (`distribution_date`),
  KEY `idx_dist_status` (`status`),
  CONSTRAINT `fk_dist_center` FOREIGN KEY (`evacuation_center_id`) REFERENCES `evacuation_centers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_dist_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dist_user` FOREIGN KEY (`distributed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.distributions: ~1 rows (approximately)
INSERT INTO `distributions` (`id`, `reference_no`, `evacuation_center_id`, `document_id`, `distributed_by`, `distribution_date`, `total_beneficiaries`, `status`, `remarks`, `created_at`, `updated_at`) VALUES
	(1, 'REL-2026-000001', 3, 2, 5, '2026-07-24', 100, 'Pending Approval', 'sample', '2026-07-24 16:31:25', '2026-07-24 16:31:25');

-- Dumping structure for table dts_drds.distribution_items
DROP TABLE IF EXISTS `distribution_items`;
CREATE TABLE IF NOT EXISTS `distribution_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `distribution_id` int unsigned NOT NULL,
  `inventory_id` int unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `fk_ditems_distribution` (`distribution_id`),
  KEY `fk_ditems_inventory` (`inventory_id`),
  CONSTRAINT `fk_ditems_distribution` FOREIGN KEY (`distribution_id`) REFERENCES `distributions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ditems_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `relief_inventory` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.distribution_items: ~1 rows (approximately)
INSERT INTO `distribution_items` (`id`, `distribution_id`, `inventory_id`, `quantity`) VALUES
	(1, 1, 2, 100);

-- Dumping structure for table dts_drds.documents
DROP TABLE IF EXISTS `documents`;
CREATE TABLE IF NOT EXISTS `documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tracking_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `doc_type` enum('Memo','Letter','Report','Purchase Request','Relief Manifest','Special Order','ORs/DV','PPMP','Purchase Order','Leave Application','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `priority` enum('Low','Normal','High','Urgent') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Normal',
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Draft','Pending Routing','In Transit','Received','Completed','Overdue') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `origin_department_id` int unsigned DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `current_holder_id` int unsigned DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `approval_status` enum('Not Required','Pending','Approved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Not Required',
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `conclusion_remarks` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tracking_number` (`tracking_number`),
  KEY `fk_documents_origin_dept` (`origin_department_id`),
  KEY `fk_documents_created_by` (`created_by`),
  KEY `fk_documents_current_holder` (`current_holder_id`),
  KEY `fk_documents_approved_by` (`approved_by`),
  KEY `idx_documents_status` (`status`),
  KEY `idx_documents_archived` (`is_archived`),
  KEY `idx_documents_approval_status` (`approval_status`),
  FULLTEXT KEY `ft_documents_title_desc` (`title`,`description`),
  CONSTRAINT `fk_documents_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_current_holder` FOREIGN KEY (`current_holder_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_origin_dept` FOREIGN KEY (`origin_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.documents: ~3 rows (approximately)
INSERT INTO `documents` (`id`, `tracking_number`, `title`, `doc_type`, `priority`, `description`, `status`, `origin_department_id`, `created_by`, `current_holder_id`, `due_date`, `is_archived`, `created_at`, `updated_at`) VALUES
	(1, 'DOC-2026-000001', 'sample', 'Memo', 'Low', 'sampple', 'In Transit', 4, 5, 4, '2026-07-24', 0, '2026-07-24 16:30:24', '2026-07-24 16:43:21'),
	(2, 'DOC-2026-000002', 'Relief Distribution Manifest — REL-2026-000001 (City Sports Complex)', 'Relief Manifest', 'High', 'Relief distribution manifest for City Sports Complex dated 2026-07-24. Beneficiaries: 100.', 'Draft', NULL, 5, 5, NULL, 0, '2026-07-24 16:31:25', '2026-07-24 16:31:25'),
	(3, 'DOC-2026-000003', 'sample 2', 'Other', 'High', 'sample memo from the secretary to all OBSUs', 'Draft', 4, 5, 5, '2026-07-25', 0, '2026-07-24 16:49:06', '2026-07-24 16:49:06');

-- Dumping structure for table dts_drds.document_attachments
DROP TABLE IF EXISTS `document_attachments`;
CREATE TABLE IF NOT EXISTS `document_attachments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned NOT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_attach_document` (`document_id`),
  KEY `fk_attach_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_attach_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attach_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.document_attachments: ~0 rows (approximately)

-- Dumping structure for table dts_drds.document_links
DROP TABLE IF EXISTS `document_links`;
CREATE TABLE IF NOT EXISTS `document_links` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `url` varchar(2048) COLLATE utf8mb4_unicode_ci NOT NULL,
  `added_by` int unsigned NOT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_links_document` (`document_id`),
  KEY `fk_link_added_by` (`added_by`),
  CONSTRAINT `fk_link_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_link_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.document_links: ~0 rows (approximately)

-- Dumping structure for table dts_drds.document_logs
DROP TABLE IF EXISTS `document_logs`;
CREATE TABLE IF NOT EXISTS `document_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `action` enum('Created','Updated','Routed','Received','Completed','Archived','Restored','Attachment Added','Attachment Removed','Approved','Rejected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_logs_user` (`user_id`),
  KEY `idx_logs_document_time` (`document_id`,`created_at`),
  CONSTRAINT `fk_logs_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.document_logs: ~4 rows (approximately)
INSERT INTO `document_logs` (`id`, `document_id`, `user_id`, `action`, `details`, `created_at`) VALUES
	(1, 1, 5, 'Created', 'Document "sample" created.', '2026-07-24 16:30:24'),
	(2, 2, 5, 'Created', 'Document "Relief Distribution Manifest — REL-2026-000001 (City Sports Complex)" created.', '2026-07-24 16:31:25'),
	(3, 1, 5, 'Routed', 'Routed to user #4 — Action required: for review', '2026-07-24 16:43:21'),
	(4, 3, 5, 'Created', 'Document "sample 2" created.', '2026-07-24 16:49:06');

-- Dumping structure for table dts_drds.document_routes
DROP TABLE IF EXISTS `document_routes`;
CREATE TABLE IF NOT EXISTS `document_routes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int unsigned NOT NULL,
  `from_user_id` int unsigned NOT NULL,
  `to_user_id` int unsigned NOT NULL,
  `from_department_id` int unsigned DEFAULT NULL,
  `to_department_id` int unsigned DEFAULT NULL,
  `action_required` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `status` enum('Pending','Received','Completed','Returned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `routed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_route_document` (`document_id`),
  KEY `fk_route_from_user` (`from_user_id`),
  KEY `fk_route_to_user` (`to_user_id`),
  KEY `fk_route_from_dept` (`from_department_id`),
  KEY `fk_route_to_dept` (`to_department_id`),
  KEY `idx_route_status` (`status`),
  CONSTRAINT `fk_route_document` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_route_from_dept` FOREIGN KEY (`from_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_route_from_user` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_route_to_dept` FOREIGN KEY (`to_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_route_to_user` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.document_routes: ~1 rows (approximately)
INSERT INTO `document_routes` (`id`, `document_id`, `from_user_id`, `to_user_id`, `from_department_id`, `to_department_id`, `action_required`, `remarks`, `status`, `routed_at`, `received_at`) VALUES
	(1, 1, 5, 4, 4, 1, 'for review', 'sample', 'Pending', '2026-07-24 16:43:21', NULL);

-- Dumping structure for table dts_drds.evacuation_centers
DROP TABLE IF EXISTS `evacuation_centers`;
CREATE TABLE IF NOT EXISTS `evacuation_centers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_area` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `capacity` int unsigned DEFAULT NULL,
  `contact_person` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.evacuation_centers: ~3 rows (approximately)
INSERT INTO `evacuation_centers` (`id`, `name`, `target_area`, `address`, `capacity`, `contact_person`, `contact_number`, `is_active`, `created_at`) VALUES
	(1, 'San Isidro Elementary School', 'Barangay San Isidro', 'San Isidro, City Proper', 500, 'Brgy. Capt. R. Ramos', '0917-000-0001', 1, '2026-07-24 12:20:22'),
	(2, 'Sto. Niño Covered Court', 'Barangay Sto. Niño', 'Sto. Niño District', 300, 'Brgy. Capt. L. Cruz', '0917-000-0002', 1, '2026-07-24 12:20:22'),
	(3, 'City Sports Complex', 'City-wide', 'National Highway', 1200, 'MDRRMO Officer', '0917-000-0003', 1, '2026-07-24 12:20:22');

-- Dumping structure for table dts_drds.login_attempts
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_lookup` (`username`,`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping structure for table dts_drds.login_otps
DROP TABLE IF EXISTS `login_otps`;
CREATE TABLE IF NOT EXISTS `login_otps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `expires_at` datetime NOT NULL,
  `consumed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_otps_active` (`user_id`,`consumed_at`,`expires_at`),
  CONSTRAINT `fk_login_otps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.login_attempts: ~24 rows (approximately)
INSERT INTO `login_attempts` (`id`, `username`, `ip_address`, `success`, `attempted_at`) VALUES
	(1, 'admin', '::1', 0, '2026-07-24 12:24:33'),
	(2, 'root', '::1', 0, '2026-07-24 12:48:56'),
	(3, 'root', '::1', 0, '2026-07-24 12:48:58'),
	(4, 'admin', '::1', 0, '2026-07-24 12:49:04'),
	(5, 'admin', '::1', 0, '2026-07-24 12:53:10'),
	(6, 'admin', '::1', 0, '2026-07-24 12:54:45'),
	(7, 'admin', '::1', 0, '2026-07-24 12:55:04'),
	(8, 'admin', '::1', 0, '2026-07-24 12:56:38'),
	(9, 'admin', '::1', 0, '2026-07-24 12:59:05'),
	(10, 'admin', '127.0.0.1', 0, '2026-07-24 13:42:07'),
	(11, 'logistics1', '127.0.0.1', 0, '2026-07-24 13:43:36'),
	(12, 'admin', '::1', 1, '2026-07-24 13:54:05'),
	(13, 'drms_user', '::1', 1, '2026-07-24 14:12:30'),
	(14, 'admin', '::1', 1, '2026-07-24 14:13:11'),
	(15, 'admin', '::1', 1, '2026-07-24 14:24:31'),
	(16, 'logistics1', '::1', 1, '2026-07-24 14:27:06'),
	(17, 'admin', '::1', 1, '2026-07-24 15:32:55'),
	(18, 'avscorrea', '::1', 1, '2026-07-24 15:35:04'),
	(19, 'avscorrea', '::1', 0, '2026-07-24 15:38:37'),
	(20, 'avscorrea', '::1', 1, '2026-07-24 15:39:08'),
	(21, 'atduartejr', '::1', 1, '2026-07-24 15:59:24'),
	(22, 'atduartejr', '::1', 1, '2026-07-24 16:04:57'),
	(23, 'atduartejr', '::1', 1, '2026-07-24 16:51:12'),
	(24, 'atduartejr', '::1', 1, '2026-07-24 17:28:43');

-- Dumping structure for table dts_drds.notifications
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user_read` (`user_id`,`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.notifications: ~1 rows (approximately)
INSERT INTO `notifications` (`id`, `user_id`, `message`, `link`, `is_read`, `created_at`) VALUES
	(1, 4, 'A document has been routed to you for action.', 'document_view.php?id=1', 0, '2026-07-24 16:43:21');

-- Dumping structure for table dts_drds.relief_inventory
DROP TABLE IF EXISTS `relief_inventory`;
CREATE TABLE IF NOT EXISTS `relief_inventory` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` enum('Food','Medical','Shelter','Water','Hygiene','Other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `unit` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pack',
  `quantity_available` int unsigned NOT NULL DEFAULT '0',
  `quantity_distributed` int unsigned NOT NULL DEFAULT '0',
  `reorder_level` int unsigned NOT NULL DEFAULT '50',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.relief_inventory: ~6 rows (approximately)
INSERT INTO `relief_inventory` (`id`, `item_name`, `category`, `unit`, `quantity_available`, `quantity_distributed`, `reorder_level`, `updated_at`, `created_at`) VALUES
	(1, 'Family Food Pack', 'Food', 'pack', 5000, 1200, 500, '2026-07-24 12:20:22', '2026-07-24 12:20:22'),
	(2, 'Bottled Water (6L pack)', 'Water', 'pack', 2900, 1000, 300, '2026-07-24 16:31:25', '2026-07-24 12:20:22'),
	(3, 'First Aid Kit', 'Medical', 'kit', 800, 150, 100, '2026-07-24 12:20:22', '2026-07-24 12:20:22'),
	(4, 'Emergency Tent', 'Shelter', 'unit', 300, 60, 50, '2026-07-24 12:20:22', '2026-07-24 12:20:22'),
	(5, 'Hygiene Kit', 'Hygiene', 'kit', 1500, 400, 200, '2026-07-24 12:20:22', '2026-07-24 12:20:22'),
	(6, 'Maternal Kit', 'Hygiene', 'pack', 500, 0, 700, '2026-07-24 16:33:41', '2026-07-24 16:33:41');

-- Dumping structure for table dts_drds.users
DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','department','logistics','approver') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'department',
  `department_id` int unsigned DEFAULT NULL,
  `can_route` tinyint(1) NOT NULL DEFAULT '1',
  `avatar_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_department` (`department_id`),
  KEY `idx_users_role` (`role`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table dts_drds.users: ~5 rows (approximately)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `department_id`, `avatar_path`, `is_active`, `last_login_at`, `created_at`, `updated_at`) VALUES
	(1, 'admin', 'admin@relief.gov.ph', '$2y$10$HeHPwY7vL8hICbiOhdUmt.FG7CItJiKHkSSJQPDf2p.k4fuZCyvEi', 'System Administrator', 'admin', 1, NULL, 1, '2026-07-24 15:32:55', '2026-07-24 12:20:22', '2026-07-24 15:32:55'),
	(2, 'drms_user', 'department@relief.gov.ph', '$2y$10$HeHPwY7vL8hICbiOhdUmt.FG7CItJiKHkSSJQPDf2p.k4fuZCyvEi', 'Juan Dela Cruz', 'department', 3, NULL, 1, '2026-07-24 14:12:30', '2026-07-24 12:20:22', '2026-07-24 14:12:30'),
	(3, 'logistics1', 'logistics@relief.gov.ph', '$2y$10$HeHPwY7vL8hICbiOhdUmt.FG7CItJiKHkSSJQPDf2p.k4fuZCyvEi', 'Maria Santos', 'logistics', 2, NULL, 1, '2026-07-24 14:27:06', '2026-07-24 12:20:22', '2026-07-24 14:27:06'),
	(4, 'avscorrea', 'correaacevergel@gmail.com', '$2y$10$1aaxElPWm05f9zfFeEGyUeHoR8VjTrM2kGx6v4d55ZFfhj66jqX52', 'Ace Vergel Correa', 'admin', 1, NULL, 1, '2026-07-24 15:39:08', '2026-07-24 15:33:54', '2026-07-24 15:39:08'),
	(5, 'atduartejr', 'atduartejr@dswd.gov.ph', '$2y$10$x5vZtTjRKAoj1jYkrwlWBuUiJwdRxDQPg9tX82TsiS6Ibyw8Ygocy', 'Abner T. Duarte Jr.', 'admin', 4, NULL, 1, '2026-07-24 17:28:43', '2026-07-24 15:59:06', '2026-07-24 17:28:43');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
