-- Explicit no-charge marker on dated order lines.
-- daily_order_items.unit_price defaults to 0.00 and every order writer stores 0
-- when the catalog price is unknown, so a bare 0 cannot be told apart from a
-- deliberate comp. Unmarked 0/NULL lines are still filled at delivery confirm.
-- Marked lines stay $0.
-- owner-approved-core-column: staff no-charge lines (comp, sample, replacement, donation) must not be repriced when a delivery is confirmed.
-- schema_migrations tracks apply-once; do not re-run after recorded.

ALTER TABLE daily_order_items
  ADD COLUMN is_no_charge TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN no_charge_reason VARCHAR(32) NULL DEFAULT NULL;
