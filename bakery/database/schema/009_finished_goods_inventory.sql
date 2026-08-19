-- Finished goods inventory for a delivery day. Quantities are whole sellable units.

CREATE TABLE IF NOT EXISTS product_inventory_days (
  id INT NOT NULL AUTO_INCREMENT,
  delivery_date DATE NOT NULL,
  product_id INT NOT NULL,
  available_quantity INT NOT NULL DEFAULT 0,
  produced_quantity INT NOT NULL DEFAULT 0,
  counted_quantity INT NULL DEFAULT NULL,
  loaded_quantity INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_day_product (delivery_date, product_id),
  KEY idx_inventory_product_date (product_id, delivery_date),
  CONSTRAINT fk_inventory_day_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INT NOT NULL AUTO_INCREMENT,
  delivery_date DATE NOT NULL,
  product_id INT NOT NULL,
  movement_type ENUM('production','count','load','load_correction','return','waste','delivery') NOT NULL,
  quantity_delta INT NOT NULL,
  driver_id INT NULL DEFAULT NULL,
  daily_order_id INT NULL DEFAULT NULL,
  notes VARCHAR(500) NULL DEFAULT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inventory_movements_date (delivery_date),
  KEY idx_inventory_movements_product (product_id),
  KEY idx_inventory_movements_driver (driver_id),
  CONSTRAINT fk_inventory_movement_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_inventory_movement_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  CONSTRAINT fk_inventory_movement_order FOREIGN KEY (daily_order_id) REFERENCES daily_orders(id) ON DELETE SET NULL,
  CONSTRAINT fk_inventory_movement_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_loads (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  status ENUM('loaded','reconciled') NOT NULL DEFAULT 'loaded',
  notes VARCHAR(500) NULL DEFAULT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_load_date (driver_id, delivery_date),
  KEY idx_driver_load_date (delivery_date),
  CONSTRAINT fk_driver_load_driver FOREIGN KEY (driver_id) REFERENCES drivers(id),
  CONSTRAINT fk_driver_load_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_load_items (
  id INT NOT NULL AUTO_INCREMENT,
  driver_load_id INT NOT NULL,
  product_id INT NOT NULL,
  loaded_quantity INT NOT NULL DEFAULT 0,
  returned_quantity INT NOT NULL DEFAULT 0,
  wasted_quantity INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_load_product (driver_load_id, product_id),
  CONSTRAINT fk_driver_load_item_load FOREIGN KEY (driver_load_id) REFERENCES driver_loads(id) ON DELETE CASCADE,
  CONSTRAINT fk_driver_load_item_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
