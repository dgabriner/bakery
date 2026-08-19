-- Customer portal: login, pricing tiers, week pauses, product images

-- Portal credentials and pricing tier on customers
ALTER TABLE customers
  ADD COLUMN portal_phone VARCHAR(20) NULL DEFAULT NULL AFTER phone,
  ADD COLUMN portal_code CHAR(4) NULL DEFAULT NULL,
  ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN pricing_tier ENUM('retail', 'wholesale', 'custom') NOT NULL DEFAULT 'retail';

-- Wholesale price on products (retail uses existing price column)
ALTER TABLE products
  ADD COLUMN wholesale_price DECIMAL(10,2) NULL DEFAULT NULL AFTER price;

-- Per-product custom pricing for customers on the custom tier
CREATE TABLE IF NOT EXISTS customer_product_prices (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  product_id INT NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_product_price (customer_id, product_id),
  CONSTRAINT fk_cpp_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Week pauses: customer skips deliveries for a calendar week (Monday start)
CREATE TABLE IF NOT EXISTS standing_order_pauses (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  week_start DATE NOT NULL COMMENT 'Monday of the paused week',
  note VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_week_pause (customer_id, week_start),
  CONSTRAINT fk_pause_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product image gallery with one primary per product
CREATE TABLE IF NOT EXISTS product_images (
  id INT NOT NULL AUTO_INCREMENT,
  product_id INT NOT NULL,
  filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(512) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  file_size INT DEFAULT NULL,
  mime_type VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_images_product (product_id),
  KEY idx_product_images_primary (product_id, is_primary),
  CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
