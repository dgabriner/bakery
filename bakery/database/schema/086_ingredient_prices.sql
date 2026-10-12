-- Invoice price history for ingredients.
-- Current cost is the latest row by invoice date (then id), not ingredients.unit_cost.
-- Reorder point already exists as ingredients.reorder_level. This adds reorder_qty only.
-- The next free number after this file is 087. Do not reuse 086.
-- Draft purchase orders are printable views only. This migration sends nothing.

ALTER TABLE ingredients
  ADD COLUMN reorder_qty DECIMAL(12,3) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS ingredient_price_history (
  id INT NOT NULL AUTO_INCREMENT,
  ingredient_id INT NOT NULL,
  vendor VARCHAR(255) NOT NULL,
  vendor_sku VARCHAR(80) NULL DEFAULT NULL,
  pack_size_grams DECIMAL(12,3) NOT NULL,
  pack_price DECIMAL(12,2) NOT NULL,
  cost_per_kg DECIMAL(14,4) NOT NULL,
  invoice_number VARCHAR(64) NULL DEFAULT NULL,
  invoice_date DATE NOT NULL,
  entered_by INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ingredient_price_history_latest (ingredient_id, invoice_date, id),
  KEY idx_ingredient_price_history_vendor (vendor),
  CONSTRAINT fk_ingredient_price_history_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ingredient_price_history_user FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
