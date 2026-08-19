# Sour Flour OS / Bakery Manager — Where We Are

**Document date:** 2026-08-06  
**Application path:** `bakery/` in monorepo `windsurf-project`  
**Working branch (documented):** `chore/checkpoint-0a-repo-safety`  
**Primary assessment source:** [DEVELOPMENT_ASSESSMENT_2026-08-04.md](DEVELOPMENT_ASSESSMENT_2026-08-04.md)  
**Ops pointer (may lag):** [CURRENT_STATE.md](CURRENT_STATE.md)

This is a full status synthesis for humans and agents: what the product is, what already works, what was stabilized, what is broken or blocked, and where development is headed.

---

## 1. One-sentence status

Bakery Manager is a substantial, actively used PHP operations application that already covers most of the bakery’s physical day; it is in a **stabilization and role-aware integration phase**, and is **not ready for another production deployment** until local/test database isolation and the full test gate are trustworthy again.

---

## 2. Product identity

| Item | Reality |
|------|---------|
| Working name | Bakery Manager |
| Modernization name | Sour Flour OS |
| Code location | `bakery/` (do not rename; do not greenfield-rewrite) |
| Stack | Flat PHP SSR (page scripts + `includes/`), vanilla JS/CSS, MariaDB/MySQL |
| Framework | None authorized — no Laravel/Symfony rewrite |
| Hosting target | DreamHost (`bakery.sourflour.org`) |
| Local DB | `bakerysf_local` on `127.0.0.1` / localhost only |
| Prod DB | `bakerysf` (touch only via explicit pull / authorized deploy) |

**North-star promise (target, not fully realized):**

> By looking at one date, every role can see what must happen next, perform that work, and leave a trustworthy record for the next role.

**Canonical daily loop (target):**

1. Confirm demand  
2. Commit production plan  
3. Bake and pack  
4. Load and dispatch  
5. Deliver and adjust  
6. Invoice and close  

---

## 3. What the OS currently is

This is no longer “a bakery database UI.” It models most of the physical operating loop:

```text
customer demand
  → daily orders
  → production plan
  → bake confirmation
  → finished goods
  → driver load
  → route execution
  → delivery reconciliation
  → invoice
```

Supporting master data also exists for customers, products, recipes/formulas, ingredients, zones, drivers, recurring orders, recurring routes, pricing, and staff access.

### Roles

| Role | Intended experience | Maturity |
|------|---------------------|----------|
| Administrator | Full ops + users, diagnostics, deployment-adjacent tools, historical pages | Broad but cluttered |
| Manager | Daily command center across operating modules | Broad coverage; too much navigation between overlapping screens |
| Baker | Daily Production + Pack List, filtered by assigned product lines | Focused; increasingly mobile-friendly; production feedback only partly integrated with planning/inventory |
| Driver | My Route + Call HQ: completion, photos, adjustments, invoice data | Clearest end-to-end role workflow; mobile redesign active; legacy route paths still coexist |

Access is enforced server-side in `includes/auth.php`. Menu hiding is never the only control.  
Module map: [MODULE_ACCESS_GUIDE.md](MODULE_ACCESS_GUIDE.md) and in-app **Module Guide**.

---

## 4. Current functional surface

### 4.1 Manager / administrator workspace

| Area | Modules | Purpose |
|------|---------|---------|
| Workday | Operations Dashboard | Selected-day order / production / delivery snapshot |
| Production | Production Center | Weekly finished-goods planning from orders + stock |
| Production | Daily Production | Daily bake schedule and quantities |
| Production | Pack List | Production-day packing checklist |
| Production | Finished Goods | Finished-good availability by delivery date |
| Production | Driver Pickup Loads | Load quantities before departure |
| Orders & Customers | Daily Orders | Date-specific order review and changes |
| Orders & Customers | Standing Orders | Recurring orders by customer and day |
| Orders & Customers | Customers | Customer records and ordering details |
| Orders & Customers | Customer Schedule | Planned deliveries by customer and zone |
| Orders & Customers | Delivery Zones | Zone setup |
| Orders & Customers | Pan Dulce Pricing / Standards | Zone pricing and default quantities |
| Orders & Customers | Invoice Center | Delivery invoice review/generation |
| Orders & Customers | Sales Leads | Prospects and follow-up |
| Delivery | Driver Assignment | Assign daily delivery work |
| Delivery | My Route | Driver identity / route workflow (managers can enter without ending session) |
| Delivery | Daily Route | Daily / monthly / list route plans |
| Delivery | Driver Management | Driver records + recurring route maintenance |
| Delivery | Standing Routes | Recurring customer→driver plan |
| Delivery | Route Manager | Assigned stop review/maintenance |
| Delivery | Customer Map | Locations and zones |
| Products & Recipes | Products, Dough Types & Lines, Formulas, Ingredients | Catalog and recipe data |
| Insights | Customer Overview, Customer Routes, Product Distribution, Module Guide | Exploration / reference |

### 4.2 Role-limited surfaces

- **Baker:** Daily Production, Pack List only  
- **Driver:** My Route, Call HQ only  

### 4.3 Historical / retained (admin)

Still reachable under **Administration → Historical Navigation**, intentionally not promoted:

- Older standing-order editor (`standing_orders.php`)  
- Large distribution workspace (`bread_distribution.php`)  
- Older order summary (`orders.php`)  
- Legacy driver overview/list  
- Route tester  

These exist for continuity and exceptional workflows while staff move to the curated workspace.

---

## 5. Functional maturity by operating loop

### 5.1 Demand and customer setup — functional, fragmented

**Strengths**

- Standing (recurring) and daily (date-specific) demand both exist  
- Daily orders can become the date-specific source of truth over standing demand  
- Zones and Pan Dulce rules model real operating exceptions  
- Prepared statements are common on active pages  

**Gaps**

- Overlapping current/historical pages for similar jobs  
- Business rules duplicated across large page controllers  
- Standing → daily generation is not one explicit, auditable workflow  
- Customer / schedule / route / standing edits happen in separate contexts without a single operational customer record  

### 5.2 Production planning — useful foundation, early integration

**Strengths**

- Distinguishes forecast demand from generated daily orders  
- Saved weekly targets in `production_plan_items`  
- Production confirmation can write finished goods into inventory  
- Baker view is appropriately narrow  

**Gaps**

- Parts of classic production still aggregate standing rather than daily orders (known contract)  
- Saved targets are not full production work orders (lifecycle, ownership, completion, variance, notes)  
- Formulas/ingredients do not yet drive material requirements or purchasing as an integrated workflow  
- Production / packing / inventory are multiple screens, not one sequenced shift  

### 5.3 Finished-goods inventory and loading — coherent recent build

**Strengths**

- Per-product/day inventory, immutable movements, driver loads  
- Transactions and row locking in shared helpers  
- Load corrections can return stock  
- Loading advances eligible orders toward `out_for_delivery`  
- Availability checks prevent over-loading  

**Gaps**

- Recent feature; needs a green database-backed test gate  
- No formal reconciliation for waste, damage, returns, samples, transfers, EOD carryover  
- Ingredient inventory columns exist; purchasing/receiving/lots/consumption are not a finished loop  

### 5.4 Route planning and delivery — broadest capability, most overlap

**Strengths**

- Recurring routes, daily assignment, ordering, mapping  
- Driver accounts linkable to driver records  
- Delivery completion can update assignment + parent order transactionally  
- Ordered vs delivered quantities distinguished  
- Managers can enter driver-mode without destroying manager session  

**Gaps**

- Many overlapping entry points (Assignment, Daily Route, Standing Routes, Route Manager, Map, Overview, List, Tester)  
- Legacy and current endpoints coexist → risk of divergent status/ordering rules  
- Route optimization is retained/testing territory, not a dependable planning service  
- Offline / retry / idempotency not evident despite mobile network exposure  

### 5.5 Delivery reconciliation and invoicing — meaningful, incomplete financial boundary

**Strengths**

- Delivery pricing snapshots reduce later price drift  
- Confirmation and order updates can be transaction-aware  
- Item-level delivered quantity supports variance  

**Gaps**

- Multiple invoice generators; no single documented canonical billing service  
- Payment / AR / remittance / accounting sync not a complete ledger  
- Record-level authorization (driver may mutate only assigned orders) still needs hardening  

### 5.6 Management insight — snapshot exists, analytics shallow

**Strengths**

- Operations Dashboard: selected-day counts + short trend  
- Customer/product distribution exploration  

**Gaps**

- No unified exception queue (unassigned stops, shortfalls, missing confirmations, load discrepancies, failed deliveries, uninvoiced work, stale customers)  
- Weak plan-vs-actual, waste, on-time, fill rate, credits, revenue/margin metrics  
- Some dashboard logic can degrade errors into zeros (missing data looks healthy)  

---

## 6. Stabilization checkpoints completed

| Checkpoint | Status | What it delivered |
|------------|--------|-------------------|
| **0A** | Done | Repo classification, gitignore safety, quarantine inventory approach |
| **0B** | Done | Local fail-closed config, `bakerysf_local`, Scoop MariaDB user process |
| **0C** | Done | Characterization suite + documented behavior contracts |
| **0D** | Done | Session auth, roles, CSRF, login/logout, auth tests |
| **0E** | Done | Driver endpoint, zone join integrity, production guards, quarantine/docs |
| **Post-0E** | Done locally | Sunday weekday migration, `customers.zone_id`, prod pull, deploy prep, durable local login |

### Post-0E data contracts (implemented)

- **Weekday:** canonical Sunday = `7` (legacy `0` still readable via compatibility helpers)  
- **Zones:** `customers.zone_id` FK + text `zone` kept for display/back-compat  
- **Driver orders API:** `get_driver_orders.php` contract documented and implemented  

### Known behavior contracts still called out historically

1. Classic `production.php` aggregates `standing_orders` in places, not only `daily_orders`  
2. Delivery updates historically focused on assignment status (`daily_orders.status` not always advanced the same way)  

Treat characterization findings as **contracts to document/guard**, not silent “fixes,” unless tests and coordinator agreement say otherwise.

---

## 7. Schema / migrations landscape

Tracked schema under `database/schema/` includes (among others):

| Migration area | Examples |
|----------------|----------|
| Baseline / auth | `001`, `002_auth` |
| Weekday / zones | `003_weekday_normalize`, `004_zone_id` |
| Inventory | `005_inventory`, `009_finished_goods_inventory` |
| Roles / codes | `006_driver_archive`, `007_baker_role`, `008_login_code` |
| Baker lines / Pan Dulce | `010_*` (note: duplicate `010` naming exists — known concern) |
| Delivery / invoice | `013_delivery_confirmation`, `014_delivery_invoice_snapshot` |
| Production plans | `015_production_center_plans` |
| Later expansions | `016_customer_lifecycle`, `017_ingredient_purchasing`, `018_customer_portal` |

**Data ownership rules that should become explicit contracts:**

| Concept | Intended ownership |
|---------|-------------------|
| Standing orders | Forecast / template |
| Daily orders | Dated commercial commitment |
| Production plan items | Dated production target |
| Inventory movements | Stock ledger |
| Driver loads | Custody transfer to delivery |
| Delivered qty + invoice snapshots | Fulfillment / billable record |

Pages should not independently reinterpret these.

---

## 8. Security and access — current reality

**Improvements already landed**

- Role/permission infrastructure  
- Centralized CSRF on authenticated POSTs via auth gate  
- Diagnostics largely admin-gated / allowlisted  

**Still not production-safe enough**

- Fixed sign-in codes embedded in tracked auth code historically  
- Public baker convenience / auto-provision paths are a risk if exposed on the internet  
- Four-digit codes need rate limiting, attempt logging, lockout, managed rotation  
- Record-level authorization for driver/order mutations needs to be complete  
- Security-relevant admin actions need a durable audit log  

**Target**

- No real identity secrets in source or migrations  
- Disable public auto-login in production; use device enrollment / kiosk design if floor passwordless access is required  
- Minimal public health endpoint only  

---

## 9. Engineering reality (codebase)

### What works

- Simple deployable stack: PHP + MariaDB + vanilla front end  
- Shared config, DB, auth, env loading, navigation, inventory helpers  
- PDO prepared statements and transactions in important newer workflows  
- Migrations tracked; newer features increasingly schema-first  
- Role-aware navigation contract tests are useful and relatively fast  

### What slows velocity

- ~150+ non-vendor PHP files; major pages often 800–3500+ lines  
- UI, request parsing, SQL, business rules, and side effects often in one file  
- Stated PSR-12 / class MVC architecture in root README/ARCHITECTURE does **not** match the procedural page-controller reality  
- No Composer project definition / standard PHPUnit CI visible as the main path  
- Custom test scripts can fatal instead of reporting a full suite result  
- Legacy, backup, fixed, optimized, debug, and canonical variants sit near production code  
- CSS/JS split between shared assets and large inline blocks  

### Architecture direction (approved intent)

Do **not** rewrite or microservice. Incrementally form a modular monolith:

1. Keep SSR PHP + MariaDB  
2. Add Composer for autoload/tooling when ready  
3. Domain services: Demand, Production, Inventory, Delivery, Billing, Identity, Reporting  
4. Thin page controllers: authorize → validate → use case → render/redirect  
5. Shared layout/components; move inline CSS/JS into versioned assets  
6. JSON endpoints only where mobile async needs them  

---

## 10. Critical blocker (as of 2026-08-04 assessment)

### Database isolation failure

During assessment, local `.env` had `USE_PROD_DB=true`. Test setup reset `bakerysf_local`, but application code under test connected to live `bakerysf`.

Resulting split-brain:

1. Fixtures/setup hit local  
2. App harness selected production  
3. Characterization/integrity writes failed against prod FK constraints  
4. Auth tests partially executed against production before failing  

**Release blocker:** every reset/characterization/auth/integrity suite must assert the **actual PDO** database name/host and refuse anything that is not explicitly local/test-scoped, regardless of `USE_PROD_DB`.

### Assessment checklist still open

1. Force development DB mode back to local before further testing  
2. Audit production auth users/logs for assessment-run side effects  
3. PDO target assertions in all destructive suites  
4. Repair fixture assumptions (hard-coded customer IDs not always present after reset)  
5. Suites must fail cleanly without cascading fatals  
6. Fix or quarantine tracked syntax-invalid diagnostics  
7. Review/checkpoint the uncommitted mobile/role/nav/deploy tranche before adding surface area  

### Documentation drift

| Doc | Issue |
|-----|-------|
| `CURRENT_STATE.md` | Last says suites green 2026-07-28; not reproducible under Aug 4 config |
| Root `README.md` | Describes Composer/Artisan/MVC layout this app does not use |
| `POST_0E_DECISIONS.md` / `PRODUCTION_DEPLOY.md` | Treat deploy as ready; **suspend** until isolation + full test gate repaired |
| `ARCHITECTURE.md` | Aspirational / partially stale vs procedural reality |

---

## 11. Deploy / ops posture

| Item | Status |
|------|--------|
| Canonical ops pages in git | Done (historically `b803b78` + follow-ons) |
| Local MariaDB user-process scripts | Done |
| Prod → local pull script | Done |
| Production deploy guide | Written |
| DreamHost GitHub deploy work | Discussed / in progress across prior chats |
| **Next production deploy** | **Suspended** until Phase 0 exit criteria met |
| Quarantine file deletion | Pending human review only |

Safety rails that remain mandatory:

- Local-only DB for day-to-day dev  
- Prod access only via explicit pull / authorized deploy  
- Never print or commit secrets  
- No deleting quarantine files without human review  
- Stage explicit paths only — never broad `git add bakery/`  

---

## 12. Where we are trying to improve

### Phase 0 — Restore trust in the build (**now**)

- Enforce local/test PDO targets in all test and reset commands  
- Audit production for assessment-run effects  
- Remove hard-coded production credentials/codes; close public auto-login in prod  
- Repair deterministic fixtures; make nav + characterization + auth + integrity green  
- Fix tracked parse errors; add repo-wide lint command  
- Split/review/checkpoint current uncommitted UI and deployment changes  
- Correct README, architecture, current-state, and deploy-readiness docs  

**Exit criterion:** one command creates a clean local DB, applies every migration, runs lint + all tests, and **cannot** connect to production.

### Phase 1 — One canonical daily workflow

- Document order / production / inventory / load / delivery / invoice state transitions  
- Exception-driven daily command center by selected date  
- Declare canonical screens; redirect/archive overlapping legacy entry points  
- E2E tests for manager, baker, driver golden paths  
- Record-level delivery auth + idempotent completion  

**Exit criterion:** manager runs one representative day from demand confirmation through invoice close without historical navigation.

### Phase 2 — Harden field operations

- Finish mobile nav/driver tranche with real-device testing  
- Offline/retry-safe delivery + duplicate-submission protection  
- Production/packing completion with variance/waste reasons  
- Load reconciliation + end-of-route returns  
- Operational audit timeline per order/customer/date  

**Exit criterion:** network interruption or accidental repeat action cannot corrupt a route or invoice.

### Phase 3 — Make the OS measurable

- Plan-vs-actual, fill rate, waste, credits, on-time, invoice-close metrics  
- Exceptions and aging over passive dashboards  
- Formulas → ingredient requirements → purchasing forecasts  
- Supported accounting export/integration boundary  

**Exit criterion:** managers identify operational loss and unfinished work from the command center without spreadsheet reconciliation.

---

## 13. Product decisions still needed

Before broad feature work resumes, these need explicit answers:

1. Is authoritative daily demand auto-generated from standing orders, manually committed, or both with a cutoff?  
2. Does production plan against delivery date, bake date, or both?  
3. When does physical custody move from FG inventory to a driver, and how are returns reconciled?  
4. Is Bakery OS the invoice system of record, or an operational source that exports to accounting?  
5. Which legacy pages have unique indispensable workflows vs redirect/archive?  
6. What is the acceptable passwordless/kiosk model for bakers and drivers?  

---

## 14. Recommended next development slice

Do **not** add another feature page next. Deliver a trustworthy local build and one tested vertical workflow:

1. Safe local build/test command  
2. Deterministic demo-day fixture  
3. Manager confirms demand and production plan  
4. Baker records production completion  
5. Manager loads a driver  
6. Driver completes delivery with an adjustment  
7. Manager sees resulting invoice and any exception  

That slice exposes source-of-truth inconsistencies early and creates a regression backbone for later work.

---

## 15. Doc trust hierarchy

When docs conflict, prefer this order:

1. This status doc + [DEVELOPMENT_ASSESSMENT_2026-08-04.md](DEVELOPMENT_ASSESSMENT_2026-08-04.md) for “where we are / what’s blocked”  
2. Agent shared context and checkpoint evidence (`00_SHARED_CONTEXT`, `CHECKPOINT_0A`…`0D`, `0C` findings)  
3. Ops runbooks: `LOCAL_SETUP`, `MARIADB_USER_PROCESS`, `PRODUCTION_DEPLOY`, `CREDENTIAL_ROTATION_RUNBOOK`  
4. Module access guide for current menu/roles  
5. Root `README.md` / `ARCHITECTURE.md` / informal ideas — **lowest trust** if they conflict with checkpoints or the Aug 4 assessment  

---

## 16. Bottom line

| Dimension | Verdict |
|-----------|---------|
| Operational coverage | Strong — most of the bakery day is represented |
| Role experience | Emerging — driver clearest; baker narrowing; manager still menu-heavy |
| Data model | Good enough for next phase; ownership rules need enforcement |
| Safety / auth | Much better than open pages; not yet internet-hardened |
| Test / deploy trust | **Broken / suspended** until DB isolation fixed |
| Product direction | Stop accumulating pages; build one date-driven command loop |
| Immediate work | Phase 0 build trust → then one vertical daily workflow |

**We have a real bakery OS shell. The next win is not more surface area — it is a safe local gate and one proven day from demand confirm through invoice close.**
