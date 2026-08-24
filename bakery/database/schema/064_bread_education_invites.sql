-- 064 — Bread Education onboarding invites (Prompt 25).
-- Staff-minted codes carry a cohort intent through signup. A code marks one
-- customer; signup itself never creates orders, zones, routes, or invoices.

CREATE TABLE IF NOT EXISTS sfb_invites (
  id INT NOT NULL AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL,
  intent ENUM('learn', 'share') NOT NULL DEFAULT 'learn',
  label VARCHAR(150) NULL DEFAULT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  used_by_customer_id INT NULL DEFAULT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sfb_invites_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
