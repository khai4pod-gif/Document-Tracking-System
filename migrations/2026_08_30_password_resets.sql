-- Migration: self-service password reset
--
-- Adds the table behind forgot_password.php / reset_password.php. Until now
-- a forgotten password could only be cleared by an administrator through the
-- Users screen.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
--
--   mysql -u root -p dts_drds < migrations/2026_08_30_password_resets.sql

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_password_resets_token` (`token_hash`),
  CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
      REFERENCES `users`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX `idx_password_resets_active` (`user_id`, `consumed_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
