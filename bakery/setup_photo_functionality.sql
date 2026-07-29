-- Photo Storage Table for Driver Deliveries
-- Each photo is tied to a specific customer delivery
CREATE TABLE IF NOT EXISTS `driver_photos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `delivery_date` date NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `file_size` int(11) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `photo_type` enum('Before','After','Receipt') DEFAULT 'Before',
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_driver_customer_date` (`driver_id`, `customer_id`, `delivery_date`),
  KEY `idx_customer_date` (`customer_id`, `delivery_date`),
  KEY `idx_photo_type` (`photo_type`),
  KEY `idx_delivery_date` (`delivery_date`),
  FOREIGN KEY (`driver_id`) REFERENCES `drivers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create indexes for better performance
CREATE INDEX `idx_driver_photos_location` ON `driver_photos`(`latitude`, `longitude`);

-- Add photo tracking to driver_history for analytics (optional)
ALTER TABLE `driver_history` 
ADD COLUMN `photos_taken` int(11) DEFAULT 0 AFTER `longitude`;

-- Insert some sample data for testing (remove in production)
-- INSERT INTO `driver_photos` (`driver_id`, `customer_id`, `delivery_date`, `filename`, `original_filename`, `file_path`, `file_size`, `mime_type`, `photo_type`, `notes`) VALUES
-- (1, 1, CURDATE(), 'sample_before_001.jpg', 'store_front.jpg', '/uploads/driver_photos/2024/12/', 150000, 'image/jpeg', 'Before', 'Store front before delivery');

-- Verify table creation
DESCRIBE `driver_photos`;

-- Show current drivers and customers for reference
SELECT 'Drivers:' as info_type, id, name FROM drivers 
UNION ALL
SELECT 'Customers:', id, name FROM customers LIMIT 10; 