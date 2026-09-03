-- Cashier role and shop photos table.
-- Shop photos are taken by cashiers, organized by date, cashier, and photo category
-- (window display, trays, general, etc.).

INSERT INTO roles (slug, name, description) VALUES
('cashier', 'Cashier', 'Shop floor access: take and review daily shop photos')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO permissions (slug, description) VALUES
('shop.photos', 'Upload and view daily shop photos')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'shop.photos'
WHERE r.slug = 'cashier'
ON DUPLICATE KEY UPDATE role_id = role_id;

-- Allow managers and administrators to view shop photos too
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'shop.photos'
WHERE r.slug IN ('administrator', 'manager')
ON DUPLICATE KEY UPDATE role_id = role_id;

CREATE TABLE IF NOT EXISTS shop_photos (
  id            INT NOT NULL AUTO_INCREMENT,
  cashier_user_id INT NOT NULL,
  photo_date    DATE NOT NULL,
  photo_category ENUM('window_display','trays','general','other') NOT NULL DEFAULT 'general',
  caption       VARCHAR(255) NOT NULL DEFAULT '',
  filename      VARCHAR(255) NOT NULL,
  original_filename VARCHAR(255) NOT NULL DEFAULT '',
  file_path     VARCHAR(500) NOT NULL,
  file_size     INT UNSIGNED NOT NULL DEFAULT 0,
  mime_type     VARCHAR(100) NOT NULL DEFAULT '',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_shop_photos_date (photo_date),
  KEY idx_shop_photos_cashier_date (cashier_user_id, photo_date),
  CONSTRAINT fk_shop_photo_user FOREIGN KEY (cashier_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
