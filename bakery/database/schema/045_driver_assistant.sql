-- Driver Assistant role and optional date-specific route pairings.
-- The assistant follows the linked default driver unless this table has a
-- pairing for the operating date.

INSERT INTO roles (slug, name, description) VALUES
('driver_assistant', 'Driver Assistant', 'Paired delivery route access')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'delivery.execute'
WHERE r.slug = 'driver_assistant'
ON DUPLICATE KEY UPDATE role_id = role_id;

CREATE TABLE IF NOT EXISTS driver_assistant_assignments (
  id INT NOT NULL AUTO_INCREMENT,
  assistant_user_id INT NOT NULL,
  driver_id INT NOT NULL,
  delivery_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_assistant_date (assistant_user_id, delivery_date),
  KEY idx_driver_assistant_date_driver (delivery_date, driver_id),
  CONSTRAINT fk_driver_assistant_user FOREIGN KEY (assistant_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_driver_assistant_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
