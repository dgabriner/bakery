-- Create daily_order_assignments table for driver assignment functionality
CREATE TABLE IF NOT EXISTS `daily_order_assignments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `daily_order_id` int NOT NULL,
  `driver_id` int NOT NULL,
  `delivery_date` date NOT NULL,
  `scheduled_delivery_time` time DEFAULT NULL,
  `actual_delivery_time` time DEFAULT NULL,
  `route_order` int NOT NULL DEFAULT 0 COMMENT 'Order in the route (1, 2, 3, etc.)',
  `estimated_delivery_time` time DEFAULT NULL COMMENT 'Estimated time based on route planning',
  `delivery_status` enum('pending','in_transit','delivered','failed','cancelled') NOT NULL DEFAULT 'pending',
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`daily_order_id`, `driver_id`, `delivery_date`),
  KEY `idx_driver_date` (`driver_id`, `delivery_date`),
  KEY `idx_delivery_date` (`delivery_date`),
  KEY `idx_daily_order` (`daily_order_id`),
  CONSTRAINT `fk_daily_order_assignments_daily_order` FOREIGN KEY (`daily_order_id`) REFERENCES `daily_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_daily_order_assignments_driver` FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks daily order assignments to drivers with delivery times and route order'; 