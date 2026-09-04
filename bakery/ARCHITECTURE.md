# Sour Flour OS — Architecture as it actually is

This describes the code in this tree, not an aspiration. When this file and the code disagree, the code wins — fix this file. Product behavior and business invariants live in `BAKERY_PRODUCT_CONTEXT.md`.

## Shape

```text
bakery/
├── *.php                    page controllers and JSON endpoints (one file = one URL)
├── includes/
│   ├── *.php                domain libraries (bakery_* functions) and HTML partials
│   └── *.js                 shared browser scripts (IIFE, no bundler)
├── css/                     tokens.css + base.css + nav.css + per-surface CSS
├── lang/en.php, lang/es.php i18n catalogs at exact key parity
├── database/schema/         NNN_slug.sql additive migrations (schema_migrations keyed by full id)
├── database/fixtures/       demo data for isolated test databases
├── tests/run_*.php          integration suites on bakerysf_test (no PHPUnit)
├── scripts/                 CLI: migrations, Homebase, test gates, deploy, backups
├── breadeducation/          static public curriculum site (separate SFTP zone)
├── docs/                    manuals, plans, prompts (missions), archive (historical)
└── vendor/phpmailer/        the only third-party PHP dependency (vendored by hand)
```

## Request lifecycle

1. Page defines `ACCESS_ALLOWED`, requires `includes/config.php` (env, session, timezone `America/Los_Angeles`, i18n) and `includes/database.php`.
2. `database.php` opens the PDO connection (`ERRMODE_EXCEPTION`, utf8mb4), loads `includes/auth.php`, and runs `bakery_enforce_request_security()` — login, role allowlists per script, CSRF on mutating methods, portal vs staff vs community identity. New pages inherit "any logged-in staff" unless listed in a role allowlist; keep the allowlists honest.
3. Page handles `$_POST['action']` / JSON, calling `includes/` helpers for every mutation. One mutation path per business fact (`includes/customer_order_mutations.php`, `includes/daily_order_generation.php`, `includes/product_inventory.php`, `includes/billing.php`, ...).
4. Page requires `includes/header.php` (tokens → base → nav → page CSS, workspace body class per role), renders, requires `includes/footer.php`.

## Data model in one paragraph

`customers` is the identity hub (wholesale accounts, portal customers, SF Bakers via `sfb_origin`). `standing_orders` is the weekly template; `daily_orders` + `daily_order_items` are the dated commercial commitment (dated beats standing, per customer). `demand_confirmations`, `production_plan_commits`, `product_inventory_days` + `inventory_movements`, `driver_loads`, `daily_order_assignments` (route positions and delivery status), delivery snapshot columns on `daily_orders`, `operating_day_closeouts`. Prefixed families: `sfb_*` (community/education), `square_*`, `text_*`, `survey*`, `shop_photos`. Weekday encoding: Sunday = 7.

## Conventions that are load-bearing

- **Runtime degradation:** helpers check `table_exists()` / `column_exists()` and render "unavailable" instead of failing when a migration has not reached a host. Preserve this.
- **Snapshots over live prices:** delivery confirmation freezes prices; invoices never read `products.price` after the fact.
- **Two status enums:** `daily_orders.status` and `daily_order_assignments.delivery_status` advance on different write paths; keep them aligned (`tests/run_status_alignment_tests.php`).
- **Migrations:** next number from `php scripts/next_schema_migration.php --name=slug`; never rename applied files; 050+ files are self-contained SQL applied by glob, earlier ones are hand-wired in `scripts/run_migrations.php`.
- **i18n:** every user-facing string gets a key in both `lang/en.php` and `lang/es.php` in the same change (`tests/run_i18n_tests.php`).
- **Deployable surface:** new root `*.php` must be listed in `scripts/deploy_manifest.ps1`. Prefer an include + existing screen over a new page.
- **Errors:** `display_errors` is on only when `IS_LOCAL`; production logs to `logs/`. Never echo raw exception text to browsers.

## Environments

`APP_ENV=local|staging|production`; `IS_LOCAL`, `IS_STAGING`. Local databases: `bakerysf_local` (mirror, read-only), `bakerysf_stage_local` (development + Agent Homebase), `bakerysf_test` (tests only, guarded by `includes/test_target_guard.php`). Hosted Staging DB `bakerysoftware`; Live DB `bakerysf`. `USE_PROD_DB` is a local opt-in that agents never set.

## Testing

Suites are `tests/run_*.php` scripts asserting DB state after calling domain functions. `includes/agent_work_map.php` maps files → suites → invariants and is consulted by `php scripts/agent_homebase.php tests-for` and both gates:

- Desktop: `scripts/run_local_test_gate.ps1` (production snapshot → `bakerysf_test` → lint → all suites)
- Linux/cloud: `scripts/run_test_gate.sh` (snapshot when present, else schema + fixtures; `--files`, `--suites`, `--changed-since`)

Eight suites need snapshot data or gitignored quarantine files and are skipped on fixture databases (see `DESKTOP_ONLY_SUITES` in the Linux gate).

## Deploy

GitHub is code truth; Staging via SFTP (desktop auto-push or `scripts/cloud_agent_stage.py`); Live only by the owner through Staging Manager → Next, with `scripts/hosted_promotion_worker.php` and `scripts/hosted_migration_worker.php` on the host. Details: `docs/GROK_AND_CLOUD_AGENT_DEPLOY.md`, `docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md`.

## Growth rules (how this scales without a rewrite)

1. Shrink page controllers by moving SQL and business rules into `includes/` libraries, and inline `<style>`/`<script>` into `css/` and `includes/*.js` (`bakery_asset_href()` cache-busts by mtime).
2. Split `$_POST['action']` switches into `includes/<page>_actions.php` functions behind thin `*_api.php` endpoints.
3. One helper per business mutation; pages never write the same SQL twice.
4. New product surfaces add prefixed tables with FKs to `customers`. They do not add columns to `customers`, `daily_orders`, `daily_order_items`, or `standing_orders` unless the migration header contains `-- owner-approved-core-column` (owner Decided exception). Enforced in `tests/run_schema_compare_tests.php` for schema files `077+`.
5. Every new suite is registered in `includes/agent_work_map.php`; every mission names its suites.

What we are not doing: MVC/framework rewrite, Composer/PHPUnit migration, per-product databases, new staff home pages.
