-- Migration: an assigned approver per office
--
-- The revised workflow removes the manual "Route to" step. A document is
-- classified on upload, goes to the Office of the Secretary, and once the
-- OSEC user acknowledges it, it moves on by itself to the approver who
-- handles that office:
--
--   Traditional Media Services  -> its approver
--   Digital Media Service       -> its approver
--   Public Relations Services   -> its approver
--
-- For that hop to happen without anyone choosing a recipient, each office
-- has to name its approver up front. The three approver accounts all sit
-- in the Office of the Secretary, so their own department_id cannot say
-- which office they answer for — it has to be recorded here.
--
-- Nullable on purpose: an office with no approver yet is a normal state,
-- and the routing code reports it rather than guessing.
--
-- Re-runnable.
--
--   mysql -u root -p dts_drds < migrations/2026_09_16_office_approvers.sql

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'departments'
      AND COLUMN_NAME  = 'approver_user_id'
);
SET @sql := IF(@has_col = 0,
    'ALTER TABLE departments
        ADD COLUMN approver_user_id INT UNSIGNED NULL DEFAULT NULL AFTER description',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index + foreign key, added only once.
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'departments'
      AND INDEX_NAME   = 'fk_departments_approver'
);
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE departments ADD KEY fk_departments_approver (approver_user_id)',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ON DELETE SET NULL: removing a user must not take the office with it;
-- the office simply reverts to having no approver, which the routing code
-- already handles.
SET @has_fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA   = DATABASE()
      AND TABLE_NAME     = 'departments'
      AND CONSTRAINT_NAME = 'fk_departments_approver'
);
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE departments
        ADD CONSTRAINT fk_departments_approver
        FOREIGN KEY (approver_user_id) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
