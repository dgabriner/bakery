# Prompt 11 — Mobile exception desk

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace.

Sister prompts: `docs/prompts/10-exception-connections.md`, `docs/prompts/12-exception-desktop.md`. You own **thumb-first workability**. Desktop power features are Prompt 12. Deep-link graph is Prompt 10.

---

You are making exceptions workable on a phone for the people who are actually holding one: the driver at a failed stop, the baker staring at a shortage, the manager walking the floor. The GUI must be as simple as possible. One exception, one decision, two or three large buttons. Super-features stay off this viewport.

## Shared contract

- Stack stays **flat PHP + MariaDB**. Vanilla CSS. No new JS framework. No new top-level page if an include + existing screen will do.
- Read `BAKERY_PRODUCT_CONTEXT.md` §§2, 3, 8. Driver UX is the reference implementation — study `driver.php` / `complete_delivery.php` before inventing patterns.
- Reuse `bakery_ops_exception`, `bakery_manager_exception_save`, `bakery_delivery_recovery_*`. No second write path.
- Completing desk work never suppresses the live operational exception. It records ownership/note/state only.
- Failed-stop rules in `docs/FAILED_STOP_RECOVERY_MODEL.md` are law: reason + manager note required; retry only from `failed`; reassign only through Driver Assignment; never mutate invoice/credit/qty from this desk.
- Roles: driver sees only their stops; baker sees only assigned product lines; manager/admin see the date queue. Server-side `bakery_require_role` — menu hiding is not security.
- i18n: `lang/en.php` and `lang/es.php`. Baker/driver copy leans Spanish-friendly; still add both keys.
- Local only. Do not deploy. Do not enable auto-push.
- End with a §10 handoff.

## Read first

- `driver.php`, `complete_delivery.php`, `pack_list.php`, `production.php`
- `manager.php` attention queue + failed-stop section (currently dense forms)
- `includes/manager_mode.php`, `includes/delivery_recovery.php`
- `css/manager.css`, `css/manager_recovery.css` (existing breakpoints — extend, don’t fight)
- `docs/FAILED_STOP_RECOVERY_MODEL.md`
- `tests/run_failed_stop_recovery_tests.php`, `tests/run_driver_workflow_tests.php`

## What is already true (do not redesign)

- Manager Mode already has acknowledge / assign / due / note / complete, plus failed-stop recovery. It is a computer form stuffed into a phone.
- Drivers can fail a stop in the delivery wizard, but the manager recovery case is a separate desk they cannot usefully use on a handset.
- Bakers get pack shortages as messages, not as a one-tap “this product is short — what now?”
- `workspace-driver` / `workspace-baker` body classes already exist in `includes/header.php`.

## Ship

Extract a shared renderer `includes/exception_desk.php` + `css/exception_desk.css`. **Do not rewrite Manager Mode’s desktop queue.** Insert one call at a marked comment in `manager.php`:

```php
<?php if (function_exists('bakery_exception_desk_render')) { bakery_exception_desk_render($db, $selectedDate, $exceptions); } ?>
```

On viewports **≤720px**, the desk replaces the dense attention/recovery forms. Above 720px the existing (or Prompt 12) layout remains visible and the desk is hidden.

### Desk behavior (all roles, then specialize)

Each card shows only: severity, title, who/what (customer or product or driver), one sentence of detail, then **at most three actions**.

Default manager actions:

1. **Mine** — acknowledge + assign to current user (one tap).
2. **Fix** — deep link to the canonical screen (Prompt 10’s href if present; otherwise existing `bakery_ops_link_*`).
3. **Note** — one field, then save. Completing still requires a note (existing rule).

No due-time datetime picker on mobile. No multi-select. No related-exception matrix.

### Driver (phone)

On `driver.php` / confirm wizard, a failed stop is a **reason chips + one note + Report** flow, not a manager form. Allowed reasons are exactly `bakery_delivery_recovery_reason_codes()`. `other` requires the note. After report, the stop stays failed; the recovery case opens for managers. Driver does not retry/reassign/close billing.

If the driver is mid-route, also show a single “Call HQ” already in nav — do not add a second HQ screen.

### Baker (phone)

On Daily Production and Pack List (baker role only): if FG is short for a product on their line, show one card: product, short by N, **Mark packed anyway** stays the existing pack check-off (do not change inventory math), plus **Flag shortage** which creates/acks a manager exception work row for `production_fg_shortfall` with a baker note. Do not let bakers edit orders or loads.

### Manager (phone)

Date at the top (prev/next already on Manager Mode). Queue sorted critical → warning. Failed-stop cards use the same three-button pattern: **Ack**, **Retry** (datetime optional collapsed), **Reassign** (driver `<select>`). Hide the billing/credit dropdowns behind “More”. More must still write through `bakery_delivery_recovery_apply`.

## Constraints

- Do not add `mobile_exceptions.php` as a staff home page. The desk lives on screens people already open.
- Do not restyle the whole app. Scope CSS to `.exception-desk` and existing workspace body classes.
- Do not implement bulk actions, keyboard shortcuts, grouping by customer, or create-from-any-row (Prompt 12).
- Do not change production confirmation to be non-additive except if you only add a warning — inventory math is a different mission.
- Touch `manager.php` only to insert the desk render call and, if needed, wrap the existing dense forms in a `.manager-desktop-only` class. If Prompt 12 has already added a workshop include, do not delete it.

## Tests

Extend `tests/run_failed_stop_recovery_tests.php` and/or add `tests/run_exception_desk_tests.php`:

- Driver cannot complete recovery or mark invoiced.
- Baker flag requires a note and does not change `available_quantity`.
- Manager “Mine” sets acknowledged + assigned_to current user.
- Desk HTML omits due-at and bulk controls (string absent).
- `tests/run_manager_mode_tests.php` still passes (Manager Mode still has attention queue / recovery).

## Done when

- A manager can ack and open the fix for the top exception with thumbs only.
- A driver can report a failed stop with a reason chip without seeing manager recovery fields.
- A baker can flag a shortage without leaving Pack List / Daily Production.
- Desktop Manager Mode still works (hidden desk, existing forms or Prompt 12 workshop).
- en/es complete. No deploy.
