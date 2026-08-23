-- Create Product Lines table
CREATE TABLE IF NOT EXISTS product_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    color_code VARCHAR(7) DEFAULT '#3498db', -- Hex color for UI display
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert the initial Product Lines
INSERT INTO product_lines (name, description, color_code, sort_order) VALUES
('Sour Flour', 'Traditional sourdough and artisan breads with natural fermentation', '#e67e22', 1),
('Pan Dulce', 'Sweet Mexican pastries and dessert breads', '#e74c3c', 2),
('Pan Grande', 'Large format breads and bulk production items', '#2ecc71', 3),
('Traditional', 'Classic bread varieties and standard bakery items', '#3498db', 4);

-- Add product_line_id column to dough_types table
ALTER TABLE dough_types 
ADD COLUMN product_line_id INT,
ADD CONSTRAINT fk_dough_types_product_line 
    FOREIGN KEY (product_line_id) REFERENCES product_lines(id) 
    ON DELETE SET NULL ON UPDATE CASCADE;

-- Add index for better performance
CREATE INDEX idx_dough_types_product_line ON dough_types(product_line_id);

-- Sample UPDATE statements to assign existing dough types to product lines
-- You'll need to adjust these based on your actual dough type names

-- Example assignments (uncomment and modify as needed):
/*
-- Sour Flour assignments
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Sour Flour')
WHERE name IN ('Sourdough', 'Artisan White', 'Whole Wheat Sourdough', 'Rye Sourdough');

-- Pan Dulce assignments  
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Pan Dulce')
WHERE name IN ('Sweet Dough', 'Conchas', 'Brioche', 'Enriched Dough', 'Cinnamon Roll');

-- Pan Grande assignments
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Pan Grande') 
WHERE name IN ('White Bread', 'Whole Wheat', 'Multigrain', 'Sandwich Loaf');

-- Traditional assignments
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Traditional')
WHERE name IN ('French Bread', 'Italian', 'Focaccia', 'Pizza Dough', 'Baguette');
*/

-- View to see current dough types and their product line assignments
CREATE OR REPLACE VIEW v_dough_types_with_product_lines AS
SELECT 
    dt.id,
    dt.name as dough_type_name,
    dt.description as dough_type_description,
    pl.id as product_line_id,
    pl.name as product_line_name,
    pl.description as product_line_description,
    pl.color_code,
    COUNT(p.id) as product_count
FROM dough_types dt
LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
LEFT JOIN products p ON p.dough_type_id = dt.id
GROUP BY dt.id, pl.id
ORDER BY pl.sort_order, dt.name;

-- Query to see unassigned dough types (helpful for initial setup)
SELECT 
    id,
    name,
    description
FROM dough_types 
WHERE product_line_id IS NULL
ORDER BY name;

-- Query to see product line summary
SELECT 
    pl.name as product_line,
    pl.description,
    COUNT(DISTINCT dt.id) as dough_type_count,
    COUNT(DISTINCT p.id) as total_products
FROM product_lines pl
LEFT JOIN dough_types dt ON dt.product_line_id = pl.id
LEFT JOIN products p ON p.dough_type_id = dt.id
GROUP BY pl.id
ORDER BY pl.sort_order; 