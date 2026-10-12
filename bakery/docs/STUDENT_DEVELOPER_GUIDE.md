# Sour Flour OS — Guide for a new developer

The shareable intern packet is the four PDFs in `docs/intern/`:

1. `01-start-here.pdf`
2. `02-walk-the-day.pdf`
3. `03-the-screens.pdf`
4. `04-how-the-code-works.pdf`

Those are written for a new developer, with Staging links for Thursday, October 1, 2026. Rebuild them with `docs/intern/build_pdfs.ps1` after editing the HTML. This markdown file is the longer reference behind that packet.

This file explains what the software is, how the bakery uses it, which screens matter, and how the code is put together.

The longer manuals stay the source of truth. When this guide and the code disagree, the code wins. When two documents disagree, use the trust order at the end of this file.

You do not need to read the whole repository before you can be useful. Read Part 1, then the page map for the role you are about to touch, then Part 3 before you change code.

---

## Part 1 — How the bakery works

Sour Flour is a wholesale sourdough bakery in San Francisco. Sour Flour OS (the app folder is `bakery/`) runs one physical day of that bakery. Customers are mostly stores and standing wholesale accounts, not a walk-in checkout line.

Everything important is keyed to **one operating date**. A delivery on Wednesday is a different commercial fact from the same customer's usual Wednesday. Staff, screens, and tests all pick a date and then ask: what must happen next, who does it, and what record does the next person inherit?

### The day, in order

```text
standing weekly pattern
  → dated orders for one delivery date
  → manager confirms that demand
  → production plan (what to bake, how much)
  → mix and bake
  → pack by customer, product, and route
  → load finished bread onto a van
  → deliver (photo, pieces, credits, cash)
  → reconcile the van
  → invoice from the delivery record
  → next date's demand
```

The product goal is narrow: by looking at one date, every role can see the next honest action, do it, and leave a record the next role can trust. The work so far has been closing those handoffs. The app already has many screens. A new screen is usually the wrong answer.

### Two kinds of order

| Kind | What it is | What it must never do |
| --- | --- | --- |
| **Standing order** | The weekly template. "This store usually wants 12 conchas on Wednesday." | Rewrite a date that already happened, or silently replace a date someone already edited. |
| **Dated order** | The commercial commitment for one calendar date. | Write back into the weekly template. |

**Dated beats standing, per customer.** If Acme has a dated order for Wednesday, that dated order is Wednesday for Acme. Every other customer with no dated order still falls back to their standing pattern. Never treat a whole date as "all standing" or "all dated."

Pauses, vacation ranges, and skip dates suppress both the forecast and the generated order. Inactive customers do not generate orders.

Generation keeps human edits. If someone already changed Wednesday's quantities, generating again must not wipe that change unless they explicitly choose overwrite. Route transfers and one-time stops are preserved the same way. New standing stops append. They do not collide with stops already placed.

### Bake day and delivery day are not always the same day

Daily Production is still keyed on the **delivery date**. The Tuesday pan dulce bake sheet is Wednesday's orders.

- Main pan dulce is produced Monday–Friday. Friday's bake covers Saturday (including markets), Sunday, and Monday. Monday's bake is for Tuesday.
- Sour Flour bread is a separate cadence: Tuesday and Friday for the following days' deliveries, plus Sunday for Monday.
- Saved production targets and finished-goods inventory stay on the delivery date. The app does not quietly borrow stock from another date.

Weekday numbers in the database use **Sunday = 7**.

### Who uses it, and how

People sign in with a short staff code. Baker, driver, and manager screens lean Spanish. Administrator screens lean English. Both languages are real translations (`lang/en.php` and `lang/es.php`), kept at the same keys.

| Person | Where they live | What a normal day looks like |
| --- | --- | --- |
| **Administrator** | Desktop. Operations Dashboard, Daily Run, Billing Center, and the full menu. | Sees the whole date: what is unusual, what is not done, and whether the day can close. |
| **Manager** | Phone first. Bakery Manager: Today, Routes, Kitchen, Missed. The rest of the menu is behind More. | Before planning: are tomorrow's orders ready? Before vans leave: does every stop have a driver? During delivery: who is behind? At the end: are routes reconciled so the day can close? The phone screen points at the real tools. It does not become a second place to edit orders or routes. |
| **Baker** | Mix Today, Daily Production, Pack List. | A sequenced workload: which dough, how much, formula in grams, then what goes in whose box. Bakers do not reconstruct the order book. After a manager commits the date, bake quantities come from that committed plan. An uncommitted date says so out loud. |
| **Packing** | Pack List. | Checklist by product, customer, or route. Shared check-offs. Shortages use bread on hand plus bread already loaded. |
| **Driver and driver assistant** | My Route, and Call HQ when they need the bakery. | Phone. Next stop, a small map of remaining stops, then photo → pieces and credits → cash on delivery → invoice preview → confirm. A driver assistant works the paired driver's route, so both people update the same stops. This flow is the reference for how a role screen should feel. |
| **Cashier** | Shop photos, add-product photos, counter pickup slips. | Writes a pickup note: date, which counter, what they want, paid or not, optional photo. Those slips are notes for the manager and bakers. They do not create a Square sale and they do not move finished goods. |
| **Wholesale customer** | Customer portal, by phone plus 4-digit code or a staff-made QR. | Upcoming deliveries, standing order, week pauses, invoices, photos, and issue reports. Portal edits use the same demand rules as staff. |
| **SF Baker (learner)** | Separate learner screens on a portal identity (`sfb_*.php`), plus the public curriculum site in `breadeducation/`. | Courses, a batch journal, community, and a guided baking week. This is education beside the wholesale day, not a second order system. Signup never creates standing orders, routes, or invoices. |

### What "used" means in practice

The live site, `bakery.sourflour.org/bake/`, is the real bakery. Managers run the date from a phone. Bakers work the committed bake list. Drivers confirm stops on the route, and that confirmation freezes the bill. Billing later sends the portal invoice from that frozen record. Customers look up their own deliveries. English and Spanish usage videos of these screens live in the app under **Insights → Walkthroughs** (recorded on a local copy of production data, not on the live server).

A few boundaries are already decided, so you do not have to rediscover them:

- Invoices are computed from the delivery snapshot. There is no separate accounts-receivable ledger in this phase, and no weekly rollup invoice.
- Cash on delivery is tracked on the order and turned in per driver and day. It is not a Square invoice.
- Ingredient Planner gives purchase hints and ordered/received notes. It does not adjust ingredient stock on hand.
- Retail Square register sales stay outside this app. Square point of sale owns the register.

### The rules that break the day if you violate them

These are the ones to memorize. The full list is `BAKERY_PRODUCT_CONTEXT.md` section 4.

1. Dated beats standing, **per customer**, never for the whole date at once.
2. Standing edits do not rewrite past dated orders. Dated edits do not rewrite standing.
3. Re-generating a date preserves manual quantity edits unless overwrite is explicit, and it does not rewrite an existing route decision.
4. Delivery confirmation is the one transaction that creates the billable record: pieces, credits, prices, and cash, together.
5. Historical invoices never read today's catalog price. The price that left the door stays the price.
6. Two status fields move on different writes and must stay aligned: the order (`pending` → `delivered` → `invoiced`) and the route assignment (`pending` → `delivered`, plus skip/cancel).
7. Loading a van moves custody of finished bread (available → on the van). It does not record the sale. End-of-route closeout records delivered, returned, and wasted.
8. Marking an exception "handled" never hides the fact that the exception is still true.

---

## Part 2 — Key pages

The live menu is `includes/navigation_catalog.php`. The in-app **Module Guide** (`module_guide.php`) is that same catalog. Hiding a menu item is not security. `includes/auth.php` enforces the role on the server. A page that is not in the catalog defaults to administrator-only.

Usage words below match the catalog: **everyday**, **moderate**, **occasional**.

### The date, from morning to close

| Moment | Page | File | What it is for |
| --- | --- | --- | --- |
| Manager glance | Bakery Manager | `manager.php` | Phone home. Today, Routes, Kitchen, Missed. Read-first. Deep links keep the selected date. |
| Whole-day snapshot | Operations Dashboard | `index.php` | Order, production, and delivery state, with the unusual cases sorted above the quiet ones. |
| The checklist | Daily Run | `daily_run.php` | Gated stages for the date, ending in day close. Closeout refuses to record while blockers remain. |
| Shift handoff | Daily Brief | `daily_brief.php` | One printable page: changes, production, routes, exceptions. |
| Demand | Daily Orders | `daily_orders.php` | Generate the date from standing, review forecast vs dated, edit quantities, add a one-time order. |
| Weekly template | Standing Orders | `standing_orders_manager.php` | Recurring order by customer and weekday. The older `standing_orders.php` is historical only. |
| One customer | Customer Hub | `customer_record.php` | Summaries and links. Editors for orders, pricing, and billing stay on their own pages. |
| Sense the bake | Production Manager | `production_manager.php` | Dough-first board: pieces, batch size, dough weight, this week vs last. |
| Plan and commit | Production Center | `production_center.php` | One delivery day. Demand, plan, produce, pack. Autosave. Commit is what the baker executes. |
| Bake | Daily Production | `production.php` | Baker's list by dough. Records good output and waste into finished goods. |
| Mix | Mix Today | `baker_mix.php` | Starter feedings and dough batches. |
| Pack | Pack List | `pack_list.php` | Who gets what. Shared check-offs. |
| Bread on hand | Finished Goods | `inventory.php` | Counts and the movement ledger for a delivery date. |
| Put bread on the van | Driver Pickup Loads | `driver_load.php` | Load quantities. Moves stock into van custody. Sets open orders out for delivery. |
| Build the route | Driver Assignment | `driver_assignment.php` | Prepare demand, build from the standing route, drag, transfer, unassign. Unassign does not delete the order. |
| Drive it | My Route | `driver.php` | Stops, map, reorder remaining stops, open the confirm wizard. |
| Confirm a stop | Complete delivery | `complete_delivery.php` | Photo, pieces, credits, COD, snapshot prices, one transaction. |
| Reconcile the van | Route Closeout | `route_closeout.php` | Loaded = net delivered + leftover + waste + door credits. Required before Daily Run can close the day. |
| Cash turned in | Route Manager | `route_manager.php` | Stops plus COD on hand and turn-in totals. |
| See the day in photos | Route Summary | `route_summary.php` | Photo-first review: customer, amount, driver. |
| Bill | Billing Center | `billing_center.php` | Mark a confirmed delivery invoiced, send the portal invoice, statements, Square pay links for non-COD accounts, QuickBooks export. Amounts are read-only here. |
| Problems customers reported | Service Issues | `service_issues.php` | Quantity, damage, billing questions. |
| Texts | Text Command Center | `text_comms.php` | The SMS ledger. Sending happens on this page. |
| Tomorrow's store order | Survey Center | `text_comms.php?view=surveys` | Lock stores and set order for tomorrow. |

Daily Run's stages, in spirit: confirm demand, commit the production plan, produce, pack, assign / load / dispatch, deliver and reconcile, invoice, close the day.

### Setup and lookup (not every day)

| Area | Pages | Files |
| --- | --- | --- |
| Customers | Customers, schedule, zones, leads | `customers.php`, `customer_schedule.php`, `zones.php`, `leads.php` |
| Prices | Pan Dulce zone prices, custom per-customer prices, Pan Dulce standard quantities | `pan_dulce_pricing.php`, `customer_pricing.php`, `pan_dulce_quantities.php` |
| Routes as a template | Standing Routes, drivers, map, daily route view, route timing | `standing_routes.php`, `drivers.php`, `map.php`, `daily_route.php`, `route_analysis.php` |
| Recipes | Products, photos, dough types, formulas, ingredients | `products.php`, `product_photos.php`, `dough_types.php`, `formulas.php`, `ingredients.php` |
| Planning math | Ingredient Planner, Product Manager Plan, Product Distribution | `ingredient_requirements.php`, `product_manager_plan.php`, `product_distribution.php` |
| Audit | Operational Timeline, Login History | `operational_timeline.php`, `login_history.php` |
| Identity | User Management, customer QR login | `users.php`, `qr_login.php` |

### Other product surfaces in the same codebase

| Surface | Entry | What it is |
| --- | --- | --- |
| Customer portal | `customer_portal.php`, `customer_login.php`, `customer_invoice.php` | The customer's own upcoming orders, pauses, invoices, and issues. |
| Counter slips | `counter_orders.php` | Pickup notes. Not sales, not inventory. |
| SF Baker learning | `sfb_join.php`, `sfb_dashboard.php`, `sfb_intensive.php`, and the other `sfb_*.php` pages | Learner home, courses, batch journal, community, Full Week Intensive. |
| Education commerce | `sfb_offerings.php`, `square_webhook.php` | Offerings bought through Square or account credit. The signed webhook is the payment truth. |
| Public curriculum site | `breadeducation/` | Static pages, deployed separately from the app. |
| Staff coaching for learners | `sfb_admin_overview.php`, `sfb_admin_intensive.php` | Review batches and leave notes. Administrator only. |
| Agent Homebase | `agent_homebase.php` and `scripts/agent_homebase.php` | Decisions, bugs, and session notes for people and agents working on the code. |
| Historical menu | `historical_navigation.php` | Older pages kept for reference. Do not promote them back into the daily menu, and do not build on the quarantined invoice generators. They redirect to Billing Center. |

### Videos of real use

**Insights → Walkthroughs** (`walkthroughs.php`) has short English and Spanish recordings: staff login, Daily Run, building and reordering a route, Driver Assignment, the manager phone, and the driver path (sign in, tomorrow's route, complete a stop, skip a stop, adjust the remaining order, Call HQ).

---

## Part 3 — How the code is built, and how to change it

This is ordinary server-rendered PHP. There is no React app, no MVC framework, no Composer project, and no PHPUnit. That is a choice. A rewrite into a framework is out of scope.

If you have only seen courses that start with a framework, read this section before you look for `src/controllers`.

### Shape of the tree

```text
bakery/
├── *.php                 one URL per file (the page or JSON endpoint)
├── includes/*.php        domain functions (bakery_*) and shared HTML
├── includes/*.js         shared browser scripts, no bundler
├── css/                  tokens.css, base.css, nav.css, then one file per surface
├── lang/en.php, es.php   every user-visible string, both languages, same keys
├── database/schema/      NNN_slug.sql migrations, applied in order, never renamed
├── database/fixtures/    demo rows for empty test databases
├── tests/run_*.php       scripts that call the domain and assert database state
├── scripts/              migrations, tests, deploy, backups, Homebase
└── breadeducation/       static public curriculum site
```

A page is a controller only in the plain sense: authorize, validate, call a function in `includes/`, render HTML. Business rules do not live in the page if they are also needed somewhere else. One business fact has one mutation function. Examples: `includes/customer_order_mutations.php`, `includes/daily_order_generation.php`, `includes/product_inventory.php`, `includes/billing.php`.

### What happens on a request

1. The page sets who may open it and loads `includes/config.php` (environment, session, `America/Los_Angeles`, translations) and `includes/database.php`.
2. `database.php` opens PDO and `includes/auth.php` checks login, role, and CSRF on any write.
3. The page handles `$_POST['action']` or JSON by calling `includes/` helpers.
4. It includes `includes/header.php`, prints the page, and includes `includes/footer.php`.

Shared chrome is the header, `includes/nav.php`, and the footer. Design tokens live in `css/tokens.css`.

### Data, in one pass

`customers` is the identity hub for wholesale accounts, portal customers, and SF Bakers. Product families add their own prefixed tables (`sfb_*`, `square_*`, `text_*`, `survey*`) with a foreign key to `customers`. They do not add columns to `customers`, `daily_orders`, `daily_order_items`, or `standing_orders` unless the owner has approved that exception in the migration file.

The commercial spine is:

- `standing_orders` — weekly template
- `daily_orders` and `daily_order_items` — one date's commitment
- `demand_confirmations` — a manager confirmed that date
- `production_plan_commits` — the bake list the baker executes
- `product_inventory_days` and `inventory_movements` — finished goods and an append-only ledger
- `driver_loads` — bread in a van
- `daily_order_assignments` — route position and delivery status
- snapshot columns on `daily_orders` — prices and totals frozen at delivery
- `operating_day_closeouts` — the day was closed

Demand math goes through `bakery_operating_demand_*` helpers in `includes/demand_review.php`. Do not sum the raw tables yourself. You will drop pauses, inactive customers, or the per-customer dated-over-standing rule.

### Habits that keep production safe

- Helpers check whether a table or column exists and show "unavailable" when a migration has not reached that host yet. Keep that tolerance.
- New schema files take the next free number: `php scripts/next_schema_migration.php --name=slug`. Never reuse or rename a file that has already been applied.
- Every new user-facing string gets a key in both language files in the same change. Spanish is a translation, not a copy of the English.
- A new root `*.php` URL must be listed in `scripts/deploy_manifest.ps1` or staging will 404. Prefer adding behavior to an existing screen.
- `display_errors` is on only in local development. Production logs under `logs/`. Never print raw exception text to the browser.
- Cache-busting for CSS and JS uses the file's modification time (`bakery_asset_href()`).

### Databases you will meet

| Database | What it is | May you write to it? |
| --- | --- | --- |
| `bakerysf_local` | Nightly mirror of production, on the laptop | No. Look only. Demo videos are recorded here. |
| `bakerysf_stage_local` | Everyday local development, and the Homebase notes | Yes. |
| `bakerysf_test` | Disposable database for tests | Only by the test scripts. The gate wipes it. |
| `bakerysoftware` on the host | Hosted staging | Staging acceptance only. |
| `bakerysf` on the host | Live bakery | Application traffic and owner-approved migrations only. |

There are three copies of the **code**, and they are not the same step:

| Layer | What it is | How it updates |
| --- | --- | --- |
| GitHub `dgabriner/bakery`, branch `main` | Source of truth for application code | Commit and push. |
| Hosted staging `staging.sourflour.org` | The copy people open on a phone before anything is real | A separate file sync. A git push does not update it. |
| Live `bakery.sourflour.org/bake/` | Today's bread, invoices, and routes | The owner promotes from Staging Manager. Agents do not SFTP the live site and do not import a staging database over live. |

Secrets, `.env` files, uploads, and database dumps are not in Git.

Local setup steps are in `docs/LOCAL_SETUP.md`. The daily loop is in `docs/DEV_WORKFLOW.md`. Read those with the owner before you point any tool at a hosted database.

### Tests

Tests are PHP scripts, `tests/run_*.php`. They call domain functions and assert rows. They refuse to run against anything except `bakerysf_test`.

```text
php scripts/agent_homebase.php tests-for --files="the_file_you_touched.php" --json
php tests/run_some_suite.php
```

`includes/agent_work_map.php` maps files to the suites that cover them. A new suite gets registered there in the same change. The desktop gate is `scripts/run_local_test_gate.ps1`. The Linux/cloud gate is `scripts/run_test_gate.sh`.

### How to make a change that belongs here

1. Name the date, the role, and the screen where that person already decides.
2. Read the workflow from the previous handoff through the next one before editing a step.
3. Put the rule in an existing `includes/` function if one already owns that fact.
4. Add English and Spanish strings together.
5. Run the suites mapped to the files you touched.
6. Leave the live site alone.

A small change that finishes a handoff is the sophisticated one. A new home page is a failed change, even if the code is clean.

### What we are not doing

No framework migration. No Composer or PHPUnit adoption. No new staff home page. No second invoicing system. No pricing of old invoices from the live catalog. No treating counter slips as sales.

---

## Part 4 — Map of the manuals

Read in this order.

| When | Document | Why |
| --- | --- | --- |
| First hour | This file | The day, the pages, the shape of the code. |
| Before any feature | `BAKERY_PRODUCT_CONTEXT.md` | The operating manual: thesis, roles, invariants, surface map, open loops, product principles. Sections 1–5 and 8 are the core. Section 4 is the rule book. |
| Before editing | `ARCHITECTURE.md` | The code as it is: request lifecycle, conventions, growth rules. |
| Before a work session | `docs/AGENT_DEVELOPMENT_MANUAL.md` and `AGENTS.md` | How a change is supposed to start, get tested, and get written down. |
| Before databases, Git, or deploy | `docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md` and `docs/DEV_WORKFLOW.md` | Which database is which, and why live is a promotion, not a file copy. |
| While choosing a screen | `includes/navigation_catalog.php` and the in-app Module Guide | The current menu. `docs/MODULE_ACCESS_GUIDE.md` is a shorter prose version and can lag the catalog. |
| To see the product used | In-app Walkthroughs | English and Spanish recordings of the real screens. |
| For one owned task | `docs/prompts/` | Mission briefs. Check product-context sections 6–7 before treating a prompt as still open. Many early prompts are already shipped. |
| Never as current truth | `docs/archive/` and dated status memos | History. They will send you to fix things that already shipped. |

Inside the product manual, section 5 is the compact file map (dashboard, Daily Run, production, routes, billing, portal, education). Section 3 is the behavior you must not casually redesign. Section 8 is the judgment rule: improve the workflow that already exists, put information on the screen where the decision happens, and let the normal case stay quiet.

`README.md` at the repository root is the one-page version of stack, roles, tests, and deploy.

### A first week that teaches the system

1. Read Part 1 of this guide and sections 1, 2, and 5 of `BAKERY_PRODUCT_CONTEXT.md`.
2. Watch the Walkthroughs for login, Daily Run, the manager phone, and completing a driver stop.
3. Pick one date in local development data and follow it with your eyes: Daily Orders → Production Center → Daily Production → Driver Assignment → My Route → Route Closeout → Billing Center. Do not click through on the live site.
4. Read one mutation end to end in code. `bakery_confirm_delivery` (delivery confirmation) or `bakery_generate_daily_orders_from_standing` (standing → dated) are the two that explain the most.
5. Run the test suite those files map to, and read the assertions. That is how this project specifies behavior.
6. Only then take a small change on an existing screen, with a named date and role.

Ask which database a command will write to before you run it. Ask before any command whose name mentions production, live, or push.

---

*Canonical product behavior remains `BAKERY_PRODUCT_CONTEXT.md`. This guide is the front door.*
