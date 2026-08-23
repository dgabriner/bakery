-- Add default quantity columns for each day of the week
ALTER TABLE products 
ADD COLUMN default_quantity_monday INT NOT NULL DEFAULT 0,
ADD COLUMN default_quantity_tuesday INT NOT NULL DEFAULT 0,
ADD COLUMN default_quantity_wednesday INT NOT NULL DEFAULT 0,
ADD COLUMN default_quantity_thursday INT NOT NULL DEFAULT 0,
ADD COLUMN default_quantity_friday INT NOT NULL DEFAULT 0,
ADD COLUMN default_quantity_saturday INT NOT NULL DEFAULT 0,
ADD COLUMN default_quantity_sunday INT NOT NULL DEFAULT 0;

-- Update existing products with some sample default quantities
UPDATE products SET 
    default_quantity_monday = 10,
    default_quantity_tuesday = 10,
    default_quantity_wednesday = 10,
    default_quantity_thursday = 10,
    default_quantity_friday = 15,
    default_quantity_saturday = 20,
    default_quantity_sunday = 5
WHERE id > 0; 