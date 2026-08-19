-- Manager exception ownership plus auditable failed-stop recovery.
-- All operational delivery and billing facts remain in their existing tables.

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
