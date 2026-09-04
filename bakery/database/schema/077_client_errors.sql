-- Client-side error beacons from shell.js (unhandledrejection / window.error).
-- Login-gated, rate-limited writes via client_error_api.php. No form values stored.

CREATE TABLE IF NOT EXISTS client_errors (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NULL DEFAULT NULL,
  login_audit_id BIGINT NULL DEFAULT NULL,
  kind VARCHAR(40) NOT NULL DEFAULT 'error',
  message VARCHAR(500) NOT NULL DEFAULT '',
  stack_head VARCHAR(2000) NULL DEFAULT NULL,
  page_path VARCHAR(1024) NULL DEFAULT NULL,
  page_href VARCHAR(1024) NULL DEFAULT NULL,
  build_id VARCHAR(64) NULL DEFAULT NULL,
  user_agent VARCHAR(512) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_client_errors_created (created_at),
  KEY idx_client_errors_user (user_id, created_at),
  KEY idx_client_errors_audit (login_audit_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
