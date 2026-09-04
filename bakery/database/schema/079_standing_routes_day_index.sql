-- Standing-route day lookup used by route build and driver assignment.
-- Guarded by hosted migration runtime (duplicate index name 1061 is ignored).

ALTER TABLE standing_routes
  ADD KEY idx_standing_routes_day_driver (day_of_week, driver_id);
