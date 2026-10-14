-- Administrator sign-in from an IP or device not seen in 30 days.
-- The dashboard reads this table. Nothing is sent off the server.

CREATE TABLE IF NOT EXISTS admin_signin_alerts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  login_audit_id BIGINT NULL DEFAULT NULL,
  ip_address VARCHAR(45) NULL DEFAULT NULL,
  user_agent VARCHAR(1000) NULL DEFAULT NULL,
  device_label VARCHAR(160) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dismissed_at DATETIME NULL DEFAULT NULL,
  dismissed_by_user_id INT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_admin_signin_alerts_open (dismissed_at, created_at),
  KEY idx_admin_signin_alerts_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
