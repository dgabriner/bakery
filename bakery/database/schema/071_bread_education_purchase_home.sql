-- 071 — Purchase Home: private workshops, gift certificates for Starter Workshop.
-- Money still rides sfb_offering_purchases + Square. Variable workshop totals
-- snapshot at purchase time. Gift codes redeem only against Starter Workshop.

ALTER TABLE sfb_offerings
  MODIFY COLUMN kind ENUM('class', 'membership', 'kit', 'donation', 'credits', 'gift') NOT NULL DEFAULT 'class';

ALTER TABLE sfb_offering_purchases
  MODIFY COLUMN paid_with ENUM('square', 'credit', 'manual', 'gift') NULL DEFAULT NULL;

INSERT IGNORE INTO sfb_offerings (title, description, price_cents, currency, kind, sort_order) VALUES
  (
    'Gift Certificate — Starter Workshop',
    'A gift certificate for one seat in the Starter Workshop ($80). Share the code after payment clears.',
    8000,
    'USD',
    'gift',
    5
  );

CREATE TABLE IF NOT EXISTS sfb_private_workshop_bookings (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  purchase_id INT NOT NULL,
  workshop_type ENUM('starter', 'pizza') NOT NULL DEFAULT 'starter',
  headcount INT NOT NULL,
  bites TINYINT(1) NOT NULL DEFAULT 0,
  drinks TINYINT(1) NOT NULL DEFAULT 0,
  contact_name VARCHAR(120) NOT NULL,
  preferred_date VARCHAR(40) NULL DEFAULT NULL,
  notes VARCHAR(255) NULL DEFAULT NULL,
  price_cents_snapshot INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_private_ws_purchase (purchase_id),
  KEY idx_sfb_private_ws_customer (customer_id),
  CONSTRAINT fk_sfb_private_ws_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_private_ws_purchase FOREIGN KEY (purchase_id) REFERENCES sfb_offering_purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_gift_certificates (
  id INT NOT NULL AUTO_INCREMENT,
  purchase_id INT NOT NULL,
  buyer_customer_id INT NOT NULL,
  code VARCHAR(24) NOT NULL,
  amount_cents INT NOT NULL DEFAULT 8000,
  for_offering_title VARCHAR(150) NOT NULL DEFAULT 'Starter Workshop',
  status ENUM('pending', 'available', 'redeemed', 'canceled') NOT NULL DEFAULT 'pending',
  redeemed_purchase_id INT NULL DEFAULT NULL,
  redeemed_customer_id INT NULL DEFAULT NULL,
  recipient_name VARCHAR(120) NULL DEFAULT NULL,
  redeemed_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_gift_code (code),
  UNIQUE KEY uq_sfb_gift_purchase (purchase_id),
  KEY idx_sfb_gift_buyer (buyer_customer_id),
  KEY idx_sfb_gift_status (status),
  CONSTRAINT fk_sfb_gift_buyer FOREIGN KEY (buyer_customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_gift_purchase FOREIGN KEY (purchase_id) REFERENCES sfb_offering_purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
