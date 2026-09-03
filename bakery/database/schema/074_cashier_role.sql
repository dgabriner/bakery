-- Cashier role: product catalog photos only
-- Target: bakerysf_local / production

INSERT INTO roles (slug, name, description) VALUES
('cashier', 'Cashier', 'Product catalog photos only')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'ops.manage'
WHERE r.slug = 'cashier'
ON DUPLICATE KEY UPDATE role_id = role_id;
