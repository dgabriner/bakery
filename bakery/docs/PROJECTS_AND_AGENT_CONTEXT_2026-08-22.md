# Project Shortlist + Work-Agent Initial Context — 2026-08-22

Proposal drafted from `BAKERY_PRODUCT_CONTEXT.md` (§6 open loops, §7 roadmap, §9 exclusions),
repo state (migrations 001–055, 57 `tests/run_*.php` runners, root scratch inventory), and
recent git history (Square invoicing, driver trusted-phone sessions, promotion hardening).
Respects §8 principles and §9 exclusions. **Reconfirm with the owner before starting Next/Later items.**

---

## Part 1 — Projects, in priority order

### Now (loop-closing, low risk, owner-aligned)

**P1 · Broken-window batch** (named in §7.6)
- Fix the Leads filter bug; retire the dead `customer_upcoming.php` redirect target;
  finish i18n (~1,170/1,174 — find the last raw-key nav items and any unpaired en/es keys);
  collapse zone dual-source-of-truth (`map.php` hardcoded list → `zones` table, per §4.14).
- Guardrails: no schema changes; extend `run_i18n_tests.php`, `run_navigation_tests.php`.
- Surfaces: `leads.php`, `customer_upcoming_edit.php`, `map.php`, `zones.php`, `lang/en.php`, `lang/es.php`.
- Suggested slug: `broken-windows`

**P2 · Staff morning alert digest** (closes §6.6 "staff receive no proactive alerts")
- One daily staff digest (email, or `MAIL_DRIVER=log` record — reuse the pattern in
  `includes/customer_notifications.php`) listing: tomorrow unconfirmed demand, dates missing
  a production-plan commit, `production_plan_drift` events, failed stops awaiting recovery,
  routes still open from yesterday. Silent when the day is clean (§8 exception-driven).
- Data already exists: `operational_events`, `demand_confirmations`,
  `production_plan_commits`, assignment states.
- Tests: `run_customer_notifications_tests.php`, `run_exception_desk_tests.php`.
- Suggested slug: `staff-alert-digest`

**P3 · Money visibility phase 1: balances + AR aging** (first "deferred bigger idea", loops 1–5 shipped)
- Manager-facing, **computed read-first**: per-customer balance and aging buckets derived
  from confirmed delivery snapshots minus COD collected (Route Manager cash) minus Square
  payments (webhook/poll status). Surface as Billing Center filter chips + a Customer Hub
  summary chip — no new screens (§8), no invented amounts (§4.11), no new ledger yet.
- Tests: `run_customer_billing_tests.php`, `run_route_manager_cash_tests.php`,
  `run_square_invoice_tests.php`, `run_snapshot_workflow_tests.php`.
- Suggested slug: `money-visibility`

### Next (owner go-ahead required)

**P4 · Weekly rollup invoices** (named "still deferred" under §7.4)
- One invoice document consolidating a customer week of confirmed deliveries. Snapshot-priced;
  per-delivery INV identity preserved underneath (rollup references child invoices).
  Needs owner decisions: numbering scheme, Square single-invoice vs per-delivery, portal display.
- Suggested slug: `weekly-rollups`

**P5 · Bulk actions + inline editing ergonomics** (rest of §7.6)
- Bulk confirm/assign/reassign on Daily Orders; inline dated-quantity edit with the same
  preservation guarantees as generation (`overwrite_changed` semantics, §4.3).
- Tests: `run_tomorrow_confirmed_tests.php`, `run_operating_demand_tests.php`,
  `run_status_alignment_tests.php`.
- Suggested slug: `demand-ergonomics`

**P6 · Offline-tolerant driver confirm** (second "deferred bigger idea")
- Outbox queue in the driver wizard: photo/quantities/credits/COD staged locally, idempotency
  key per stop+attempt, replay on reconnect. Foundation exists: transactional
  `complete_delivery.php` confirm, trusted-phone credentials (schema 050), session ping.
  Sizable — split into spike → contract → implementation missions.
- Tests: `run_driver_workflow_tests.php`, `run_failed_stop_recovery_tests.php`.
- Suggested slug: `driver-offline`

**P7 · Deploy-surface hygiene sweep (scoped, not §9 "broad cleanup")**
- Execute `docs/QUARANTINE_INVENTORY.md`: archive root scratch (`blah_blah.php`, `Blah2.php`,
  `blah3–6`, `debug*.php` ×7, `test_*.php` ×~19, `probe_smoke_20260729.php`,
  `standing_routes - Copy.php`, `route_tester.php`, `bread_distribution_{backup,fixed,optimized}.php`);
  fold the ~13 loose root `.sql` patches into `database/schema/06x_*` (or archive if already applied);
  note the duplicate migration prefixes that already exist (two 010s, two 021s, two 025s);
  add an integrity test asserting quarantined pages stay redirect-only.
- Requires explicit owner approval before any deletion. Update the inventory doc, not a rewrite.
- Suggested slug: `surface-hygiene`

**P8 · Demand cron install kit** (leftover from §7.1)
- `scripts/demand_scheduler.php` exists; package the DreamHost crontab line + a verification
  page/health check proving overnight horizon-fill ran. Mostly an ops handoff to the owner.
- Suggested slug: `cron-kit`

**P9 · QuickBooks boundary tightening**
- Evolve `billing_export.php` CSV toward scheduled export + import-status reconciliation per
  `docs/billing_quickbooks_boundary.md`. After P3 so exports carry balances consistently.
- Suggested slug: `qb-boundary`

### Later (do not start without owner)
- Production variance intelligence (planned vs made vs wasted trends from FG ledger + plan commits).
- Full payments/AR ledger (goes beyond computed views; touches §4.11 philosophy).
- Portal self-service expansion (order-change requests flowing into the confirm queue).

### Explicitly NOT projects (§9 — mention only if blocking)
Backups, staging environments, deploy process, CI/CD, containerization, framework migration,
generalized DB redesign, comprehensive security hardening, generalized test architecture,
broad technical-debt cleanup.

---

## Part 2 — Initial context to give to ANY work agent (paste verbatim)

```text
MISSION CONTEXT — Sour Flour OS (Bakery Manager)

App: wholesale-bakery day runner. One operating date drives a continuous loop:
demand -> confirm -> production plan commit -> bake -> pack -> route load -> deliver ->
closeout reconcile -> snapshot invoicing -> next week's demand.
Stack: flat server-rendered PHP (page scripts + includes/ helpers), vanilla JS/CSS,
MariaDB, no framework, no build step. Deploy target: DreamHost.

WORK ONLY IN: bakery/
The workspace root holds unrelated projects, and a stale duplicate tree sits in sfbake/.
Never edit sfbake/. All paths below are relative to bakery/.

READ FIRST (trust order):
1. BAKERY_PRODUCT_CONTEXT.md      canonical product manual. Section 4 business rules are LAW.
2. Run: php scripts/agent_homebase.php brief --agent=YOUR-SLUG --json
   (whiteboard "Decided" + open bug watchlist + canonical_slugs)
3. docs/AGENT_DEVELOPMENT_MANUAL.md   craft, testing gate, data-environment rules.
Ignore README.md and ARCHITECTURE.md (stale boilerplate). docs/archive/ is historical.

COORDINATION (durable staging ledger):
php scripts/agent_homebase.php start     --agent=SLUG --mission="..."
php scripts/agent_homebase.php tests-for --files="a.php,b.php" --json
php scripts/agent_homebase.php handoff   --agent=SLUG --summary="1....8...." --files="..."
Handoff = the 8 points in BAKERY_PRODUCT_CONTEXT.md section 10.

DATA SAFETY:
- Test suites run against bakerysf_test and WIPED it every gate. Assume destruction.
- Local/dev databases only. Staging auto-push must never target bakery.sourflour.org/bake.
  Live promotion requires explicit owner confirmation.
- Schema changes: database/schema/NNN_name.sql via scripts/run_migrations.php.
  NOTE: prefixes have duplicates already (010, 021, 025 x2). Pick the next free number (056+).

INVARIANTS (full list: BAKERY_PRODUCT_CONTEXT.md section 4):
- Dated order beats standing order, PER CUSTOMER. Demand consumption goes through
  bakery_operating_demand_* helpers, never raw table sums.
- Invoices price from immutable delivery snapshots. NEVER live products.price.
  Legacy invoice generators stay quarantined/redirect-only.
- Loads move custody, not ownership. Van math:
  loaded = net delivered + van leftover + waste + door credits.
- Delivery confirm (complete_delivery.php) is the single billable-record writer.
  Route closeout gates Daily Run day-close.
- Generation preserves manual dated edits; inactive customers never generate.
- Weekday encoding Sunday=7 via bakery_standing_day_* helpers.
- Every user-facing string needs keys in BOTH lang/en.php AND lang/es.php.

CODE PLACEMENT HYGIENE:
- Extend an existing screen or add an includes/ helper instead of new top-level scripts.
- Do not resurrect quarantined pages (legacy invoice generators, *_backup/_fixed/_optimized
  variants, blah*, debug*, test_*, loose root .sql patches).
- Procedural controllers are the accepted reality; extract to includes/ only when a second
  caller appears. Close loops; do not add modules. No generic ERP feature creep.

DEFINITION OF DONE:
- Relevant tests/run_*.php suites pass (get the list from tests-for). Run php -l on every
  touched file. i18n paired in en + es. No new root-level scratch files.
- Handoff posted before stopping, even for partial work.
```
