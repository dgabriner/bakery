<?php
/**
 * Canonical curriculum and bug watchlist for the Agent Homebase.
 * Upserted by slug so shipping this file refreshes the studio.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_agent_homebase_seed_lessons(): array
{
    return [
        [
            'slug' => 'product-thesis',
            'track' => 'product',
            'title' => 'What we are building',
            'summary' => 'Sour Flour OS runs one bakery day. Close loops; do not add modules.',
            'sort_order' => 10,
            'is_required' => 1,
            'body_md' => <<<'MD'
**Sour Flour OS** (working name: Bakery Manager) runs the physical day of a wholesale bakery. Stack: flat PHP + MariaDB, no framework, DreamHost.

The loop, keyed on one operating date:

customers / standing demand → dated orders → confirmed demand → production plan → bake → pack → load → deliver → returns/waste → invoice → close the day.

**North star:** by looking at one date, every role can see what must happen next, do that work, and leave a trustworthy record for the next role.

**Refined thesis:** the operational spine exists (`daily_run.php`, 8 gated stages). Several stages still do not fully close. Product work is closing those loops — not adding screens.

Canonical manual: `BAKERY_PRODUCT_CONTEXT.md`. When the doc and the code disagree, the code wins — then fix the doc.
MD
        ],
        [
            'slug' => 'roles-and-surfaces',
            'track' => 'product',
            'title' => 'Roles and where work happens',
            'summary' => 'Menu hiding is never the only control. Driver UX is the reference.',
            'sort_order' => 20,
            'is_required' => 1,
            'body_md' => <<<'MD'
Roles (enforced in `includes/auth.php`): administrator, manager, baker, driver, plus the customer portal.

- **Manager:** Daily Run, Manager Mode, Operations Dashboard, Daily Brief.
- **Baker:** Daily Production + Pack List only, filtered to assigned lines.
- **Driver:** My Route + Call HQ. Study this before building other role flows.
- **Customer:** portal edits go through the same demand model staff use.

Do not invent a new home page. Put chips on the screen where the decision is made.
MD
        ],
        [
            'slug' => 'invariants',
            'track' => 'product',
            'title' => 'Invariants you must not break',
            'summary' => 'Dated beats standing per customer. Generation preserves edits. Snapshots freeze prices.',
            'sort_order' => 30,
            'is_required' => 1,
            'body_md' => <<<'MD'
From `BAKERY_PRODUCT_CONTEXT.md` §4 — violating these breaks operations even if the code “works”:

1. Dated beats standing **per customer**, never all-or-nothing per date.
2. Standing is a template. Daily is the commercial commitment. They do not rewrite each other.
3. Generation preserves dated quantity edits unless `overwrite_changed` is set. Inactive and synthetic customers never generate.
4. Delivery confirmation creates the billable snapshot. Never price historical invoices from live `products.price`.
5. Billing Center marks invoiced; it does not invent amounts. Loads move custody, not ownership. Route closeout reconciles loaded = delivered + returned + waste.
6. Sunday = 7. Prefer `zones` table over hardcoded lists.
7. Feature checks are runtime (`table_exists`) and may show “unavailable”. Preserve that honesty.

Demand consumption goes through `bakery_operating_demand_*`, never raw table sums.
MD
        ],
        [
            'slug' => 'best-practices',
            'track' => 'practices',
            'title' => 'Best practices',
            'summary' => 'Improve existing workflows. Closed loops beat new screens. Exceptions over dashboards.',
            'sort_order' => 40,
            'is_required' => 1,
            'body_md' => <<<'MD'
- Improve existing workflows before inventing systems. This app has too many screens.
- Prefer closed loops: after the user’s action, what carries it forward? If the answer is “memory,” that is the bug.
- Surface information where decisions happen.
- Favor exception-driven workflows: normal case silent, unusual case loud.
- Reduce repetitive entry with bulk actions and defaults.
- Preserve strong existing behavior (standing/dated generation, dashboard honesty, driver confirm, FG ledger, invoice snapshots).
- No generic ERP feature creep. Every feature traces to a bakery-day moment.
- No framework rewrite. Extract shared logic into `includes/` when a feature touches it.
- Ugly code that closes a loop beats clean code that opens a new one.
- i18n: add keys to `lang/en.php` **and** `lang/es.php`.
- Do not add a new top-level page when an include + existing screen will do.
- Local DB only for tests (`bakerysf_local` / `bakerysf_test`). Never `setup_local_db` against the production mirror. Do not deploy unless asked. Auto-push stays off.
MD
        ],
        [
            'slug' => 'simple-practices',
            'track' => 'practices',
            'title' => 'Simple practices (how to actually work)',
            'summary' => 'Read the workflow end-to-end. One mutation path. Test the suites you touched.',
            'sort_order' => 50,
            'is_required' => 1,
            'body_md' => <<<'MD'
1. Read `BAKERY_PRODUCT_CONTEXT.md`, then the files you will touch, then one happy path through the UI in your head.
2. Start a Homebase session (`php scripts/agent_homebase.php start --agent= --mission=`).
3. Put shared writes in `includes/`. Pages authorize → validate → call helper → render/redirect.
4. CSRF on POSTs. `bakery_require_role` on the server. Menu hiding is not security.
5. Run the relevant `tests/run_*.php` plus anything that shares the helper. Use `bakery_assert_local_test_target`.
6. End with the §10 handoff and `php scripts/agent_homebase.php handoff ...`.
7. Pin unfinished thoughts on the whiteboard instead of leaving them only in chat.

If you are lost, run `php scripts/agent_homebase.php brief --json` and read the required lessons you have not completed.
MD
        ],
        [
            'slug' => 'bugs-to-focus',
            'track' => 'bugs',
            'title' => 'Bugs and open loops to focus on',
            'summary' => 'Plan does not reach the baker. Several status divergences. Known lying screens.',
            'sort_order' => 60,
            'is_required' => 1,
            'body_md' => <<<'MD'
Highest-value open loops (verify against code; this list is also the Bugs board):

- Production Center **saves** targets; Daily Production still bakes to **demand**. No commit/lock. Late demand changes after planning surface nowhere.
- Production confirm is **additive** — re-entry double-counts. No bake-sheet waste. Door credits now post FG `return` movements at confirm (do not also return them at closeout).
- Loading a van sets orders `out_for_delivery` but can leave assignments `pending`. Skip may cancel the assignment but not the order.
- Billing Center bulk-marks invoiced and can send/record the portal invoice. Legacy generators (`simple_invoice.php`, `generate_invoice.php`) redirect to Billing Center — do not extend them.
- `product_distribution.php` still flips demand all-or-nothing (violates dated-beats-standing per customer).
- Exception ownership exists; staff are not pinged. Completing work must never hide a still-true operational fact.
- Overlapping route screens. Driver Assignment is canonical.

When you find a new durable bug, log it: `php scripts/agent_homebase.php bug --title= --detail=`.
MD
        ],
        [
            'slug' => 'craft-homebase',
            'track' => 'craft',
            'title' => 'Professional craft: live in this Homebase',
            'summary' => 'Every mission checks in, learns, pins, and hands off here — not only in chat.',
            'sort_order' => 70,
            'is_required' => 1,
            'body_md' => <<<'MD'
This studio is how we accumulate judgment across chats.

**Start:** `brief` → complete unread required lessons → `start` a session.
**During:** `pin` decisions and open questions; `bug` anything that should outlive the chat; `note` insights.
**End:** `handoff` with the eight §10 fields. Prefer writing the handoff here so the next agent (and the owner in Admin → Agent Homebase) can see it without hunting transcripts.

Do not treat chat as the system of record for decisions. The whiteboard column **Decided** is.
MD
        ],
        [
            'slug' => 'handoff-shape',
            'track' => 'craft',
            'title' => 'Handoff shape (every session)',
            'summary' => 'The eight fields from BAKERY_PRODUCT_CONTEXT §10.',
            'sort_order' => 80,
            'is_required' => 1,
            'body_md' => <<<'MD'
1. What you investigated (files/workflows actually read).
2. Decisions made and why (especially §4-adjacent).
3. Files changed (explicit paths).
4. User-visible behavior changed — per role, per screen.
5. Business rules preserved — which invariants you touched and how you kept them.
6. Tests/checks performed — which `tests/run_*.php` suites and results.
7. Unresolved questions for the owner or next agent.
8. Recommendations for the next agent.

Post that markdown via `handoff`. Also put lasting decisions on the whiteboard as **Decided**.
MD
        ],
    ];
}

function bakery_agent_homebase_seed_bugs(): array
{
    return [
        [
            'slug' => 'plan-not-on-bake-sheet',
            'title' => 'Committed production plan never reaches Daily Production',
            'detail' => 'Production Center saves production_plan_items. Daily Production still builds the bake list from demand. No commit/lock; “planned” on the bake sheet means demand. Post-commit drift is silent.',
            'severity' => 'critical',
            'status' => 'open',
            'focus_area' => 'production',
            'source' => 'product-context',
        ],
        [
            'slug' => 'additive-production',
            'title' => 'Production confirmation double-counts on re-entry',
            'detail' => 'bakery_inventory_record_production adds to available_quantity and produced_quantity every time. Bake-sheet waste is not captured.',
            'severity' => 'watch',
            'status' => 'open',
            'focus_area' => 'production',
            'source' => 'product-context',
        ],
        [
            'slug' => 'status-divergence',
            'title' => 'Order vs assignment status can diverge',
            'detail' => 'Loading may set daily_orders.status out_for_delivery while assignments stay pending. Skip may cancel the assignment but not the order.',
            'severity' => 'watch',
            'status' => 'open',
            'focus_area' => 'delivery',
            'source' => 'product-context',
        ],
        [
            'slug' => 'invoice-send-gap',
            'title' => 'Canonical invoice send is missing; legacy generators still exist',
            'detail' => 'Billing Center can send or record the portal invoice (snapshot totals; MAIL_DRIVER=log does not SMTP). Legacy generators redirect to Billing Center.',
            'severity' => 'watch',
            'status' => 'fixed',
            'focus_area' => 'billing',
            'source' => 'product-context',
        ],
        [
            'slug' => 'demand-flip',
            'title' => 'product_distribution.php demand-flip',
            'detail' => 'Legacy all-or-nothing dated vs standing for a date. Violates dated-beats-standing per customer. Use bakery_operating_demand_* helpers.',
            'severity' => 'broken-window',
            'status' => 'open',
            'focus_area' => 'demand',
            'source' => 'product-context',
        ],
        [
            'slug' => 'credits-not-returned',
            'title' => 'Credits taken back are not FG returns',
            'detail' => 'Shipped: confirm posts return movements for credits_taken_back (allocated by daily_order_items.id ASC) and closeout uses net delivered so the same loaf is not returned twice. Bake-sheet production waste is still a separate gap.',
            'severity' => 'watch',
            'status' => 'fixed',
            'focus_area' => 'inventory',
            'source' => 'product-context',
        ],
        [
            'slug' => 'no-staff-alerts',
            'title' => 'Exceptions have owners but nobody is pinged',
            'detail' => 'manager_exception_work can ack/assign/complete. Service issues are a real queue. There are no proactive staff alerts. Completing work must not suppress live operational exceptions.',
            'severity' => 'watch',
            'status' => 'open',
            'focus_area' => 'exceptions',
            'source' => 'product-context',
        ],
        [
            'slug' => 'legacy-invoice-live-price',
            'title' => 'Legacy invoice generators price from live catalog',
            'detail' => 'generate_invoice.php / simple_invoice.php / generate_invoice_simple.php now redirect to Billing Center. Canonical path is delivery snapshots + customer_invoice.php send.',
            'severity' => 'broken-window',
            'status' => 'fixed',
            'focus_area' => 'billing',
            'source' => 'product-context',
        ],
    ];
}

function bakery_agent_homebase_seed_whiteboard(): array
{
    return [
        [
            'column_key' => 'now',
            'title' => 'Exception workability (prompts 10–12)',
            'body' => 'Connections, mobile desk, desktop workshop. Paste-ready in docs/prompts/10–12. Do not invent a ticketing product.',
            'agent_name' => 'homebase',
            'sort_order' => 10,
        ],
        [
            'column_key' => 'next',
            'title' => 'Commit production plan → bake sheet',
            'body' => 'Highest-value remaining ops loop after exceptions. Daily Production must execute the committed plan with demand alongside and drift flags.',
            'agent_name' => 'homebase',
            'sort_order' => 10,
        ],
        [
            'column_key' => 'decided',
            'title' => 'No framework rewrite',
            'body' => 'Flat PHP + MariaDB. Extract includes/ helpers. Procedural page controllers stay. Code wins over stale README/ARCHITECTURE.',
            'agent_name' => 'homebase',
            'sort_order' => 10,
        ],
        [
            'column_key' => 'parked',
            'title' => 'Deferred on purpose',
            'body' => 'Full AR/aging, offline driver confirm, ingredient receiving/lots, CI/CD, containerization, Laravel. See BAKERY_PRODUCT_CONTEXT §7–9.',
            'agent_name' => 'homebase',
            'sort_order' => 10,
        ],
    ];
}
