-- 060 — Mantecada mixer formula, 1.5-batch yields, piece-weight estimates.
-- Owner kitchen: 1 batch = 1 gal milk + 1 gal egg + 1 gal oil + 11 lb sugar
-- + 12 lb flour + 11 oz baking powder (3 liquid gallons).
-- 1.5 batch = 4.5 liquid gallons, and that masa can be ALL barras, ALL quequitos,
-- ALL cortadillos, or any combo that fits. Yields below are the all-one-shape
-- estimates used to derive piece weights. Colchón uses the same tray masa as
-- cortadillos (32 pcs/tray vs 33). Densities: milk 8.6, eggs 8.65, oil 7.7 lb/gal.

INSERT IGNORE INTO ingredients (name) VALUES ('Baking Powder');

UPDATE dough_types
SET standard_batch_dough_grams = 22062.000,
    description = 'Mantecada cake batter. 1 batch = 3 liquid gallons (1 gal milk, 1 gal egg, 1 gal oil) + 11 lb sugar + 12 lb flour + 11 oz baking powder. 1.5 batch = 4.5 gal. Same masa can be barras, quequitos, cortadillos, and/or colchón as long as dough lasts.'
WHERE name = 'Mantecada';

INSERT IGNORE INTO formula_ingredients (dough_type_id, ingredient_id, percentage)
SELECT dt.id, i.id, v.pct
FROM dough_types dt
JOIN (
  SELECT 'Bread Flour' AS n, 100.00 AS pct
  UNION ALL SELECT 'Sugar', 91.67
  UNION ALL SELECT 'Milk', 71.67
  UNION ALL SELECT 'Eggs', 72.08
  UNION ALL SELECT 'Oil', 64.17
  UNION ALL SELECT 'Baking Powder', 5.73
) v
JOIN ingredients i ON i.name = v.n
WHERE dt.name = 'Mantecada';

UPDATE formula_ingredients fi
JOIN dough_types dt ON dt.id = fi.dough_type_id AND dt.name = 'Mantecada'
JOIN ingredients i ON i.id = fi.ingredient_id
SET fi.percentage = CASE i.name
  WHEN 'Bread Flour' THEN 100.00
  WHEN 'Sugar' THEN 91.67
  WHEN 'Milk' THEN 71.67
  WHEN 'Eggs' THEN 72.08
  WHEN 'Oil' THEN 64.17
  WHEN 'Baking Powder' THEN 5.73
  ELSE fi.percentage
END
WHERE i.name IN ('Bread Flour', 'Sugar', 'Milk', 'Eggs', 'Oil', 'Baking Powder');

UPDATE products
SET weight_grams = 662,
    description = 'Whole barra from Mantecada masa. Estimate 662 g from 1.5 batch → 50 barras (4.5 liquid gal). Combo with quequitos/cortadillos/colchón is OK if total dough fits.'
WHERE name = 'Barras';

UPDATE products
SET weight_grams = 83,
    description = 'Quequito from Mantecada masa. Estimate 83 g from 1.5 batch → 20 trays × 20 (400 pcs). Combo OK if total dough fits.'
WHERE name = 'Quequitos';

UPDATE products
SET weight_grams = 50,
    description = 'Cortadillo slice from Mantecada masa. Estimate 50 g from 1.5 batch → 20 trays × 33 sliced. Combo OK if total dough fits.'
WHERE name = 'Cortadillos';

UPDATE products
SET weight_grams = 52,
    description = 'Colchón from the same Mantecada masa. Estimate 52 g using cortadillo tray masa at 32 pcs/tray (20 trays → 640 pcs on a 1.5 batch). Combo OK if total dough fits.'
WHERE name = 'Colchón';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, trays_per_gallon, pieces_per_tray, notes)
SELECT p.id, v.input_unit, v.pieces_per_input, v.trays_per_gallon, v.pieces_per_tray, v.notes
FROM (
  SELECT 'Quequitos' AS product_name, 'tray' AS input_unit, 20 AS pieces_per_input, 4.444444 AS trays_per_gallon, 20 AS pieces_per_tray,
         'Owner: 1.5 batch (4.5 liquid gal) → 20 trays × 20 if all quequitos. Weight 83 g. Mix shapes until masa runs out.' AS notes
  UNION ALL SELECT 'Cortadillos', 'tray', 33, 4.444444, 33,
         'Owner: 1.5 batch → 20 trays × 33 sliced if all cortadillos. Weight 50 g. Mix shapes until masa runs out.'
  UNION ALL SELECT 'Colchón', 'tray', 32, 4.444444, 32,
         'Same Mantecada masa as cortadillos; 32 pcs/tray. Weight 52 g from 1.5-batch tray estimate.'
  UNION ALL SELECT 'Barras', 'barra', 1, 11.111111, 1,
         'Owner: 1.5 batch → 50 whole barras if all barras (11.111111 barras per liquid gal). Weight 662 g. Gallon path uses trays_per_gallon × 1.'
) v
JOIN products p ON p.name = v.product_name;

UPDATE product_pack_yields y
JOIN products p ON p.id = y.product_id
SET
  y.trays_per_gallon = 4.444444,
  y.pieces_per_tray = 20,
  y.pieces_per_input = 20,
  y.input_unit = 'tray',
  y.notes = 'Owner: 1.5 batch (4.5 liquid gal) → 20 trays × 20 if all quequitos. Weight 83 g. Mix shapes until masa runs out.'
WHERE p.name = 'Quequitos';

UPDATE product_pack_yields y
JOIN products p ON p.id = y.product_id
SET
  y.trays_per_gallon = 4.444444,
  y.pieces_per_tray = 33,
  y.pieces_per_input = 33,
  y.input_unit = 'tray',
  y.notes = 'Owner: 1.5 batch → 20 trays × 33 sliced if all cortadillos. Weight 50 g. Mix shapes until masa runs out.'
WHERE p.name = 'Cortadillos';

UPDATE product_pack_yields y
JOIN products p ON p.id = y.product_id
SET
  y.trays_per_gallon = 4.444444,
  y.pieces_per_tray = 32,
  y.pieces_per_input = 32,
  y.input_unit = 'tray',
  y.notes = 'Same Mantecada masa as cortadillos; 32 pcs/tray. Weight 52 g from 1.5-batch tray estimate.'
WHERE p.name = 'Colchón';

UPDATE product_pack_yields y
JOIN products p ON p.id = y.product_id
SET
  y.trays_per_gallon = 11.111111,
  y.pieces_per_tray = 1,
  y.pieces_per_input = 1,
  y.input_unit = 'barra',
  y.notes = 'Owner: 1.5 batch → 50 whole barras if all barras (11.111111 barras per liquid gal). Weight 662 g. Gallon path uses trays_per_gallon × 1.'
WHERE p.name = 'Barras';
