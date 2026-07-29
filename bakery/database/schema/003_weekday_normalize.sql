-- =============================================================================
-- Post-0E: normalize legacy Sunday day_of_week=0 to canonical 7
-- Idempotent: safe to re-run (0 rows match after first apply)
-- =============================================================================

UPDATE standing_orders SET day_of_week = 7 WHERE day_of_week = 0;
UPDATE standing_routes SET day_of_week = 7 WHERE day_of_week = 0;
