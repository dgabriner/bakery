-- Seed Sarita as the initial cashier (code 8989) and grant catalog ops.manage.
-- Hosted gate (055+): INSERT IGNORE only.
-- Role itself is created by 074_cashier_shop_photos (or an earlier cashier role migration).

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'ops.manage'
WHERE r.slug = 'cashier';

INSERT IGNORE INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active)
SELECT
  'sarita@sourflour.local',
  '$2y$10$4EJr4evhGSFegq.nknM8ieept0oq8sVPEHTyrmiYrQVlR5sX7KTOW',
  '8989',
  'Sarita',
  r.id,
  NULL,
  1
FROM roles r
WHERE r.slug = 'cashier';
