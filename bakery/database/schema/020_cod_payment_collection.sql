-- COD vs signature-receipt payment collection on delivery
ALTER TABLE customers
  ADD COLUMN payment_collection ENUM('cod', 'signature') NOT NULL DEFAULT 'cod'
  AFTER pricing_tier;

ALTER TABLE daily_orders
  ADD COLUMN amount_collected DECIMAL(10,2) NULL DEFAULT NULL
  AFTER delivery_confirmed_at;
