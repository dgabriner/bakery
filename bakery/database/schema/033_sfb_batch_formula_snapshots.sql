-- 033 — Preserve the exact formula used for every SF Baker batch.
-- A batch's formula may be edited or deleted later; its bake record must not change.

CREATE TABLE IF NOT EXISTS sfb_batch_formula_snapshots (
  batch_id INT NOT NULL,
  source_formula_id INT NULL DEFAULT NULL,
  formula_name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  target_dough_g DECIMAL(12,1) NULL DEFAULT NULL,
  source_updated_at DATETIME NULL DEFAULT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id),
  KEY idx_sfb_batch_snapshot_source_formula (source_formula_id),
  CONSTRAINT fk_sfb_batch_snapshot_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sfb_batch_formula_snapshot_lines (
  id INT NOT NULL AUTO_INCREMENT,
  batch_id INT NOT NULL,
  line_name VARCHAR(100) NOT NULL,
  line_kind VARCHAR(50) NOT NULL DEFAULT 'other',
  percentage DECIMAL(6,2) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_batch_snapshot_line_order (batch_id, sort_order),
  KEY idx_sfb_batch_snapshot_lines_batch (batch_id),
  CONSTRAINT fk_sfb_batch_snapshot_line_batch FOREIGN KEY (batch_id) REFERENCES sfb_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing batches can only be backfilled from the formula as it exists when
-- this migration runs. New batches are snapshotted at creation time.
INSERT IGNORE INTO sfb_batch_formula_snapshots
  (batch_id, source_formula_id, formula_name, description, target_dough_g, source_updated_at)
SELECT b.id, f.id, f.name, f.description, f.target_dough_g, f.updated_at
FROM sfb_batches b
JOIN sfb_formulas f ON f.id = b.formula_id;

INSERT IGNORE INTO sfb_batch_formula_snapshot_lines
  (batch_id, line_name, line_kind, percentage, sort_order)
SELECT s.batch_id,
       COALESCE(i.name, st.name, 'Formula line') AS line_name,
       CASE WHEN fi.starter_id IS NOT NULL THEN 'starter' ELSE COALESCE(i.category, 'other') END AS line_kind,
       fi.percentage,
       fi.sort_order
FROM sfb_batch_formula_snapshots s
JOIN sfb_formula_ingredients fi ON fi.formula_id = s.source_formula_id
LEFT JOIN sfb_ingredients i ON i.id = fi.ingredient_id
LEFT JOIN sfb_starters st ON st.id = fi.starter_id;
