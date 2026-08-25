# INACTIVE FUTURE DEMAND — RESULT

Branch: `cursor/inactive-future-demand`  
Worktree: `bakery/tmp/cursor_workers/inactive_future_demand/`  
Not merged. Not pushed.

## Verified lifecycle defect

Generation already refuses to **create** dated demand for `customers.is_active = 0`. Sentinel’s hole is real:

A client can be made inactive after future `daily_orders` already exist. Those rows stayed `pending` with items and route assignments. `bakery_demand_review_build` loads dated rows **without** an active-customer filter (standing is filtered). `bakery_operating_demand_*` therefore still counted them as work to bake, pack, and deliver.

## Business semantics discovered

`is_active = 0` is a **soft client close** (reversible). UI: “Make inactive” / “history will be preserved.” Standing and history stay. It is **not** week pause, vacation range, skip date, or route cancellation.

Pause/skip remain date-scoped tools for an **active** client.

## Commitment cutoff

Retire today/future dated orders that are **not** advanced (`in_production`, `ready`, `out_for_delivery`, `delivered`, `invoiced`) and whose assignment is not `in_transit` / `delivered` / `failed`.

Includes generated, manually edited, and one-off future rows. A closed shop must not stay on next week’s run. Date-level Confirm Demand is not a per-order lock.

Protected: past dates, delivered/invoiced, in-progress bake/route. Production **commit** is not reversed (product snapshot); customer demand is retired so pack/route stop sending bread to a closed shop. Extra bake shows as existing plan-drift.

## Root cause

Deactivation in `customer_overview.php` only flipped `is_active` / `inactive_at` / `inactive_reason`. No demand mutation. Operating demand trusted leftover dated rows.

## Fix

Canonical write: `bakery_customer_apply_active_status()` → `bakery_customer_retire_inactive_future_demand()`.

- Clear items; keep `daily_orders` shell; note `Customer inactive`.
- Delete pending/cancelled future assignments.
- One `BAKERY_OP_DAILY_ORDER_CLEARED` event with metadata.
- Idempotent.

Canonical read: `bakery_demand_review_build` ignores unstarted future dated rows for inactive clients (past/advanced still visible). All `bakery_operating_demand_*` consumers inherit this.

Reactivation does not revive rows. Standing generation refills the shell.

## Before → After

Active standing → generate next week → Make inactive.

**Before:** Customer card says inactive; Daily Orders / operating demand / ingredients still include next week’s bread.

**After:** Shell remains; items and pending stops gone; operating demand excludes them; generate will not recreate while inactive. Reactivate + generate restores standing quantities.

## Reactivation behavior

Standing-derived future demand regenerates from the existing generator on the next ensure/horizon/generate. Retired items are not auto-revived.

## Route/production behavior

Pending future assignments are removed. In-progress (`out_for_delivery` tested) is protected. Bake commits are not rewritten.

## Files changed

- `bakery/includes/customer_order_mutations.php`
- `bakery/includes/demand_review.php`
- `bakery/customer_overview.php`
- `bakery/tests/run_operating_demand_tests.php`

## Invariants preserved

- History never deleted.
- Pause ≠ inactive.
- Dated beats standing for **active** clients; inactive supersedes leftover forecast.
- No schema migration. No Ox files. No filter-everywhere patch.

## Tests

`bakerysf_test` only. Worktree has no nightly dump (integrity gate not run here).

| Suite | Result |
|---|---|
| `php -l` on four PHP files | clean |
| `run_operating_demand_tests.php` | 38 pass ×2 |
| `run_tomorrow_confirmed_tests.php` | 37 pass |
| `run_demand_scheduler_tests.php` | 25 pass |
| `run_demand_visit_compare_tests.php` | 42 pass |
| `run_i18n_tests.php` | OK |
| `run_integrity_tests.php` | not run (no snapshot in worktree) |

## Git branch and commit

See commit on `cursor/inactive-future-demand` after this file.

## Integration risk

Low. Collides with other demand-review / customer-overview / order-mutation work. Overlaps Baker-Pack only if that branch edits the same helpers.

## Follow-up

1. **PLANNING-REFERENCE-FILTERS** — Sentinel G3 reference totals that ignore `is_active` / origin; not fully closed here unless they already use operating demand.
2. Run full integrity gate from a tree that has `storage/dumps/nightly` before merge.
3. Optional owner UI: if a protected in-progress order exists at deactivate time, show “finish or skip this stop” instead of only protecting silently.

`INACTIVE-FUTURE-DEMAND: READY FOR INTEGRATION`
