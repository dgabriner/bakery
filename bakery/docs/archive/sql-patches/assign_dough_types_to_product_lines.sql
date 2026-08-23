-- Helper script to assign existing dough types to product lines
-- Run this after creating the product lines structure

-- First, let's see what dough types you currently have
SELECT 'Current Dough Types:' as info;
SELECT id, name, description FROM dough_types ORDER BY name;

-- Sour Flour Product Line - Traditional sourdough and artisan breads
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Sour Flour')
WHERE LOWER(name) LIKE '%sourdough%' 
   OR LOWER(name) LIKE '%sour%'
   OR LOWER(name) LIKE '%artisan%'
   OR LOWER(name) LIKE '%levain%'
   OR LOWER(name) LIKE '%starter%'
   OR LOWER(name) LIKE '%wild yeast%';

-- Pan Dulce Product Line - Sweet Mexican pastries and dessert breads  
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Pan Dulce')
WHERE LOWER(name) LIKE '%sweet%'
   OR LOWER(name) LIKE '%concha%'
   OR LOWER(name) LIKE '%brioche%'
   OR LOWER(name) LIKE '%enriched%'
   OR LOWER(name) LIKE '%cinnamon%'
   OR LOWER(name) LIKE '%sugar%'
   OR LOWER(name) LIKE '%butter%'
   OR LOWER(name) LIKE '%danish%'
   OR LOWER(name) LIKE '%croissant%'
   OR LOWER(name) LIKE '%pastry%'
   OR LOWER(name) LIKE '%donut%'
   OR LOWER(name) LIKE '%churro%';

-- Pan Grande Product Line - Large format breads and bulk production
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Pan Grande')
WHERE LOWER(name) LIKE '%sandwich%'
   OR LOWER(name) LIKE '%loaf%'
   OR LOWER(name) LIKE '%pullman%'
   OR LOWER(name) LIKE '%texas%'
   OR LOWER(name) LIKE '%bulk%'
   OR LOWER(name) LIKE '%large%'
   OR LOWER(name) LIKE '%grande%'
   OR LOWER(name) LIKE '%pan de%';

-- Traditional Product Line - Classic bread varieties
UPDATE dough_types SET product_line_id = (SELECT id FROM product_lines WHERE name = 'Traditional')
WHERE LOWER(name) LIKE '%french%'
   OR LOWER(name) LIKE '%italian%'
   OR LOWER(name) LIKE '%baguette%'
   OR LOWER(name) LIKE '%focaccia%'
   OR LOWER(name) LIKE '%pizza%'
   OR LOWER(name) LIKE '%white bread%'
   OR LOWER(name) LIKE '%whole wheat%'
   OR LOWER(name) LIKE '%multigrain%'
   OR LOWER(name) LIKE '%rye%'
   OR LOWER(name) LIKE '%pumpernickel%'
   OR LOWER(name) LIKE '%country%'
   OR LOWER(name) LIKE '%rustic%';

-- Show results after assignment
SELECT 'Assignment Results:' as info;
SELECT 
    pl.name as product_line,
    dt.name as dough_type,
    dt.description
FROM dough_types dt
LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
ORDER BY pl.sort_order, dt.name;

-- Show any unassigned dough types that need manual assignment
SELECT 'Unassigned Dough Types (require manual assignment):' as info;
SELECT 
    id,
    name,
    description,
    CONCAT('UPDATE dough_types SET product_line_id = [PRODUCT_LINE_ID] WHERE id = ', id, ';') as suggested_sql
FROM dough_types 
WHERE product_line_id IS NULL
ORDER BY name;

-- Show summary by product line
SELECT 'Product Line Summary:' as info;
SELECT 
    pl.name as product_line,
    pl.description,
    COUNT(dt.id) as dough_type_count,
    GROUP_CONCAT(dt.name ORDER BY dt.name SEPARATOR ', ') as dough_types
FROM product_lines pl
LEFT JOIN dough_types dt ON dt.product_line_id = pl.id
GROUP BY pl.id
ORDER BY pl.sort_order; 