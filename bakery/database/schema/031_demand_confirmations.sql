-- 031 — Demand confirmations ("Tomorrow, confirmed" ritual)
-- One row per operating date: a manager reviewed the demand for the date
-- and marked it ready for the next stage. Counts are a snapshot of what
-- was confirmed; drift after confirmation is derived from operational_events.
CREATE TABLE IF NOT EXISTS demand_confirmations (
  operating_date DATE NOT NULL,
  confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_by_user_id INT NULL DEFAULT NULL,
  customers_count INT NOT NULL DEFAULT 0,
  units_count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (operating_date),
  KEY idx_demand_confirmations_confirmed_at (confirmed_at),
  CONSTRAINT fk_demand_confirmation_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
