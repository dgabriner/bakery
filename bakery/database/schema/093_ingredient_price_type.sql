-- price_type on ingredient price history.
-- invoice is the only type that can become the current cost.
-- quote and public_list are stored for the cost upper bound and never become current cost.
-- Existing rows default to invoice.
-- 091 and 092 are the staff time clock. This file is the next free number, 093.

ALTER TABLE ingredient_price_history
  ADD COLUMN price_type ENUM('invoice', 'quote', 'public_list') NOT NULL DEFAULT 'invoice';
