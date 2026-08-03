-- Preserve the priced order basis used to calculate the driver's final invoice.
ALTER TABLE daily_orders
  ADD COLUMN delivery_order_total DECIMAL(10,2) NULL DEFAULT NULL AFTER total_amount,
  ADD COLUMN delivery_pricing_label VARCHAR(50) NULL DEFAULT NULL AFTER delivery_order_total,
  ADD COLUMN delivery_confirmed_at DATETIME NULL DEFAULT NULL AFTER delivery_pricing_label;
