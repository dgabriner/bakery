-- 059 — Bolillo/Telera on Dinner Rolls dough, gallon specialty estimates, starter formula.
-- Estimates are labeled in notes; PM will refine piece yields.

INSERT IGNORE INTO products (name, dough_type_id, price, weight_grams, description)
SELECT 'Bolillo', dt.id, 0.75, 90, 'Mexican bolillo. Same dough as dinner roll and telera. Estimate 80 pieces per mixer batch.'
FROM dough_types dt
WHERE dt.name = 'Dinner Rolls'
  AND NOT EXISTS (SELECT 1 FROM products p WHERE p.name = 'Bolillo');

INSERT IGNORE INTO products (name, dough_type_id, price, weight_grams, description)
SELECT 'Telera', dt.id, 0.75, 95, 'Telera. Same dough as bolillo and dinner roll.'
FROM dough_types dt
WHERE dt.name = 'Dinner Rolls'
  AND NOT EXISTS (SELECT 1 FROM products p WHERE p.name = 'Telera');

UPDATE dough_types
SET standard_batch_dough_grams = 7200
WHERE name = 'Dinner Rolls'
  AND (standard_batch_dough_grams IS NULL OR standard_batch_dough_grams = 0);

INSERT IGNORE INTO formula_ingredients (dough_type_id, ingredient_id, percentage)
SELECT dt.id, i.id, v.pct
FROM dough_types dt
JOIN (
  SELECT 'Bread Flour' AS n, 100.00 AS pct
  UNION ALL SELECT 'Water', 58.00
  UNION ALL SELECT 'Salt', 2.00
  UNION ALL SELECT 'Instant Yeast', 1.20
  UNION ALL SELECT 'Sugar', 2.00
  UNION ALL SELECT 'Lard', 4.00
  UNION ALL SELECT 'Milk', 8.00
) v
JOIN ingredients i ON i.name = v.n
WHERE dt.name = 'Dinner Rolls'
  AND NOT EXISTS (
    SELECT 1 FROM formula_ingredients fi WHERE fi.dough_type_id = dt.id
  );

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, notes)
SELECT p.id, 'gallon', v.pcs, v.notes
FROM (
  SELECT 'Nuez' AS product_name, 80 AS pcs, 'Estimate: ~80 pieces per gallon batch (60g); refine with PM' AS notes
  UNION ALL SELECT 'Guayaba', 80, 'Estimate: ~80 pieces per gallon batch (60g); refine with PM'
  UNION ALL SELECT 'Puerco', 48, 'Estimate: ~48 pieces per gallon batch (85g); refine with PM'
  UNION ALL SELECT 'Taco', 72, 'Estimate: ~72 pieces per gallon batch (55g); refine with PM'
  UNION ALL SELECT 'Grajea', 80, 'Estimate: ~80 pieces per gallon batch (50g); refine with PM'
  UNION ALL SELECT 'Polvoron Amarilla', 100, 'Estimate: ~100 pieces per gallon batch (35g); refine with PM'
  UNION ALL SELECT 'Polvoron Rosada', 100, 'Estimate: ~100 pieces per gallon batch (35g); refine with PM'
  UNION ALL SELECT 'Chocolate Chip', 100, 'Estimate: ~100 pieces per gallon batch (35g); refine with PM'
  UNION ALL SELECT 'Bolillo', 80, 'Estimate: 80 pieces per mixer batch (90g dough pieces, 7.2kg dough)'
  UNION ALL SELECT 'Telera', 80, 'Estimate: 80 pieces per mixer batch; same dough as bolillo'
) v
JOIN products p ON p.name = v.product_name;

INSERT IGNORE INTO product_aliases (alias, product_id, notes)
SELECT v.alias, p.id, v.notes
FROM (
  SELECT 'bolillo' AS alias, 'Bolillo' AS product_name, 'exact' AS notes
  UNION ALL SELECT 'bolillos', 'Bolillo', 'plural'
  UNION ALL SELECT 'telera', 'Telera', 'exact'
  UNION ALL SELECT 'teleras', 'Telera', 'plural'
) v
JOIN products p ON p.name = v.product_name;
