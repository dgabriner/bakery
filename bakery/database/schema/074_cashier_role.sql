-- Cashier role: product catalog photos only
-- Target: bakerysf_local / staging bakerysoftware / production bakerysf
-- Hosted gate (055+): INSERT IGNORE / CREATE IF NOT EXISTS / ALTER ADD only.

INSERT IGNORE INTO roles (slug, name, description) VALUES
('cashier', 'Cashier', 'Product catalog photos only');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'ops.manage'
WHERE r.slug = 'cashier';
