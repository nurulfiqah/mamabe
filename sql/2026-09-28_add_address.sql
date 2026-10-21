-- =====================================================================================================================
-- MamaBe — database changes for PRODUCTION
-- Date      : 2026-09-28
-- Feature   : MamaBe website registration — mama's address added to the form
-- Database  : odb
-- Requires  : 2026-09-24_website_registration.sql run first (creates mamabe_submission)
--
-- SUMMARY OF CHANGES
--   1. NEW COLUMN  mamabe_submission.address   - the address as typed on the website form
--   2. NO CHANGE   mamabe_mama                 - already has `address` (TEXT); new website mamas now get it filled
--
-- Safe to re-run: the column is only added when it isn't there yet.
-- =====================================================================================================================

-- 1. NEW COLUMN: mamabe_submission.address                                                                 (2026-09-28)
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mamabe_submission' AND COLUMN_NAME = 'address');
SET @sql := IF(@has_col = 0,
    "ALTER TABLE mamabe_submission ADD COLUMN address TEXT NOT NULL COMMENT 'mama address as typed (saved to mamabe_mama.address for a new mama)' AFTER phone",
    'SELECT ''address already exists'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- CHECK AFTER RUNNING (should return one row)
SHOW COLUMNS FROM mamabe_submission LIKE 'address';
