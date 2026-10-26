-- Sales role: leads, read-only customers, and delivery zone reads.
-- Hosted-gate portable: INSERT IGNORE only. No new secrets.

INSERT IGNORE INTO roles (slug, name, description) VALUES
('sales', 'Sales', 'Sales leads, read-only customers, and delivery zone reads');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r
JOIN permissions p ON p.slug = 'ops.manage'
WHERE r.slug = 'sales';
