-- =====================================================================================================================
-- MamaBe — database changes for PRODUCTION
-- Date      : 2026-09-24
-- Feature   : MamaBe website registration (public form on the education server -> odb API -> MamaBe tables)
-- Database  : odb
--
-- Run this whole file once on the production `odb` database (phpMyAdmin > SQL, or the mysql client).
-- Every statement is safe to re-run (IF NOT EXISTS / WHERE NOT EXISTS), so running it twice does no harm.
--
-- SUMMARY OF CHANGES
--   1. NEW TABLE   mamabe_submission   - every website registration + the AI check result + links to the MamaBe rows
--   2. NEW ROW     api_user            - API user `mamabe_api` used by the education server to call odb's API
--   3. NO CHANGE   mamabe_mama         - no new columns (the website form only inserts rows - see note 3 below)
--   4. NO CHANGE   mamabe_baby         - no new columns (the website form only inserts / fills rows - see note 4 below)
--   5. NO CHANGE   customer            - the website form NEVER writes to `customer`
--
-- NOT FOR PRODUCTION: mamabe_submission_old_test and mamabe_ic_lookup existed only locally during development
-- (old test rows / a dropped design) and were deleted on 2026-09-24 - there is nothing to create or drop for them.
-- =====================================================================================================================


-- ---------------------------------------------------------------------------------------------------------------------
-- 1. NEW TABLE: mamabe_submission                                                                          (2026-09-24)
--    Same as odb/mamabe/registration/sql/mamabe_submission.sql.
--    status: 0 queued, 1 checking, 2 proceed (names match), 3 pending (needs a human look)
-- ---------------------------------------------------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mamabe_submission (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  mama_id          INT UNSIGNED NULL     COMMENT 'mamabe_mama.id the submission was saved into',
  baby_id          INT UNSIGNED NULL     COMMENT 'mamabe_baby.id (the pregnancy / baby row) the submission was saved into',
  mama_is_new      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = this submission created the mamabe_mama row, 0 = matched an existing one by IC',
  mother_name      VARCHAR(150) NOT NULL COMMENT 'mama name as typed',
  ic               VARCHAR(14)  NOT NULL COMMENT 'mama IC / passport as typed (letters+digits only)',
  phone            VARCHAR(20)  NOT NULL COMMENT 'mama phone, digits only',
  language         TINYINT UNSIGNED NOT NULL COMMENT '1 English, 2 BM, 3 Chinese, 4 Tamil (same codes as mamabe_mama.language)',
  edd              DATE         NOT NULL COMMENT 'expected delivery date',
  child_name       VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'baby name as typed (optional)',
  baby_mykid       VARCHAR(14)  NOT NULL DEFAULT '' COMMENT 'baby MyKid (optional)',
  baby_dob         DATE         NULL     COMMENT 'baby date of birth (optional)',
  baby_gender      VARCHAR(10)  NOT NULL DEFAULT '' COMMENT 'Male / Female / empty (same values as mamabe_baby.gender)',
  pink_file        VARCHAR(64)  NOT NULL COMMENT 'pink book image inside registration/uploads/ (random, re-encoded JPEG)',
  baby_file        VARCHAR(64)  NULL     COMMENT 'baby book image inside registration/uploads/ (optional)',
  status           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  extracted_child  VARCHAR(255) NULL COMMENT 'baby name the AI read from the baby book',
  extracted_mother VARCHAR(255) NULL COMMENT 'mother name the AI read from the pink book',
  check_note       VARCHAR(255) NULL COMMENT 'why it is pending (which name mismatched / not found / AI error)',
  nas_synced       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = images copied to the NAS and linked on mamabe_baby (pink_book / baby_health_book)',
  nas_note         VARCHAR(255) NULL COMMENT 'last NAS copy error, retried by worker.php',
  ip_address       VARCHAR(45)  NOT NULL COMMENT 'visitor IP (forwarded by the education server), used only for rate limiting',
  created_at       DATETIME     NOT NULL,
  checked_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_status_id (status, id),
  KEY idx_ip_created (ip_address, created_at),
  KEY idx_ic (ic),
  KEY idx_nas (nas_synced, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------------------------------------------------
-- 2. NEW ROW: api_user `mamabe_api`                                                                       (2026-09-24)
--    The education server's api/.env logs in to odb's API (/api/mamabeRegister.php) with this user.
--    Passwords in api_user are stored as SHA-256 (same as the other API users).
--
--    >>> BEFORE RUNNING: replace  PUT_THE_REAL_PASSWORD_HERE  with the same password that is in the education server's
--    >>> api/.env (ODB_API_PASSWORD). Do NOT commit this file with the real password in it.
--    Skipped automatically if `mamabe_api` already exists.
-- ---------------------------------------------------------------------------------------------------------------------
INSERT INTO api_user (username, password, platform, admin)
SELECT 'mamabe_api', SHA2('PUT_THE_REAL_PASSWORD_HERE', 256), 1, 0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM api_user WHERE username = 'mamabe_api');


-- ---------------------------------------------------------------------------------------------------------------------
-- 3. mamabe_mama - NO STRUCTURE CHANGE                                                                    (2026-09-24)
--    Rows created by the website form (only when the IC is not already in mamabe_mama) look like this:
--      registered_by = 'MamaBe website form'   <- used by the Mama List filter "Registered via website"
--      customer_id   = 0                       <- not linked to `customer` (the form never writes there)
--      outlet_id     = 0                       <- not registered at an outlet: only MamaBe Admins see them in the list
--    An existing mama (same IC) is only linked to - her details are never overwritten by the form.
--
-- 4. mamabe_baby - NO STRUCTURE CHANGE                                                                    (2026-09-24)
--    One row per pregnancy. Rows filled by the website form have:
--      lmp            = edd - 280 days         <- same rule the MamaBe page uses
--      preg_filled_by = 'MamaBe website form'  (and baby_filled_by when baby details were given)
--      pink_book / baby_health_book            <- NAS path, set by the worker after the AI check
--      benefit_mama_book / benefit_baby_book = 1 (+ _at) when that book is attached - same as the MamaBe page,
--                                              where uploading the book is what ticks it
--    Same mama + same EDD -> the existing row is reused; only empty fields are filled.
-- ---------------------------------------------------------------------------------------------------------------------


-- ---------------------------------------------------------------------------------------------------------------------
-- CHECK AFTER RUNNING (all should return a result)
-- ---------------------------------------------------------------------------------------------------------------------
SHOW TABLES LIKE 'mamabe_submission';
SELECT id, username, platform, admin FROM api_user WHERE username = 'mamabe_api';

-- DB user permissions: the odb DB user (common/index_adv.php) needs SELECT / INSERT / UPDATE on
-- mamabe_submission, mamabe_mama and mamabe_baby, and SELECT on api_user.
