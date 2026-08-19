# Prompt 22 — Credits as finished-goods returns

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace.

Sister prompts: `docs/prompts/20-commit-production-plan.md`, `docs/prompts/21-canonical-invoice-send.md`. You own **credits → stock**. You do not commit production plans. You do not email invoices.

---

You are closing the inventory hole on Sour Flour OS delivery confirm: `credits_taken_back` already reduces the billable snapshot, but those pieces never become a finished-goods `return` movement. Route closeout already reconciles van leftover (loaded = delivered + returned + waste). Door credits must join that same ledger without double-counting.

A credit is not a discount if the loaf comes home.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework. No new top-level page scripts.
- Read `BAKERY_PRODUCT_CONTEXT.md` §§3 (driver), 4 (loads, snapshots, closeout), 6.3, 8, 10 before coding.
- Homebase: `php scripts/agent_homebase.php brief --agent=credits-as-returns --json` then complete unread lessons, then `start --mission="Door credits post FG return movements"`.
- Loads move custody, not ownership. Route closeout remains the van reconciliation. You extend confirm so credited pieces are stock, not a ghost.
- Completing exception work never hides a still-true shortfall.
- i18n: every new string in `lang/en.php` **and** `lang/es.php`.
- Local/test DB only. Do not deploy. Do not enable auto-push.
- End with a §10 handoff via `php scripts/agent_homebase.php handoff`.

## Read first

- `complete_delivery.php` confirm path: `delivered_pieces`, `credits_taken_back`, billable = delivered − credits, snapshot totals
- Line items: `daily_order_items.delivered_quantity` (per SKU) vs header `credits_taken_back` (order-level — this is the hard part)
- `includes/product_inventory.php` — `bakery_inventory_movement` types `return`, `waste`, `delivery`; `bakery_inventory_record_production` (do not change unless required); `bakery_inventory_reconcile_driver_load`
- `route_closeout.php` + closeout helpers — leftover on the van vs delivered
- `includes/delivery_invoice.php` / `bakery_delivery_invoice` if present
- Driver UI that collects credits (`driver.php` / confirm wizard) — do not redesign the wizard
- `tests/run_golden_day_qa.php` (already exercises waste/return on closeout)

## What is already true (do not redesign)

- Driver confirm is transactional: assignment + order status + delivered qtys + pricing snapshot in one step. Keep that atomic.
- Billable math stays: `billable_pieces = delivered_pieces - credits_taken_back`. Do not change what the customer is charged in this ticket.
- Invoice snapshots stay frozen. Prompt 21 will send them; you only make stock match the physical credits.
- Route closeout posts van `returned_quantity` / `wasted_quantity`. Those are leftover-on-truck, not café credits.
- `inventory_movements.movement_type` already includes `return`. Reuse it. Do not add `credit` as a new type unless closeout cannot distinguish sources in notes.

## Ship

1. **On successful delivery confirm, post FG returns for credited pieces.**  
   Same transaction as the snapshot write. `return` movements increase available finished goods for that delivery date. Notes should name the order + “credit taken back”.

2. **Allocate credits to products honestly.**  
   Header `credits_taken_back` is not per SKU. Read the confirm + line `delivered_quantity` path end-to-end. Prefer: credits reduce billed qty against specific lines (already-delivered units taken back), then return those product_ids. If the order is single-product (common), allocation is trivial. If multi-product and only a header credit exists, define one explicit rule (e.g. allocate to lines in a stable order, or require line-level credit fields). Document the rule in `BAKERY_PRODUCT_CONTEXT.md`. Do not silently dump all credits onto the first SKU without saying so on the confirm UI.

3. **Idempotent.**  
   Re-confirm / replay must not double-return. If confirm is currently insert-once, keep it that way; if credits can be edited after confirm, adjust movements to match (delta), still inside the inventory helpers with row locking.

4. **No double-count with route closeout.**  
   Closeout must not treat the same loaf as both “delivered” and “van return” and “credit return”. After your change: credited pieces are bakery stock; they are not still “out on the route.” Add or adjust a test that would fail if both confirm and closeout returned the same unit.

5. **Baker/pack/load remaining stay coherent.**  
   Over-load prevention and available_quantity must see the return. Do not bypass `bakery_inventory_*` helpers with raw UPDATEs.

## Constraints

- Do not add `credit_returns.php` as a page. No Billing Center send work. No Production Center commit work.
- Do not edit `production.php` / `production_center.php` / `includes/billing.php` / `customer_invoice.php`.
- Do not build bake-sheet waste logging (production waste is a different movement origin).
- Do not mark invoices invoiced, send email, or change COD collection rules.
- Failed-stop recovery must not become a back-door credit+return without going through confirm semantics (`includes/delivery_recovery.php` — do not add billing/credit mutations there).

## Tests

Add `tests/run_credit_return_tests.php` (`bakery_assert_local_test_target`):

- Confirm with credits_taken_back = N posts return movement(s) totaling N and increases available_quantity by N.
- Confirm with credits = 0 posts no return.
- Second confirm / replay does not double available_quantity.
- Snapshot billable total still uses delivered − credits (unchanged).
- Closeout of that driver/date does not return the same N again.
- Multi-product order follows the documented allocation rule (at least one fixture).

Also run `tests/run_golden_day_qa.php`. Update `BAKERY_PRODUCT_CONTEXT.md` §6.3.

## Done when

- A driver taking two loaves back at the door puts two units into FG for that date, the invoice still charges the reduced billable count, and route closeout still balances.
- Homebase handoff has all eight §10 fields.
