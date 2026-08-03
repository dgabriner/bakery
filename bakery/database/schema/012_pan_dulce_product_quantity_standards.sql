-- Standard quick-add quantities are per Pan Dulce product, not per dough type.
CREATE TABLE IF NOT EXISTS pan_dulce_product_quantity_standards (
  product_id INT NOT NULL PRIMARY KEY,
  standard_quantity INT NOT NULL DEFAULT 12,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pan_dulce_product_quantity_product
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO pan_dulce_product_quantity_standards (product_id, standard_quantity)
SELECT p.id, COALESCE(old.standard_quantity, 12)
FROM products p
JOIN dough_types dt ON dt.id = p.dough_type_id
JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
LEFT JOIN pan_dulce_quantity_standards old ON old.dough_type_id = dt.id;
