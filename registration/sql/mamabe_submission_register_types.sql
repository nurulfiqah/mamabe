-- Mamabe: upgrade an existing mamabe_submission table (created from the older mamabe_submission.sql) for the
-- two-way form - register by pink book or by baby health book, "how do you know us", preferred outlet.
-- Run once per deployment that already has the table; a new deployment runs mamabe_submission.sql only.
ALTER TABLE mamabe_submission
  ADD COLUMN register_type VARCHAR(4) NOT NULL DEFAULT 'pink' COMMENT 'pink = pink book (EDD), baby = baby health book (baby name, DOB, MyKid)' AFTER mama_is_new,
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'how she knows us: outlet / insurance / social_media' AFTER register_type,
  ADD COLUMN preferred_outlet_id INT UNSIGNED NULL COMMENT 'outlet.id she prefers to redeem at (also saved as mamabe_mama.outlet_id when that is still 0)' AFTER source,
  MODIFY ic VARCHAR(14) NOT NULL DEFAULT '' COMMENT 'mama IC / passport as typed (letters+digits only)',
  MODIFY address TEXT NOT NULL COMMENT 'mama address as typed (fills mamabe_mama.address when empty)',
  MODIFY edd DATE NOT NULL COMMENT 'expected delivery date (baby health book: the baby DOB)',
  MODIFY pink_file VARCHAR(64) NULL COMMENT 'pink book image inside uploads/ (random, re-encoded JPEG) - pink registrations',
  MODIFY baby_file VARCHAR(64) NULL COMMENT 'baby health book image inside uploads/ - baby registrations',
  MODIFY mama_is_new TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = this submission created the mamabe_mama row, 0 = matched an existing one',
  DROP INDEX idx_ic,
  ADD KEY idx_phone (phone);
