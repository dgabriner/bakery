-- Idempotent client request ids for offline driver outbox (photo upload + confirm).
-- MySQL 8 / hosted-gate portable: plain ADD COLUMN + CREATE UNIQUE INDEX (no IF NOT EXISTS).
-- owner-approved-core-column: Mission 43 driver-offline-queue (program Decided) — confirm_request_id on daily_orders for outbox idempotency.
-- schema_migrations tracks apply-once; do not re-run after recorded.

ALTER TABLE daily_orders
  ADD COLUMN confirm_request_id VARCHAR(64) NULL DEFAULT NULL;

ALTER TABLE driver_photos
  ADD COLUMN client_request_id VARCHAR(64) NULL DEFAULT NULL;

CREATE UNIQUE INDEX uq_daily_orders_confirm_request_id
  ON daily_orders (confirm_request_id);

CREATE UNIQUE INDEX uq_driver_photos_client_request_id
  ON driver_photos (client_request_id);
