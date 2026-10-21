-- Mamabe: upgrade an existing mamabe_submission table to link the rows of one registration request together
-- (the pink + baby rows of a register_type=both submission, or twin/multi-baby rows), so the admin list can group
-- and display them as one registration event (one row per person, Submitted column spans the group).
-- Run once per deployment that already has the table; a brand new deployment runs mamabe_submission.sql only
-- (it already has this column).
ALTER TABLE mamabe_submission
  ADD COLUMN batch_id INT UNSIGNED NULL COMMENT 'links the rows of one registration request together (the pink + baby rows of a register_type=both submission, or twin/multi-baby rows) - equals the id of the first row inserted in the request; NULL = never grouped (standalone pink/baby row)' AFTER mama_is_new,
  ADD KEY idx_batch (batch_id);
