-- First route assignment of a delivery date, per driver and store.
-- Later Daily Run reads compare this baseline. They do not insert rows.
CREATE TABLE IF NOT EXISTS dated_route_morning_checks (
  id BIGINT NOT NULL AUTO_INCREMENT,
  delivery_date DATE NOT NULL,
  driver_id INT NOT NULL,
  customer_id INT NOT NULL,
  checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dated_route_morning_date_driver_customer (delivery_date, driver_id, customer_id),
  KEY idx_dated_route_morning_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
