-- One Square checkout per click. A resubmit of the same form reuses this key
-- instead of opening another DRAFT order. NULL stays allowed for older rows
-- and for attempts that never sent a key (MySQL unique indexes permit many NULLs).
-- schema_migrations tracks apply-once. Do not re-run after recorded.

ALTER TABLE sfb_offering_purchases
  ADD COLUMN checkout_key VARCHAR(64) NULL DEFAULT NULL;

CREATE UNIQUE INDEX uq_sfb_purchases_checkout_key
  ON sfb_offering_purchases (checkout_key);
