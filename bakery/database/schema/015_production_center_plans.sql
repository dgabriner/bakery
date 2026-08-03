-- Saved production targets for the weekly Production Center.
-- A target is the total finished-goods quantity desired for one product and delivery day.
CREATE TABLE IF NOT EXISTS production_plan_items (
  id INT NOT NULL AUTO_INCREMENT,
  delivery_date DATE NOT NULL,
  product_id INT NOT NULL,
  planned_quantity INT NOT NULL DEFAULT 0,
  created_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_production_plan_day_product (delivery_date, product_id),
  KEY idx_production_plan_delivery_date (delivery_date),
  CONSTRAINT fk_production_plan_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_plan_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
