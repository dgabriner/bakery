-- 087 — Formula structure for bakers and costing.
-- Standard batch (grams or pieces) plus a mix multiplier, dough loss per mix,
-- starter sub-formulas, and topping/filling grams per piece.
-- 086 is ingredient price history (086_ingredient_prices.sql). This migration is 087.
-- No recipe numbers are written here. Blank columns keep the old formula math.

ALTER TABLE dough_types
  ADD COLUMN batch_size_mode VARCHAR(16) NULL DEFAULT NULL AFTER standard_batch_dough_grams,
  ADD COLUMN standard_batch_pieces DECIMAL(12,3) NULL DEFAULT NULL AFTER batch_size_mode,
  ADD COLUMN batch_multiplier DECIMAL(8,3) NULL DEFAULT NULL AFTER standard_batch_pieces,
  ADD COLUMN dough_loss_grams DECIMAL(12,3) NULL DEFAULT NULL AFTER batch_multiplier;

CREATE TABLE IF NOT EXISTS formula_subformulas (
  id INT NOT NULL AUTO_INCREMENT,
  dough_type_id INT NULL DEFAULT NULL,
  product_id INT NULL DEFAULT NULL,
  kind VARCHAR(16) NOT NULL,
  name VARCHAR(100) NOT NULL,
  hydration_percent DECIMAL(8,3) NULL DEFAULT NULL,
  replaces_ingredient_id INT NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_formula_subformulas_dough (dough_type_id, kind),
  KEY idx_formula_subformulas_product (product_id, kind),
  CONSTRAINT fk_formula_subformulas_dough FOREIGN KEY (dough_type_id) REFERENCES dough_types (id) ON DELETE CASCADE,
  CONSTRAINT fk_formula_subformulas_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  CONSTRAINT fk_formula_subformulas_replaces FOREIGN KEY (replaces_ingredient_id) REFERENCES ingredients (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formula_subformula_lines (
  id INT NOT NULL AUTO_INCREMENT,
  subformula_id INT NOT NULL,
  ingredient_id INT NOT NULL,
  line_role VARCHAR(16) NOT NULL DEFAULT 'other',
  percentage DECIMAL(8,3) NULL DEFAULT NULL,
  grams_per_piece DECIMAL(12,3) NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_formula_subformula_line (subformula_id, ingredient_id),
  CONSTRAINT fk_formula_subformula_lines_sub FOREIGN KEY (subformula_id) REFERENCES formula_subformulas (id) ON DELETE CASCADE,
  CONSTRAINT fk_formula_subformula_lines_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
