-- Mamabe: public MamaBe-website registrations - by pink book (EDD) or by baby health book (baby name, DOB, MyKid) -
-- the MamaBe records they were saved into, and the AI vision verification result.
-- Run once per deployment (local, then the live server). A database created from an older copy of this file:
-- run mamabe_submission_register_types.sql instead.
--
-- Every submission is written straight into the existing MamaBe tables by api/register.php:
--   mamabe_mama  - matched by mobile number (recycle = 0); a new row only when it is not there yet. Never odb `customer`.
--   mamabe_baby  - the pregnancy / baby (a baby health book registration uses the DOB as the EDD); an existing row with
--                  the same EDD is reused.
-- This table keeps the submission itself (as typed), the local image copies the AI reads, and links to those rows.
--
-- status is a workflow code the app branches on, so it is a class constant
-- (MamabeVerifier::STATUS_*) rather than a lookup table:
--   0 queued   - stored, waiting for worker.php
--   1 checking - worker is reading the image(s)
--   2 proceed  - every name that could be checked matches (mother vs pink book; baby vs baby book when given)
--   3 pending  - a name mismatch / not found / AI error -> needs a human look (see check_note)
CREATE TABLE IF NOT EXISTS mamabe_submission (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mama_id          INT UNSIGNED NULL     COMMENT 'mamabe_mama.id the submission was saved into',
  baby_id          INT UNSIGNED NULL     COMMENT 'mamabe_baby.id (the pregnancy / baby row) the submission was saved into',
  mama_is_new      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = this submission created the mamabe_mama row, 0 = matched an existing one',
  batch_id         INT UNSIGNED NULL     COMMENT 'links the rows of one registration request together (the pink + baby rows of a register_type=both submission, or twin/multi-baby rows) - equals the id of the first row inserted in the request; NULL = never grouped (standalone pink/baby row)',
  register_type    VARCHAR(4)   NOT NULL DEFAULT 'pink' COMMENT 'pink = pink book (EDD), baby = baby health book (baby name, DOB, MyKid)',
  source           VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'how she knows us: outlet / insurance / social_media',
  preferred_outlet_id INT UNSIGNED NULL  COMMENT 'outlet.id she prefers to redeem at (also saved as mamabe_mama.outlet_id when that is still 0)',
  mother_name      VARCHAR(150) NOT NULL COMMENT 'mama name as typed',
  ic               VARCHAR(14)  NOT NULL DEFAULT '' COMMENT 'mama IC / passport as typed (letters+digits only)',
  phone            VARCHAR(20)  NOT NULL COMMENT 'mama phone, digits only',
  address          TEXT         NOT NULL COMMENT 'mama address as typed (fills mamabe_mama.address when empty)',
  language         TINYINT UNSIGNED NOT NULL COMMENT '1 English, 2 BM, 3 Chinese, 4 Tamil (same codes as mamabe_mama.language)',
  edd              DATE         NOT NULL COMMENT 'expected delivery date (baby health book: the baby DOB)',
  child_name       VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'baby name as typed (optional)',
  baby_mykid       VARCHAR(14)  NOT NULL DEFAULT '' COMMENT 'baby MyKid (optional)',
  baby_dob         DATE         NULL     COMMENT 'baby date of birth (optional)',
  baby_gender      VARCHAR(10)  NOT NULL DEFAULT '' COMMENT 'Male / Female / empty (same values as mamabe_baby.gender)',
  pink_file        VARCHAR(64)  NULL     COMMENT 'pink book image inside uploads/ (random, re-encoded JPEG) - pink registrations',
  baby_file        VARCHAR(64)  NULL     COMMENT 'baby health book image inside uploads/ - baby registrations',
  status           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  extracted_child  VARCHAR(255) NULL COMMENT 'baby name the AI read from the baby book (register_type = baby)',
  extracted_mother VARCHAR(255) NULL COMMENT 'mother name the AI read from the document (pink book for register_type = pink; the baby book''s own "mother on doc" field for register_type = baby - the fallback match)',
  extracted_edd    VARCHAR(255) NULL COMMENT 'EDD text as read from the pink book (register_type = pink; not parsed/validated - MamabeVerifier::parseDocDate does that at check time)',
  extracted_dob    VARCHAR(255) NULL COMMENT 'date of birth text as read from the baby book (register_type = baby)',
  extracted_mykid  VARCHAR(30)  NULL COMMENT 'baby MyKid number as read from the baby book - first 6 digits confirm a misread DOB year (register_type = baby)',
  check_note       VARCHAR(255) NULL COMMENT 'why it is pending (which check failed / not found / AI error)',
  nas_synced       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = images copied to the NAS and linked on mamabe_baby (pink_book / baby_health_book)',
  nas_note         VARCHAR(255) NULL COMMENT 'last NAS copy error, retried by worker.php',
  ip_address       VARCHAR(45)  NOT NULL COMMENT 'submitter IP, used only for rate limiting',
  created_at       DATETIME     NOT NULL,
  checked_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_status_id (status, id),
  KEY idx_ip_created (ip_address, created_at),
  KEY idx_phone (phone),
  KEY idx_nas (nas_synced, status),
  KEY idx_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-editable check settings (Settings tab). Key/value: a few scalar rule settings read by
-- MamabeVerifier::loadSettings(); a missing row falls back to the code default.
--   baby_max_age_months - a baby passes the DOB check only while younger than this many months (default 24)
CREATE TABLE IF NOT EXISTS mamabe_setting (
  setting_key    VARCHAR(50)  NOT NULL,
  setting_value  VARCHAR(255) NOT NULL,
  updated_by     INT UNSIGNED NULL COMMENT 'staff.id of the admin who last changed it (NULL = seeded default)',
  updated_at     DATETIME     NOT NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO mamabe_setting (setting_key, setting_value, updated_by, updated_at)
VALUES ('baby_max_age_months', '24', NULL, NOW());

-- AI timing log: one row per AI read by worker.php (MamabeVerifier::logAiRead), for analysing speed and what slows it
-- down (photo resolution, model, image size sent, success rate). Rows are only ever inserted, never used by the check
-- itself. One row per mamabe_submission row (this module already has one row per check unit - pink or baby - unlike
-- the mother/baby-table split elsewhere, so no separate baby_id column is needed here).
--   queue wait = started_at - the submission's created_at;  AI time = ai_ms;  a timeout shows as ai_ms ~ 300000 with ai_ok = 0.
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
