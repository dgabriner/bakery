-- 051 — Live ops catch-up for schema that Staging already has (037–047).
-- Additive catch-up for bakerysf. Safe to run once; do not re-run after success.

-- From 037_route_closeout
ALTER TABLE inventory_movements
  MODIFY COLUMN movement_type
  ENUM('production','count','load','load_correction','return','waste','delivery') NOT NULL;

ALTER TABLE driver_load_items
  ADD COLUMN wasted_quantity INT NOT NULL DEFAULT 0 AFTER returned_quantity;

ALTER TABLE driver_loads
  ADD COLUMN reconciled_at TIMESTAMP NULL DEFAULT NULL AFTER status,
  ADD COLUMN reconciled_by_user_id INT NULL DEFAULT NULL AFTER reconciled_at;

-- From 038_manager_exception_and_delivery_recovery
CREATE TABLE IF NOT EXISTS manager_exception_work (
  exception_key CHAR(64) NOT NULL,
  operating_date DATE NOT NULL,
  exception_type VARCHAR(100) NOT NULL,
  exception_category VARCHAR(64) NOT NULL,
  acknowledged_at TIMESTAMP NULL DEFAULT NULL,
  acknowledged_by_user_id INT NULL DEFAULT NULL,
  assigned_to_user_id INT NULL DEFAULT NULL,
  due_at DATETIME NULL DEFAULT NULL,
  resolution_note TEXT NULL DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  completed_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (exception_key),
  KEY idx_manager_exception_work_date (operating_date, completed_at, due_at),
  KEY idx_manager_exception_work_assignee (assigned_to_user_id, completed_at),
  CONSTRAINT fk_manager_exception_work_ack_user
    FOREIGN KEY (acknowledged_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_manager_exception_work_assigned_user
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_manager_exception_work_completed_user
    FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_recovery_cases (
  id BIGINT NOT NULL AUTO_INCREMENT,
  failed_assignment_id INT NOT NULL,
  active_assignment_id INT NULL DEFAULT NULL,
  daily_order_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  original_driver_id INT NULL DEFAULT NULL,
  reassigned_to_driver_id INT NULL DEFAULT NULL,
  failure_reason VARCHAR(40) NOT NULL,
  manager_note TEXT NOT NULL,
  workflow_state ENUM('open','acknowledged','retry_scheduled','reassigned','resolved','closed') NOT NULL DEFAULT 'open',
  retry_at DATETIME NULL DEFAULT NULL,
  customer_communication_status ENUM('not_needed','pending','contacted','unable_to_reach') NOT NULL DEFAULT 'pending',
  customer_communication_note TEXT NULL DEFAULT NULL,
  billing_handoff ENUM('not_needed','review_needed','credit_requested','credit_issued','not_billable') NOT NULL DEFAULT 'review_needed',
  resolution_note TEXT NULL DEFAULT NULL,
  acknowledged_at TIMESTAMP NULL DEFAULT NULL,
  acknowledged_by_user_id INT NULL DEFAULT NULL,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  resolved_by_user_id INT NULL DEFAULT NULL,
  closed_at TIMESTAMP NULL DEFAULT NULL,
  closed_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_delivery_recovery_failed_assignment (failed_assignment_id),
  KEY idx_delivery_recovery_day_state (delivery_date, workflow_state),
  KEY idx_delivery_recovery_order (daily_order_id),
  CONSTRAINT fk_delivery_recovery_order
    FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_recovery_original_driver
    FOREIGN KEY (original_driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_delivery_recovery_reassigned_driver
    FOREIGN KEY (reassigned_to_driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_delivery_recovery_ack_user
    FOREIGN KEY (acknowledged_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_delivery_recovery_resolved_user
    FOREIGN KEY (resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_delivery_recovery_closed_user
    FOREIGN KEY (closed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- From 041_sfb_studio_clock
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

-- From 044_agent_homebase
CREATE TABLE IF NOT EXISTS agent_lessons (
  id INT NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NOT NULL,
  track ENUM('product','practices','bugs','craft') NOT NULL DEFAULT 'product',
  title VARCHAR(180) NOT NULL,
  summary VARCHAR(400) NOT NULL DEFAULT '',
  body_md MEDIUMTEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_lessons_slug (slug),
  KEY idx_agent_lessons_track (track, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_lesson_progress (
  id INT NOT NULL AUTO_INCREMENT,
  agent_name VARCHAR(120) NOT NULL,
  lesson_id INT NOT NULL,
  notes TEXT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_lesson_progress (agent_name, lesson_id),
  KEY idx_agent_lesson_progress_lesson (lesson_id),
  CONSTRAINT fk_agent_lesson_progress_lesson
    FOREIGN KEY (lesson_id) REFERENCES agent_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  agent_name VARCHAR(120) NOT NULL,
  mission VARCHAR(240) NOT NULL DEFAULT '',
  status ENUM('open','handed_off','abandoned') NOT NULL DEFAULT 'open',
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at TIMESTAMP NULL DEFAULT NULL,
  files_touched TEXT NULL,
  handoff_md MEDIUMTEXT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_agent_sessions_open (status, started_at),
  KEY idx_agent_sessions_agent (agent_name, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_whiteboard (
  id INT NOT NULL AUTO_INCREMENT,
  column_key ENUM('now','next','decided','parked') NOT NULL DEFAULT 'now',
  title VARCHAR(180) NOT NULL,
  body TEXT NOT NULL,
  agent_name VARCHAR(120) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  archived_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_whiteboard_board (archived_at, column_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_bugs (
  id INT NOT NULL AUTO_INCREMENT,
  slug VARCHAR(80) NULL DEFAULT NULL,
  title VARCHAR(180) NOT NULL,
  detail TEXT NOT NULL,
  severity ENUM('critical','watch','broken-window') NOT NULL DEFAULT 'watch',
  status ENUM('open','watching','fixed','wont-fix') NOT NULL DEFAULT 'open',
  focus_area VARCHAR(80) NOT NULL DEFAULT 'ops',
  source VARCHAR(80) NOT NULL DEFAULT 'homebase',
  agent_name VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_agent_bugs_slug (slug),
  KEY idx_agent_bugs_status (status, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_notes (
  id INT NOT NULL AUTO_INCREMENT,
  kind ENUM('insight','question','coach') NOT NULL DEFAULT 'insight',
  title VARCHAR(180) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  agent_name VARCHAR(120) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_agent_notes_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- From 046_assignment_cancelled_status
ALTER TABLE daily_order_assignments
  MODIFY COLUMN delivery_status
    ENUM('pending','in_transit','delivered','failed','cancelled','rescheduled')
    NULL DEFAULT 'pending';

UPDATE daily_order_assignments
SET delivery_status = 'cancelled'
WHERE (delivery_status IS NULL OR delivery_status = '')
  AND notes LIKE 'Skipped:%';

UPDATE daily_order_assignments
SET delivery_status = 'pending'
WHERE delivery_status IS NULL OR delivery_status = '';

ALTER TABLE daily_order_assignments
  MODIFY COLUMN delivery_status
    ENUM('pending','in_transit','delivered','failed','cancelled','rescheduled')
    NOT NULL DEFAULT 'pending';

-- From 047_unique_dated_route_positions
UPDATE daily_order_assignments doa
JOIN (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY driver_id, delivery_date
               ORDER BY route_order, id
           ) AS normalized_route_order
    FROM daily_order_assignments
) ranked ON ranked.id = doa.id
SET doa.route_order = ranked.normalized_route_order;

CREATE UNIQUE INDEX uq_assignment_driver_date_route_order
  ON daily_order_assignments (driver_id, delivery_date, route_order);

-- Align the migration ledger with Staging's historical IDs.
INSERT IGNORE INTO schema_migrations (id) VALUES
  ('037_route_closeout'),
  ('038_manager_exception_and_delivery_recovery'),
  ('041_sfb_studio_clock'),
  ('044_agent_homebase'),
  ('046_assignment_cancelled_status'),
  ('047_unique_dated_route_positions');
