CREATE TABLE IF NOT EXISTS pan_dulce_quantity_standards (
  dough_type_id INT NOT NULL,
  standard_quantity INT NOT NULL DEFAULT 12,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (dough_type_id),
  CONSTRAINT fk_pan_dulce_quantity_standards_dough_type
    FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pan_dulce_quantity_standards (dough_type_id, standard_quantity)
SELECT dt.id, 12
FROM dough_types dt
JOIN product_lines pl ON pl.id = dt.product_line_id
WHERE pl.name = 'Pan Dulce'
ON DUPLICATE KEY UPDATE standard_quantity = standard_quantity;
