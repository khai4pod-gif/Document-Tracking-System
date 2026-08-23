-- Migration: conclusion remarks on archive
--
-- Archiving is how a document is closed out, so it now asks the user why.
-- The reason is stored on the document and repeated in the audit log.
--
-- Re-runnable: the ADD is guarded so it is skipped if the column exists.
--
--   mysql -u root -p dts_drds < migrations/2026_08_23_conclusion_remarks.sql

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'documents'
      AND COLUMN_NAME  = 'conclusion_remarks'
);

SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `documents` ADD COLUMN `conclusion_remarks` VARCHAR(500) DEFAULT NULL AFTER `is_archived`',
    'SELECT "conclusion_remarks already present, nothing to do"'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
