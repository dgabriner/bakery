# Bakery OS Development Assessment

**Assessment date:** 2026-08-04  
**Repository:** `windsurf-project/bakery`  
**Branch reviewed:** `chore/checkpoint-0a-repo-safety`

## Executive status

Bakery OS is a substantial, actively used operations application in a stabilization and product-integration phase. It already covers much of the bakery's operational surface, and the current work adds a stronger role-aware mobile experience. It is not currently ready for another production deployment because the local-versus-production database safety boundary is not enforced by the test harness.

The immediate objective is to restore a trustworthy local build and test gate. After that, development should shift from accumulating page-level features toward a coherent bakery operating system built around a few end-to-end workflows, explicit data ownership, and observable operational outcomes.

## Verified repository and test findings

### Working state

- The Git repository is a monorepo rooted at `windsurf-project`, one directory above the bakery application.
- The bakery working tree contains 15 modified files with approximately 1,273 additions and 149 deletions.
- The current uncommitted tranche combines mobile/role navigation, driver workflow, customer scheduling, automatic push controls, and SFTP deployment changes.
- Unrelated sibling projects appear in repository status because of the monorepo root. Bakery changes should continue to be staged by explicit path.

### Automated checks

- Navigation and role contracts: **35 checks passed**.
- Database-backed characterization suite: **failed**.
- Authentication suite: **partially passed, then failed**.
- Data-integrity suite: **partially passed, then failed**.
- PHP lint: the application is mostly clean, but tracked diagnostic file `trace_driver_list.php` has a parse error. Ignored/untracked `db_test.php` and `test_db.php` also have parse errors.

### Critical database-isolation finding

The local `.env` had `USE_PROD_DB=true` during assessment. The test setup reset `bakerysf_local`, but application code loaded by the suites connected to the live `bakerysf` database. This created a split-brain test run:

1. Setup and fixtures operated on the local database.
2. The application test harness selected the production database.
3. Characterization and integrity writes failed against production foreign-key constraints.
4. Authentication tests executed partly against production before failing.

The authentication suite may have created or updated its staff/baker test identities in production. Production user records and relevant logs should be audited.

This is a release blocker. Destructive or write-capable tests must independently verify their actual PDO connection before making changes. They must refuse any database that is not explicitly local or test-scoped, regardless of `USE_PROD_DB`.

### Documentation state

- `docs/CURRENT_STATE.md` says the main suites were green on 2026-07-28, but that result is not reproducible in the current configuration.
- The root `README.md` describes an MVC/Composer/Artisan project structure that this application does not use. Its installation and testing instructions are misleading.
- `docs/POST_0E_DECISIONS.md` describes production deployment as ready. That status should be suspended until database isolation and the complete test gate are repaired.

## Immediate release gates

1. Change the active development database mode back to local before further development testing.
2. Audit production authentication users and logs for effects from the 2026-08-04 assessment run.
3. Make every reset, characterization, authentication, and integrity suite assert the active database name and host using the actual PDO connection.
4. Repair fixture assumptions: current tests refer to customer IDs such as `1` and `144`, while the reset fixture does not reliably supply those records.
5. Make all suites report failures without cascading PHP fatal errors.
6. Fix or quarantine tracked syntax-invalid diagnostics.
7. Review and checkpoint the current feature tranche before adding more surface area.

## What the OS currently is

This is no longer merely a bakery database interface. The application models most of the physical operating loop:

`customer demand -> daily orders -> production plan -> bake confirmation -> finished goods -> driver load -> route execution -> delivery reconciliation -> invoice`

It also contains the supporting master data for customers, products, recipes, ingredients, zones, drivers, recurring orders, recurring routes, pricing, and staff access.

Four role experiences are defined:

| Role | Intended operating experience | Current maturity |
| --- | --- | --- |
| Administrator | Full operations plus users, diagnostics, deployment-adjacent tools, and historical pages | Broad but cluttered; powerful operational and diagnostic capabilities share one application surface |
| Manager | Daily command center and access to all current operating modules | Broad coverage; requires too much navigation between overlapping screens |
| Baker | Daily Production and Pack List, filtered by assigned product lines | Focused and increasingly mobile-friendly; production feedback is only partly integrated with planning and inventory |
| Driver | My Route and Call HQ, including delivery completion, photos, adjustments, and invoice data | The clearest end-to-end role workflow; currently under active mobile redesign and still carries legacy route paths |

## Functional maturity by operating loop

### 1. Demand and customer setup — functional, fragmented

Available functionality includes customer records, standing orders, daily orders, customer schedules, delivery zones, Pan Dulce pricing and quantity standards, leads, and customer/route insight screens.

Strengths:

- Recurring demand and day-specific orders are both represented.
- Daily orders can become the date-specific source of truth over standing demand.
- Zone and customer-specific Pan Dulce rules model real operating exceptions.
- Prepared statements are commonly used in the active pages.

Gaps:

- Several current and historical pages overlap in purpose.
- Business rules are duplicated across large page controllers.
- The transition from standing demand to generated daily orders is not presented as one explicit, auditable workflow.
- Customers, schedules, routes, and standing orders can be edited from separate contexts without a single customer operational record.

### 2. Production planning — useful foundation, early integration

The Production Center combines standing forecasts, actual daily orders, finished-goods stock, and saved product/date targets. Daily Production and Pack List provide the baker-facing execution views. Baker-to-product-line assignments limit irrelevant production information.

Strengths:

- The system distinguishes forecast demand from actual generated orders.
- Saved weekly production targets exist in `production_plan_items`.
- Production confirmation can record finished goods into inventory.
- The role-specific baker view is appropriately narrow.

Gaps:

- The documented contract still says parts of classic production aggregate standing rather than daily orders.
- A saved target is not yet a production work order with lifecycle, ownership, completion, variance, or notes.
- Formula and ingredient data do not yet drive material requirements or purchasing.
- Production, packing, and inventory use multiple screens rather than a clearly sequenced shift workflow.

### 3. Finished-goods inventory and loading — coherent recent build, needs operational proof

Recent migrations add per-product/day inventory, immutable movement records, driver loads, and driver-load items. Inventory changes use transactions and row locking in the shared helper.

Strengths:

- Inventory movements preserve an audit trail.
- Driver load corrections return stock rather than silently replacing counts.
- Loading advances eligible orders to `out_for_delivery`.
- Availability checks prevent loading more than current stock.

Gaps:

- The feature is recent and lacks a passing database-backed test gate.
- There is no formal reconciliation for waste, damage, returns, samples, transfers, or end-of-day carryover.
- Inventory is finished-goods oriented; ingredient inventory columns exist, but purchasing, receiving, lots, and consumption are not an integrated workflow.

### 4. Route planning and delivery — broadest capability, excessive overlap

The system supports recurring routes, daily assignment, route ordering, mapping, driver identity links, mobile route execution, delivery photos, delivery adjustments, credits, and delivery status.

Strengths:

- Driver accounts can be linked directly to driver records.
- Delivery completion updates the assignment and parent order transactionally.
- Ordered versus delivered quantities are distinguished.
- Managers can enter a driver-mode workflow without destroying their manager session.

Gaps:

- Driver Assignment, Daily Route, Standing Routes, Route Manager, Customer Map, Driver Overview, Driver List, and Route Tester expose overlapping concepts.
- Legacy and current endpoints coexist, increasing the chance of different status or ordering rules.
- Route optimization is primarily a retained/testing concern rather than a dependable planning service.
- Offline behavior, retry/idempotency, and poor-connectivity recovery are not evident even though delivery is the workflow most exposed to mobile network failure.

### 5. Delivery reconciliation and invoicing — meaningful implementation, incomplete financial boundary

Delivery completion can preserve the original priced order, record delivered pieces and credits, calculate billable totals, and expose a reloadable invoice snapshot. Invoice Center and several older invoice generators coexist.

Strengths:

- Delivery pricing snapshots reduce later price drift.
- Delivery confirmation and order status updates are transaction-aware.
- Item-level delivered quantity is available for delivery variance.

Gaps:

- Invoice generation has several implementations and no clearly documented canonical service.
- Payment state, accounts receivable, remittance, and accounting-system synchronization are not modeled as a complete ledger.
- Authorization should be checked at the record level so drivers can mutate only their assigned orders.

### 6. Management insight — operational snapshot exists, analytics are shallow

The Operations Dashboard gives selected-day counts and a short order trend. Customer and product distribution views provide useful exploration.

Gaps:

- There is no unified exception queue: unassigned stops, production shortfalls, missing confirmations, load discrepancies, failed deliveries, uninvoiced deliveries, and stale customer data should be actionable from one place.
- Metrics do not yet measure plan-versus-actual, waste, on-time delivery, fill rate, credits/returns, revenue, or margin.
- Dashboard logic still contains references to tables from older architectural assumptions and often degrades errors into zero values, which can make missing data look healthy.

## Data model assessment

The current schema is strong enough to support the next phase. It contains normalized master data and explicit operational records for orders, assignments, inventory movements, loads, delivery confirmation, users, roles, and production targets.

The main data risk is not absence of tables; it is ambiguity about which table owns each stage:

- Standing orders are forecasts/templates.
- Daily orders should be the dated commercial commitment.
- Production plan items should be the dated production target.
- Inventory movements should be the stock ledger.
- Driver loads should be custody transfer to delivery.
- Delivered quantities and invoice snapshots should be the fulfillment record.

These ownership rules should become explicit domain contracts and be enforced in one service layer. Pages should not independently reinterpret them.

Schema/migration concerns:

- Migration numbering has a naming mismatch: the runner calls baker product lines migration `011`, while the file is named `010_baker_product_lines.sql` and another `010` file exists.
- The reset flow and migration runner can connect to different databases under the current configuration.
- A baseline rebuild should prove that all migrations apply from zero and that the resulting schema matches production expectations.
- The legacy schema dump is not a dependable canonical schema source; `database/schema` plus a verified migration ledger should be canonical.

## Security and access assessment

Role and permission infrastructure is a genuine improvement, and authenticated POST requests receive centralized CSRF enforcement through the auth gate. However, the current sign-in convenience model is not production-safe:

- Fixed administrator, baker, and driver codes are embedded in tracked `includes/auth.php`.
- Public `baker.php` automatically provisions baker users and signs one in without a user-entered secret.
- Some diagnostic/health scripts are deliberately public.
- Four-digit codes need rate limiting, attempt logging, lockout policy, and managed rotation to be reasonable for internet exposure.

Recommended target:

- Remove all real identity secrets from source and migrations.
- Disable public auto-login in production; replace it with a device enrollment or kiosk session design if passwordless bakery-floor access is required.
- Add record-level authorization for driver/customer/order mutations.
- Separate diagnostics from the public application and expose only a minimal non-sensitive health response.
- Record security-relevant events and administrative changes in an audit log.

## Code-build assessment

### What is working

- The stack is operationally simple: server-rendered PHP, MariaDB/MySQL, vanilla JavaScript, and CSS.
- Shared config, database, authentication, environment loading, navigation, and inventory helpers exist.
- PDO prepared statements and transactions are used in important newer workflows.
- Migrations are tracked and recent features are increasingly designed around explicit schema changes.
- Role-aware navigation tests provide a fast, useful contract.

### What limits development velocity

- There are about 153 non-vendor PHP files. Major active pages range from roughly 800 to more than 3,500 lines.
- UI rendering, request parsing, SQL, business decisions, and side effects commonly live in the same file.
- The codebase does not match its stated PSR-12/class-based architecture; most code is procedural page-controller PHP.
- There is no Composer project definition, standard unit-test framework configuration, or visible CI pipeline.
- Current tests are custom scripts and can terminate with fatal errors rather than producing a complete failure report.
- Legacy, backup, fixed, optimized, debug, and canonical variants remain physically close to production code.
- CSS and JavaScript are split between shared assets and large inline page blocks, making consistent responsive behavior expensive.
- Documentation contains mojibake/encoding artifacts and materially inaccurate setup guidance.

### Recommended architecture direction

Do not rewrite the application or introduce microservices. Preserve the simple deployment model and refactor incrementally into a modular monolith:

1. Keep server-rendered PHP and MariaDB.
2. Add Composer for autoloading, scripts, and development tooling.
3. Introduce small domain/service modules for Demand, Production, Inventory, Delivery, Billing, Identity, and Reporting.
4. Move SQL and multi-step business operations out of page files into tested services/repositories.
5. Keep page controllers thin: authorize, validate, invoke a use case, render/redirect.
6. Establish shared layout/components and move inline CSS/JS into versioned assets.
7. Add JSON endpoints only where the mobile workflow needs asynchronous behavior; do not pursue an API-first rewrite yet.

## Where we should go

The product should become a **daily bakery command system**, not a collection of management pages. Its primary promise should be:

> By looking at one date, every role can see what must happen next, perform that work, and leave a trustworthy record for the next role.

The canonical daily operating loop should be:

1. **Confirm demand** — generate/review the dated orders and highlight exceptions.
2. **Commit the production plan** — convert demand plus stock into owned production work.
3. **Bake and pack** — record completion, variance, waste, and readiness.
4. **Load and dispatch** — reconcile physical product with each route.
5. **Deliver and adjust** — work reliably on mobile, preserving proof and actual quantities.
6. **Invoice and close** — reconcile delivered value, credits, missing evidence, and exceptions.

Managers should operate this loop from a single date-oriented command center. Existing specialist screens can remain, but they should be reached as drill-downs from workflow state rather than as a menu of unrelated tools.

## Prioritized roadmap

### Phase 0 — restore trust in the build (now)

- Enforce local/test PDO targets in all test and reset commands.
- Audit production for assessment-run effects.
- Remove hard-coded production credentials/codes and close public auto-login.
- Repair deterministic fixtures and make all four suites green.
- Fix tracked parse errors and add a repository-wide lint command.
- Split, review, and checkpoint the current uncommitted UI and deployment changes.
- Correct README, architecture, current-state, and deployment-readiness documentation.

**Exit criterion:** one command creates a clean local database, applies every migration, runs lint and all tests, and cannot connect to production.

### Phase 1 — establish one canonical daily workflow

- Define and document order, production, inventory, load, delivery, and invoice state transitions.
- Build an exception-driven daily command center organized by selected date.
- Declare canonical screens and redirect/archive overlapping legacy entry points.
- Add end-to-end tests for manager, baker, and driver golden paths.
- Add record-level delivery authorization and idempotent completion endpoints.

**Exit criterion:** a manager can run one representative day from demand confirmation through invoice close without using historical navigation.

### Phase 2 — harden field operations

- Finish the mobile navigation/driver tranche with real-device testing.
- Add offline/retry-safe delivery actions and duplicate-submission protection.
- Add production and packing completion with variance/waste reasons.
- Add load reconciliation and end-of-route returns.
- Add an operational audit timeline per order/customer/date.

**Exit criterion:** network interruption or an accidental repeat action cannot corrupt a route or invoice.

### Phase 3 — make the OS measurable

- Add plan-versus-actual production, fill rate, waste, credits, on-time delivery, and invoice-close metrics.
- Add exceptions and aging rather than more passive dashboards.
- Connect formulas to ingredient requirements and purchasing forecasts.
- Define a supported accounting export/integration boundary.

**Exit criterion:** managers can identify operational loss and unfinished work from the command center without manual spreadsheet reconciliation.

## Near-term product decisions needed

These decisions should be made before broad feature work resumes:

1. Is the authoritative daily demand created automatically from standing orders, manually committed by a manager, or both with an explicit cutoff?
2. Does production plan against delivery date, bake date, or both?
3. When does physical custody move from finished-goods inventory to a driver, and how are returns reconciled?
4. Is Bakery OS the invoice system of record or an operational source that exports to accounting?
5. Which legacy pages contain unique indispensable workflows and which can be redirected or archived?
6. What is the acceptable passwordless/kiosk access model for bakers and drivers?

## Recommended next development slice

The next slice should not add another feature page. It should deliver a trustworthy local build and one tested vertical workflow:

1. Safe local build/test command.
2. Deterministic demo day fixture.
3. Manager confirms demand and production plan.
4. Baker records production completion.
5. Manager loads a driver.
6. Driver completes delivery with an adjustment.
7. Manager sees the resulting invoice and any exception.

That slice will expose inconsistent source-of-truth rules early and create a durable regression backbone for every later feature.
