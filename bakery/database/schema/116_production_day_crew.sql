-- Bake-day crew corrections and durable work leads.
-- Suggestions come from time-clock punches. This table stores only a
-- manager's add or remove for one bake day. Hours stay on time_clock_punches.
-- Leads name who owns a product line, a dough, or one customer's work.
-- No users, roles, or login codes are written here.

CREATE TABLE IF NOT EXISTS production_day_crew (
  bake_date DATE NOT NULL,
  user_id INT NOT NULL,
  pin ENUM('add', 'remove') NOT NULL,
  pinned_by_user_id INT NULL DEFAULT NULL,
  pinned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (bake_date, user_id),
  KEY idx_production_day_crew_user (user_id),
  CONSTRAINT fk_production_day_crew_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_day_crew_pinned_by FOREIGN KEY (pinned_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_work_leads (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT NOT NULL,
  product_line_id INT NULL DEFAULT NULL,
  dough_type_id INT NULL DEFAULT NULL,
  customer_id INT NULL DEFAULT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_production_work_leads_user (user_id),
  KEY idx_production_work_leads_line (product_line_id),
  KEY idx_production_work_leads_dough (dough_type_id),
  KEY idx_production_work_leads_customer (customer_id),
  CONSTRAINT fk_production_work_leads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_work_leads_line FOREIGN KEY (product_line_id) REFERENCES product_lines(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_work_leads_dough FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_work_leads_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_work_leads_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
