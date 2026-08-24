-- 067 — Offerings v2: donations, Bread Education Credits, Starter Workshop seed.
-- Widens the kind enum (hosted-safe MODIFY ... ENUM), adds credit units and a
-- per-purchase payment channel, plus one small ledger for credit balances.
-- Seeds are idempotent via the new unique title key; prices stay owner-editable.

ALTER TABLE sfb_offerings
  MODIFY COLUMN kind ENUM('class', 'membership', 'kit', 'donation', 'credits') NOT NULL DEFAULT 'class',
  ADD COLUMN units INT NULL DEFAULT NULL,
  ADD UNIQUE KEY uq_sfb_offerings_title (title);

ALTER TABLE sfb_offering_purchases
  ADD COLUMN paid_with ENUM('square', 'credit', 'manual') NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS sfb_credit_entries (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  delta INT NOT NULL,
  reason VARCHAR(120) NOT NULL DEFAULT 'purchase',
  purchase_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_credit_customer (customer_id),
  CONSTRAINT fk_sfb_credit_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sfb_offerings (title, description, price_cents, currency, kind, sort_order) VALUES
  ('Starter Workshop', 'Hands-on sourdough starter workshop at Sour Flour. Leave with a living starter and the schedule to keep it strong.', 8000, 'USD', 'class', 1),
  ('Donate $10', 'Support free bread education for the community.', 1000, 'USD', 'donation', 90),
  ('Donate $25', 'Support free bread education for the community.', 2500, 'USD', 'donation', 91),
  ('Donate $50', 'Support free bread education for the community.', 5000, 'USD', 'donation', 92);
