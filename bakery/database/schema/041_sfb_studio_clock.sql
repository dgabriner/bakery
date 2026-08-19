-- 041 — Synthetic Studio clock, pace settings, and action log.
-- Cron (or the manager "Run tick") advances synthetic bakers through the
-- journal → complete → share → post loop at a configured interval.

CREATE TABLE IF NOT EXISTS sfb_studio_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  clock_enabled TINYINT(1) NOT NULL DEFAULT 1,
  min_interval_minutes INT NOT NULL DEFAULT 6,
  max_interval_minutes INT NOT NULL DEFAULT 10,
  max_actions_per_baker INT NOT NULL DEFAULT 3,
  max_bakers_per_tick INT NOT NULL DEFAULT 20,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by_user_id INT NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sfb_studio_settings
  (id, clock_enabled, min_interval_minutes, max_interval_minutes, max_actions_per_baker, max_bakers_per_tick)
VALUES (1, 1, 6, 10, 3, 20);

CREATE TABLE IF NOT EXISTS sfb_studio_clock (
  customer_id INT NOT NULL,
  next_action_at DATETIME NOT NULL,
  last_action_at DATETIME NULL DEFAULT NULL,
  last_action VARCHAR(40) NULL DEFAULT NULL,
  paused TINYINT(1) NOT NULL DEFAULT 0,
  actions_taken INT NOT NULL DEFAULT 0,
  PRIMARY KEY (customer_id),
  KEY idx_sfb_studio_clock_due (paused, next_action_at),
  CONSTRAINT fk_sfb_studio_clock_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_studio_action_log (
  id INT NOT NULL AUTO_INCREMENT,
  tick_id VARCHAR(40) NOT NULL,
  customer_id INT NOT NULL,
  action VARCHAR(40) NOT NULL,
  status ENUM('ok', 'skip', 'error') NOT NULL DEFAULT 'ok',
  summary VARCHAR(255) NOT NULL,
  detail_json TEXT NULL,
  batch_id INT NULL DEFAULT NULL,
  topic_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_studio_log_baker (customer_id, created_at),
  KEY idx_sfb_studio_log_tick (tick_id),
  KEY idx_sfb_studio_log_created (created_at),
  CONSTRAINT fk_sfb_studio_log_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
