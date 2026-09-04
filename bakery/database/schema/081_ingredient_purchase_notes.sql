-- Mission 63A: ordered/received purchase notes per ingredient per bake date.
-- Prefixed side table; no stock adjust, no PO.
CREATE TABLE IF NOT EXISTS ingredient_purchase_notes (
  id INT NOT NULL AUTO_INCREMENT,
  ingredient_id INT NOT NULL,
  bake_date DATE NOT NULL,
  ordered TINYINT(1) NOT NULL DEFAULT 0,
  received TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL DEFAULT NULL,
  updated_by_user_id INT NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ingredient_purchase_notes_day (ingredient_id, bake_date),
  KEY idx_ingredient_purchase_notes_date (bake_date),
  CONSTRAINT fk_ingredient_purchase_notes_ingredient FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
  CONSTRAINT fk_ingredient_purchase_notes_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
