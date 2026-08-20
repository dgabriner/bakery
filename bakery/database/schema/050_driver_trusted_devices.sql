-- Durable driver-only browser trust. The raw token lives only in the phone's
-- HttpOnly cookie; the database stores its SHA-256 hash for lookup/revocation.
CREATE TABLE IF NOT EXISTS driver_trusted_devices (
  id BIGINT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  user_agent VARCHAR(500) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_trusted_device_token (token_hash),
  KEY idx_driver_trusted_device_user (user_id, revoked_at, expires_at),
  KEY idx_driver_trusted_device_expiry (expires_at, revoked_at),
  CONSTRAINT fk_driver_trusted_device_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
