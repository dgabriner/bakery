# Sour Flour OS (Bakery Manager)

Operations software for a wholesale sourdough bakery in San Francisco: standing orders → dated demand → production plan → bake → pack → load → deliver → close the day → invoice. It also carries the customer portal, the SF Baker community, Bread Education commerce, a retail cashier workspace, Square invoicing, and Twilio texting.

**Read first:** [`BAKERY_PRODUCT_CONTEXT.md`](BAKERY_PRODUCT_CONTEXT.md) (product manual and business invariants), then [`AGENTS.md`](AGENTS.md) and [`docs/AGENT_DEVELOPMENT_MANUAL.md`](docs/AGENT_DEVELOPMENT_MANUAL.md) (how we develop). Architecture: [`ARCHITECTURE.md`](ARCHITECTURE.md).

## What this actually is

- **Flat PHP 8.3, server-rendered.** Root `*.php` files are page controllers (authorize → validate → call `includes/` → render). Shared domain logic lives in `includes/*.php` as `bakery_*` functions; shared chrome in `includes/header.php`, `includes/nav.php`, `includes/footer.php`.
- **MariaDB.** Additive migrations in `database/schema/NNN_slug.sql`, applied by `scripts/run_migrations.php` and tracked in `schema_migrations` by full id. Fixtures for isolated tests in `database/fixtures/`.
- **Vanilla CSS + JS.** Design tokens in `css/tokens.css`; page CSS in `css/`; shared scripts in `includes/*.js`. No bundler, no framework.
- **No Composer, no PHPUnit.** `vendor/phpmailer` is vendored by hand. Tests are `tests/run_*.php` scripts on a disposable database.
- **i18n** in `lang/en.php` and `lang/es.php`, at exact key parity. Baker, driver, and manager surfaces lean Spanish.

## Roles and homes

Server-side enforcement is in `includes/auth.php` (`bakery_enforce_request_security`); the menu is `includes/navigation_catalog.php` + `includes/nav.php`.

- administrator → `index.php` Operations Dashboard, Daily Run, Billing Center, everything
- manager → `manager.php` phone workspace (Today / Routes / Kitchen / Missed)
- baker → `production.php` Daily Production, `baker_mix.php` Mix Today, `pack_list.php`
- driver / driver_assistant → `driver.php` My Route, `complete_delivery.php` confirm
- cashier → `product_photos.php`, `cashier_shop_photos.php`, `cashier_add_product.php`
- customer (portal) → `customer_portal.php` family, phone + 4-digit code or QR
- SF Baker (learner) → `sfb_*.php` on the portal identity

## Local setup

Desktop (Windows, owner's laptop): [`docs/LOCAL_SETUP.md`](docs/LOCAL_SETUP.md) and [`docs/DEV_WORKFLOW.md`](docs/DEV_WORKFLOW.md). Three local databases: `bakerysf_local` (nightly production mirror, read-only), `bakerysf_stage_local` (everyday development + Agent Homebase ledger), `bakerysf_test` (disposable; the test gate wipes it).

Linux / cloud agents: `.cursor/environment.json` at the repo root runs `scripts/cloud_agent_install.sh` (PHP 8.3, MariaDB, `bakerysf_test` from schema + fixtures, local-only `.env`). Manually:

```bash
bash scripts/cloud_agent_install.sh
bash scripts/run_test_gate.sh --files=billing_center.php     # suites mapped to the files you touched
bash scripts/run_test_gate.sh                                 # lint + reset + every suite
```

## Tests

```bash
php scripts/agent_homebase.php tests-for --files="a.php,b.php" --json   # which suites
php tests/run_invoice_send_tests.php                                     # one suite
bash scripts/run_test_gate.sh --changed-since=origin/main               # suites for your diff
.\scripts\run_local_test_gate.ps1                                        # desktop full gate (snapshot-based)
```

Tests target `bakerysf_test` only (`includes/test_target_guard.php` refuses anything else). `includes/agent_work_map.php` maps files → suites → invariants; a new suite must be registered there in the same change.

## Deploy model

GitHub `dgabriner/bakery` (`main`) is the code truth. Hosted Staging is the phone/acceptance host, updated by desktop SFTP auto-push or `scripts/cloud_agent_stage.py`. Live (`bakery.sourflour.org/bake/`) is promoted only by the owner through Staging Manager. Pushing Git ≠ updating Staging ≠ Live. See [`docs/GROK_AND_CLOUD_AGENT_DEPLOY.md`](docs/GROK_AND_CLOUD_AGENT_DEPLOY.md) and `.cursor/rules/git-staging-live-sync.mdc`.

## Working here as an agent

```text
php scripts/agent_homebase.php brief --agent=SLUG --json
php scripts/agent_homebase.php start --agent=SLUG --mission="one sentence"
... code, test ...
php scripts/agent_homebase.php handoff --agent=SLUG --summary="1. ... 8. ..." --files="a.php,b.php"
```

Mission briefs are in [`docs/prompts/`](docs/prompts/README.md). Close loops; do not add modules. Dated beats standing per customer. Never price historical invoices from live catalog prices.
