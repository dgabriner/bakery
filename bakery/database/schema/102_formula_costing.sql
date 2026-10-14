-- Costing-ready formulas.
-- pieces_per_batch is the yield of one standard gram batch. Leave it NULL.
-- subformula_dough_type_id lets a preferment ingredient point at the dough
-- type whose formula holds its flour and water.
-- supplier_name, package_size, and unit_cost are the existing cost columns.
-- Adding them again is a no-op when they are already present.
-- No recipe numbers and no price types are written here.

ALTER TABLE dough_types
  ADD COLUMN pieces_per_batch DECIMAL(12,3) NULL DEFAULT NULL AFTER standard_batch_dough_grams;

ALTER TABLE ingredients
  ADD COLUMN subformula_dough_type_id INT NULL DEFAULT NULL;

ALTER TABLE ingredients
  ADD KEY idx_ingredients_subformula_dough (subformula_dough_type_id);

ALTER TABLE ingredients
  ADD CONSTRAINT fk_ingredients_subformula_dough
  FOREIGN KEY (subformula_dough_type_id) REFERENCES dough_types (id) ON DELETE SET NULL;

ALTER TABLE ingredients
  ADD COLUMN supplier_name VARCHAR(255) NULL DEFAULT NULL;

ALTER TABLE ingredients
  ADD COLUMN package_size DECIMAL(12,3) NULL DEFAULT NULL;

ALTER TABLE ingredients
  ADD COLUMN unit_cost DECIMAL(10,2) NULL DEFAULT NULL;
