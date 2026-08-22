-- Migration: cloud links for documents
--
-- The New Document form has always collected "Cloud Link" values, but there
-- was nowhere to put them and document_save.php discarded them. This adds
-- the missing table.
--
-- Safe to re-run: CREATE TABLE IF NOT EXISTS.
--
--   mysql -u root -p dts_drds < migrations/2026_08_22_document_links.sql

CREATE TABLE IF NOT EXISTS `document_links` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT UNSIGNED NOT NULL,
  `url` VARCHAR(2048) NOT NULL,
  `added_by` INT UNSIGNED NOT NULL,
  `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_links_document` (`document_id`),
  CONSTRAINT `fk_link_document` FOREIGN KEY (`document_id`)
      REFERENCES `documents`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_link_added_by` FOREIGN KEY (`added_by`)
      REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
