# Bakery Product Context — Operating Manual for Agents

**Read this before touching any feature.** It exists so you can make product-literate
changes without re-auditing the whole repository. Everything below was verified against
the code during two product/workflow audits (Aug 2026). When this document and the code
disagree, the code wins — fix the doc.

**Stack reality:** flat PHP server-rendered pages (page scripts + `includes/`), vanilla
JS/CSS, MariaDB. No framework. Deploy target DreamHost. Migrations in `database/schema/`
(001–049; note some schema files are placeholders and the real columns are added by
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
prepares demand first, and route closeout reconciles loads. Staff alerts shipped (nav bell
+ cron digest); the overnight cron install itself remains an owner ops task
(`docs/CRON_KIT.md`). The product work is closing remaining loops — not adding modules.

## 2. Primary user roles

Server-side role enforcement lives in `includes/auth.php`; menu in
`includes/navigation_catalog.php`. Menu hiding is never the only control.

| Role | Needs that drive product decisions |
|------|-------------------------------------|
| **Manager / owner** | State of the bakery in 30 seconds; what changed; what's unusual; what's not done; tomorrow's readiness. The **manager role** works from a phone-first `manager.php` (Today / Routes / Kitchen / Missed, extras in More). Administrators still use Daily Run / Operations Dashboard / Daily Brief as the desktop spine. Driver UX remains the reference implementation for role flows. |
| **Order/customer manager** | Find a customer fast; see their normal pattern and today's exception; create/edit orders safely; manage standing orders, pauses, pricing. |
| **Production planner** | Turn confirmed demand into one production plan; know the baker sees the approved numbers; hear about late changes. Works in Production Center + Ingredient Planner. |
| **Baker** | A clear, sequenced workload: what, how much, which dough, formula grams. Sees only Daily Production + Pack List, filtered to assigned product lines. Should never reverse-engineer orders. |
| **Packing staff** | What goes in whose bag/box, what's short, what's done. Pack List (by product or by customer). |
| **Driver / Driver Assistant** | Mobile-first: next stop, compact remaining-stop map (live location + numbered pins) with next-leg, next-three, and full-day views, driving distance/time, delivery-window context, next-three horizon, remembered scope, and zoom controls; then navigate, photo → pieces/credits → COD → invoice preview → confirm. Remaining stops can be reordered on My Route (`Go next` or compact Adjust). A Driver Assistant works the paired driver's route (default or dated pairing), so both update the same stops and delivery records. Driver code login creates a rolling trusted-phone credential that can rebuild a lost PHP session; explicit logout or deactivation revokes access. Sees My Route + Call HQ only. The reference implementation for role UX — study it before building other role flows. |
| **Customer (portal)** | See/edit upcoming deliveries and standing order, pause weeks, view invoices/statements/photos, report issues. Login: 4-digit code or staff-generated QR. |

Defaults: baker/driver/manager UIs lean Spanish, admin English; i18n is complete —
`lang/en.php` and `lang/es.php` sit at exact key parity (3,052/3,052, verified 2026-08-23),
with no raw-key references left in any scanned surface. If you add user-facing strings, add
`lang/en.php` AND `lang/es.php` keys in the same change — Spanish must be a genuine
translation, never an English copy.

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
  line-filtered per baker, progress from `produced_quantity`. After a manager commits
  the date, bake quantities come from the committed plan snapshot; dated demand stays
  visible beside them. Uncommitted dates show demand and say so — they do not silently
   treat saved Production Center targets as the bake list. Baker-role presentation stays
   work-first: amount left and made are primary (the committed bake target rides beside
   them after commit, so Left always has context), formulas open in grams, manager plan notes
   and shortage reporting are collapsed, and configured Pan Dulce yields translate pieces
   into bench-language gallon/tray hints. Manager views retain drift and reconciliation detail.
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
  `return`, `waste`, `delivery`), row locking, and load corrections return stock. A manager-
  confirmed above-stock pickup (for example, product supplied by a store) first posts a
  `count` source adjustment and then a `load`, so loaded custody stays balanced. Door credits
  at confirm post `return` movements (see §4.8–4.9). Route closeout
  (`route_closeout.php`) reconciles per-driver loaded = net delivered + returned + waste
  and gates Daily Run day close.
- **Invoice snapshots** (migration 014 + `complete_delivery.php`). Confirmation freezes
  `delivery_order_total`, `delivery_pricing_label`, line prices, COD amount. Invoices are
  immune to later catalog price changes. Never price historical invoices from live
  `products.price`. Legacy `generate_invoice.php` / `simple_invoice.php` /
  `generate_invoice_simple.php` are quarantined and redirect to Billing Center.

## 4. Critical business rules and invariants

Violating these breaks operations even if the code "works."

1. **Dated beats standing, per customer.** A customer's dated order replaces their standing
   order for that date only; other customers still fall back to standing. Never flip
   all-or-nothing per date. Consumption goes through `bakery_operating_demand_*`
   (Product Distribution, Daily Production, ingredient planner).
2. **Standing = template/forecast. Daily = commercial commitment.** Standing edits never
   rewrite past dated orders; dated edits never write standing. Shared semantics live in
   `includes/customer_order_mutations.php`.
3. **Generation is automatic for a 7-day horizon**, plus on-demand via Daily Orders
   single-date or "Generate week", the dashboard/Daily Run inline `generate_daily_orders`
   action, first view of Daily Run / Daily Orders / dashboard / Daily Production
   (`bakery_fill_demand_horizon` → `bakery_ensure_daily_orders_for_date`), route build
   (`assign_from_standing`), or DreamHost cron `scripts/demand_scheduler.php`.
   Cadence from calendar today: default mid-week lookahead is bake tomorrow
   (`today+1`) for the route the day after (`today+2`). That matches pan dulce
   Monday→Tuesday through Thursday→Friday. Friday's pan dulce bake covers
   Saturday (including Markets), Sunday, and Monday. Sour Flour is a separate
   line: Tuesday and Friday for the following days' deliveries, plus Sunday
   for Monday. Encoded in `includes/production_cadence.php`. Daily Production
   stays keyed on the delivery date, so the Tuesday pan dulce bake sheet is
   Wednesday's standing-derived orders. Route build prepares dated demand
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
   Load marks **open** stops' orders `out_for_delivery` and leaves assignments `pending`
   (`in_transit` is at-the-door and locks reorder). Skip cancels the assignment and
   pulls the order off `out_for_delivery` (back to `ready`). Unskip restores pending;
   if that driver still has an open load, the order returns to `out_for_delivery`.
7. **Production confirmation is additive by batch** (`bakery_inventory_record_production`):
   each "Record now" adds sellable units. The bake sheet starts at 0 and posts the made-so-far
   count so a stale resubmit cannot double-count. Waste/unusable units from the same batch
   are recorded beside good output: gross output posts a `production` movement, waste posts
   a negative `waste` movement, and only good units increase Made and available FG.
8. **Loads move custody, not ownership:** saving a driver load moves FG `available → loaded`;
   reducing a load returns stock. Delivery confirmation does **not** post the sale —
   end-of-route closeout does (`bakery_inventory_reconcile_driver_load`): posts van
   `return`, `waste`, and `delivery` movements, sets `driver_loads.status = reconciled`,
   and blocks Daily Run closeout while any route remains open. Closeout **delivered** is
   pieces that stayed with the customer: line `delivered_quantity` minus door credits.
   Door credits (`credits_taken_back`) post `return` movements in the same confirm
   transaction (`bakery_inventory_record_delivery_credit_returns`): those pieces leave
   loaded custody and become `available_quantity` immediately. Closeout must not return
   them again. Van math is **loaded = net delivered + van leftover + waste + door credits**.
   Header credits are allocated to products by walking `daily_order_items`
   in ascending `id`, taking `min(remaining credits, line delivered_quantity)` (ordered
   `quantity` if delivered is still null). Mixed-stop confirm UI states this rule; it
   does not silently dump every credit onto the first SKU. Notes on the ledger name the
   order (`Order #{id} credit taken back`). Re-confirm deltas the movements rather than
   double-returning.
9. **Delivery confirmation creates the billable record.** `complete_delivery.php`
   `confirm_delivery` / `bakery_confirm_delivery` sets delivered pieces, credits taken back,
   snapshot totals, COD `amount_collected`, `delivery_confirmed_at`, marks order +
   assignment delivered, and posts FG credit returns. Billable math stays
   `billable_pieces = delivered_pieces - credits_taken_back`. Obsolete delivery mutations
   were removed; callers use this one confirmation transaction.
10. **Invoice identity is computed, not stored:** `INV-YYYYMMDD-{orderId padded 5}`, one
    invoice per confirmed delivery. Legacy period generators (`simple_invoice.php`,
    `generate_invoice_simple.php`, `generate_invoice.php`) redirect to Billing Center;
    they must not mint a second numbering or live-catalog price universe.
11. **Billing Center marks and sends; it doesn't invent amounts.** "Generate invoice" = set
    `status='invoiced'` on a confirmed delivery. Staff send emails (or records, when
    `MAIL_DRIVER=log`) the same portal document (`customer_invoice.php`) using snapshot
    totals. Amounts are read-only there. No AR/payments ledger exists; COD cash is tracked
    on the order and summarized per driver in Route Manager.
12. **Pricing tiers:** zone pricing (Pan Dulce), per-customer overrides (`customer_pricing.php`,
    tier `custom`), per-customer default Pan Dulce price. Snapshot at delivery time wins forever.
13. **Weekday encoding:** Sunday = `7` (legacy `0` readable via compat helpers). Use
    `bakery_standing_day_from_date()` / `bakery_standing_day_in_clause()`.
14. **Zones have two sources of truth:** `zones` table (zones.php) vs hardcoded zone list in
    `map.php`. Prefer the table.
15. **Feature checks are runtime, not install-time:** much core code uses `table_exists()` /
    column checks and degrades to "unavailable" states. Preserve that tolerance — the
    dashboard's honesty about missing data is deliberate.
17. **Bake day is not always the delivery day.** Main pan dulce is produced Mon–Fri;
    Friday's bake covers Saturday (Markets), Sunday, and Monday; Monday's bake is for
    Tuesday. Sunday deliveries are usually none; Sunday production is minimal.
    Sour Flour is a separate Tuesday/Friday cadence plus Sunday-for-Monday.
    Some items are daily; others last multiple days or can sit in stock. Saved
    targets and inventory stay keyed on the delivery date
    (`includes/production_cadence.php` names the cover window; it does not
    auto-borrow stock across dates).

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
| Production Center | `production_center.php` (schema 015 + 048), `includes/production_cadence.php`, `includes/production_assign.php`, `includes/production_workflow_strip.php` | Production Manager hub for one delivery day: Demand→Plan→Produce→Pack strip, saved FG targets vs demand/stock, conflict-safe autosave, bake-run cover chips, assign units to standing (default) or one-off dated orders, explicit commit to the baker |
| Daily Production | `production.php` | Baker's bake list by dough; confirms good output + waste to FG ledger; shares the kitchen strip; loud when uncommitted |
| Pack List | `pack_list.php` | Packing checklist by product / customer / route; shared check-offs; FG shortage uses on-hand + loaded; made units + kitchen strip |
| Finished Goods | `inventory.php`, `includes/product_inventory.php` | Counts, availability, movement ledger |
| Ingredient Planner | `ingredient_requirements.php`, `includes/ingredient_requirements.php` | Plan/demand → formula grams, batches, purchase *hints* (no PO) |
| Driver Assignment | `driver_assignment.php`, `includes/driver_assignments.php` | Canonical route board: prepare demand + build from standing, drag, transfer, unassign without deleting demand |
| Route tools (overlapping) | `standing_routes.php`, `route_manager.php` (also COD cash), `route_summary.php` (photo-first day review), `daily_route.php`, `drivers.php`, `map.php`, `zones.php` | Template routes, live monitoring, views |
| Driver app | `driver.php`, `complete_delivery.php`, `upload_driver_photo.php`, `includes/driver_route_map.js` | Stops, remaining-stop map + reorder, confirm wizard, photos, GPS |
| Driver Loads | `driver_load.php` | Pickup quantities; reserves FG; sets orders out_for_delivery |
| Billing | `billing_center.php`, `includes/billing*.php`, `billing_api.php`, `billing_export.php`, `customer_statement.php`, `customer_invoice.php` | Reconcile, mark invoiced, send/record the portal invoice, statements, QuickBooks CSV |
| Legacy invoicing (quarantined) | `invoice_center.php`, `simple_invoice.php`, `generate_invoice_simple.php`, `generate_invoice.php` | Redirect to Billing Center; historical nav only |
| Service Issues | `service_issues.php`, `service_issues_api.php`, `includes/customer_delivery_issues.php` | Real queue for customer-reported problems |
| Portal | `customer_portal*.php`, `customer_login.php`, `qr_login.php`, `includes/customer_portal.php`, `includes/portal_*` | Customer self-service |
| Admin | `users.php`, `login_history.php`, `historical_navigation.php`, `module_guide.php`, `agent_homebase.php` | Identity, audit, retained legacy menu, Agent Learning Studio / Homebase (admin coaching view; agents use `scripts/agent_homebase.php`) |
| Insights | `customer_overview.php`, `customer_routes.php`, `product_distribution.php` (per-customer demand merge) | Read-only exploration |
| Notifications | `includes/customer_notifications.php` | Automated customer in-app/email; staff side ships the alert bell + `scripts/staff_alert_digest.php` cron email (silent when clean) |
| Texting Command Center | `text_comms.php`, `text_comms_api.php`, `includes/text_comms.php`, `includes/twilio_config.php`, `twilio_webhook.php`, schema 057 | One SMS ledger for outbound attempts (live, failed, or recorded-only without credentials), inbound replies linked to customers by phone tail, delivery health, and ops mix. Sending happens only on the Command Center page; the API is read-only. Status callbacks can advance but never regress a row; Twilio retries of recorded inbound messages answer success, not a second row. |
| Timeline | `operational_timeline.php`, `includes/operational_timeline.php` | Audit/event feed per date/customer/order |

## 6. Known open loops

1. **Trusted future demand.** Generation filters inactive customers and
   can run per week or inline from dashboard/Daily Run; Daily Run / Daily Orders /
   dashboard / Daily Production lazy-fill a rolling 7-day horizon from standing
   (preserves dated edits). DreamHost cron `scripts/demand_scheduler.php` does the
   same overnight. A `demand_confirmations` table (schema 031) records manager
   confirmation per date; Daily Run stage 1 is complete only when confirmed (and
   reopens on post-confirm demand drift from `operational_events`), which
   hard-gates closeout; the dashboard shows a    tomorrow-readiness strip plus the
   demand cadence (mid-week bake-tomorrow / route-next-day, with Friday pan dulce
   covering Saturday–Monday and Sour Flour on Tuesday/Friday/Sunday-for-Monday).
   Standing remains the template; dated edits win.
2. **Plan → baker.** Shipped: Production Center still saves per-day draft targets.
   `production_plan_commits` + `production_plan_commit_items` (schema 048) record an
   explicit manager commit per delivery date. Daily Production bakes the committed
   snapshot (demand / committed / made). Daily Run stage 2 is complete only when
   committed (when the table exists), not merely when saved targets cover demand.
   Post-commit dated-demand changes raise `production_plan_drift`; the bake sheet
   does not auto-rewrite. Re-commit updates baker numbers and does not zero
   `produced_quantity`. Completing exception work never hides still-true drift.
    When planned or on-hand is below demand, Production Center **Assign to orders**
    recommends proportional store quantities. Default apply writes standing (and
    this delivery day's dated line; later same-weekday dated copies of the old
    standing amount follow). **This delivery only** writes dated quantities and
    leaves standing alone. Van / delivered orders are skipped.
    Daily Run **Produce** completion measures against the committed bake when a
    commit exists (so intentional plan-below-demand does not leave Produce stuck).
    Production Center is the Production Manager hub (kitchen stage strip + deep
    links); bakers never open it — Daily Production carries the committed plan
    to them instead: the committed bake target sits beside Left/Made once the
    date is committed, the focus strip stamps when the manager set the numbers,
    and an uncommitted date says so plainly on its collapsed note. Re-commit
    diff chips show every quantity change on both views.
3. **Route and production waste.** Closed: `route_closeout.php` reconciles loaded = net
   delivered + returned + waste + door credits, while Daily Production records batch waste
   without adding unusable units to sellable FG. Door credits remain FG `return` movements
   at confirm, not van leftover.
4. **Canonical invoicing.** Billing Center bulk-marks invoiced and can send the portal
   `customer_invoice.php` document (snapshot totals) to the customer billing email, or
   record the send when `MAIL_DRIVER=log`. Non-COD Ready deliveries can be sent as a Square
   Invoice (card, Cash App Pay, bank ACH) from Billing Center; COD stays on Route Manager.
   Still deferred: AR aging, weekly rollup invoices, full QuickBooks sync.
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
    `driver_list.php`). Service issues remain a real customer-reported queue. Staff alerts
    shipped: the nav bell (`includes/staff_alerts.php` + `staff_alerts_api.php`) surfaces
    live critical/warning facts plus owned assignments on every staff page, and
    `scripts/staff_alert_digest.php` emails one silent-when-clean digest to
    administrators/managers (DreamHost cron; see `docs/CRON_KIT.md`).

## 7. Current improvement priorities (working roadmap, not gospel)

Agreed direction after the audits, in rough order. Reconfirm with the owner before starting
a later item.

1. **"Tomorrow, Confirmed"** — shipped: shared generation + `is_active`, Generate
   week, dashboard/Daily Run inline Generate, rolling 7-day horizon
   (`bakery_fill_demand_horizon`) on Daily Run / Daily Orders / dashboard / Daily
   Production, DreamHost cron `scripts/demand_scheduler.php`, `demand_confirmations`
   + Confirm Demand hard-gating stage 1 / closeout, "changed since confirmation",
   tomorrow-readiness strip, demand cadence with Friday/Sour Flour cover windows. *Still open: optional
   overnight cron must be installed on DreamHost (page load fills the horizon
   even without it).*
2. **Production-plan integration** — shipped: commit action in Production Center
   (and Daily Run calling the same helper); Daily Production executes the committed
   plan with demand alongside + drift flags; post-commit demand changes raise
   `production_plan_drift`. Production Center can assign the recorded bake to
   standing (usual) or one-off dated orders. *Bakers live in the committed plan
   on Daily Production (target cell + set-at stamp + plain uncommitted note);
   they still never open Production Center.*
3. **Route closeout/reconciliation** — shipped (`route_closeout.php`): per-driver loaded vs
   delivered vs returned vs wasted; waste + delivery movement types; Daily Run closeout
   requires closed routes.
4. **Canonical invoicing** — shipped: bulk mark-invoiced, send/record of the portal
   per-delivery invoice from Billing Center, legacy generators quarantined. Non-COD Square
   invoice send + webhook/poll status is in Billing Center. *Money visibility phase 1
   shipped 2026-08-23: computed per-customer balances + AR aging (`includes/billing_aging.php`,
   snapshot totals − COD collected − Square-PAID settlements) as Billing Center balance chips
   and a Customer Hub chip — read-only, no ledger; full AR/payments ledger, weekly rollup
   invoices, and QuickBooks sync stay deferred.*
5. **Customer hub + findability** — `customer_record.php` is the staff hub (nav item
    "Customer Hub"); `customers.php` has name/phone/email/zone/address search with Enter-to-
    open; high-frequency name surfaces link to the hub. Sections remain summaries + deep
    links — do not rebuild standing/pricing/billing editors inside the hub.
6. **High-value usability fixes** — bulk actions, inline order editing, broken-window batch
     closed: leads pipeline filter fixed; dead `customer_upcoming.php` link repaired as a
     quarantined redirect-only stub into the portal deliveries screens; i18n parity verified
     with zero raw-key references. Zones are single-source now: `includes/zones_catalog.php`
     (table-first + legacy fallback) feeds map, driver trio, customers, schedule.
   Product Distribution demand is the per-customer merge (dated beats standing). Pack List now has shared check-offs, route/driver
    grouping, and shortage display aligned with dashboard (on-hand + loaded).

Deferred bigger ideas (only after loops close): production variance intelligence,
offline-capable driver confirm.

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
