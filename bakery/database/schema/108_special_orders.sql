-- One-off special orders for a customer and delivery date.
-- These are not pan dulce. They do not write standing_orders or the dated
-- pan dulce grid. 107 is reserved by the open closeout photo branch, so
-- this file is 108.
-- Hosted-gate portable: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS special_orders (
  id INT NOT NULL AUTO_INCREMENT,
  customer_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  daily_order_id INT NULL DEFAULT NULL,
  note VARCHAR(500) NOT NULL DEFAULT '',
  status ENUM('open','cancelled') NOT NULL DEFAULT 'open',
  created_by INT NULL DEFAULT NULL,
  updated_by INT NULL DEFAULT NULL,
  cancelled_by INT NULL DEFAULT NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_special_orders_customer_date (customer_id, delivery_date, status),
  KEY idx_special_orders_daily_order (daily_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS special_order_lines (
  id INT NOT NULL AUTO_INCREMENT,
  special_order_id INT NOT NULL,
  line_kind ENUM('catalog','text') NOT NULL,
  product_id INT NULL DEFAULT NULL,
  description VARCHAR(200) NOT NULL DEFAULT '',
  quantity INT NOT NULL DEFAULT 0,
  delivered_quantity INT NULL DEFAULT NULL,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_no_charge TINYINT(1) NOT NULL DEFAULT 0,
  no_charge_reason VARCHAR(32) NULL DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_special_order_lines_order (special_order_id),
  KEY idx_special_order_lines_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
