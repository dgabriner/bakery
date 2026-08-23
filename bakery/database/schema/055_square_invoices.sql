-- Square Invoices linkage for non-COD Billing Center sends.
-- Reversible: DROP the square_* columns / tables after clearing values.

ALTER TABLE customers
  ADD COLUMN square_customer_id VARCHAR(64) NULL DEFAULT NULL AFTER payment_collection;

ALTER TABLE daily_orders
  ADD COLUMN square_invoice_id VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_order_id VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_customer_id VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_public_url VARCHAR(512) NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_status VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_recipient_email VARCHAR(255) NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_published_at DATETIME NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_paid_at DATETIME NULL DEFAULT NULL;
ALTER TABLE daily_orders
  ADD COLUMN square_last_synced_at DATETIME NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS square_webhook_events (
  id INT NOT NULL AUTO_INCREMENT,
  event_id VARCHAR(80) NOT NULL,
  event_type VARCHAR(80) NULL DEFAULT NULL,
  square_invoice_id VARCHAR(64) NULL DEFAULT NULL,
  daily_order_id INT NULL DEFAULT NULL,
  processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_square_webhook_event_id (event_id),
  KEY idx_square_webhook_invoice (square_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
