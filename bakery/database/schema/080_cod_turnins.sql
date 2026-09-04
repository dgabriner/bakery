-- Mission 61: COD cash turn-in per driver per operating day.
-- Prefixed side table; FK to drivers. No new daily_orders columns.
CREATE TABLE IF NOT EXISTS cod_turnins (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  turnin_date DATE NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  recorded_by_user_id INT NULL DEFAULT NULL,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cod_turnins_driver_date (driver_id, turnin_date),
  KEY idx_cod_turnins_date (turnin_date),
  CONSTRAINT fk_cod_turnins_driver FOREIGN KEY (driver_id) REFERENCES drivers(id),
  CONSTRAINT fk_cod_turnins_user FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
