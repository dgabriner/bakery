-- 070 — Physical sourdough starter jar sales (pickup or ship).
-- Money still rides sfb_offering_purchases + Square; this table holds
-- fulfillment details only. Seed titles are unique (067); prices stay
-- owner-editable in Learn admin.

INSERT IGNORE INTO sfb_offerings (title, description, price_cents, currency, kind, sort_order) VALUES
  (
    'Sourdough Starter — Bakery Pickup',
    'A living Sour Flour starter jar for pickup at the bakery on Tuesday or Friday. $5.',
    500,
    'USD',
    'kit',
    10
  ),
  (
    'Sourdough Starter — Shipped',
    'A living Sour Flour starter jar shipped to you. $25 includes shipping.',
    2500,
    'USD',
    'kit',
    11
  );

CREATE TABLE IF NOT EXISTS sfb_starter_jar_orders (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  purchase_id INT NOT NULL,
  fulfillment ENUM('pickup', 'ship') NOT NULL,
  pickup_day ENUM('tuesday', 'friday') NULL DEFAULT NULL,
  contact_name VARCHAR(120) NOT NULL,
  ship_line1 VARCHAR(150) NULL DEFAULT NULL,
  ship_line2 VARCHAR(150) NULL DEFAULT NULL,
  ship_city VARCHAR(80) NULL DEFAULT NULL,
  ship_state VARCHAR(40) NULL DEFAULT NULL,
  ship_zip VARCHAR(20) NULL DEFAULT NULL,
  notes VARCHAR(255) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_starter_jar_purchase (purchase_id),
  KEY idx_sfb_starter_jar_customer (customer_id),
  CONSTRAINT fk_sfb_starter_jar_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_starter_jar_purchase FOREIGN KEY (purchase_id) REFERENCES sfb_offering_purchases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
