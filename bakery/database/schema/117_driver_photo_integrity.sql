-- Photo integrity, stored once at upload.
-- The Nov 17 spec asked for 114. Live schema_migrations already has
-- 114_user_112_role_probe and 115_user_112_sales_role, which are not files
-- in git. Draft #111 uses 116_route_closeout_source.sql. This file is 117.
-- 107 stays unused for draft #78.
-- captured_at and capture_source are the capture columns from that draft's
-- 107_driver_photo_capture.sql. GPS stays on latitude and longitude.
-- Old rows keep NULL sha256 and NULL flags. Readers treat that as unflagged.
-- schema_migrations records this id once. Do not re-run the ALTER after that.

ALTER TABLE driver_photos
  ADD COLUMN sha256 CHAR(64) NULL DEFAULT NULL,
  ADD COLUMN integrity_flags JSON NULL DEFAULT NULL,
  ADD COLUMN captured_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN capture_source VARCHAR(16) NULL DEFAULT NULL,
  ADD INDEX idx_driver_photos_sha256 (sha256);
