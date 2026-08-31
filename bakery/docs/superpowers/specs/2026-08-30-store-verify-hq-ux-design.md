# Store-verify HQ UX — design

**Approved:** 2026-08-30 (owner: collapse, zones, move mode, date switch, copyable links; SMS later)

## Goal

Improve the existing no-login store-verify survey so Manager can edit all drivers on a phone, and each driver can open only their list — without a new top-level page.

## Behavior

1. **Date** — Default remains next sell/delivery day. `?date=YYYY-MM-DD` (and a date control) reloads lists for that day. Token stays auth.
2. **Collapsible drivers (HQ)** — Each driver is a `<details>` block with ON count in the summary.
3. **Zones** — Within a driver, stores grouped by `customers.zone` (fallback “No zone”). Assigned vs other still apply.
4. **Move mode** — Optional toggle (off by default). Move a store to another driver’s ON set in this survey snapshot only (no `daily_order_assignments` write).
5. **Links** — HQ page exposes copyable Manager URL and per-driver URLs (`view_driver=` under HQ token, plus existing per-driver tokens when minted). No SMS required this pass.
6. **i18n** — New strings in `lang/en.php` and `lang/es.php`.

## Out of scope

Twilio bulk send, Live Next, rewriting standing/dated assignments.
