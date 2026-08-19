# Prompt 20 — Commit production plan

Paste this entire file into a **new** Cursor chat in the `bakery/` workspace.

Sister prompts: `docs/prompts/21-canonical-invoice-send.md`, `docs/prompts/22-credits-as-returns.md`. You own **plan → baker**. You do not send invoices. You do not post delivery credits to inventory.

---

You are closing Sour Flour OS Daily Run stage 2 so “Commit Production Plan” is a real ritual, not a check that targets were saved. After commit, Daily Production must bake the **committed** finished-goods numbers. Demand stays visible beside those numbers. Dated demand that moves after commit must raise exceptions — it must not silently rewrite the bake sheet.

## Shared contract

- Stack stays **flat PHP + MariaDB**. No framework. No new top-level page scripts.
- Read `BAKERY_PRODUCT_CONTEXT.md` §§1, 3, 4, 6.2, 7.2, 8, 10 before coding.
- Homebase: `php scripts/agent_homebase.php brief --agent=commit-production-plan --json` then complete unread lessons, then `start --mission="Commit production plan reaches the baker"`.
- Close loops. Prefer chips on Production Center / Daily Production / Daily Run over a new report.
- Dated beats standing **per customer**. Demand consumption goes through `bakery_operating_demand_*`.
- i18n: every new string in `lang/en.php` **and** `lang/es.php`.
- Local/test DB only (`bakerysf_test` / isolated test DB). Do not deploy. Do not enable auto-push. Never `setup_local_db` against the production mirror.
- End with a §10 handoff via `php scripts/agent_homebase.php handoff`.

## Read first

- `BAKERY_PRODUCT_CONTEXT.md` §§3 (baker + planner), 4, 6.2, 7.2
- `production_center.php` — `save_plan` upserts `production_plan_items`
- `production.php` — bake list is built from `bakery_operating_demand_by_product`; `"planned"` on the sheet **is demand**
- `includes/daily_run.php` stage 2 (`production_plan`) — complete when saved targets cover demand; **no commit timestamp**
- `database/schema/015_production_center_plans.sql`
- `database/schema/031_demand_confirmations.sql` — copy this ritual shape (date-level row, runtime-tolerant if table missing)
- `includes/demand_confirmation.php` — confirm / changed-since / hard-gate pattern
- `includes/operational_exceptions.php` — `production_plan_missing`, `production_plan_short`
- `includes/operational_timeline.php` — `BAKERY_OP_PRODUCTION_PLAN_SAVED`
- `includes/product_inventory.php` — `bakery_inventory_record_production` is **additive** (re-entry double-counts). If you touch it, make baker confirmation remaining-based or otherwise idempotent. Do not leave double-count if you change the write path.
- `tests/run_golden_day_qa.php`

## What is already true (do not redesign)

- Production Center is the weekly planner. Saving targets is useful. Keep save.
- Daily Production is the baker’s only home: dough groups, formula grams, line filter. Do not invent a second baker UI.
- Daily Run already has a stage labeled Commit Production Plan. Strengthen it; do not add a parallel checklist.
- Demand confirmation (stage 1) already hard-gates closeout. Mirror that for plan commit.
- Bakers must not gain Production Center access (`tests/run_navigation_tests.php` / `bakery_baker_scripts()`).

## Ship

1. **Date-level commit, same shape as demand confirmation.**  
   New table e.g. `production_plan_commits` (`delivery_date` PK, `committed_at`, `committed_by_user_id`, snapshot counts). Runtime-tolerant via `table_exists`. Saving targets is not commit. Commit is an explicit manager action on Production Center (and optionally Daily Run calling the same helper). Re-commit is allowed after review, like demand confirm-again.

2. **Daily Production executes the committed plan.**  
   After commit, bake-sheet quantities come from committed `production_plan_items` for that date. Show **demand alongside** (chip or column): demand / committed / made. If the date is not yet committed, the baker must see that clearly (not a silent fallback that looks like a plan). Do not hide demand.

3. **Post-commit drift is loud.**  
   If dated demand changes after commit (new/changed orders, pauses, generation), Daily Run / dashboard raise a plan-drift exception. The bake sheet does **not** auto-rewrite to the new demand. Planner re-commits (or explicitly accepts) to move the baker numbers. Completing exception *work* never hides a still-true drift.

4. **Daily Run stage 2 is complete only when committed** (when the commits table exists), not merely when saved targets ≥ demand. Missing/short targets still warn before commit.

5. **Preserve baker progress.** Commit and re-commit must not wipe `produced_quantity` / inventory production movements.

## Constraints

- Do not add `production_commit.php` as a page. Helpers live in `includes/` (new `includes/production_plan.php` is OK).
- Do not edit `complete_delivery.php`, `includes/billing.php`, `billing_center.php`, `customer_invoice.php`.
- In `includes/daily_run.php` touch only the Commit Production Plan stage and any closeout gate that should require commit (mirror demand confirmation). Leave Invoice / Deliver stages alone.
- Do not build hierarchical recipes, yield %, POs, nutrition, or bake-sheet waste (waste is a later ticket; Prompt 22 owns delivery credits).
- New migration: add a numbered schema file **and** wire it in `scripts/run_migrations.php` the same way 031 was wired. Do not put dumps in Git.

## Tests

Add `tests/run_production_plan_commit_tests.php` (local/test DB only, `bakery_assert_local_test_target` / isolated test DB — do not call a full snapshot reset unless the suite already does):

- Save plan without commit → Daily Production does not treat saved targets as the bake list truth.
- Commit → baker quantities equal committed plan, demand still readable.
- Change dated demand after commit → bake list stays on committed plan; drift exception exists.
- Re-commit after drift → baker quantities update; produced_quantity is not zeroed.
- Baker role still cannot open Production Center.
- Closeout / Daily Run stage 2 is not `complete` without commit when the table exists.

Also run `tests/run_golden_day_qa.php` and `tests/run_navigation_tests.php`. Update `BAKERY_PRODUCT_CONTEXT.md` §6.2 / §7.2 to match what you shipped.

## Done when

- A manager can commit Tuesday’s plan on Production Center and the baker’s Tuesday (delivery-date keyed) sheet shows those numbers.
- A café changing Wednesday’s order after commit does not silently change the bake; Daily Run says so.
- Homebase handoff has all eight §10 fields.
