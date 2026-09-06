-- Migration: 'Resubmitted' document log action
--
-- A rejected document that is routed back to an approver is a resubmission:
-- the approval gate reopens so the approver can rule on the revision. That
-- event needs its own entry in the timeline, and document_logs.action is an
-- ENUM, so the value has to exist before the application can write it.
--
-- Without this the INSERT throws inside route()'s transaction, the routing
-- is rolled back, and the document does not move at all.
--
-- Re-runnable: the ENUM is only rewritten when the value is missing.
--
--   mysql -u root -p dts_drds < migrations/2026_09_06_resubmitted_action.sql

SET @has_value := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'document_logs'
      AND COLUMN_NAME  = 'action'
      AND COLUMN_TYPE LIKE '%''Resubmitted''%'
);

SET @ddl := IF(@has_value = 0,
    "ALTER TABLE `document_logs` MODIFY COLUMN `action`
       ENUM('Created','Updated','Routed','Received','Completed','Archived',
            'Restored','Attachment Added','Attachment Removed','Approved',
            'Rejected','Resubmitted') NOT NULL",
    'SELECT "Resubmitted already present, nothing to do"'
);

PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
