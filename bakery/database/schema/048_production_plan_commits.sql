-- 048 — Production plan commits ("Commit Production Plan" ritual)
-- One row per delivery date: a manager reviewed saved production_plan_items
-- and committed them to the baker. Line quantities are snapshotted so later
-- saves and dated-demand edits cannot silently rewrite Daily Production.
-- Drift after commit is derived from operational_events (demand-change types).
CREATE TABLE IF NOT EXISTS production_plan_commits (
  delivery_date DATE NOT NULL,
  committed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  committed_by_user_id INT NULL DEFAULT NULL,
  products_count INT NOT NULL DEFAULT 0,
  units_count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (delivery_date),
  KEY idx_production_plan_commits_committed_at (committed_at),
  CONSTRAINT fk_production_plan_commit_user FOREIGN KEY (committed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_plan_commit_items (
  delivery_date DATE NOT NULL,
  product_id INT NOT NULL,
  committed_quantity INT NOT NULL DEFAULT 0,
  PRIMARY KEY (delivery_date, product_id),
  KEY idx_production_plan_commit_items_product (product_id),
  CONSTRAINT fk_production_plan_commit_items_date FOREIGN KEY (delivery_date) REFERENCES production_plan_commits(delivery_date) ON DELETE CASCADE,
  CONSTRAINT fk_production_plan_commit_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
