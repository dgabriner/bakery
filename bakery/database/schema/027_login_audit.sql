-- Login, device, optional location, and session-duration audit trail.
-- Keep this separate from operational_events: it is restricted to administrators.

CREATE TABLE IF NOT EXISTS login_audit (
  id BIGINT NOT NULL AUTO_INCREMENT,
  auth_type ENUM('staff', 'customer') NOT NULL,
  user_id INT NULL DEFAULT NULL,
  customer_id INT NULL DEFAULT NULL,
  principal VARCHAR(255) NOT NULL,
  outcome ENUM('success', 'failure') NOT NULL,
  failure_reason VARCHAR(100) NULL DEFAULT NULL,
  login_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  logout_at TIMESTAMP NULL DEFAULT NULL,
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL DEFAULT NULL,
  user_agent TEXT NULL,
  browser VARCHAR(100) NULL DEFAULT NULL,
  operating_system VARCHAR(100) NULL DEFAULT NULL,
  device_type VARCHAR(50) NULL DEFAULT NULL,
  client_metadata JSON NULL DEFAULT NULL,
  location_status ENUM('not_requested', 'granted', 'denied', 'unavailable', 'error') NOT NULL DEFAULT 'not_requested',
  gps_latitude DECIMAL(10,8) NULL DEFAULT NULL,
  gps_longitude DECIMAL(11,8) NULL DEFAULT NULL,
  gps_accuracy_m DECIMAL(8,2) NULL DEFAULT NULL,
  location_captured_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_login_audit_time (login_at),
  KEY idx_login_audit_user (auth_type, user_id, login_at),
  KEY idx_login_audit_customer (auth_type, customer_id, login_at),
  KEY idx_login_audit_outcome (outcome, login_at),
  CONSTRAINT fk_login_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_login_audit_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
