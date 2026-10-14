-- A manager unassign is a dated decision. The next standing fill must not
-- copy that stop back. Explicit Restore deletes the row and adds the stop.
CREATE TABLE IF NOT EXISTS dated_route_removals (
  id INT NOT NULL AUTO_INCREMENT,
  delivery_date DATE NOT NULL,
  customer_id INT NOT NULL,
  driver_id INT NULL DEFAULT NULL,
  removed_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dated_route_removals_date_customer (delivery_date, customer_id),
  KEY idx_dated_route_removals_driver_date (driver_id, delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
