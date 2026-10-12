-- Cashier time clock. One open punch per person.
-- Hosted-gate portable: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS time_clock_punches (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  clock_in_at DATETIME NOT NULL,
  clock_out_at DATETIME NULL DEFAULT NULL,
  open_user_id INT GENERATED ALWAYS AS (CASE WHEN clock_out_at IS NULL THEN user_id ELSE NULL END) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_time_clock_open_user (open_user_id),
  KEY idx_time_clock_in (clock_in_at),
  CONSTRAINT fk_time_clock_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
