-- Customer order power tools: single-date skips, date-range pauses, change requests

CREATE TABLE IF NOT EXISTS customer_delivery_skips (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  skip_date DATE NOT NULL COMMENT 'Delivery date the customer skipped',
  note VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_skip_date (customer_id, skip_date),
  CONSTRAINT fk_cds_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_delivery_pauses (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  pause_start DATE NOT NULL COMMENT 'First skipped delivery date (inclusive)',
  pause_end DATE NOT NULL COMMENT 'Last skipped delivery date (inclusive)',
  note VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cdp_customer_dates (customer_id, pause_start, pause_end),
  CONSTRAINT fk_cdp_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_change_requests (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  order_date DATE NOT NULL,
  daily_order_id INT DEFAULT NULL,
  request_type VARCHAR(64) NOT NULL DEFAULT 'delivery_change',
  message TEXT NOT NULL,
  status ENUM('pending', 'resolved', 'declined') NOT NULL DEFAULT 'pending',
  metadata JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_ccr_customer_date (customer_id, order_date),
  KEY idx_ccr_status (status),
  CONSTRAINT fk_ccr_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccr_daily_order FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
