-- Canonical delivery outcomes used by complete_delivery.php and recovery views.
-- Keep the legacy rescheduled value readable while adding cancelled explicitly.
ALTER TABLE daily_order_assignments
  MODIFY COLUMN delivery_status
    ENUM('pending','in_transit','delivered','failed','cancelled','rescheduled')
    NULL DEFAULT 'pending';

-- Older databases silently stored an empty ENUM value when code wrote
-- "cancelled" before the value existed. Repair historical skipped stops.
UPDATE daily_order_assignments
SET delivery_status = 'cancelled'
WHERE (delivery_status IS NULL OR delivery_status = '')
  AND notes LIKE 'Skipped:%';

UPDATE daily_order_assignments
SET delivery_status = 'pending'
WHERE delivery_status IS NULL OR delivery_status = '';

ALTER TABLE daily_order_assignments
  MODIFY COLUMN delivery_status
    ENUM('pending','in_transit','delivered','failed','cancelled','rescheduled')
    NOT NULL DEFAULT 'pending';
