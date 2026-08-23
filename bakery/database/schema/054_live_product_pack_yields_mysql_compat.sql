-- 054 — MySQL-compatible forward promotion of the pack-yield reference data.
--
-- This is the portable successor to 053. It preserves its additive-only,
-- INSERT IGNORE semantics but omits DEFAULT NULL on TEXT columns so it also
-- runs on older MySQL hosts. It is safe whether 053 made no changes or only a
-- subset of its idempotent changes before stopping.

CREATE TABLE IF NOT EXISTS product_pack_yields (
  product_id INT NOT NULL PRIMARY KEY,
  input_unit VARCHAR(16) NOT NULL DEFAULT 'piece',
  pieces_per_input DECIMAL(12,4) NULL DEFAULT NULL,
  trays_per_gallon DECIMAL(12,6) NULL DEFAULT NULL,
  pieces_per_tray INT NULL DEFAULT NULL,
  source_product_id INT NULL DEFAULT NULL,
  cut_ratio DECIMAL(12,4) NULL DEFAULT NULL,
  notes TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_product_pack_yields_product
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_product_pack_yields_source
    FOREIGN KEY (source_product_id) REFERENCES products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dough_type_pack_yields (
  dough_type_id INT NOT NULL PRIMARY KEY,
  trays_per_gallon DECIMAL(12,6) NOT NULL,
  pieces_per_tray INT NOT NULL DEFAULT 20,
  notes TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_dough_type_pack_yields_dough
    FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_aliases (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  alias VARCHAR(100) NOT NULL,
  product_id INT NOT NULL,
  notes VARCHAR(255) NULL DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_aliases_alias (alias),
  KEY idx_product_aliases_product (product_id),
  CONSTRAINT fk_product_aliases_product
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO dough_type_pack_yields (dough_type_id, trays_per_gallon, pieces_per_tray, notes)
SELECT dt.id, 14.666667, 20, 'Owner estimate: 3 gal → 44 trays → 880 pcs'
FROM dough_types dt
JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
WHERE dt.name IN ('Concha', 'Fino');

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, pieces_per_tray, notes)
SELECT p.id, 'tray', 33, 33, 'Was 30; now 33 pieces per tray'
FROM products p WHERE p.name = 'Cortadillos';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, pieces_per_tray, notes)
SELECT p.id, 'tray', 32, 32, '32 pieces per tray'
FROM products p WHERE p.name = 'Colchón';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, pieces_per_tray, notes)
SELECT p.id, 'tray', 40, 40, '40 pieces per tray'
FROM products p WHERE p.name = 'Budín';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, notes)
SELECT p.id, 'barra', 1, 'Whole barras; 1 order unit = 1 Barras piece'
FROM products p WHERE p.name = 'Barras';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, pieces_per_input, source_product_id, cut_ratio, notes)
SELECT rebanada.id, 'piece', 1, barras.id, 6, '1 whole Barras cuts into 6 Barra (Rebanada)'
FROM products rebanada
JOIN products barras ON barras.name = 'Barras'
WHERE rebanada.name = 'Barra (Rebanada)';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, trays_per_gallon, pieces_per_tray, notes)
SELECT p.id, 'gallon', 14.666667, 20, 'Uses Concha dough gallon geometry'
FROM products p WHERE p.name = 'Conchas';

INSERT IGNORE INTO product_pack_yields (product_id, input_unit, trays_per_gallon, pieces_per_tray, notes)
SELECT p.id, 'gallon', 14.666667, 20, 'Fino family; gallon orders expand via dough split'
FROM products p
JOIN dough_types dt ON dt.id = p.dough_type_id
WHERE dt.name = 'Fino' AND p.name IN ('Elotes', 'Cuerno Azucar', 'Tostado', 'Nopal', 'Chamuco');

INSERT IGNORE INTO product_aliases (alias, product_id, notes)
SELECT v.alias, p.id, v.notes
FROM (
  SELECT 'pudin' AS alias, 'Budín' AS product_name, 'spelling' AS notes
  UNION ALL SELECT 'pudín', 'Budín', 'spelling'
  UNION ALL SELECT 'budin', 'Budín', 'spelling'
  UNION ALL SELECT 'queiquito', 'Quequitos', 'spelling'
  UNION ALL SELECT 'queiquitos', 'Quequitos', 'spelling'
  UNION ALL SELECT 'quequito', 'Quequitos', 'spelling'
  UNION ALL SELECT 'gragea', 'Grajea', 'spelling'
  UNION ALL SELECT 'grajea', 'Grajea', 'spelling'
  UNION ALL SELECT 'colchones', 'Colchón', 'plural'
  UNION ALL SELECT 'colchon', 'Colchón', 'spelling'
  UNION ALL SELECT 'concha', 'Conchas', 'singular'
  UNION ALL SELECT 'conchas', 'Conchas', 'plural'
  UNION ALL SELECT 'yoyo', 'Yo-yo', 'spelling'
  UNION ALL SELECT 'yoyó', 'Yo-yo', 'spelling'
  UNION ALL SELECT 'yoyos', 'Yo-yo', 'plural'
  UNION ALL SELECT 'yoyós', 'Yo-yo', 'plural'
  UNION ALL SELECT 'pinguino', 'Pinguino', 'spelling'
  UNION ALL SELECT 'pingüino', 'Pinguino', 'spelling'
  UNION ALL SELECT 'pingüinos', 'Pinguino', 'plural'
  UNION ALL SELECT 'mariana', 'Mariana', 'singular'
  UNION ALL SELECT 'marianas', 'Mariana', 'plural'
  UNION ALL SELECT 'cortadillo', 'Cortadillos', 'singular'
  UNION ALL SELECT 'barra', 'Barras', 'singular whole barra'
  UNION ALL SELECT 'barras', 'Barras', 'plural'
  UNION ALL SELECT 'rebanada', 'Barra (Rebanada)', 'singular'
  UNION ALL SELECT 'rebanadas', 'Barra (Rebanada)', 'plural'
  UNION ALL SELECT 'nuez', 'Nuez', 'exact'
  UNION ALL SELECT 'guayaba', 'Guayaba', 'exact'
  UNION ALL SELECT 'chamuco', 'Chamuco', 'exact'
  UNION ALL SELECT 'tostado', 'Tostado', 'singular'
  UNION ALL SELECT 'tostados', 'Tostado', 'plural'
  UNION ALL SELECT 'nopal', 'Nopal', 'exact'
  UNION ALL SELECT 'nopales', 'Nopal', 'plural'
  UNION ALL SELECT 'elote', 'Elotes', 'singular'
  UNION ALL SELECT 'elotes', 'Elotes', 'exact'
  UNION ALL SELECT 'cuerno', 'Cuerno Azucar', 'short'
  UNION ALL SELECT 'cuerno azucar', 'Cuerno Azucar', 'exact'
  UNION ALL SELECT 'taco', 'Taco', 'exact'
  UNION ALL SELECT 'puerco', 'Puerco', 'exact'
  UNION ALL SELECT 'quesadilla', 'Quesadilla', 'exact'
  UNION ALL SELECT 'pastel', 'Pastel', 'exact'
  UNION ALL SELECT 'roles', 'Roles de Canela', 'short'
  UNION ALL SELECT 'roles de canela', 'Roles de Canela', 'exact'
  UNION ALL SELECT 'gusano', 'Gusano', 'exact'
  UNION ALL SELECT 'tortuga', 'Tortuga', 'exact'
  UNION ALL SELECT 'rosada', 'Polvoron Rosada', 'short'
  UNION ALL SELECT 'amarilla', 'Polvoron Amarilla', 'short'
  UNION ALL SELECT 'chocolate', 'Chocolate Chip', 'short'
  UNION ALL SELECT 'chocolates', 'Chocolate Chip', 'plural'
) AS v
JOIN products p ON p.name = v.product_name;
