-- SF Baker module: starters, formulas, batches, turns, temps, photos
-- Self-contained sfb_* tables; no coupling to wholesale inventory/purchasing.

-- Ingredient library. customer_id NULL = standard library entry shared by all bakers.
CREATE TABLE IF NOT EXISTS sfb_ingredients (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NULL DEFAULT NULL,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(50) NOT NULL DEFAULT 'other',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_ingredients_customer (customer_id),
  CONSTRAINT fk_sfb_ing_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A baker's starters (levains). Feedings tracked in sfb_starter_feedings.
CREATE TABLE IF NOT EXISTS sfb_starters (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  flour_blend VARCHAR(255) NULL DEFAULT NULL,
  hydration_pct DECIMAL(5,1) NOT NULL DEFAULT 100.0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_starters_customer (customer_id),
  CONSTRAINT fk_sfb_starter_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Starter feeding log. Ratio is derived from starter/flour/water grams.
CREATE TABLE IF NOT EXISTS sfb_starter_feedings (
  id INT NOT NULL AUTO_INCREMENT,
  starter_id INT NOT NULL,
  fed_at DATETIME NOT NULL,
  starter_g DECIMAL(10,1) NOT NULL DEFAULT 0,
  flour_g DECIMAL(10,1) NOT NULL DEFAULT 0,
  water_g DECIMAL(10,1) NOT NULL DEFAULT 0,
  peak_notes VARCHAR(255) NULL DEFAULT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_feedings_starter (starter_id),
  CONSTRAINT fk_sfb_feeding_starter FOREIGN KEY (starter_id) REFERENCES sfb_starters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Formulas. customer_id NULL + is_template = 1 = shared standard formula.
CREATE TABLE IF NOT EXISTS sfb_formulas (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NULL DEFAULT NULL,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  target_dough_g DECIMAL(12,1) NULL DEFAULT NULL,
  is_template TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_formulas_customer (customer_id),
  CONSTRAINT fk_sfb_formula_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Baker's-math lines. Exactly one of ingredient_id / starter_id is set;
-- a starter line is how a Starter becomes an ingredient in the batch.
CREATE TABLE IF NOT EXISTS sfb_formula_ingredients (
  id INT NOT NULL AUTO_INCREMENT,
  formula_id INT NOT NULL,
  ingredient_id INT NULL DEFAULT NULL,
  starter_id INT NULL DEFAULT NULL,
  percentage DECIMAL(6,2) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_sfb_fi_formula (formula_id),
  CONSTRAINT fk_sfb_fi_formula FOREIGN KEY (formula_id) REFERENCES sfb_formulas(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_fi_ingredient FOREIGN KEY (ingredient_id) REFERENCES sfb_ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_fi_starter FOREIGN KEY (starter_id) REFERENCES sfb_starters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batches on the journey to 1,000 loaves. Phase timing lives on the batch;
-- development turns, dough temps, and photos live in child tables.
CREATE TABLE IF NOT EXISTS sfb_batches (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  formula_id INT NULL DEFAULT NULL,
  name VARCHAR(120) NOT NULL,
  status ENUM('in_progress', 'completed', 'abandoned') NOT NULL DEFAULT 'in_progress',
  loaf_count INT NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL,
  mix_minutes INT NULL DEFAULT NULL,
  mix_speed VARCHAR(50) NULL DEFAULT NULL,
  mix_notes TEXT NULL,
  mix_completed_at DATETIME NULL DEFAULT NULL,
  bulk_started_at DATETIME NULL DEFAULT NULL,
  bulk_ended_at DATETIME NULL DEFAULT NULL,
  shaped_at DATETIME NULL DEFAULT NULL,
  shape_notes TEXT NULL,
  bake_started_at DATETIME NULL DEFAULT NULL,
  bake_ended_at DATETIME NULL DEFAULT NULL,
  oven_temp_f DECIMAL(6,1) NULL DEFAULT NULL,
  bake_notes TEXT NULL,
  final_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_batches_customer (customer_id, status),
  CONSTRAINT fk_sfb_batch_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfb_batch_formula FOREIGN KEY (formula_id) REFERENCES sfb_formulas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Development turns (stretch & fold, coil fold, lamination, ...).
CREATE TABLE IF NOT EXISTS sfb_batch_turns (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  occurred_at DATETIME NOT NULL,
  turn_type VARCHAR(50) NOT NULL DEFAULT 'stretch_fold',
  dough_temp_f DECIMAL(5,1) NULL DEFAULT NULL,
  notes VARCHAR(255) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_turns_batch (batch_id),
  CONSTRAINT fk_sfb_turn_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional dough temperature readings at any phase.
CREATE TABLE IF NOT EXISTS sfb_batch_temps (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  phase VARCHAR(20) NOT NULL DEFAULT 'development',
  measured_at DATETIME NOT NULL,
  temp_f DECIMAL(5,1) NOT NULL,
  notes VARCHAR(255) NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_temps_batch (batch_id),
  CONSTRAINT fk_sfb_temp_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Photos per phase; files under uploads/sfb_photos/Y/m/.
CREATE TABLE IF NOT EXISTS sfb_batch_photos (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  phase ENUM('starter', 'mix', 'development', 'shape', 'bake', 'final') NOT NULL DEFAULT 'final',
  filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(512) NOT NULL,
  caption VARCHAR(255) NULL DEFAULT NULL,
  file_size INT NULL DEFAULT NULL,
  mime_type VARCHAR(100) NULL DEFAULT NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sfb_photos_batch (batch_id),
  CONSTRAINT fk_sfb_photo_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Standard ingredient library (shared; customer_id NULL).
INSERT INTO sfb_ingredients (customer_id, name, category) VALUES
  (NULL, 'Bread Flour', 'flour'),
  (NULL, 'All-Purpose Flour', 'flour'),
  (NULL, 'Whole Wheat Flour', 'flour'),
  (NULL, 'Rye Flour', 'flour'),
  (NULL, 'Water', 'water'),
  (NULL, 'Salt', 'salt'),
  (NULL, 'Sourdough Starter', 'starter'),
  (NULL, 'Olive Oil', 'fat'),
  (NULL, 'Sugar', 'sweetener'),
  (NULL, 'Instant Yeast', 'leavener');

-- Standard formulas (templates; customer_id NULL).
INSERT INTO sfb_formulas (customer_id, name, description, target_dough_g, is_template) VALUES
  (NULL, 'Basic Sourdough', 'Everyday country loaf at about 75% hydration. A great first formula.', 1800, 1),
  (NULL, 'Whole Wheat Sourdough', 'Thirty percent whole wheat for a heartier crumb and deeper flavor.', 1800, 1),
  (NULL, 'Rustic Country', 'A touch of whole wheat, slightly stiffer dough, mild tang.', 1800, 1),
  (NULL, 'High-Hydration Sourdough', 'Eighty-five percent water for an open crumb. Handle gently.', 1800, 1);

INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, percentage, sort_order)
SELECT f.id, i.id, p.pct, p.ord
FROM sfb_formulas f
JOIN (
  SELECT 'Bread Flour' AS iname, 100.00 AS pct, 1 AS ord
  UNION ALL SELECT 'Water', 75.00, 2
  UNION ALL SELECT 'Salt', 2.00, 3
  UNION ALL SELECT 'Sourdough Starter', 20.00, 4
) p
JOIN sfb_ingredients i ON i.name = p.iname AND i.customer_id IS NULL
WHERE f.name = 'Basic Sourdough' AND f.is_template = 1 AND f.customer_id IS NULL;

INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, percentage, sort_order)
SELECT f.id, i.id, p.pct, p.ord
FROM sfb_formulas f
JOIN (
  SELECT 'Bread Flour' AS iname, 70.00 AS pct, 1 AS ord
  UNION ALL SELECT 'Whole Wheat Flour', 30.00, 2
  UNION ALL SELECT 'Water', 78.00, 3
  UNION ALL SELECT 'Salt', 2.00, 4
  UNION ALL SELECT 'Sourdough Starter', 20.00, 5
) p
JOIN sfb_ingredients i ON i.name = p.iname AND i.customer_id IS NULL
WHERE f.name = 'Whole Wheat Sourdough' AND f.is_template = 1 AND f.customer_id IS NULL;

INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, percentage, sort_order)
SELECT f.id, i.id, p.pct, p.ord
FROM sfb_formulas f
JOIN (
  SELECT 'Bread Flour' AS iname, 90.00 AS pct, 1 AS ord
  UNION ALL SELECT 'Whole Wheat Flour', 10.00, 2
  UNION ALL SELECT 'Water', 72.00, 3
  UNION ALL SELECT 'Salt', 2.00, 4
  UNION ALL SELECT 'Sourdough Starter', 15.00, 5
) p
JOIN sfb_ingredients i ON i.name = p.iname AND i.customer_id IS NULL
WHERE f.name = 'Rustic Country' AND f.is_template = 1 AND f.customer_id IS NULL;

INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, percentage, sort_order)
SELECT f.id, i.id, p.pct, p.ord
FROM sfb_formulas f
JOIN (
  SELECT 'Bread Flour' AS iname, 100.00 AS pct, 1 AS ord
  UNION ALL SELECT 'Water', 85.00, 2
  UNION ALL SELECT 'Salt', 2.00, 3
  UNION ALL SELECT 'Sourdough Starter', 20.00, 4
) p
JOIN sfb_ingredients i ON i.name = p.iname AND i.customer_id IS NULL
WHERE f.name = 'High-Hydration Sourdough' AND f.is_template = 1 AND f.customer_id IS NULL;
