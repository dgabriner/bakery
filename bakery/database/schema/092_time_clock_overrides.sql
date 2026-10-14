-- Manager corrections keep the original punch and replace the working times.
-- Hosted-gate portable: one additive ADD per statement.
-- Live already created these columns as 086_time_clock_overrides.
-- A repeat is a no-op: the runner confirms a duplicate column or key and continues.

ALTER TABLE time_clock_punches
  ADD COLUMN original_clock_in_at DATETIME NULL DEFAULT NULL;

ALTER TABLE time_clock_punches
  ADD COLUMN original_clock_out_at DATETIME NULL DEFAULT NULL;

ALTER TABLE time_clock_punches
  ADD COLUMN override_note VARCHAR(255) NULL DEFAULT NULL;

ALTER TABLE time_clock_punches
  ADD COLUMN overridden_by_user_id INT NULL DEFAULT NULL;

ALTER TABLE time_clock_punches
  ADD COLUMN overridden_at DATETIME NULL DEFAULT NULL;

ALTER TABLE time_clock_punches
  ADD KEY idx_time_clock_overridden_by (overridden_by_user_id);
