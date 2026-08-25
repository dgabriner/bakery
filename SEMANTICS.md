# Inactive future demand — proven semantics

## Meaning of inactive

`customers.is_active = 0` is a **soft administrative close** of a wholesale client.

Proven from code and UI, not invented:

- Schema `016_customer_lifecycle.sql`: `is_active`, `inactive_at`, `inactive_reason`. Not a delete.
- `customer_overview.php`: “Make inactive” / “Reactivate client”; confirm text **“Their history will be preserved.”** Optional reason. Standing routes cannot be edited until reactivation.
- Generation (`bakery_generate_daily_orders_from_standing`) and standing demand review **exclude** inactive customers.
- Portal / SF Baker / manager-phone lookups require `is_active = 1`.
- Standing rows, standing routes, and historical `daily_orders` are **not** deleted on deactivate today (that is the hole for *future* dated rows).

Inactive is **not** “temporarily not routed.” Standing routes remain stored; they are simply uneditable and unused for generation.

## Difference from pause/skip

| Mechanism | Meaning | Generation | Dated rows |
|---|---|---|---|
| **Inactive** | No longer an operating client (reversible) | Never creates | Previously left in place (defect) |
| **Week pause** (`standing_order_pauses`) | Skip this week’s standing, still a client | `continue` | Not created; leftover dated still possible |
| **Date-range pause** (`customer_delivery_pauses`) | Vacation window | `continue` | Same |
| **Skip date** (`customer_delivery_skips`) | One delivery date off | `continue`; existing dated items zeroed | Shell kept; items cleared |
| **Route skip** (`delivery_status = cancelled`) | Stop not on the van | n/a | Order kept; `daily_orders` has **no** cancelled enum |

Pause/skip are date-scoped tools for an **active** client. Inactive must not become a pause.

## Eligible future records

A dated order is **eligible to retire** when all of:

1. `customer_id` is the customer being deactivated.
2. `order_date >= today` (today and future; past is history).
3. Order `status` is **not** advanced: not `in_production`, `ready`, `out_for_delivery`, `delivered`, `invoiced`.
4. Assignment `delivery_status` (if any) is **not** `in_transit`, `delivered`, or `failed`.

Eligible includes:

- Untouched generated forecast rows (`pending`, items match standing).
- Manually edited quantities (`changed`).
- One-off dated demand with no standing for that weekday.
- Date-level manager confirmation does **not** stamp `daily_orders.status`; confirmation is not a per-order lock. Eligible rows on a confirmed date still retire (the shop is no longer a client). Production **commit** is product-level and is **not** reversed.

Retirement action (existing domain, not DELETE of history):

- Delete `daily_order_items` (same family as portal skip).
- Zero `total_amount`; note the shell (`Customer inactive`).
- Delete **pending** (or already-cancelled) `daily_order_assignments` so the stop leaves the future route board. Do not delete in-progress/delivered assignments.
- Keep the `daily_orders` row (evidence the dated commitment existed).

## Protected records

Never mutate:

- `order_date < today`
- `delivered` / `invoiced`
- `in_production`, `ready`, `out_for_delivery`
- Assignment `in_transit` / `delivered` / `failed`
- Delivery snapshots, invoice rows, operational events already written

If a future order has already crossed that boundary, leave it. Staff finish or skip it with existing route/production tools.

## Commitment cutoff

Earliest safe boundary = **not yet in bakery/route execution**, using existing `bakery_demand_review_is_advanced_status`.

Production plan commit does **not** change `daily_orders.status`. We do **not** reverse a committed bake sheet. We **do** retire eligible customer demand so pack/route/operating demand stop treating a closed shop as a stop. Extra baked units surface as existing plan-drift, not as a silent bake rewrite.

## Reactivation behavior

- Do **not** revive retired items or assignments.
- Standing remains; generation / horizon fill recreates **new** dated lines for future weekdays once `is_active = 1`.
- Empty future shells (`INSERT IGNORE` then item insert) are refilled by the existing generator.
- Because pending assignments were deleted, generate can create a fresh dated assignment from standing routes.

## Route behavior

- Deactivate: drop pending future assignments for eligible orders.
- In-progress van stops: protected.
- `bakery_driver_assign_from_standing_routes` already joins `c.is_active = 1`, so a later route rebuild will not re-add an inactive client.
- Reactivate + generate/rebuild restores standing-derived stops.

## Audit behavior

No new event type. One `BAKERY_OP_DAILY_ORDER_CLEARED` per deactivation (customer-level), metadata: retired order ids, protected count. Existing timeline answers “why did tomorrow’s work lose this shop?”

## Exact proposed mutation path

**Write:** `bakery_customer_apply_active_status()` in `includes/customer_order_mutations.php`.

- Sole caller today: `customer_overview.php` `update_customer_status`.
- On `is_active = 0`: update customer flags, then `bakery_customer_retire_inactive_future_demand()`.
- On `is_active = 1`: update flags only (no demand revival).
- Idempotent: second deactivate finds nothing eligible.

**Read (canonical, not per-page filters):** `bakery_demand_review_build` ignores dated rows for inactive customers unless the order is protected (past or advanced). `bakery_operating_demand_*` already consumes that review, so Daily Production, ingredients, pack, Confirm Demand, and Daily Run inherit the rule.

Not a scatter of `WHERE is_active = 1` on every screen.
