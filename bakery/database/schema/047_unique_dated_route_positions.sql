-- Normalize every historical driver/date route to a stable 1..N sequence.
UPDATE daily_order_assignments doa
JOIN (
    SELECT id,
           ROW_NUMBER() OVER (
               PARTITION BY driver_id, delivery_date
               ORDER BY route_order, id
           ) AS normalized_route_order
    FROM daily_order_assignments
) ranked ON ranked.id = doa.id
SET doa.route_order = ranked.normalized_route_order;

-- Prevent late generation or concurrent edits from creating ambiguous stops.
CREATE UNIQUE INDEX uq_assignment_driver_date_route_order
  ON daily_order_assignments (driver_id, delivery_date, route_order);
