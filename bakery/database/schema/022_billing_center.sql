-- Billing Center: audit trail, statement history, accounting export tracking.

CREATE TABLE IF NOT EXISTS audit_log (
  id INT NOT NULL AUTO_INCREMENT,
  action VARCHAR(64) NOT NULL,
  entity VARCHAR(64) NOT NULL,
  entity_id INT NULL DEFAULT NULL,
  details TEXT NULL,
  user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_log_entity (entity, entity_id),
  KEY idx_audit_log_action (action),
  KEY idx_audit_log_created (created_at),
  CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_statements (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  statement_date DATE NOT NULL,
  invoice_count INT NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id INT NULL DEFAULT NULL,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  sent_by_user_id INT NULL DEFAULT NULL,
  sent_to_email VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_billing_statements_customer (customer_id),
  KEY idx_billing_statements_period (period_start, period_end),
  KEY idx_billing_statements_sent (sent_at),
  CONSTRAINT fk_billing_statements_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_statements_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_billing_statements_sent_by FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_exports (
  id INT NOT NULL AUTO_INCREMENT,
  export_key VARCHAR(64) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  row_count INT NOT NULL DEFAULT 0,
  invoice_count INT NOT NULL DEFAULT 0,
  content_hash CHAR(64) NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id INT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_billing_exports_key (export_key),
  KEY idx_billing_exports_period (period_start, period_end),
  KEY idx_billing_exports_created (created_at),
  CONSTRAINT fk_billing_exports_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS billing_export_invoices (
  export_id INT NOT NULL,
  daily_order_id INT NOT NULL,
  PRIMARY KEY (export_id, daily_order_id),
  KEY idx_billing_export_invoices_order (daily_order_id),
  CONSTRAINT fk_billing_export_invoices_export FOREIGN KEY (export_id) REFERENCES billing_exports(id) ON DELETE CASCADE,
  CONSTRAINT fk_billing_export_invoices_order FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
