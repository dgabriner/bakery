# Bakery Product Context — Operating Manual for Agents

**Read this before touching any feature.** It exists so you can make product-literate
changes without re-auditing the whole repository. Everything below was verified against
the code during two product/workflow audits (Aug 2026). When this document and the code
disagree, the code wins — fix the doc.

**Stack reality:** flat PHP server-rendered pages (page scripts + `includes/`), vanilla
JS/CSS, MariaDB. No framework. Deploy target DreamHost. Migrations in `database/schema/`
(001–047; note some schema files are placeholders and the real columns are added by
`scripts/run_migrations.php`). Tests are custom scripts under `tests/`.

---

## 1. Product thesis

Bakery Manager (modernization name: "Sour Flour OS") runs the physical day of a wholesale
bakery. The mental model is one continuous loop, keyed on a single operating date:

```
customers / standing demand
  → dated orders + exceptions
  → confirmed demand
  → production plan (what to make, how much)
  → baking (by dough, from formulas)
  → packing (by customer / product / route)
  → route loading (finished goods → van custody)
  → delivery (photo, quantities, COD, adjustments)
  → returns / waste / credits
  → invoicing (from delivery snapshots)
  → payment / account history
  → next week's demand
```

**North star:** by looking at one date, every role can see what must happen next, do that
work, and leave a trustworthy record for the next role.

**Refined thesis (the important one):** *The operational spine already exists; several
individual stages do not fully close.* `includes/daily_run.php` implements an 8-stage
gated checklist (Confirm Demand → Commit Production Plan → Produce → Pack →
Assign/Load/Dispatch → Deliver & Reconcile → Invoice → Close the Day) whose closeout
refuses to record while blockers exist. Dated demand now lazy-generates, route construction
prepares demand first, and route closeout reconciles loads. The largest remaining gaps are
that the "committed" production plan never reaches the baker's screen and invoicing is
one-order-at-a-time. The product work is closing those loops — not adding modules.

## 2. Primary user roles

Server-side role enforcement lives in `includes/auth.php`; menu in
`includes/navigation_catalog.php`. Menu hiding is never the only control.

| Role | Needs that drive product decisions |
|------|-------------------------------------|
| **Manager / owner** | State of the bakery in 30 seconds; what changed; what's unusual; what's not done; tomorrow's readiness. Works from Daily Run / Operations Dashboard / Daily Brief. |
| **Order/customer manager** | Find a customer fast; see their normal pattern and today's exception; create/edit orders safely; manage standing orders, pauses, pricing. |
| **Production planner** | Turn confirmed demand into one production plan; know the baker sees the approved numbers; hear about late changes. Works in Production Center + Ingredient Planner. |
| **Baker** | A clear, sequenced workload: what, how much, which dough, formula grams. Sees only Daily Production + Pack List, filtered to assigned product lines. Should never reverse-engineer orders. |
| **Packing staff** | What goes in whose bag/box, what's short, what's done. Pack List (by product or by customer). |
| **Driver / Driver Assistant** | Mobile-first: next stop, compact remaining-stop map (live location + numbered pins) with next-leg, next-three, and full-day views, driving distance/time, delivery-window context, next-three horizon, remembered scope, and zoom controls; then navigate, photo → pieces/credits → COD → invoice preview → confirm. Remaining stops can be reordered on My Route (`Go next` or compact Adjust). A Driver Assistant works the paired driver's route (default or dated pairing), so both update the same stops and delivery records. Sees My Route + Call HQ only. The reference implementation for role UX — study it before building other role flows. |
| **Customer (portal)** | See/edit upcoming deliveries and standing order, pause weeks, view invoices/statements/photos, report issues. Login: 4-digit code or staff-generated QR. |

Defaults: baker/driver/manager UIs lean Spanish, admin English; i18n is nearly complete
(~1,170/1,174 keys). If you add user-facing strings, add `lang/en.php` AND `lang/es.php`
keys — several nav items already show raw keys because this was skipped.

## 3. Existing strengths — do not casually redesign

- **Standing vs dated demand model** (`includes/demand_review.php`). Per customer: dated
  order wins, standing is forecast, pauses/skips respected, Pan Dulce standards fill routed
  customers with no orders. This is how wholesale bakeries actually think. All demand
  consumption should go through `bakery_operating_demand_*` helpers, never raw table sums.
- **Generation with edit preservation** (`includes/daily_order_generation.php` →
  `bakery_generate_daily_orders_from_standing($db, $date, $options)`; called from
  `daily_orders.php` single-date + "Generate week", `daily_run_api.php`
  `generate_daily_orders` inline action, lazy `bakery_ensure_daily_orders_for_date`
  / rolling `bakery_fill_demand_horizon` on Daily Run, Daily Orders, dashboard,
  and Daily Production, plus DreamHost cron `scripts/demand_scheduler.php`).
  Re-generation preserves manually changed
  dated quantities unless `overwrite_changed` is set. It also preserves dated route
  transfers/reorders and one-time stops; newly discovered standing stops append instead
  of colliding with existing route positions. Losing either behavior would destroy staff
  trust. Inactive customers are excluded (`is_active = 1`).
- **Operations Dashboard** (`index.php` + `includes/dashboard_command_center.php`).
  6-stage strip, severity-sorted exceptions, deep links with filters pre-applied, inline
  fix actions, and honest "zero vs unavailable" distinction. Extend this pattern; don't
  replace it.
- **Daily Run** (`daily_run.php` + `includes/daily_run.php`). Gated stages (Confirm Demand
  hard-gates closeout when `demand_confirmations` is installed), recorded closeout, reopen,
  stale-closeout detection. This is the spine — strengthen stages rather than routing
  around it.
- **Baker workflow** (`production.php`). Dough-type grouping, formula gram explode,
  line-filtered per baker, progress from `produced_quantity`.
- **Driver workflow** (`driver.php` + `complete_delivery.php`). Transactional confirm
  writes assignment status, order status, delivered line quantities, and a pricing snapshot
  in one step; notifies the customer. My Route also shows a compact remaining-stop map
  (stored customer coordinates, live location, dated `route_order` path). It defaults to
  the next leg for tiny phones and can widen to the next three stops or full day. Google
  driving metrics, a delivery-window watch, and a tappable next-three horizon make the map
  a planning surface; the chosen scope is remembered for the driver/date. Remaining-stop
  reorder stays on this screen. Managers can enter driver mode without losing their
  session.
- **Customer portal** (`customer_portal*.php`). Lock states (`forecast|editable|locked|
  skipped|paused`), week pauses + vacation ranges, issues with credit requests. Portal
  edits flow through the same demand model staff use.
- **Finished-goods inventory** (`includes/product_inventory.php`). Per product/day
  quantities, immutable movement ledger (`production`, `count`, `load`, `load_correction`,
  `return`, `waste`, `delivery`), row locking, load corrections return stock, over-loading
  prevented. Route closeout (`route_closeout.php`) reconciles per-driver loaded =
  delivered + returned + waste and gates Daily Run day close.
- **Invoice snapshots** (migration 014 + `complete_delivery.php`). Confirmation freezes
  `delivery_order_total`, `delivery_pricing_label`, line prices, COD amount. Invoices are
  immune to later catalog price changes. Never price historical invoices from live
  `products.price` (legacy `generate_invoice.php` does — that's a bug, not a pattern).

## 4. Critical business rules and invariants

Violating these breaks operations even if the code "works."

1. **Dated beats standing, per customer.** A customer's dated order replaces their standing
   order for that date only; other customers still fall back to standing. Never flip
   all-or-nothing per date (legacy `product_distribution.php` does this — known bug).
2. **Standing = template/forecast. Daily = commercial commitment.** Standing edits never
   rewrite past dated orders; dated edits never write standing. Shared semantics live in
   `includes/customer_order_mutations.php`.
3. **Generation is automatic for a 7-day horizon**, plus on-demand via Daily Orders
   single-date or "Generate week", the dashboard/Daily Run inline `generate_daily_orders`
   action, first view of Daily Run / Daily Orders / dashboard / Daily Production
   (`bakery_fill_demand_horizon` → `bakery_ensure_daily_orders_for_date`), route build
   (`assign_from_standing`), or DreamHost cron `scripts/demand_scheduler.php`.
   Cadence from calendar today: bake tomorrow (`today+1`) for the route the day after
   (`today+2`). Daily Production is keyed on the delivery date, so the Tuesday bake
   sheet is Wednesday's standing-derived orders. Route build prepares dated demand
   first and then explicitly rebuilds the standing-route stops. It respects week
   pauses, date-range pauses, and skip dates, and filters
   `is_active = 1` — inactive customers never generate. Re-generation preserves
   dated quantity edits unless `overwrite_changed` is set and never rewrites an existing
   dated route decision. New stops append; `(driver_id, delivery_date, route_order)` is unique.
4. **Pauses/skips** (`standing_order_pauses`, skip helpers in `customer_order_mutations.php`)
   suppress generation and forecast. Portal customers set these themselves.
5. **Editable window:** portal customers can edit dated orders only while order status is
   `pending`/`confirmed`; later ops statuses lock the order. Portal edits to an ungenerated
   date auto-create the dated order from standing (`bakery_customer_ensure_daily_order`).
6. **Daily order status enum:** `pending, confirmed, in_production, ready, out_for_delivery,
   delivered, invoiced`. Assignment status: `pending, in_transit, delivered, failed,
   cancelled, rescheduled` (`rescheduled` is retained for legacy history).
   These two advance on *different* write paths — keep them consistent when you touch either.
   Known divergence: loading a van sets orders `out_for_delivery` but leaves assignments
   `pending`; skip cancels the assignment but not the order.
7. **Production confirmation is additive** (`bakery_inventory_record_production`): each
   "Record now" adds units to `available_quantity` and `produced_quantity`. Re-entry
   double-counts. Production waste is still not captured on the bake sheet; route waste
   is captured at closeout.
8. **Loads move custody, not ownership:** saving a driver load moves FG `available → loaded`;
   reducing a load returns stock. Delivery confirmation does **not** decrement loads —
   end-of-route closeout does (`bakery_inventory_reconcile_driver_load`): posts `return`,
   `waste`, and `delivery` movements, sets `driver_loads.status = reconciled`, and blocks
   Daily Run closeout while any route remains open.
9. **Delivery confirmation creates the billable record.** `complete_delivery.php`
   `confirm_delivery` sets delivered pieces, credits taken back, snapshot totals, COD
   `amount_collected`, `delivery_confirmed_at`, and marks order + assignment delivered.
   Legacy `mark_delivered` skips the snapshot — don't build on it.
10. **Invoice identity is computed, not stored:** `INV-YYYYMMDD-{orderId padded 5}`, one
    invoice per confirmed delivery. Legacy period generators (`simple_invoice.php`,
    `generate_invoice_simple.php`, `generate_invoice.php`) use a different numbering scheme —
    they are deprecated-by-intent; Billing Center is canonical.
11. **Billing Center marks, it doesn't create.** "Generate invoice" = set `status='invoiced'`
    on a confirmed delivery. Amounts are read-only there. No AR/payments ledger exists; COD
    cash is tracked on the order and summarized per driver in Route Manager.
12. **Pricing tiers:** zone pricing (Pan Dulce), per-customer overrides (`customer_pricing.php`,
    tier `custom`), per-customer default Pan Dulce price. Snapshot at delivery time wins forever.
13. **Weekday encoding:** Sunday = `7` (legacy `0` readable via compat helpers). Use
    `bakery_standing_day_from_date()` / `bakery_standing_day_in_clause()`.
14. **Zones have two sources of truth:** `zones` table (zones.php) vs hardcoded zone list in
    `map.php`. Prefer the table.
15. **Feature checks are runtime, not install-time:** much core code uses `table_exists()` /
    column checks and degrades to "unavailable" states. Preserve that tolerance — the
    dashboard's honesty about missing data is deliberate.
16. **Unassign is not delete.** Driver Assignment removes a stop from a dated route but
    keeps its order and quantities. Only Daily Orders removes an order shell, and only
    after every product line is gone. One-time demand starts in Daily Orders (create/edit),
    then moves to Driver Assignment for routing.

## 5. Major application surfaces

Compact map — entry points only, not every file.

| Surface | File(s) | Responsibility |
|---------|---------|----------------|
| Operations Dashboard | `index.php`, `includes/dashboard_command_center.php` | Per-date 6-stage state + exceptions + inline actions |
| Daily Run | `daily_run.php`, `includes/daily_run.php`, `daily_run_api.php` | 8-stage checklist, gated day closeout |
| Daily Brief | `daily_brief.php`, `includes/daily_brief.php` | Printable shift handoff |
| Daily Orders | `daily_orders.php`, `includes/demand_review.php` | The day's demand: generate, create one-time dated orders, review states, edit |
| Standing Orders | `standing_orders_manager.php` (canonical; `standing_orders.php` legacy) | Recurring weekly template |
| Customers | `customers.php` (searchable list), `customer_record.php` (Customer Hub — summaries + deep links), `customer_overview.php`, `customer_schedule.php`, `customer_pricing.php`, `leads.php` | Records, hub orientation, lifecycle, schedule, pricing, pipeline |
| Production Center | `production_center.php` (schema 015) | Weekly saved FG targets vs demand/stock |
| Daily Production | `production.php` | Baker's bake list by dough; confirm → FG inventory |
| Pack List | `pack_list.php` | Packing checklist by product / customer / route; shared check-offs; FG shortage uses on-hand + loaded |
| Finished Goods | `inventory.php`, `includes/product_inventory.php` | Counts, availability, movement ledger |
| Ingredient Planner | `ingredient_requirements.php`, `includes/ingredient_requirements.php` | Plan/demand → formula grams, batches, purchase *hints* (no PO) |
| Driver Assignment | `driver_assignment.php`, `includes/driver_assignments.php` | Canonical route board: prepare demand + build from standing, drag, transfer, unassign without deleting demand |
| Route tools (overlapping) | `standing_routes.php`, `route_manager.php` (also COD cash), `route_summary.php` (photo-first day review), `daily_route.php`, `drivers.php`, `map.php`, `zones.php` | Template routes, live monitoring, views |
| Driver app | `driver.php`, `complete_delivery.php`, `upload_driver_photo.php`, `includes/driver_route_map.js` | Stops, remaining-stop map + reorder, confirm wizard, photos, GPS |
| Driver Loads | `driver_load.php` | Pickup quantities; reserves FG; sets orders out_for_delivery |
| Billing | `billing_center.php`, `includes/billing*.php`, `billing_api.php`, `billing_export.php`, `customer_statement.php` | Reconcile, mark invoiced, statements, QuickBooks CSV |
| Legacy invoicing (deprecated intent) | `invoice_center.php` (redirect), `simple_invoice.php`, `generate_invoice_simple.php`, `generate_invoice.php` | Period printables; do not extend |
| Service Issues | `service_issues.php`, `service_issues_api.php`, `includes/customer_delivery_issues.php` | Real queue for customer-reported problems |
| Portal | `customer_portal*.php`, `customer_login.php`, `qr_login.php`, `includes/customer_portal.php`, `includes/portal_*` | Customer self-service |
| Admin | `users.php`, `login_history.php`, `historical_navigation.php`, `module_guide.php`, `agent_homebase.php` | Identity, audit, retained legacy menu, Agent Learning Studio / Homebase (admin coaching view; agents use `scripts/agent_homebase.php`) |
| Insights | `customer_overview.php`, `customer_routes.php`, `product_distribution.php` (known demand-flip bug) | Read-only exploration |
| Notifications | `includes/customer_notifications.php` | Automated customer in-app/email; **no staff alerts exist** |
| Timeline | `operational_timeline.php`, `includes/operational_timeline.php` | Audit/event feed per date/customer/order |

## 6. Known open loops

1. **Trusted future demand.** Generation filters inactive customers and
   can run per week or inline from dashboard/Daily Run; Daily Run / Daily Orders /
   dashboard / Daily Production lazy-fill a rolling 7-day horizon from standing
   (preserves dated edits). DreamHost cron `scripts/demand_scheduler.php` does the
   same overnight. A `demand_confirmations` table (schema 031) records manager
   confirmation per date; Daily Run stage 1 is complete only when confirmed (and
   reopens on post-confirm demand drift from `operational_events`), which
   hard-gates closeout; the dashboard shows a tomorrow-readiness strip plus the
   two-day bake→route cadence. Standing remains the template; dated edits win.
2. **Plan → baker.** Production Center saves per-day targets and Daily Run checks coverage,
   but Daily Production bakes to demand; no commit/lock; "planned" on the bake sheet means
   demand. Late demand changes after planning surface nowhere.
3. **Route closeout.** Closed via `route_closeout.php` (loaded = delivered + returned +
   waste). Remaining gaps: production-side waste, credits taken back not auto-ledgered as
   returns.
4. **Canonical bulk invoicing.** Mark-invoiced is single-order; no real customer-facing send
   (the email path mails a test address); legacy generators mint a second invoice-number
   universe, one with live-catalog pricing.
5. **Customer fragmentation.** Contact, lifecycle, standing, schedule, pricing, billing, and
   issues still live on specialized screens; `customer_record.php` is now the staff hub
   (nav + search + jump links). Editing still happens on the specialized screens — do not
   duplicate mutations into the hub.
6. **Exception ownership.** Dashboard/Daily Run exceptions are recomputed live. Manager Mode
   records optional acknowledgement / assignee / due / note / complete (`manager_exception_work`)
   and a desktop workshop (`includes/exception_workshop.php`, ≥900px) for filter/group/bulk
   coordination — completing that work never hides a still-true operational fact. Destination
   pages honor `return=` plus the promised `review` / `attention` / `filter`, show situation
   chips on the implicated rows, and failed delivery deep-links to Manager recovery (not
   `driver_list.php`). Service issues remain a real customer-reported queue. Staff still
   receive no proactive alerts.

## 7. Current improvement priorities (working roadmap, not gospel)

Agreed direction after the audits, in rough order. Reconfirm with the owner before starting
a later item.

1. **"Tomorrow, Confirmed"** — shipped: shared generation + `is_active`, Generate
   week, dashboard/Daily Run inline Generate, rolling 7-day horizon
   (`bakery_fill_demand_horizon`) on Daily Run / Daily Orders / dashboard / Daily
   Production, DreamHost cron `scripts/demand_scheduler.php`, `demand_confirmations`
   + Confirm Demand hard-gating stage 1 / closeout, "changed since confirmation",
   tomorrow-readiness strip, two-day bake→route cadence. *Still open: optional
   overnight cron must be installed on DreamHost (page load fills the horizon
   even without it).*
2. **Production-plan integration** — commit action in Production Center; Daily Production
   executes committed plan with demand alongside + drift flags; post-commit changes raise
   exceptions.
3. **Route closeout/reconciliation** — shipped (`route_closeout.php`): per-driver loaded vs
   delivered vs returned vs wasted; waste + delivery movement types; Daily Run closeout
   requires closed routes.
4. **Canonical bulk invoicing** — bulk mark-invoiced + canonical per-delivery document
   (reuse portal `customer_invoice.php`) + optional send; redirect legacy generators.
5. **Customer hub + findability** — `customer_record.php` is the staff hub (nav item
   "Customer Hub"); `customers.php` has name/phone/email/zone/address search with Enter-to-
   open; high-frequency name surfaces link to the hub. Sections remain summaries + deep
   links — do not rebuild standing/pricing/billing editors inside the hub.
6. **High-value usability fixes** — bulk actions, inline order editing, broken-window batch
   (leads filter bug, dead `customer_upcoming.php` redirect, missing i18n keys,
   `product_distribution.php` demand flip). Pack List now has shared check-offs, route/driver
   grouping, and shortage display aligned with dashboard (on-hand + loaded).

Deferred bigger ideas (only after loops close): money visibility (balances/credits/aging),
production variance intelligence, offline-capable driver confirm.

## 8. Product principles for agents

- **Improve existing workflows before inventing new systems.** The app has too many screens,
  not too few.
- **Prefer closed loops over additional screens.** Ask: after the user's action, what carries
  it forward? If the answer is "memory," that's the bug.
- **Surface information where decisions happen.** Don't build a report when a chip on the
  screen where the decision is made would do.
- **Favor exception-driven workflows.** Make the normal case silent and the unusual case loud.
- **Reduce repetitive entry.** Bulk actions and sensible defaults beat new forms.
- **Preserve strong existing behavior** (§3). Read the workflow you're touching end-to-end
  before changing any step.
- **No generic ERP feature creep.** Every feature must trace to a concrete bakery-day moment.
- **No unnecessary architecture work.** Procedural page controllers are the accepted reality;
  extract shared logic into `includes/` when a feature touches it, don't refactor for its own
  sake.
- **Don't redesign something because the code is old.** Distinguish operational value from
  technical elegance. Ugly code that closes a loop beats clean code that opens a new one.
- **Respect the runtime-degradation pattern** (`table_exists`, "unavailable" states) and the
  i18n requirement (both lang files).
- **Deployable-surface hygiene:** don't add new top-level page scripts when an existing
  screen or an `includes/` helper + small endpoint will do; legacy variants live in
  quarantine/historical nav — don't resurrect them.

## 9. Intentionally deferred topics

NOT current product-development priorities (the owner handles these separately):
backup strategy, staging environments, production deployment process, local/production
database isolation, CI/CD, containerization/hosting, framework migration, generalized
database redesign or normalization, comprehensive security hardening, generalized test
architecture, broad technical-debt cleanup.

Mention one only if it directly blocks a feature you're changing — then flag it in your
handoff and move on. Never let these become your deliverable.

## 10. Agent handoff expectations

End every work session with a handoff containing:

1. **What you investigated** (files/workflows actually read).
2. **Decisions made** and why (especially anything §4-adjacent).
3. **Files changed** (explicit paths).
4. **User-visible behavior changed** — per role, per screen.
5. **Business rules preserved** — which §4 invariants you touched and how you kept them.
6. **Tests/checks performed** — which `tests/run_*.php` suites you ran and results.
7. **Unresolved questions** for the owner or next agent.
8. **Recommendations for the next agent** — follow-on work this change enables or requires.

---

*Canonical source: BAKERY_PRODUCT_CONTEXT.md at the app root. Update it when you change
something it describes.*
