-- Migration: mode of transmittal, and a correction to old routing entries
--
-- Two changes, both in service of the document history panel:
--
--   1. HOW THE DOCUMENT TRAVELLED. A routing row already records who sent
--      it, to whom, and when. It has never recorded whether the paper was
--      walked over, the scan was shared, or it was emailed — which is the
--      first thing anyone chasing a document asks. Stored per hop rather
--      than per document because a document can arrive as hard copy and
--      leave by email.
--
--   2. "Routed to user #8". Older audit entries name the recipient by
--      database id, which is unreadable to the people the audit trail is
--      for. The facts are unchanged; only the rendering is corrected, and
--      only for entries that still carry an id.
--
-- Re-runnable.
--
--   mysql -u root -p dts_drds < migrations/2026_09_19_transmittal_mode.sql

DROP PROCEDURE IF EXISTS add_route_column_if_missing;
DELIMITER //
CREATE PROCEDURE add_route_column_if_missing(IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_routes'
           AND COLUMN_NAME = col) = 0 THEN
        SET @sql = CONCAT('ALTER TABLE document_routes ADD COLUMN ', ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;

-- NULL rather than a default: rows written before this migration genuinely
-- do not know, and "Hard Copy" would be a guess dressed up as a record.
CALL add_route_column_if_missing('transmittal_mode',
    "transmittal_mode ENUM('Hard Copy','Soft Copy','Email') NULL DEFAULT NULL AFTER action_required");

DROP PROCEDURE IF EXISTS add_route_column_if_missing;

-- -------------------------------------------------------------------
-- Replace "user #<id>" with the name that id belongs to.
--
-- Matched on the exact text the old code wrote, so an entry that has
-- already been corrected, or that never carried an id, is left alone.
-- -------------------------------------------------------------------
UPDATE document_logs l
  JOIN users u
    ON l.details LIKE CONCAT('Routed to user #', u.id, ' %')
    OR l.details = CONCAT('Routed to user #', u.id)
   SET l.details = REPLACE(l.details,
                           CONCAT('Routed to user #', u.id),
                           CONCAT('Routed document to ', u.full_name))
 WHERE l.action = 'Routed'
   AND l.details LIKE 'Routed to user #%';
