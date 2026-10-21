-- Mamabe: upgrade an existing mamabe_submission table for the richer AI check (date rules read from the document
-- itself, MyKid-based DOB confirmation, admin-editable baby age limit, AI timing log/analysis).
-- Run once per deployment that already has the table (already ran mamabe_submission.sql and, if it predates
-- register_type, mamabe_submission_register_types.sql too); a brand new deployment runs mamabe_submission.sql only
-- (it already has these columns/tables).
ALTER TABLE mamabe_submission
  ADD COLUMN extracted_edd   VARCHAR(255) NULL COMMENT 'EDD text as read from the pink book (register_type = pink; not parsed/validated - MamabeVerifier::parseDocDate does that at check time)' AFTER extracted_mother,
  ADD COLUMN extracted_dob   VARCHAR(255) NULL COMMENT 'date of birth text as read from the baby book (register_type = baby)' AFTER extracted_edd,
  ADD COLUMN extracted_mykid VARCHAR(30)  NULL COMMENT 'baby MyKid number as read from the baby book - first 6 digits confirm a misread DOB year (register_type = baby)' AFTER extracted_dob,
  MODIFY extracted_mother VARCHAR(255) NULL COMMENT 'mother name the AI read from the document (pink book for register_type = pink; the baby book''s own "mother on doc" field for register_type = baby - the fallback match)';

CREATE TABLE IF NOT EXISTS mamabe_setting (
  setting_key    VARCHAR(50)  NOT NULL,
  setting_value  VARCHAR(255) NOT NULL,
  updated_by     INT UNSIGNED NULL COMMENT 'staff.id of the admin who last changed it (NULL = seeded default)',
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mamabe_setting (setting_key, setting_value, updated_by, updated_at)
VALUES ('baby_max_age_months', '24', NULL, NOW());

CREATE TABLE IF NOT EXISTS mamabe_ai_log (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  submission_id  INT UNSIGNED NOT NULL,
  started_at     DATETIME     NOT NULL COMMENT 'when the worker picked the unit up',
  ai_ms          INT UNSIGNED NOT NULL COMMENT 'duration of the AI call itself in milliseconds (0 = never called, e.g. file missing)',
  img_width      SMALLINT UNSIGNED NULL COMMENT 'stored image size in px (before the shrink to ai_max_px)',
  img_height     SMALLINT UNSIGNED NULL,
  img_bytes      INT UNSIGNED NULL COMMENT 'stored image file size',
  ai_max_px      SMALLINT UNSIGNED NOT NULL COMMENT 'MAMABE_AI_MAX_PX at the time - longest side sent to the AI',
  model          VARCHAR(50)  NOT NULL COMMENT 'AI_VISION_MODEL at the time',
  ai_ok          TINYINT(1)   NOT NULL COMMENT '1 = AI answered and the reply parsed',
  ai_error       VARCHAR(255) NULL COMMENT 'AI / parse error when ai_ok = 0',
  result_status  TINYINT UNSIGNED NOT NULL COMMENT 'MamabeVerifier::STATUS_* decided for this read (2 proceed, 3 pending)',
  PRIMARY KEY (id),
  KEY idx_started (started_at),
  KEY idx_submission (submission_id),
  CONSTRAINT fk_mamabe_ailog_submission FOREIGN KEY (submission_id) REFERENCES mamabe_submission (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
