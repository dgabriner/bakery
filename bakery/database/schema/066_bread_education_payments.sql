-- 066 — Education offerings and purchases (Prompt 26).
-- Money honesty: Square references only, prices snapshot at purchase time,
-- one row per attempt. 'intent' means recorded without a Square session
-- (credentials missing or API failure) — never a pretend paid state.

CREATE TABLE IF NOT EXISTS sfb_offerings (
  id INT NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  description TEXT NULL,
  price_cents INT NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  kind ENUM('class', 'membership', 'kit') NOT NULL DEFAULT 'class',
  entitlement_days INT NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_offering_purchases (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  offering_id INT NULL DEFAULT NULL,
  offering_title_snapshot VARCHAR(150) NOT NULL,
  price_cents_snapshot INT NOT NULL DEFAULT 0,
  currency_snapshot CHAR(3) NOT NULL DEFAULT 'USD',
  status ENUM('intent', 'pending', 'paid', 'refunded', 'canceled', 'failed') NOT NULL DEFAULT 'intent',
  square_payment_link_id VARCHAR(64) NULL DEFAULT NULL,
  square_order_id VARCHAR(64) NULL DEFAULT NULL,
  square_payment_id VARCHAR(64) NULL DEFAULT NULL,
  checkout_url VARCHAR(512) NULL DEFAULT NULL,
  manual_note VARCHAR(255) NULL DEFAULT NULL,
  actor_user_id INT NULL DEFAULT NULL,
  paid_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_purchases_customer (customer_id, status),
  KEY idx_sfb_purchases_order (square_order_id),
  KEY idx_sfb_purchases_payment (square_payment_id),
  CONSTRAINT fk_sfb_purchase_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
