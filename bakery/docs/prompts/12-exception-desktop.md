# Prompt 12 — Desktop exception workshop

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace.

Sister prompts: `docs/prompts/10-exception-connections.md`, `docs/prompts/11-exception-mobile.md`. You own **computer super-features**: many ways to add / change / review / hand off situations on a large screen. You do not simplify the phone UI (Prompt 11). You do not rebuild destination-page chips (Prompt 10).

---

You are turning Manager Mode’s attention queue into a **workshop** for the operating date: scan, group, act in bulk, open related work, and record what was tried — without becoming a generic ticketing product. Every action must map to a bakery-day moment (wrong qty, missing stop, short bake, failed delivery, uninvoiced, customer complaint).

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework. Prefer an include over a new page.
- Read `BAKERY_PRODUCT_CONTEXT.md` §§3, 4, 6, 8. Exception-driven: normal case silent, unusual loud. Bulk + defaults beat new forms.
- Reuse `bakery_ops_exception`, `bakery_manager_enrich_exception_work`, `bakery_manager_exception_save`, `bakery_delivery_recovery_*`, Billing Center, Service Issues. **No second source of truth.**
- Completing workshop work never hides a still-true operational exception. Filters can hide *completed coordination* with an explicit toggle; live facts stay.
- Canonical mutations: do not duplicate Daily Orders editors, Driver Assignment drag-drop, or Billing mark-invoiced inside the workshop. The workshop **invokes** those services / deep-links with selection context.
- i18n: `lang/en.php` and `lang/es.php`.
- Local only. Do not deploy. Do not enable auto-push.
- End with a §10 handoff.

## Read first

- `manager.php` (attention queue, driver board, recovery, tool suite)
- `includes/manager_mode.php`, `includes/operational_exceptions.php`, `includes/delivery_recovery.php`
- `includes/operational_timeline.php` (append-only audit — use it)
- `service_issues.php` / `includes/customer_delivery_issues.php`
- `billing_api.php` bulk mark-invoiced
- `docs/FAILED_STOP_RECOVERY_MODEL.md`, `docs/MANAGER_MODE_DEEP_DIVE.md`
- `tests/run_manager_mode_tests.php`, `tests/run_failed_stop_recovery_tests.php`

## What is already true (do not redesign)

- Queue is live-recomputed exceptions + optional `manager_exception_work` (ack, assignee, due, note, complete).
- Failed-stop recovery is a real state machine with billing **handoff** (not billing mutation).
- Service issues are a real customer-reported queue.
- Daily Run still gates closeout on operational blockers, not on whether a manager clicked Complete.
- Inline generate/assign/confirm already exist on Daily Run / Dashboard via `daily_run_api.php`.

## Ship

New include `includes/exception_workshop.php` + `css/exception_workshop.css`. Insert one call in `manager.php` at a marked comment:

```php
<?php if (function_exists('bakery_exception_workshop_render')) { bakery_exception_workshop_render($db, $selectedDate, $exceptions); } ?>
```

Show the workshop at **min-width 900px**. Below that, leave Prompt 11’s desk (or the current dense forms) alone. Do not delete the mobile include if it exists.

### Super-features (desktop only)

1. **Filters and grouping**  
   Filter: severity, category/stage, assignee (me / unassigned / named), coordination state (new / owned / completed), type.  
   Group: none (default), customer, product, driver, stage. Counts on each group. Keyboard: `j`/`k` move, `Enter` open Fix, `a` assign-to-me, `c` complete (if note present).

2. **Related situations**  
   Selecting one exception opens a side panel of others sharing `customer_id` / `product_id` / `driver_id` / `daily_order_id` on this date, plus that customer’s open service issues and recovery case. Deep links use Prompt 10 helpers when present (`bakery_ops_link_*`).

3. **Bulk coordination** (not bulk lying)  
   Multi-select → Mine / Assign / Complete-with-one-note. Bulk complete still requires a note.  
   Bulk **operational** actions only where a shared helper already exists: generate dated orders for the date, build routes from standing, bulk mark invoiced for selected order ids via `bakery_billing_mark_invoiced`. Confirm each. Preserve generation `overwrite_changed=false`. Do not bulk-edit quantities.

4. **Create / change a situation from a row**  
   From the driver board, production handoff, or related panel, a manager can:  
   - open failed-stop recovery (existing report)  
   - open a service issue for that customer/order  
   - add a work note / due / assignee on the matching exception  
   - “Confirm demand again” if `demand_changed_since`  
   Do **not** allow free-text tickets with no type. New work must attach to an existing exception type, a recovery case, or a service issue.

5. **Review what was tried**  
   Side panel shows `operational_events` for that order/customer/date (existing timeline helpers). Completing work writes an event. Do not build a second audit table.

6. **Split view**  
   Left: grouped queue. Right: detail + related + timeline + primary Fix button. Resizable optional; a CSS two-column grid is enough. Remember last filter in a cookie or query string (`?ex_group=&ex_filter=`).

## Constraints

- Do not add `tickets.php` or a general CRM.
- Do not let Complete mean “day is fine.” Daily Run blockers stay computed.
- Do not price or send invoices here. Billing Center remains canonical; workshop may call mark-invoiced with selected ids.
- Do not reimplement Driver Assignment. Reassign in recovery must keep calling the assignment transfer service.
- Touch `manager.php` only for the include call and a `.manager-workshop-host` wrapper. If Prompt 11 added `.manager-desktop-only`, put the workshop inside it.
- If Prompt 10 has not landed, use current `href` / `bakery_ops_link_*`. Do not wait, and do not fork link builders.

## Tests

Add `tests/run_exception_workshop_tests.php` (local/test target guard):

- Group-by customer clusters two exceptions that share `context.customer_id`.
- Bulk complete without a note throws / is rejected.
- Bulk mark-invoiced only touches selected delivered order ids.
- Generate-from-workshop uses `overwrite_changed=false`.
- Workshop markup is absent from a simulated mobile-only render helper (or CSS class `exception-workshop` documented as `min-width: 900px`).
- Recovery still cannot mark an invoice paid.

Keep `tests/run_manager_mode_tests.php` and `tests/run_failed_stop_recovery_tests.php` green.

## Done when

- On a computer, a manager can filter, group, assign, note, and jump to related work for the date without leaving Manager Mode except for the canonical Fix screen.
- Bulk actions exist for coordination and for the few safe operational helpers — not for silent quantity edits.
- A new situation can be opened only as recovery, service issue, or work-on-existing-exception.
- Phone layout is unchanged (workshop hidden).
- en/es complete. No deploy.
