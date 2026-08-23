-- SQL Script to Add Latitude and Longitude Columns to Customers Table
-- This will allow for mapping and distance calculations

-- Add latitude and longitude columns to the customers table
ALTER TABLE customers 
ADD COLUMN latitude DECIMAL(10, 8) NULL,
ADD COLUMN longitude DECIMAL(11, 8) NULL;

-- Add an index on the coordinate columns for faster geo-queries
CREATE INDEX idx_customers_coordinates ON customers (latitude, longitude);

-- Verify the table structure (this should work with most database permissions)
DESCRIBE customers;

-- Simple verification: Select a few customers to see the new columns
SELECT 
    id,
    name,
    address,
    latitude,
    longitude
FROM customers 
LIMIT 5; 