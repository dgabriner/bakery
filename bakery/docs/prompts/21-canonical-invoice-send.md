# Prompt 21 — Canonical invoice send

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace.

Sister prompts: `docs/prompts/20-commit-production-plan.md`, `docs/prompts/22-credits-as-returns.md`. You own **invoice → customer**. You do not change the bake sheet. You do not post inventory returns.

---

You are closing Sour Flour OS Daily Run invoicing so a week of deliveries can be marked **and actually sent** as the same document the customer already sees in the portal. Billing Center already bulk-marks invoiced from delivery snapshots. The remaining hole is a real customer send, plus stopping legacy generators from minting a second price universe.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework. No new top-level page scripts.
- Read `BAKERY_PRODUCT_CONTEXT.md` §§3, 4.4–4.5, 6.4, 7.4, 8, 10 before coding.
- Homebase: `php scripts/agent_homebase.php brief --agent=canonical-invoice-send --json` then complete unread lessons, then `start --mission="Canonical invoice document send from Billing Center"`.
- **Never price historical invoices from live `products.price`.** Amounts come from delivery snapshots (`delivery_order_total`, line prices frozen at confirm).
- Billing Center marks invoiced; it does not invent amounts.
- i18n: every new string in `lang/en.php` **and** `lang/es.php`.
- Local/test DB only. `MAIL_DRIVER=log` must never SMTP a real customer. Staging must stay on log. Do not deploy. Do not enable auto-push.
- End with a §10 handoff via `php scripts/agent_homebase.php handoff`.

## Read first

- `BAKERY_PRODUCT_CONTEXT.md` billing rows in §5, open loop §6.4, roadmap §7.4
- `billing_center.php`, `includes/billing.php` (`bakery_billing_mark_invoiced`, `bakery_billing_email_ready`)
- `includes/billing_panel_invoices.php` — bulk `bulk_mark_invoiced` already exists
- `customer_invoice.php` — **canonical per-delivery document** (portal + staff)
- `customer_portal_billing.php`, `includes/customer_notifications.php` (`bakery_customer_notify_invoice_available`)
- `includes/email_utils.php`
- `billing_api.php`, `daily_run_api.php` `mark_invoiced`
- `includes/daily_run.php` invoice stage (complete when uninvoiced/unconfirmed are zero)
- Legacy traps: `generate_invoice.php`, `generate_invoice_simple.php`, `simple_invoice.php`, `orders.php` email JS, `customer_record.php` link to `simple_invoice.php`
- `tests/run_golden_day_qa.php`, customer billing tests if present (`tests/run_customer_*`)

## What is already true (do not redesign)

- Confirming a delivery freezes the billable snapshot. You read it; you do not recompute from catalog.
- `bakery_billing_mark_invoiced` sets `daily_orders.status = 'invoiced'` only when `delivery_confirmed_at` is set.
- Exception workshop and Daily Run can already bulk/single mark invoiced via that helper. Keep one mutation path.
- Portal customers can already **view** `customer_invoice.php`. Staff send must be that same document (HTML/PDF), not a new template with live prices.
- Statement generate + “email not configured” copy already exist. Invoice send should follow the same MAIL_DRIVER honesty.
- QuickBooks CSV remains the accounting export. Do not build AR aging, Square pay, or weekly rollup invoices in this ticket (those are Wave 2).

## Ship

1. **Send the canonical document.**  
   From Billing Center (single + bulk on selected delivered/invoiced rows): send `customer_invoice.php` (or a shared renderer it already uses) to the customer’s billing email. Record `sent_at` / recipient / actor on a durable column or reuse statement-style fields — do not invent a second invoice-number sequence. Idempotent-ish: re-send is allowed and logged; mark-invoiced still happens if not already invoiced.

2. **Local/staging safety.**  
   When `MAIL_DRIVER=log` or SMTP is missing: do not send SMTP; persist a log/outbox row; UI says “recorded, not emailed” (existing `bakery_billing_email_ready` pattern). Never hard-code a test inbox as the customer destination.

3. **Quarantine legacy generators.**  
   `generate_invoice.php`, `generate_invoice_simple.php`, and `simple_invoice.php` must not remain a live pricing path. Redirect staff to Billing Center / `customer_invoice.php` (or historical nav only). Update `customer_record.php` / `orders.php` links that still open `simple_invoice.php`. Do not “fix” legacy generators by teaching them live catalog math.

4. **Daily Run invoice stage.**  
   Stage can stay complete when delivered orders are marked invoiced (send may be optional per customer). If you add a “sent” metric, show it as a chip — do not block day close solely because SMTP is log-mode.

## Constraints

- Do not add `invoice_send.php` as a new top-level page. Extend Billing Center + `includes/billing.php`.
- Do not edit `production.php`, `production_center.php`, or `complete_delivery.php`.
- In `includes/daily_run.php` touch only the Invoice stage block if needed.
- Do not wire Square. Do not send from the driver wizard.
- CSRF + `bakery_require_role` on the send action. Drivers do not send invoices.

## Tests

Add or extend a focused suite (e.g. `tests/run_invoice_send_tests.php`) with `bakery_assert_local_test_target`:

- Send uses snapshot totals, not `products.price`.
- Bulk send only includes selected orders that are delivery-confirmed.
- `MAIL_DRIVER=log` records send without throwing and without calling real SMTP.
- Legacy generator scripts no longer emit a separately numbered live-priced invoice for staff (redirect or quarantine assertion).
- `bakery_billing_mark_invoiced` still refuses unconfirmed deliveries.

Also run `tests/run_golden_day_qa.php`. Update `BAKERY_PRODUCT_CONTEXT.md` §6.4 / §7.4.

## Done when

- A manager can select this week’s delivered stops in Billing Center, mark invoiced, and send the **portal** invoice to each customer (or record the send when mail is log).
- Nobody on staff is one click away from `generate_invoice.php` live catalog prices.
- Homebase handoff has all eight §10 fields.
