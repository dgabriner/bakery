-- Baker role: production and pack list access only
-- Target: bakerysf_local / production

INSERT INTO roles (slug, name, description) VALUES
('baker', 'Baker', 'Production and pack list only')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'ops.manage'
WHERE r.slug = 'baker'
ON DUPLICATE KEY UPDATE role_id = role_id;
