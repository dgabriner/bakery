# Prompt 10 — Exception connections

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace.

Sister prompts: `docs/prompts/11-exception-mobile.md`, `docs/prompts/12-exception-desktop.md`. You own the **graph** between exceptions and existing workflows. You do not rebuild Manager Mode. You do not design a new mobile desk or desktop workshop.

---

You are upgrading Sour Flour OS so an exception is never a dead-end list item. Staff must be able to land on the exact rows that caused it, do the real work there, and return to the date they came from — for every situation that already has a type.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework. No new top-level page scripts.
- Read `BAKERY_PRODUCT_CONTEXT.md` before coding. Improve existing workflows. Prefer chips on the decision screen over a new report.
- One exception contract: `bakery_ops_exception()` / `bakery_ops_enrich_exceptions()` / `bakery_ops_link_*()` in `includes/operational_exceptions.php`. Do not invent a second DTO.
- Completing manager work **never** suppresses the underlying operational fact. Deep links and filters stay honest.
- Mutations stay on canonical surfaces: Daily Orders, Production Center, Daily Production, Pack List, Driver Assignment, Driver Load, Route Closeout, Billing Center, Service Issues. Inline actions may call `daily_run_api.php` only for already-safe bulk helpers.
- Failed-stop recovery stays in `includes/delivery_recovery.php`. Do not mark invoices paid, issue credits, or change delivered quantities from an exception chip.
- Synthetics stay out of wholesale ops (`bakery_sfb_ops_origin_clause`).
- i18n: every new string in `lang/en.php` **and** `lang/es.php`.
- Local only. Do not deploy. Do not enable auto-push. Do not `setup_local_db` against `bakerysf_local`.
- End with a §10 handoff from `BAKERY_PRODUCT_CONTEXT.md`.

## Read first

- `BAKERY_PRODUCT_CONTEXT.md` §§3, 4, 6, 8
- `includes/operational_exceptions.php`
- `includes/dashboard_command_center.php` (where exceptions are born)
- `daily_run_api.php`
- Destination pages that already render `bakery_ops_render_return_banner`: `daily_orders.php`, `driver_assignment.php`, `driver_load.php`, `route_closeout.php`, `billing_center.php`, `production_center.php`, `inventory.php`, `ingredient_requirements.php`, `service_issues.php`
- Destination pages that **do not**: `pack_list.php`, `production.php`, `customer_record.php`, `driver.php`
- `includes/manager_mode.php` (work keys — do not restyle the queue)
- `tests/run_golden_day_qa.php`, `tests/run_tomorrow_confirmed_tests.php`

## What is already true (do not redesign)

- Exceptions are recomputed live from the operating date. Types include missing/empty demand, plan short, FG shortfall, unassigned stops, failed delivery, qty variance, uninvoiced, open service issues, open routes, ingredient alerts.
- Deep links already append `return=` (`daily_run` | `dashboard` | `daily_brief` | `manager`) and often `attention=` / `filter=` / `review=`.
- Several destination pages already show a return banner. Pack List and Daily Production do not.
- Inline actions exist only for generate daily orders, build routes from standing, confirm demand, mark one invoice.
- `delivery_failed` currently deep-links to `driver_list.php` (legacy). Canonical recovery is Manager Mode + Driver Assignment.

## Ship

1. **Round-trip on every destination that an exception can open.**  
   Honor `return=` with `bakery_ops_render_return_banner`. Honor the filter the link promised (`review`, `attention`, `filter`) and actually show those rows first. Add Pack List and Daily Production. Failed delivery must land on Manager recovery or Driver Assignment with failed stops filtered — **not** `driver_list.php`.

2. **Situation chips on the rows that caused the exception.**  
   On Daily Orders, Pack List, Daily Production, Driver Assignment, Driver Load, Billing invoices, Production Center: if this date has exceptions whose `context` matches the row (customer / product / driver / order), show a compact chip (severity + one verb). Clicking the chip scrolls to or filters that row. Do not duplicate the Manager attention queue on these pages.

3. **Situation actions that already exist as real mutations — expose them next to the chip.**  
   Catalog, do not invent: generate dated orders (preserve edits), confirm demand, save/set production target to demand, record production, pack check-off, build/assign route, save load, retry/reassign via existing recovery service, mark invoiced, open service issue. One primary action per chip; secondary is “Open full screen”. Unsafe or ambiguous actions stay as deep links.

4. **Related strip on Customer Hub** for the selected date: open exceptions, open service issues, failed-stop case, uninvoiced delivery. Summaries + deep links only — no new editors in the hub.

5. **After a successful inline/API action, return to the origin date with a flash** that names what changed. If the exception is still true, it must still show. If it is gone, the list is quieter. Never fake a clear.

## Constraints

- Do not add `exception_connections.php` as a page.
- Do not change `manager.php` layout (Prompts 11 and 12 own that). You may add helpers they can call.
- Do not add a new exception table. `manager_exception_work` is coordination only.
- Preserve §4: dated beats standing per customer; generation preserves dated edits unless `overwrite_changed`; invoice snapshots stay read-only on Billing Center.
- Extend `daily_run_api.php` only when the action is already a shared helper and CSRF/role gated.

## Tests

Add `tests/run_exception_connection_tests.php` (local/test DB only, `bakery_assert_local_test_target`):

- Each known exception type produces a deep link whose path is a canonical page (not `driver_list.php` / `generate_invoice*.php`).
- `return=manager` round-trips to `manager.php?date=`.
- Failed-delivery type points at recovery or assignment with a failed/attention filter.
- Inline `generate_daily_orders` still uses `overwrite_changed=false`.
- Chip helper is a no-op when context ids are missing (no fatal).

Also run `tests/run_tomorrow_confirmed_tests.php` and `tests/run_golden_day_qa.php` if you touch demand or billing helpers.

## Done when

- From Daily Run or Dashboard, every exception opens the right filtered rows and offers Back to that date.
- Pack List and Daily Production participate in the same round-trip.
- A manager can fix a typical missing-order / unassigned-stop / short-plan situation without memorizing which menu item to open.
- Customer Hub shows that customer’s open situations for the date.
- en/es complete. No deploy.
