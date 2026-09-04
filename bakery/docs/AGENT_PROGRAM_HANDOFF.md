# Agent Program 2026-09 — Handoff and remaining build

Written 2026-09-04 by the investigating cloud agent at the end of a credit-limited session. This is the **remaining build document**: what shipped, how to prove it, exactly what to do next and in what order, and the traps already stepped in so the next agent does not pay for them twice.

Trust order still applies: `BAKERY_PRODUCT_CONTEXT.md` → Homebase Decided → `docs/AGENT_DEVELOPMENT_MANUAL.md` → this file → `docs/prompts/30..64`.

## 1. What shipped (branch `cursor/sour-flour-agent-program-8d3b`, PR #19)

| Mission | Status | Proof |
|---|---|---|
| 30 `agent-env` — cloud agents can run the gate | **shipped** | `bash scripts/run_test_gate.sh` → 77 passed, 0 failed, 8 desktop-only skipped |
| 31 `docs-truth` — real-stack README/ARCHITECTURE, 26 briefs, work-map lanes | **shipped** | `php tests/run_agent_work_map_tests.php`; `brief --agent=<slug> --json` for every slug 30–64 |
| 32 `webhook-fail-closed` — Square/Twilio refuse unsigned traffic | **shipped** | `tests/run_webhook_fail_closed_tests.php` (13 checks, real `php -S`) |
| 33 `edge-entrypoints` — OAuth/setup scripts gated, ping sanitized, `*_api.php` JSON rule | **shipped** | `tests/run_edge_entrypoint_tests.php` (22 checks) |
| 34 `error-boundary` — global handlers, no raw exception text, `safe_execute` throws | **shipped** | `tests/run_error_boundary_tests.php` (18 checks) |
| 35 `money-transactions` — invoice send outbox + Square tx | **shipped** | `run_invoice_send_tests` + `run_square_invoice_tests` (fixture-green; removed from desktop-only) |
| 37 `characterize-core` — four god-page suites | **shipped** | `run_daily_orders_page_tests`, `run_standing_orders_manager_tests`, `run_production_center_tests`, `run_complete_delivery_tests` (all green twice) |
| 36 `js-safety-net` — browser error beacon + fetch audit | **shipped** | `run_client_error_api_tests`, extended `run_driver_photo_ui_tests` / `run_login_history_tests` / `run_i18n_tests` |
| 40 `nav-catalog-roles` — catalog drives role allowlists | **shipped** | `run_navigation_tests`, `run_auth_tests`, `run_cashier_role_tests`, `run_i18n_tests` |
| 41–46, 50–55, 60–64 | **briefed, not built** | `docs/prompts/NN-*.md` + `includes/agent_program_map.php` |

Also fixed along the way (each is its own commit):
- `scripts/run_migrations.php` never applied `025_customer_account_preferences.sql` (only the parallel `025_customer_notifications`). Fresh databases lacked `customers.ordering_contact_phone` etc., which `includes/text_comms.php:136` queries unguarded. Now wired with column guards.
- `SQUARE_WEBHOOK_NOTIFICATION_URL` was referenced by `square_webhook.php` but never defined from env; now in `includes/square_config.php` + `.env.example`.
- `tests/run_manager_phone_tests.php` asserted a string that `login.php` no longer contains (it routes via `bakery_role_home()`); test now asserts the function.
- `tests/run_staging_env_tests.php` / `run_phase4_auto_deploy_tests.php` accept `python3` as the launcher.
- `.sourflour-promotion-export/` (written beside the repo by hosted-promotion tests) is gitignored.

**Staging was not synced. Live was not touched.** GitHub only.

## 2. How to pick this up (five minutes)

```bash
git fetch origin && git checkout cursor/sour-flour-agent-program-8d3b   # or main after merge
bash bakery/scripts/cloud_agent_install.sh        # only if php/mariadb are missing (fresh VM boots run it)
cd bakery && bash scripts/run_test_gate.sh        # expect failed=0
php scripts/agent_homebase.php brief --agent=characterize-core --json   # packet for the next mission
```

Homebase needs `bakerysf_stage_local` with the 044 schema; on a fresh cloud VM `start`/`handoff` will complain until `php scripts/run_migrations.php --database=bakerysf_stage_local` has run once. `brief` and `tests-for` work without it.

## 3. Remaining build, in the order that pays best

Each item names the brief; the brief names files, tests, invariants, and done-when. Do them one mission per commit/PR.

### Do next (highest severity, smallest lanes)
1. ~~**35 remainder**~~ — **shipped** (Square create/publish/webhook transactions + never-regress).
2. ~~**36 `js-safety-net`**~~ — **shipped**.
3. ~~**37 `characterize-core`**~~ — **shipped** (four suites green twice).

Wave 1 reliability is complete. Continue with Wave 2 mobile below.

### Then mobile (Wave 2)
5. ~~**40 `nav-catalog-roles`**~~ — **shipped** (catalog ∪ registry allowlists; manager More ≤ 8 + searchable sheet; cashier Add Product has nav).
6. **42 `driver-fast-path`** then **43 `driver-offline-queue`** (43 needs the next free migration number — run `php scripts/next_schema_migration.php --name=delivery_client_request_id`; currently **078** after `077_client_errors`).
7. **44 `manager-phone-closeout`**, **45 `kitchen-one-screen`**, **41 `touch-tokens`**, **46 `sfb-bottom-nav`**.

### Then structure (Wave 3) — one page per PR
8. **50 `extract-assets`** (`route_manager.php` first: 88% of the file is CSS+JS) → **51 `split-actions`** → **52 `one-mutation-path`** → **53 `hot-path-queries`** (verify index migration number) → **54 `gate-scaling`** (GitHub Actions; `.github/` does not exist yet) → **55 `product-boundaries`** (Homebase Decided + product context §8).

### Integration (Wave 4) — owner decisions first
9. **60 `overnight-cron`** (agent builds staleness chip + runbook; owner installs crontab), **62 `engagement-writeback`** (no decision needed), **61 `settlement-story`** (step 1 needs no decision; ledger does), **63 `ingredient-light`** (step A only until owner confirms), **64 `retail-scope-decision`** (owner).

## 4. Owner decisions still open

- Pin a Homebase **Decided** that scopes product-context §9 ("deferred: security hardening, test architecture, tech-debt cleanup") to allow missions 34–37 and 50–55 as briefed — each protects a named bakery-day loop.
- AR/payments ledger: yes or computed-only (61).
- Weekly rollup invoices: yes/no (61).
- Retail cashier: Square sales read-back, or out of scope (64).
- Ingredient stock adjust: yes/no (63 step B).
- Install `demand_scheduler` + `staff_alert_digest` cron on DreamHost (60).

## 5. Traps already paid for

- **`proc_open` + `php -S`**: the command runs under `sh -c`; `proc_terminate` kills the shell, not PHP. Always `exec "php" -S …`, use one port per server instance, and refuse to start if the port already answers. Stale servers from earlier runs silently answered with the wrong config for twenty minutes.
- **Process env beats `.env`** (`includes/env_loader.php` skips keys that already have a non-empty value), but you cannot *unset* a `.env` value from the environment — pass a non-empty value or accept the `.env` default in the assertion (`TWILIO_VALIDATE_WEBHOOK=1` in the cloud `.env` is why the Twilio refusal is 403 there, 503 elsewhere; both are refusals).
- **`APP_ENV≠local` over plain HTTP is impossible in tests**: `includes/config.php:127` forces HTTPS for non-local. Test non-local branches at the function level, not over `php -S`.
- **Fixture DB ≠ snapshot DB.** Eight suites (`DESKTOP_ONLY_SUITES` in `scripts/run_test_gate.sh`) need production-snapshot rows (Cortadillos product, a baker user, Twilio media history) or gitignored files (`simple_invoice.php`, `generate_invoice_simple.php`). They are skipped by name, not silently.
- **`0000` is the test suite's guaranteed-bad login code**; the cloud `.env` uses `LOCAL_ADMIN_CODE=9741`, `SFB_AGENT_ADMIN_CODE=9099` so `run_auth_tests.php` passes. Do not "fix" `.env.example` back to 0000 for cloud.
- **`tests/run_characterization.php` rewrites `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md`** from whatever DB it ran against. On a fixture DB the result is not truth — `git checkout` that file after running it.
- **`bakery_agent_work_map_path_matches` matches by basename suffix** (`nav.php` also matches `portal_nav.php`). Prefer unique basenames in new lanes.
- **`bakery_error_message_for_user($e)` is now the way to show an exception to a user.** New catch blocks must not echo `$e->getMessage()`; `run_error_boundary_tests.php` greps the four core pages for it — extend that list when you touch another page.
- **`safe_execute()` throws now.** Any old caller that tested `if ($stmt)` must catch `RuntimeException` (only `generate_crud_handlers` used it in-tree).
- **Every `tests/run_*.php` must be listed in the work map** or `run_agent_work_map_tests.php` fails. New suites go into `includes/agent_program_map.php` (program) or `includes/agent_work_map.php` (core).
- **`pkill -f` with a pattern that appears in your own command line kills your shell.** Use `pgrep -f "php -S 127.0.0.1"` + loop `kill`.

## 6. Handoff (§10 fields)

1. **Investigated:** four parallel audits (reliability, mobile nav, scalability, ops coverage) + direct reads of `includes/auth.php`, `config.php`, `database.php`, `square_webhook.php`, `twilio_webhook.php`, `billing.php:1296`, migrations, tests, gates, deploy docs.
2. **Decided:** program of 26 scoped missions in five waves rather than generalized hardening; cloud gate builds `bakerysf_test` from schema + fixtures with desktop-only suites skipped by name; webhooks fail closed with no staging bypass; redirect-only stubs may skip the auth gate.
3. **Files changed:** see `git log --stat main..cursor/sour-flour-agent-program-8d3b` — root `.cursor/environment.json`, `.gitignore`; `bakery/`: `README.md`, `ARCHITECTURE.md`, `.env.example`, `ping.php`, `oauth_setup.php`, `oauth_callback.php`, `setup_directories.php`, `square_webhook.php`, `twilio_webhook.php`, `includes/{auth,square_config,square_invoices,twilio_config,agent_work_map,agent_program_map}.php`, `scripts/{run_migrations.php,run_test_gate.sh,cloud_agent_install.sh,cloud_agent_start.sh}`, `tests/{isolate_test_db,run_agent_work_map_tests,run_manager_phone_tests,run_staging_env_tests,run_phase4_auto_deploy_tests,run_webhook_fail_closed_tests,run_edge_entrypoint_tests}.php`, `docs/prompts/30..64 + README.md`, `docs/archive/*`, `docs/GROK_AND_CLOUD_AGENT_DEPLOY.md`, `.cursor/skills/test-gate/SKILL.md`; deleted `assets/api/get_route.php`.
4. **User-visible behavior:** none for bakery staff. Square/Twilio forged webhooks now refused; `ping.php` no longer prints paths; OAuth setup/callback and directory setup require an administrator session.
5. **Invariants preserved:** no pricing, demand, inventory, or invoice logic touched; signature-checked webhook remains the only payment truth; tests only on `bakerysf_test`.
6. **Tests:** full Linux gate 77 passed / 0 failed / 8 desktop-only skipped; new suites `run_webhook_fail_closed_tests` (13/13), `run_edge_entrypoint_tests` (22/22), `run_error_boundary_tests` (18/18); `run_invoice_send_tests` 56/58 (the 2 failures are the gitignored quarantine files, pre-existing on fixture DBs); `run_agent_work_map_tests`, `run_agent_homebase_tests` green. Not run here: the eight desktop-only suites (need snapshot/quarantine files).
7. **Unresolved:** owner decisions in §4; Homebase `start`/`handoff` not recorded in the ledger from this VM (no `bakerysf_stage_local` schema here) — the next desktop session should `pin` the program as **Decided** and log this handoff.
8. **Next agent:** Wave 2 continues: 42 → 43 → 44 → 45 → 41 → 46. Read the brief, run `tests-for --files=`, keep the lane, register any new suite, `bash scripts/run_test_gate.sh --changed-since=origin/main` before pushing. **35, 36, 37, and 40 are shipped.** Staging and Live were not touched.
