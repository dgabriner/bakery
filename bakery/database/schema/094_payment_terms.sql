-- Payment terms decide COD cash versus an invoice or a check.
-- Existing signature accounts are invoice terms. COD stays the default.
-- Store credit is one negative line so a new confirm can match its header.
-- Historical invoices are not rewritten here.
-- schema_migrations tracks apply-once. Do not re-run after recorded.
-- owner-approved-core-column: terms choose the delivery path, and a credit needs its own line.

ALTER TABLE customers
  ADD COLUMN payment_terms ENUM('cod', 'invoice', 'check') NOT NULL DEFAULT 'cod';

UPDATE customers
   SET payment_terms = 'invoice'
 WHERE payment_collection = 'signature';

ALTER TABLE daily_order_items
  ADD COLUMN is_store_credit TINYINT(1) NOT NULL DEFAULT 0;

INSERT IGNORE INTO products (name, price, description)
VALUES ('Store credit', 0.00, 'Negative line for credits taken back at the door');
