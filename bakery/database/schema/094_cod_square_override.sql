-- Manager reason when COD cash is allowed on a stop that already has Square.
-- 093 is the ingredient price type on the cost pull request. This file is 094 and runs before payment terms.
-- schema_migrations tracks apply-once. Do not re-run after recorded.
-- owner-approved-core-column: a logged manager reason is required before COD cash can be recorded on a Square prepaid stop.

ALTER TABLE daily_orders
  ADD COLUMN cod_square_override_reason VARCHAR(255) NULL DEFAULT NULL;

ALTER TABLE daily_orders
  ADD COLUMN cod_square_override_user_id INT NULL DEFAULT NULL;

ALTER TABLE daily_orders
  ADD COLUMN cod_square_override_at DATETIME NULL DEFAULT NULL;
