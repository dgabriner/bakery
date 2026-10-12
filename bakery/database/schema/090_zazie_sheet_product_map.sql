-- Remembered product names from the Zazie weekly order sheet.
-- sheet_key is the normalized name. sheet_label is the spelling last saved.
-- schema_migrations tracks apply-once. Do not re-run after recorded.

CREATE TABLE IF NOT EXISTS zazie_sheet_product_map (
  id INT NOT NULL AUTO_INCREMENT,
  sheet_key VARCHAR(191) NOT NULL,
  sheet_label VARCHAR(191) NOT NULL,
  product_id INT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zazie_sheet_product_map_key (sheet_key),
  KEY idx_zazie_sheet_product_map_product (product_id),
  CONSTRAINT fk_zazie_sheet_product_map_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
