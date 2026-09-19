-- Migration: automatic document classification
--
-- The creator no longer picks the document type; the system reads the
-- uploaded file and proposes one. Two things have to be recorded for that
-- to work and to keep working:
--
--   1. WHAT THE SYSTEM THOUGHT, separately from what the document ended
--      up as. documents.doc_type stays the single source of truth for the
--      type; detected_type records the prediction beside it. Keeping them
--      apart is what makes accuracy measurable — every row where they
--      differ is a correction, and corrections are the training set.
--
--   2. THE TEXT IT READ. Stored per document so the corpus builds itself
--      as people work. Today's classifier is a set of rules; the SVM that
--      replaces it will need hundreds of labelled examples, and the only
--      way to have them later is to start keeping them now.
--
-- Re-runnable.
--
--   mysql -u root -p dts_drds < migrations/2026_09_16_document_classification.sql

DROP PROCEDURE IF EXISTS add_doc_column_if_missing;
DELIMITER //
CREATE PROCEDURE add_doc_column_if_missing(IN col VARCHAR(64), IN ddl TEXT)
BEGIN
    IF (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'
           AND COLUMN_NAME = col) = 0 THEN
        SET @sql = CONCAT('ALTER TABLE documents ADD COLUMN ', ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;

-- What the classifier proposed, and how sure it was.
CALL add_doc_column_if_missing('detected_type',
    "detected_type VARCHAR(50) NULL DEFAULT NULL AFTER doc_type");
-- 0.000-1.000. Below the acceptance threshold the form asks instead of guessing.
CALL add_doc_column_if_missing('detection_confidence',
    "detection_confidence DECIMAL(4,3) NULL DEFAULT NULL AFTER detected_type");
-- Which implementation produced it, so a change of model is traceable in
-- the data rather than only in the code history.
CALL add_doc_column_if_missing('detection_source',
    "detection_source VARCHAR(20) NULL DEFAULT NULL AFTER detection_confidence");
CALL add_doc_column_if_missing('detected_at',
    "detected_at DATETIME NULL DEFAULT NULL AFTER detection_source");

DROP PROCEDURE IF EXISTS add_doc_column_if_missing;

-- -------------------------------------------------------------------
-- The text the classifier read.
--
-- Its own table rather than a column on documents: it is large, it is
-- only needed when classifying or retraining, and SELECT * on documents
-- runs on nearly every screen in the app.
-- -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_text (
    document_id   INT UNSIGNED NOT NULL,
    content       LONGTEXT NULL,
    -- 'pdf', 'docx', 'fields' (title/description only), or 'none'
    source        VARCHAR(20) NOT NULL DEFAULT 'none',
    char_count    INT UNSIGNED NOT NULL DEFAULT 0,
    extracted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (document_id),
    CONSTRAINT fk_document_text_document
        FOREIGN KEY (document_id) REFERENCES documents (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
