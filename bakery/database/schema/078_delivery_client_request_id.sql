-- Idempotent client request ids for offline driver outbox (photo upload + confirm).
-- Hosted-gate portable: ADD COLUMN IF NOT EXISTS + unique indexes IF NOT EXISTS.
-- owner-approved-core-column: Mission 43 driver-offline-queue (program Decided) — confirm_request_id on daily_orders for outbox idempotency.

ALTER TABLE daily_orders
  ADD COLUMN IF NOT EXISTS confirm_request_id VARCHAR(64) NULL DEFAULT NULL;

ALTER TABLE driver_photos
  ADD COLUMN IF NOT EXISTS client_request_id VARCHAR(64) NULL DEFAULT NULL;

-- Unique when set; multiple NULLs are allowed in MariaDB/MySQL unique indexes.
CREATE UNIQUE INDEX IF NOT EXISTS uq_daily_orders_confirm_request_id
  ON daily_orders (confirm_request_id);

CREATE UNIQUE INDEX IF NOT EXISTS uq_driver_photos_client_request_id
  ON driver_photos (client_request_id);
