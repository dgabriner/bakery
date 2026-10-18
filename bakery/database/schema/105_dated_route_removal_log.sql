-- Append-only record of every stop taken off a dated route.
-- dated_route_removals is the refill tombstone. This log keeps who, when,
-- why, and which code path, and it stays after Restore clears the tombstone.
CREATE TABLE IF NOT EXISTS dated_route_removal_log (
  id BIGINT NOT NULL AUTO_INCREMENT,
  delivery_date DATE NOT NULL,
  customer_id INT NOT NULL,
  daily_order_id INT NULL DEFAULT NULL,
  driver_id INT NULL DEFAULT NULL,
  removed_by_user_id INT NULL DEFAULT NULL,
  removed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(64) NOT NULL,
  code_path VARCHAR(128) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_dated_route_removal_log_date_driver (delivery_date, driver_id),
  KEY idx_dated_route_removal_log_customer_date (customer_id, delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
