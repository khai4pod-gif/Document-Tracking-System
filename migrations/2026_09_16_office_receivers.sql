-- Migration: the receiving user for an office
--
-- Companion to 2026_09_16_office_approvers.sql. Between them the two
-- columns describe the whole automatic chain:
--
--   creator uploads
--     -> receiver_user_id of the Office of the Secretary   (acknowledges)
--       -> approver_user_id of the originating office      (approves)
--
-- A route needs a named recipient — document_routes.to_user_id is NOT
-- NULL — so "send it to the Office of the Secretary" has to resolve to a
-- person. That person is the OSEC staff member who acknowledges intake,
-- and this column is where that assignment lives.
--
-- Nullable: an office with no receiver is a normal state, and the routing
-- code reports it rather than guessing at a recipient.
--
-- Re-runnable.
--
--   mysql -u root -p dts_drds < migrations/2026_09_16_office_receivers.sql

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'departments'
      AND COLUMN_NAME  = 'receiver_user_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE departments
        ADD COLUMN receiver_user_id INT UNSIGNED NULL DEFAULT NULL AFTER approver_user_id',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'departments'
      AND INDEX_NAME   = 'fk_departments_receiver'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE departments ADD KEY fk_departments_receiver (receiver_user_id)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = DATABASE()
      AND TABLE_NAME      = 'departments'
      AND CONSTRAINT_NAME = 'fk_departments_receiver'
);
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE departments
        ADD CONSTRAINT fk_departments_receiver
        FOREIGN KEY (receiver_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
