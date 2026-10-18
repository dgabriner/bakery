-- When the photo was taken, and whether that time came from the phone or EXIF.
-- GPS stays on latitude and longitude. Later reads do not write these columns.

ALTER TABLE driver_photos
  ADD COLUMN captured_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN capture_source VARCHAR(16) NULL DEFAULT NULL;
