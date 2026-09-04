# Prompt 37 — Characterize the untested core

Wave 1 (reliability). `--agent=characterize-core`. Prerequisite for Wave 3 refactors (50–52).

---

Four of the most-edited pages have no dedicated suite: `daily_orders.php` (generate/edit actions), `standing_orders_manager.php` (CRUD, copy week), `production_center.php` (save targets, commit, assign), `complete_delivery.php` (confirm transaction, credits, snapshot). Refactoring them without characterization is guessing.

## Read first

- The four pages' `$_POST['action']` switches and the helpers they call
- `tests/run_golden_day_qa.php`, `tests/run_tomorrow_confirmed_tests.php`, `tests/run_production_plan_commit_tests.php`, `tests/run_credit_return_tests.php` (existing partial coverage — do not duplicate, extend)
- `tests/harness.php`, `tests/isolate_test_db.php`

## Ship

Four suites, each on `bakerysf_test`, each exercising the page's actions the way the browser does (simulate `$_POST` + `$_SESSION` where the page is a controller; call the helper directly where one exists):

- `tests/run_daily_orders_page_tests.php` — generate single date preserves dated edits; create one-time order; edit quantity recomputes total; inactive customer excluded.
- `tests/run_standing_orders_manager_tests.php` — upsert, delete on zero, copy-week does not touch past dated orders, pause suppresses generation.
- `tests/run_production_center_tests.php` — save draft, commit snapshot, drift event on post-commit demand change, assign-to-orders standing vs dated.
- `tests/run_complete_delivery_tests.php` — confirm writes assignment + order + line quantities + snapshot in one transaction; door credits post `return` movements once; re-confirm deltas.

Register all four in `includes/agent_work_map.php` (the map test fails on unmapped suites).

## Done when

All four green twice; each asserts at least one §4 invariant by name in its output.

**Status:** shipped (2026-09-04). Four suites on `bakerysf_test` via page helpers (not HTTP controllers): generate/edit/one-time, standing upsert/copy/pause, production save/commit/drift/assign, delivery confirm transaction + credit delta. Registered under `characterize-core` in `includes/agent_program_map.php`. Staging and Live were not touched.
