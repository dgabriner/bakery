-- Add archive support for drivers (soft-hide inactive drivers from operational UI)
ALTER TABLE drivers
  ADD COLUMN archived TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
  ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER archived;

CREATE INDEX idx_drivers_archived ON drivers (archived);
