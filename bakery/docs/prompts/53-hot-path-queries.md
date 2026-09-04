# Prompt 53 — Hot-path queries

Wave 3 (scalability). `--agent=hot-path-queries`.

---

Route build loops per stop (`includes/driver_assignments.php:404–446`: SELECT then INSERT/UPDATE per customer). Copy-week prepares per row (`standing_orders_manager.php:208`). Driver load looks up names per unknown id (`driver_load.php:409`). Daily Production queries formula ingredients per dough (`production.php:451`). `standing_routes` has no `day_of_week` index. `bread_distribution.php` opens its own PDO.

## Read first

- The four loops above; `database/schema/001_baseline.sql` (`standing_routes`), `047_unique_dated_route_positions.sql`
- `includes/database.php` connection factory
- `tests/run_driver_workflow_tests.php`, `tests/run_status_alignment_tests.php`, `tests/run_production_confirm_tests.php`

## Ship

1. Route build: one SELECT of existing `(customer_id → id)` for the date, one multi-row INSERT for missing orders, one `UPDATE … WHERE id IN (...)` for driver assignment. Same results, same events.
2. Batch the other three loops (`WHERE id IN`, single prepared statement reused).
3. Migration `NNN_standing_routes_day_index.sql`: `ALTER TABLE standing_routes ADD KEY idx_standing_routes_day_driver (day_of_week, driver_id)` (guarded).
4. `bread_distribution.php` uses `$db` from `includes/database.php`.
5. Handoff includes `EXPLAIN` before/after for the route-build query and query counts from a fixture route of 30 stops.

## Done when

Route build for N stops issues O(1) statements; suites green; new index recorded in `schema_migrations`.
