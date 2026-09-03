-- Seed Sarita as the initial cashier (code 8989).
-- Hosted gate (055+): INSERT IGNORE only.

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
