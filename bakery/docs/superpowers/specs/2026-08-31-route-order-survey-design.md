# Route-order survey — design

**Approved:** 2026-08-31 (owner: driver-primary + manager, tap-in-sequence, save on submit, new kind; “use best design and go”)

## Goal

After store-verify locks which stores each driver covers for a delivery date, give drivers (and managers) a **no-login** survey to set **visit order** by tapping stores in sequence — dense enough for ~20 stores on one phone screen, hard to mis-tap, easy to undo.

## Kind & auth

- New survey kind: **`route_order`** (link mode).
- Open token = auth on `survey.php` (same allowlist pattern as `store_verify` / `route_review`).
- Spanish-primary with EN toggle.
- Tokens stay **open** after save so order can be reviewed and re-saved.
- Absolute copy/SMS URLs via `bakery_survey_link_url` (keep Live `/bake/`).

## Audience

| Surface | Behavior |
|--------|----------|
| **Driver token** (`driver_id` > 0) | That driver’s dated assigned stores only. |
| **Manager / HQ token** (`driver_id` = 0) | All active drivers as **collapsible** sections; one expanded for ordering at a time. Per-driver **Save** for the section being edited. |
| **Survey Center** | Mint/copy HQ + per-driver `route_order` links beside store-verify; coverage hint when lock-in exists but order survey not opened yet (light-touch). |

## Data

- **Source list:** `daily_order_assignments` for `(driver_id, delivery_date)` with status not in locked set for reordering UI — show **movable** stops (typically `pending`). Locked (`delivered`, `in_transit`, `cancelled`) stay fixed at the front of the route and are **not** in the tap list (shown as a short “already done / locked” note if any).
- **Do not** rewrite `standing_routes`.
- **Save:** apply ordered `daily_order_id` list via a **token-safe** apply helper mirroring `bakery_driver_reorder_remaining_stops` (no staff role required; same locked/movable rules and temp negative `route_order` renumber).
- Log `survey_responses` + HQ SMS summarizing driver + ordered store names (compact; truncate safely).

## UX (best defaults)

1. **Sticky top bar:** `Ordenados N / Total` · **Deshacer** (undo last tap) · **Empezar de nuevo** (clear sequence).
2. **Ordered strip (compact):** numbered chips for chosen stores — thin, wrap allowed, not the main visual.
3. **Remaining list (majority of viewport):** one full-width row per store — short name, large tap target (~40–44px), minimal chrome, no zone headers (order is global for the day). Aim for ~20 rows visible on a typical phone without zoom.
4. **Tap rule:** each tap on a remaining store appends next number and moves it to the ordered strip. No multi-select.
5. **Save (sticky bottom):** enabled only when **all** movable stores are ordered (avoids partial silent gaps). On success, flash + stay on same token URL (like store-verify).
6. **Manager:** `<details>` per driver; expand one to order; Save scoped to that driver. Copy links for each driver + HQ.

## Date

Default next sell/delivery day; `?date=YYYY-MM-DD` + date control (same resolve helper family as store-verify).

## Out of scope

- Changing membership (ON/OFF / move between drivers) — that remains store-verify.
- Drag-and-drop.
- Auto-save on each tap.
- Standing route edits.
- New top-level module page (stays on `survey.php` + Survey Center).

## Files (expected)

- `includes/survey_route_order.php` — pure helpers + submit/apply (testable without full page).
- `includes/surveys.php` — kind, ensure/mint, public-token allow, SMS body hook.
- `survey.php` — render + POST `order_route`.
- `text_comms.php` / nav catalog strings — Survey Center entry + copy links.
- `lang/en.php`, `lang/es.php`
- `tests/run_survey_route_order_tests.php`
