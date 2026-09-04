# Prompt 61 — One settlement story per delivery

Wave 4 (integration). `--agent=settlement-story`. Owner decision gates the ledger step.

---

Money lives in four computed views: COD `amount_collected` on the order (Route Manager), Square invoice status (`square_*`), email send records (`billing_invoice_sends`), and AR aging (`includes/billing_aging.php`). Nobody sees them on one row, and COD cash turn-in is not recorded anywhere.

## Read first

- `billing_center.php`, `includes/billing.php`, `includes/billing_aging.php`, `includes/billing_panel_invoices.php`, `includes/square_invoices.php`
- `route_manager.php` COD summary, `docs/billing_quickbooks_boundary.md`
- `BAKERY_PRODUCT_CONTEXT.md` §4.11, §6.4, §7.4

## Ship (no owner decision needed)

1. Billing Center row per confirmed delivery: snapshot total · COD collected · Square status · send status · open balance — all from existing sources; amounts read-only.
2. COD turn-in: `bakery_cod_turnin_record(PDO $db, int $driverId, string $date, float $amount, int $userId)` writing `cod_turnins` (new `NNN` migration, prefixed table, FK to `drivers`); Route Manager and the manager phone Routes card show "collected vs turned in" per driver per day.
3. Filters: unpaid > 14 days, COD not turned in, Square failed.

## Owner decision (before any ledger)

AR/payments ledger table (yes / computed-only), weekly rollup invoices (yes / no). Record the answer as a Homebase **Decided** and in `BAKERY_PRODUCT_CONTEXT.md` §6.4.

## Tests

`run_invoice_send_tests.php`, `run_square_invoice_tests.php`, `run_route_manager_cash_tests.php`, `run_customer_billing_tests.php`; new assertions for turn-in math.

## Done when

A manager answers "what is still owed on Tuesday's deliveries and where is the cash" from one screen.

**Status:** partial 2026-09-04 — steps 1–3 shipped (settlement row, `080_cod_turnins` + `bakery_cod_turnin_record`, unpaid>14d / COD not turned in / Square failed filters). AR ledger + weekly rollup still owner-gated. Staging and Live were not touched.
