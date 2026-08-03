-- =============================================================================
-- Sour Flour OS — sanitized local baseline schema (NO production customer data)
-- Database target: bakerysf_local
-- Checkpoint 0B
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS daily_order_assignments;
DROP TABLE IF EXISTS daily_order_items;
DROP TABLE IF EXISTS daily_orders;
DROP TABLE IF EXISTS standing_orders;
DROP TABLE IF EXISTS standing_routes;
DROP TABLE IF EXISTS driver_photos;
DROP TABLE IF EXISTS driver_history;
DROP TABLE IF EXISTS formula_ingredients;
DROP TABLE IF EXISTS lead_contacts;
DROP TABLE IF EXISTS leads;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS dough_types;
DROP TABLE IF EXISTS product_lines;
DROP TABLE IF EXISTS ingredients;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS drivers;
DROP TABLE IF EXISTS zones;
DROP TABLE IF EXISTS routes;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE zones (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  color VARCHAR(7) DEFAULT '#007bff',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  address TEXT,
  phone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(100) DEFAULT NULL,
  latitude DECIMAL(10,8) DEFAULT NULL,
  longitude DECIMAL(11,8) DEFAULT NULL,
  deliver_by TIME DEFAULT NULL,
  deliver_after TIME DEFAULT NULL,
  delivery_time INT DEFAULT 20,
  zone VARCHAR(50) DEFAULT NULL,
  zone_id INT DEFAULT NULL,
  default_pan_dulce_price DECIMAL(10,2) DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY name (name),
  KEY idx_customers_coordinates (latitude, longitude),
  KEY idx_customers_zone_id (zone_id),
  CONSTRAINT fk_customers_zone_id FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_lines (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description TEXT,
  color_code VARCHAR(7) DEFAULT '#3498db',
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dough_types (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  description TEXT,
  product_line_id INT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY name (name),
  KEY idx_dough_types_product_line (product_line_id),
  CONSTRAINT fk_dough_types_product_line FOREIGN KEY (product_line_id) REFERENCES product_lines(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ingredients (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  description TEXT,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  unit TEXT,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  dough_type_id INT DEFAULT NULL,
  price DECIMAL(10,2) DEFAULT 0.00,
  weight_grams INT DEFAULT NULL,
  description TEXT,
  default_quantity_monday INT NOT NULL DEFAULT 0,
  default_quantity_tuesday INT NOT NULL DEFAULT 0,
  default_quantity_wednesday INT NOT NULL DEFAULT 0,
  default_quantity_thursday INT NOT NULL DEFAULT 0,
  default_quantity_friday INT NOT NULL DEFAULT 0,
  default_quantity_saturday INT NOT NULL DEFAULT 0,
  default_quantity_sunday INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY name (name),
  KEY products_ibfk_1 (dough_type_id),
  CONSTRAINT products_ibfk_1 FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE formula_ingredients (
  id INT NOT NULL AUTO_INCREMENT,
  dough_type_id INT NOT NULL,
  ingredient_id INT NOT NULL,
  percentage DECIMAL(5,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY unique_ingredient_per_formula (dough_type_id, ingredient_id),
  KEY ingredient_id (ingredient_id),
  CONSTRAINT formula_ingredients_ibfk_1 FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE,
  CONSTRAINT formula_ingredients_ibfk_2 FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drivers (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE routes (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE standing_orders (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  product_id INT NOT NULL,
  day_of_week TINYINT NOT NULL COMMENT 'Fixtures use 1=Mon through 7=Sun',
  quantity INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY unique_order (customer_id, product_id, day_of_week),
  KEY product_id (product_id),
  CONSTRAINT standing_orders_ibfk_1 FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT standing_orders_ibfk_2 FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE standing_routes (
  id INT NOT NULL AUTO_INCREMENT,
  day_of_week TINYINT NOT NULL COMMENT 'Fixtures use 1=Mon through 7=Sun',
  driver_id INT NOT NULL,
  customer_id INT NOT NULL,
  route_order INT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY driver_id (driver_id),
  KEY customer_id (customer_id),
  CONSTRAINT standing_routes_ibfk_1 FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT standing_routes_ibfk_2 FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_orders (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  order_date DATE NOT NULL,
  route_id INT DEFAULT NULL,
  driver_id INT DEFAULT NULL,
  status ENUM('pending','confirmed','in_production','ready','out_for_delivery','delivered','invoiced') DEFAULT 'pending',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes TEXT,
  delivery_time TIME DEFAULT NULL,
  total_amount DECIMAL(10,2) DEFAULT 0.00,
  delivery_order_total DECIMAL(10,2) NULL DEFAULT NULL,
  delivery_pricing_label VARCHAR(50) NULL DEFAULT NULL,
  delivery_confirmed_at DATETIME NULL DEFAULT NULL,
  delivered_pieces INT NULL DEFAULT NULL,
  credits_taken_back INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY unique_customer_date (customer_id, order_date),
  KEY idx_order_date (order_date),
  KEY idx_status (status),
  CONSTRAINT daily_orders_ibfk_1 FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_order_items (
  id INT NOT NULL AUTO_INCREMENT,
  daily_order_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  delivered_quantity INT NULL DEFAULT NULL,
  unit_price DECIMAL(8,2) DEFAULT 0.00,
  line_total DECIMAL(10,2) DEFAULT 0.00,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_order_product (daily_order_id, product_id),
  KEY idx_daily_order (daily_order_id),
  KEY idx_product (product_id),
  CONSTRAINT daily_order_items_ibfk_1 FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE CASCADE,
  CONSTRAINT daily_order_items_ibfk_2 FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE daily_order_assignments (
  id INT NOT NULL AUTO_INCREMENT,
  daily_order_id INT NOT NULL,
  driver_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  scheduled_delivery_time TIME DEFAULT NULL,
  actual_delivery_time TIME DEFAULT NULL,
  route_order INT NOT NULL DEFAULT 0,
  estimated_delivery_time TIME DEFAULT NULL,
  delivery_status ENUM('pending','in_transit','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
  notes TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY unique_assignment (daily_order_id, driver_id, delivery_date),
  KEY idx_driver_date (driver_id, delivery_date),
  KEY idx_delivery_date (delivery_date),
  KEY idx_daily_order (daily_order_id),
  CONSTRAINT fk_daily_order_assignments_daily_order FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_daily_order_assignments_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE driver_history (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  timestamp DATETIME NOT NULL,
  latitude DECIMAL(10,8) NOT NULL,
  longitude DECIMAL(11,8) NOT NULL,
  photos_taken INT DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_driver_id (driver_id),
  KEY idx_timestamp (timestamp),
  CONSTRAINT driver_history_ibfk_1 FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE driver_photos (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  customer_id INT DEFAULT NULL,
  delivery_date DATE NOT NULL,
  filename VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  thumbnail_path VARCHAR(500) DEFAULT NULL,
  file_size INT NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  photo_type ENUM('Before','After','Receipt') DEFAULT 'Before',
  latitude DECIMAL(10,8) DEFAULT NULL,
  longitude DECIMAL(11,8) DEFAULT NULL,
  notes TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_driver_date (driver_id, delivery_date),
  KEY idx_customer (customer_id),
  CONSTRAINT driver_photos_ibfk_1 FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
  CONSTRAINT driver_photos_ibfk_2 FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leads (
  id INT NOT NULL AUTO_INCREMENT,
  customer_name VARCHAR(255) NOT NULL,
  contact_name VARCHAR(255) NOT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(255) DEFAULT NULL,
  address TEXT,
  notes TEXT,
  status ENUM('new','contacted','interested','qualified','converted','closed') DEFAULT 'new',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lead_contacts (
  id INT NOT NULL AUTO_INCREMENT,
  lead_id INT NOT NULL,
  contact_date DATE NOT NULL,
  contact_mode ENUM('phone','email','in_person','text','social_media') NOT NULL,
  comment TEXT,
  follow_up_needed TINYINT(1) DEFAULT 0,
  follow_up_date DATE DEFAULT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lead_id (lead_id),
  CONSTRAINT lead_contacts_ibfk_1 FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
