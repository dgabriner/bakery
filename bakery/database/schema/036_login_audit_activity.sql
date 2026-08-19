-- Time-stamped navigation events inside a signed-in session.
-- This intentionally records navigation only; sensitive form values and page content are never stored.

CREATE TABLE IF NOT EXISTS login_audit_activity (
  id BIGINT NOT NULL AUTO_INCREMENT,
  login_audit_id BIGINT NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  page_path VARCHAR(1024) NULL DEFAULT NULL,
  page_title VARCHAR(255) NULL DEFAULT NULL,
  client_metadata JSON NULL DEFAULT NULL,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_audit_activity_session (login_audit_id, occurred_at),
  KEY idx_login_audit_activity_time (occurred_at),
  CONSTRAINT fk_login_audit_activity_session
    FOREIGN KEY (login_audit_id) REFERENCES login_audit(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
