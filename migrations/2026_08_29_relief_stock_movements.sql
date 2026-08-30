-- Migration: stock movement ledger for relief inventory
--
-- relief_inventory holds only the current position. A restock, a correction
-- or a write-off left no trace of who changed what or why, and no balance
-- could be read back for any date but today. This adds the ledger, then
-- seeds one Opening row per existing item so the running balance is
-- complete from the day the log goes live.
--
-- Safe to re-run: the table creation is guarded, and the seed only inserts
-- for items that have no movements yet.
--
--   mysql -u root -p dts_drds < migrations/2026_08_29_relief_stock_movements.sql

CREATE TABLE IF NOT EXISTS `relief_stock_movements` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `inventory_id` INT UNSIGNED NOT NULL,
  `movement_type` ENUM('Opening','Receipt','Release','Return','Adjustment','Write-off') NOT NULL,
  `quantity` INT NOT NULL,
  `balance_after` INT UNSIGNED NOT NULL,
  `distribution_id` INT UNSIGNED DEFAULT NULL,
  `reference` VARCHAR(120) DEFAULT NULL,
  `remarks` VARCHAR(500) DEFAULT NULL,
  `moved_by` INT UNSIGNED DEFAULT NULL,
  `moved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_movements_item_date` (`inventory_id`, `moved_at`),
  INDEX `idx_movements_date` (`moved_at`),
  CONSTRAINT `fk_movement_inventory` FOREIGN KEY (`inventory_id`)
      REFERENCES `relief_inventory`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_movement_distribution` FOREIGN KEY (`distribution_id`)
      REFERENCES `distributions`(`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_movement_user` FOREIGN KEY (`moved_by`)
      REFERENCES `users`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Opening position for stock that predates the ledger. Historic receipts and
-- releases cannot be reconstructed — nothing recorded them — so each item
-- starts from its current quantity, dated to the item's own creation so the
-- ledger reads in order.
INSERT INTO `relief_stock_movements`
      (`inventory_id`, `movement_type`, `quantity`, `balance_after`, `remarks`, `moved_at`)
SELECT ri.`id`, 'Opening', ri.`quantity_available`, ri.`quantity_available`,
       'Opening balance recorded when the stock ledger was introduced.',
       ri.`created_at`
  FROM `relief_inventory` ri
 WHERE NOT EXISTS (
       SELECT 1 FROM `relief_stock_movements` m WHERE m.`inventory_id` = ri.`id`
 );
