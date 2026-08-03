-- Store the driver's delivery reconciliation for invoice/payment handoff.
ALTER TABLE daily_orders
  ADD COLUMN delivered_pieces INT NULL DEFAULT NULL AFTER total_amount,
  ADD COLUMN credits_taken_back INT NOT NULL DEFAULT 0 AFTER delivered_pieces;
