-- Short-lived, single-use customer portal invitations displayed as QR codes.
CREATE TABLE IF NOT EXISTS customer_qr_login_invites (
  id BIGINT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_by_user_id INT NULL,
  created_by_driver_id INT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_qr_login_token (token_hash),
  KEY idx_customer_qr_login_customer (customer_id, created_at),
  KEY idx_customer_qr_login_expiry (expires_at, used_at),
  CONSTRAINT fk_customer_qr_login_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_customer_qr_login_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_customer_qr_login_driver FOREIGN KEY (created_by_driver_id) REFERENCES drivers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
