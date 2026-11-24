-- Route load estimates and missed pan dulce carry-forward.
-- Hosted-gate portable: CREATE TABLE IF NOT EXISTS and INSERT IGNORE only.
-- Straight-line drive time uses coordinates already stored on customers.
-- No map API and no new secret.
-- Service overrides live here, not on customers, so the old delivery_time
-- default of 20 minutes does not hide learned history.

CREATE TABLE IF NOT EXISTS route_load_settings (
  id TINYINT NOT NULL,
  finish_after TIME NOT NULL DEFAULT '17:00:00',
  max_stops INT NOT NULL DEFAULT 15,
  default_service_minutes INT NOT NULL DEFAULT 20,
  default_drive_minutes INT NOT NULL DEFAULT 15,
  min_drive_samples INT NOT NULL DEFAULT 3,
  min_service_samples INT NOT NULL DEFAULT 1,
  route_start TIME NOT NULL DEFAULT '08:00:00',
  drive_speed_mph DECIMAL(6,2) NOT NULL DEFAULT 15.00,
  outlier_trim_percent INT NOT NULL DEFAULT 10,
  bulk_close_minutes INT NOT NULL DEFAULT 2,
  lookback_days INT NOT NULL DEFAULT 90,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO route_load_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS route_service_overrides (
  customer_id INT NOT NULL,
  minutes INT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_service_stats (
  customer_id INT NOT NULL,
  median_minutes DECIMAL(8,2) NOT NULL,
  sample_count INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_drive_stats (
  from_customer_id INT NOT NULL,
  to_customer_id INT NOT NULL,
  median_minutes DECIMAL(8,2) NOT NULL,
  sample_count INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (from_customer_id, to_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_carry_forwards (
  id INT NOT NULL AUTO_INCREMENT,
  source_date DATE NOT NULL,
  source_assignment_id INT NULL DEFAULT NULL,
  customer_id INT NOT NULL,
  driver_id INT NOT NULL,
  route_order INT NOT NULL DEFAULT 0,
  target_date DATE NOT NULL,
  target_daily_order_id INT NULL DEFAULT NULL,
  target_assignment_id INT NULL DEFAULT NULL,
  outcome VARCHAR(32) NOT NULL,
  status ENUM('active','removed') NOT NULL DEFAULT 'active',
  restored_standing TINYINT(1) NOT NULL DEFAULT 0,
  removed_at DATETIME NULL DEFAULT NULL,
  removed_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_route_carry_source_customer (source_date, customer_id),
  KEY idx_route_carry_target (target_date, customer_id),
  KEY idx_route_carry_driver_target (driver_id, target_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
