-- =====================================================================
-- Document Tracking & Disaster Relief Distribution System (DTS-DRDS)
-- Database Schema
-- Engine: MySQL 8.0+  |  Charset: utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `dts_drds` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dts_drds`;

-- ---------------------------------------------------------------------
-- 1. DEPARTMENTS / OFFICES
-- ---------------------------------------------------------------------
CREATE TABLE `departments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. USERS & ROLES
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `role` ENUM('admin','department','logistics','approver') NOT NULL DEFAULT 'department',
  `department_id` INT UNSIGNED DEFAULT NULL,
  `can_route` TINYINT(1) NOT NULL DEFAULT 1,
  `avatar_path` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`)
      REFERENCES `departments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_users_role` (`role`)
) ENGINE=InnoDB;

-- Login throttling / brute-force protection
CREATE TABLE `login_attempts` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_login_attempts_lookup` (`username`, `ip_address`, `attempted_at`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. DOCUMENT TRACKING SYSTEM (DTS)
-- ---------------------------------------------------------------------
CREATE TABLE `documents` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tracking_number` VARCHAR(30) NOT NULL UNIQUE,
  `title` VARCHAR(255) NOT NULL,
  `doc_type` ENUM('Memo','Letter','Report','Purchase Request','Relief Manifest','Special Order','ORs/DV','PPMP','Purchase Order','Leave Application','Other') NOT NULL DEFAULT 'Other',
  `priority` ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  `description` TEXT DEFAULT NULL,
  `status` ENUM('Draft','Pending Routing','In Transit','Received','Completed','Overdue') NOT NULL DEFAULT 'Draft',
  `origin_department_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `current_holder_id` INT UNSIGNED DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `approval_status` ENUM('Not Required','Pending','Approved','Rejected') NOT NULL DEFAULT 'Not Required',
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_documents_origin_dept` FOREIGN KEY (`origin_department_id`)
      REFERENCES `departments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_created_by` FOREIGN KEY (`created_by`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_current_holder` FOREIGN KEY (`current_holder_id`)
      REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_documents_approved_by` FOREIGN KEY (`approved_by`)
      REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_documents_status` (`status`),
  INDEX `idx_documents_archived` (`is_archived`),
  INDEX `idx_documents_approval_status` (`approval_status`),
  FULLTEXT INDEX `ft_documents_title_desc` (`title`, `description`)
) ENGINE=InnoDB;

CREATE TABLE `document_attachments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `uploaded_by` INT UNSIGNED NOT NULL,
  `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_attach_document` FOREIGN KEY (`document_id`)
      REFERENCES `documents`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_attach_uploaded_by` FOREIGN KEY (`uploaded_by`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Routing / workflow (office-to-office, user-to-user transfer)
CREATE TABLE `document_routes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `from_user_id` INT UNSIGNED NOT NULL,
  `to_user_id` INT UNSIGNED NOT NULL,
  `from_department_id` INT UNSIGNED DEFAULT NULL,
  `to_department_id` INT UNSIGNED DEFAULT NULL,
  `action_required` VARCHAR(255) NOT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` ENUM('Pending','Received','Completed','Returned') NOT NULL DEFAULT 'Pending',
  `routed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_at` DATETIME DEFAULT NULL,
  CONSTRAINT `fk_route_document` FOREIGN KEY (`document_id`)
      REFERENCES `documents`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_route_from_user` FOREIGN KEY (`from_user_id`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_route_to_user` FOREIGN KEY (`to_user_id`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_route_from_dept` FOREIGN KEY (`from_department_id`)
      REFERENCES `departments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_route_to_dept` FOREIGN KEY (`to_department_id`)
      REFERENCES `departments`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX `idx_route_status` (`status`)
) ENGINE=InnoDB;

-- Full audit trail / transaction log (immutable history)
CREATE TABLE `document_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action` ENUM('Created','Updated','Routed','Received','Completed','Archived','Restored','Attachment Added','Attachment Removed','Approved','Rejected') NOT NULL,
  `details` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_logs_document` FOREIGN KEY (`document_id`)
      REFERENCES `documents`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX `idx_logs_document_time` (`document_id`, `created_at`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. DISASTER RELIEF DISTRIBUTION
-- ---------------------------------------------------------------------
CREATE TABLE `relief_inventory` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `item_name` VARCHAR(150) NOT NULL,
  `category` ENUM('Food','Medical','Shelter','Water','Hygiene','Other') NOT NULL DEFAULT 'Other',
  `unit` VARCHAR(30) NOT NULL DEFAULT 'pack',
  `quantity_available` INT UNSIGNED NOT NULL DEFAULT 0,
  `quantity_distributed` INT UNSIGNED NOT NULL DEFAULT 0,
  `reorder_level` INT UNSIGNED NOT NULL DEFAULT 50,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `evacuation_centers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `target_area` VARCHAR(150) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `capacity` INT UNSIGNED DEFAULT NULL,
  `contact_person` VARCHAR(150) DEFAULT NULL,
  `contact_number` VARCHAR(30) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A distribution run/event, optionally backed by an official manifest document
CREATE TABLE `distributions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reference_no` VARCHAR(30) NOT NULL UNIQUE,
  `evacuation_center_id` INT UNSIGNED NOT NULL,
  `document_id` INT UNSIGNED DEFAULT NULL COMMENT 'Linked DTS manifest document, if routed for approval',
  `distributed_by` INT UNSIGNED NOT NULL,
  `distribution_date` DATE NOT NULL,
  `total_beneficiaries` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('Draft','Pending Approval','Approved','Completed','Cancelled') NOT NULL DEFAULT 'Draft',
  `remarks` VARCHAR(500) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_dist_center` FOREIGN KEY (`evacuation_center_id`)
      REFERENCES `evacuation_centers`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_dist_document` FOREIGN KEY (`document_id`)
      REFERENCES `documents`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_dist_user` FOREIGN KEY (`distributed_by`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX `idx_dist_date` (`distribution_date`),
  INDEX `idx_dist_status` (`status`)
) ENGINE=InnoDB;

-- Line items of goods per distribution event
CREATE TABLE `distribution_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `distribution_id` INT UNSIGNED NOT NULL,
  `inventory_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT `fk_ditems_distribution` FOREIGN KEY (`distribution_id`)
      REFERENCES `distributions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ditems_inventory` FOREIGN KEY (`inventory_id`)
      REFERENCES `relief_inventory`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. NOTIFICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE `notifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  `link` VARCHAR(255) DEFAULT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_notif_user_read` (`user_id`, `is_read`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- =====================================================================

INSERT INTO `departments` (`name`, `code`, `description`) VALUES
('Office of the Administrator', 'ADMIN', 'Executive / central administration'),
('Logistics & Relief Operations', 'LOGISTICS', 'Handles relief goods and distribution'),
('Records Management Office', 'RECORDS', 'Central document receiving/releasing unit');

-- Default password for ALL seed accounts below is:  Passw0rd!123
-- (hash generated with PHP password_hash() using PASSWORD_DEFAULT / bcrypt)
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`, `department_id`) VALUES
('admin',     'admin@relief.gov.ph',     '$2y$10$92pQ1zC2Yc0J1r0nQyq8P.eYFhq9m5U9hFQwz1zXW0k2u4l0m9Kqi', 'System Administrator', 'admin', 1),
('drms_user', 'department@relief.gov.ph','$2y$10$92pQ1zC2Yc0J1r0nQyq8P.eYFhq9m5U9hFQwz1zXW0k2u4l0m9Kqi', 'Juan Dela Cruz',       'department', 3),
('logistics1','logistics@relief.gov.ph','$2y$10$92pQ1zC2Yc0J1r0nQyq8P.eYFhq9m5U9hFQwz1zXW0k2u4l0m9Kqi', 'Maria Santos',         'logistics', 2);

INSERT INTO `relief_inventory` (`item_name`, `category`, `unit`, `quantity_available`, `quantity_distributed`, `reorder_level`) VALUES
('Family Food Pack',        'Food',    'pack', 5000, 1200, 500),
('Bottled Water (6L pack)', 'Water',   'pack', 3000, 900,  300),
('First Aid Kit',           'Medical', 'kit',  800,  150,  100),
('Emergency Tent',          'Shelter', 'unit', 300,  60,   50),
('Hygiene Kit',             'Hygiene', 'kit',  1500, 400,  200);

INSERT INTO `evacuation_centers` (`name`, `target_area`, `address`, `capacity`, `contact_person`, `contact_number`) VALUES
('San Isidro Elementary School', 'Barangay San Isidro', 'San Isidro, City Proper', 500, 'Brgy. Capt. R. Ramos', '0917-000-0001'),
('Sto. Niño Covered Court',      'Barangay Sto. Niño',  'Sto. Niño District',      300, 'Brgy. Capt. L. Cruz',  '0917-000-0002'),
('City Sports Complex',          'City-wide',           'National Highway',        1200,'MDRRMO Officer',       '0917-000-0003');
