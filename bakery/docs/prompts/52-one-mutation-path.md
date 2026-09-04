# Prompt 52 — One mutation path for orders

Wave 3 (scalability). `--agent=one-mutation-path`. Depends on Prompt 51.

---

Three SQL patterns are copy-pasted: standing-order upsert (`INSERT … ON DUPLICATE KEY UPDATE`) in 9 files; find daily order by customer+date in 4; recompute `daily_orders.total_amount` in 4. Drift between copies is how invariants break.

## Read first

- `includes/customer_order_mutations.php`, `includes/daily_order_generation.php`
- Call sites: `standing_orders_manager.php`, `standing_orders.php`, `bread_distribution.php`, `product_distribution.php`, `includes/customer_portal.php`, `includes/driver_assignments.php`, `includes/production_assign.php`, `includes/pan_dulce_standards.php`, `includes/survey_store_verify.php`, `daily_orders.php`
- `BAKERY_PRODUCT_CONTEXT.md` §4.1–4.3

## Ship

1. `bakery_standing_order_upsert(PDO $db, int $customerId, int $productId, int $dayOfWeek, int $qty): void` (delete on zero).
2. `bakery_daily_order_find_or_create(PDO $db, int $customerId, string $date, array $opts = []): int`.
3. `bakery_daily_order_recompute_total(PDO $db, int $orderId): float`.
4. Replace every call site; delete the inline SQL. Record an `operational_events` row where the old code did.

## Tests

`run_operating_demand_tests.php`, `run_customer_order_power_tests.php`, `run_golden_day_qa.php`, `run_tomorrow_confirmed_tests.php`, plus Prompt 37 suites. Add a grep-assert in `run_integrity_tests.php` that no root page contains `INSERT INTO standing_orders`.

## Done when

`rg "INSERT INTO standing_orders" *.php includes/*.php` returns only `includes/customer_order_mutations.php`.

**Status:** shipped 2026-09-04 on `cursor/sour-flour-agent-program-a061` (PR #20). `bakery_standing_order_upsert`, `bakery_daily_order_find_or_create`, `bakery_daily_order_recompute_total` own the three SQL patterns; integrity grep-assert green. Staging and Live were not touched.
