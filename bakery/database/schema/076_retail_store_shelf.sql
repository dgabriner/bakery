-- Retail / store shelf grouping for cashier catalog items (coffee, chips, snacks).
-- Not a production dough — used only so store items group under Retail in photos.
-- Hosted gate (055+): INSERT IGNORE only.

INSERT IGNORE INTO product_lines (name, description, color_code, sort_order)
VALUES ('Retail', 'Store shelf items — coffee, chips, snacks, and other retail', '#6b8f71', 900);

INSERT IGNORE INTO dough_types (name, description, product_line_id)
SELECT 'Store shelf', 'Retail store items (not a bakery dough)', pl.id
FROM product_lines pl
WHERE pl.name = 'Retail';
