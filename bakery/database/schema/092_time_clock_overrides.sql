-- Manager corrections keep the original punch and replace the working times.
-- Hosted-gate portable: additive ALTER TABLE ... ADD only.

ALTER TABLE time_clock_punches
  ADD COLUMN original_clock_in_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN original_clock_out_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN override_note VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN overridden_by_user_id INT NULL DEFAULT NULL,
  ADD COLUMN overridden_at DATETIME NULL DEFAULT NULL,
  ADD KEY idx_time_clock_overridden_by (overridden_by_user_id);
