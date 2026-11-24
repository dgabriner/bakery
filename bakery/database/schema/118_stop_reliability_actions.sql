-- Acknowledge and snooze log for Driver Manager stop-reliability flags.
-- 114 and 115 are Live-only ledger rows and are not files.
-- 116 is reserved by open draft dgabriner/bakery#111.
-- 117 is reserved by open draft dgabriner/bakery#113.
-- This file is 118. It does not change how dated orders are generated.

CREATE TABLE IF NOT EXISTS stop_reliability_actions (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  action ENUM('acknowledge', 'snooze') NOT NULL,
  note VARCHAR(240) NOT NULL,
  snooze_until DATE NULL DEFAULT NULL,
  flag_fingerprint CHAR(40) NOT NULL,
  user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_stop_reliability_customer (customer_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
