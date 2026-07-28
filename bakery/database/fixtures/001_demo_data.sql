-- =============================================================================
-- Fictional local fixtures for bakerysf_local (Checkpoint 0B)
-- No real customer names, phones, emails, or production addresses.
-- Weekday convention in fixtures: 1=Monday ... 7=Sunday (matches most standing UIs)
-- =============================================================================

INSERT INTO zones (id, name, description, color) VALUES
(1, 'North Zone', 'Fictional north delivery zone', '#007bff'),
(2, 'South Zone', 'Fictional south delivery zone', '#dc3545'),
(3, 'Ruta Demo', 'Fictional demo route zone', '#28a745');

INSERT INTO product_lines (id, name, description, color_code, sort_order) VALUES
(1, 'Sour Flour', 'Demo sourdough line', '#e67e22', 1),
(2, 'Pan Dulce', 'Demo sweet line', '#e74c3c', 2),
(3, 'Traditional', 'Demo traditional line', '#3498db', 3);

INSERT INTO dough_types (id, name, description, product_line_id) VALUES
(1, 'Demo Sourdough', 'Fictional sourdough', 1),
(2, 'Demo Sweet Dough', 'Fictional sweet dough', 2),
(3, 'Demo White', 'Fictional white dough', 3);

INSERT INTO ingredients (id, name, description, is_active, unit) VALUES
(1, 'Demo Flour', 'Fictional flour', 1, 'g'),
(2, 'Demo Water', 'Fictional water', 1, 'g'),
(3, 'Demo Salt', 'Fictional salt', 1, 'g'),
(4, 'Demo Starter', 'Fictional starter', 1, 'g'),
(5, 'Demo Sugar', 'Fictional sugar', 1, 'g');

INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES
(1, 1, 100.00),
(1, 2, 75.00),
(1, 3, 2.20),
(1, 4, 20.00),
(2, 1, 100.00),
(2, 2, 55.00),
(2, 5, 15.00),
(2, 3, 1.80),
(3, 1, 100.00),
(3, 2, 65.00),
(3, 3, 2.00);

INSERT INTO products (id, name, dough_type_id, price, weight_grams, description,
  default_quantity_monday, default_quantity_tuesday, default_quantity_wednesday,
  default_quantity_thursday, default_quantity_friday, default_quantity_saturday, default_quantity_sunday) VALUES
(1, 'Demo Country Loaf', 1, 6.50, 700, 'Fictional loaf', 5, 5, 5, 5, 8, 10, 0),
(2, 'Demo Batard', 1, 7.00, 800, 'Fictional batard', 3, 3, 3, 3, 5, 6, 0),
(3, 'Demo Concha', 2, 2.25, 90, 'Fictional sweet bun', 12, 12, 12, 12, 20, 24, 0),
(4, 'Demo Sandwich Loaf', 3, 5.00, 680, 'Fictional sandwich bread', 4, 4, 4, 4, 6, 4, 2);

INSERT INTO drivers (id, name) VALUES
(1, 'Demo Driver Ava'),
(2, 'Demo Driver Ben');

INSERT INTO customers (id, name, address, phone, email, latitude, longitude, deliver_by, deliver_after, delivery_time, zone, default_pan_dulce_price) VALUES
(1, 'Demo Cafe Alpha', '100 Demo Street, Testville, CA 90000', '555-0101', 'alpha@example.test', 37.78000000, -122.41000000, '08:00:00', NULL, 15, 'North Zone', 2.00),
(2, 'Demo Market Beta', '200 Sample Ave, Testville, CA 90001', '555-0102', 'beta@example.test', 37.76000000, -122.42000000, NULL, '15:00:00', 20, 'South Zone', NULL),
(3, 'Demo Spot Gamma', '300 Fixture Rd, Testville, CA 90002', '555-0103', 'gamma@example.test', 37.75000000, -122.43000000, '09:30:00', NULL, 10, 'Ruta Demo', 2.10);

-- Standing orders: days 1-7 (Mon-Sun). Includes Sunday=7 for characterization.
INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES
(1, 1, 1, 5),
(1, 1, 3, 5),
(1, 1, 5, 8),
(1, 3, 1, 12),
(1, 3, 5, 20),
(2, 2, 2, 4),
(2, 2, 4, 4),
(2, 4, 6, 6),
(3, 1, 1, 3),
(3, 1, 7, 2),
(3, 3, 7, 10),
(3, 4, 7, 1);

INSERT INTO standing_routes (day_of_week, driver_id, customer_id) VALUES
(1, 1, 1),
(1, 1, 3),
(2, 2, 2),
(5, 1, 1),
(7, 1, 3);

INSERT INTO leads (customer_name, contact_name, phone, email, address, notes, status) VALUES
('Demo Prospect Shop', 'Casey Contact', '555-0199', 'casey@example.test', '400 Lead Lane, Testville', 'Fictional lead only', 'new');
