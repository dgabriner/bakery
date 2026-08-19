-- Canonical invoice send metadata. Invoice identity remains INV-YYYYMMDD-{orderId}.
-- daily_orders last-send columns are added by scripts/run_migrations.php (049).
-- One outbox row per send attempt (including MAIL_DRIVER=log).

CREATE TABLE IF NOT EXISTS billing_invoice_sends (
  id INT NOT NULL AUTO_INCREMENT,
  daily_order_id INT NOT NULL,
  invoice_number VARCHAR(40) NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_by_user_id INT NULL DEFAULT NULL,
  sent_to_email VARCHAR(255) NULL DEFAULT NULL,
  channel VARCHAR(16) NOT NULL DEFAULT 'log',
  status VARCHAR(16) NOT NULL DEFAULT 'logged',
  PRIMARY KEY (id),
  KEY idx_billing_invoice_sends_order (daily_order_id),
  KEY idx_billing_invoice_sends_sent (sent_at),
  CONSTRAINT fk_billing_invoice_sends_order FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_invoice_sends_user FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
