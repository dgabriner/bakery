-- Manager closeout record for an operating date (administrative fact, not a workflow lock).
CREATE TABLE IF NOT EXISTS operating_day_closeouts (
  operating_date DATE NOT NULL,
  closed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_by_user_id INT NULL DEFAULT NULL,
  manager_note TEXT NULL,
  reopened_at TIMESTAMP NULL DEFAULT NULL,
  reopened_by_user_id INT NULL DEFAULT NULL,
  PRIMARY KEY (operating_date),
  KEY idx_operating_day_closeouts_closed_at (closed_at),
  CONSTRAINT fk_operating_day_closeout_user FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_operating_day_closeout_reopened_user FOREIGN KEY (reopened_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
