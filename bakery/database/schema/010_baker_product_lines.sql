-- Product-line visibility for baker production and pack-list screens.
CREATE TABLE IF NOT EXISTS baker_product_lines (
  baker_user_id INT NOT NULL,
  product_line_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (baker_user_id, product_line_id),
  CONSTRAINT fk_baker_product_lines_user FOREIGN KEY (baker_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_baker_product_lines_line FOREIGN KEY (product_line_id) REFERENCES product_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO baker_product_lines (baker_user_id, product_line_id)
SELECT u.id, pl.id
FROM users u CROSS JOIN product_lines pl
WHERE LOWER(u.email) = 'juan.carlos@sourflour.local'
  AND pl.name IN ('Sour Flour', 'Traditional');

INSERT IGNORE INTO baker_product_lines (baker_user_id, product_line_id)
SELECT u.id, pl.id
FROM users u CROSS JOIN product_lines pl
WHERE LOWER(u.email) = 'niko@sourflour.local'
  AND pl.name = 'Pan Dulce';
