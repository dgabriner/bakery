# Agent Handoff — Surface-Hygiene Sweep & Roadmap Seeding (2026-08-22)

**Agent slug:** `surface-hygiene` (handoff also posted to Homebase ledger)
**Branch:** `feat/square-invoicing` · **Commit:** `1e3e3bf` ("Sweep quarantined root scratch, archive legacy SQL patches, add surface-hygiene gate"), parent `c010036`
**Scope:** repo hygiene execution + project-backlog creation. **No product/invariant code paths touched.**

---

## 1. Investigated

- `BAKERY_PRODUCT_CONTEXT.md` (§6 loops, §7 roadmap, §8 principles, §9 exclusions), `AGENTS.md`, `AGENT_DEVELOPMENT_MANUAL.md` trust order
- `docs/QUARANTINE_INVENTORY.md`, `.gitignore`, `includes/auth.php` (debug-page blocklist), `includes/staging_live_approval.php` (deploy-manifest exclusions), `scripts/run_migrations.php` (applied-tracking = filename id in `schema_migrations`), `tests/run_invoice_send_tests.php` (legacy-generator contract), `includes/test_target_guard.php` + `.cursor/skills/test-gate/SKILL.md` (DB-target rules)
- Grep-audited references for every deletion candidate before removing it

## 2. Decisions

1. **Zip-then-delete** (owner-approved) over hard delete or quarantine-folder shuffling.
2. **Kept** legacy invoice redirects (`generate_invoice*.php`, `simple_invoice.php`, `invoice_center.php`) — required by `run_invoice_send_tests.php`; pages delegate to `bakery_billing_legacy_generator_emit_quarantine()` in `includes/billing.php`.
3. **Did NOT rename** duplicate schema prefixes (two `010`s, `021`s, two `025`s) — runner tracks by filename id; renaming would re-run applied migrations. Documented instead.
4. **Deferred** `driver_pages_probe.php` / `trace_driver_list.php` / `ping.php`: `health_deploy.php` asserts on the probe name; needs a dedicated pass.
5. **Kept** `auth.php` blocklist entries for deleted files (harmless defense-in-depth).
6. **Deleted stale sibling tree `../sfbake/`** (untracked older app copy w/ own `.env`) after zipping outside the repo.
7. Deferred bulk checkpoint of pre-existing WIP — a **concurrent agent session was actively editing core includes mid-session** (see §7).

## 3. Files changed (committed in `1e3e3bf` unless noted)

- Deleted: `blah_blah.php`, `Blah2.php`, `blah3–6.php`, `probe_smoke_20260729.php`; local-only sweep of 39 ignored scratch files (`debug*`, `test_*`, variants, `.htaccess.bak`, etc.)
- Renamed: 7 loose root `.sql` patches → `docs/archive/sql-patches/`
- Edited: `.gitignore` (+quarantine patterns), `includes/nav_historical.php`, `includes/navigation_catalog.php` (dead Route Tester links), `lang/en.php`+`lang/es.php` (`page.route_tester` key removed from BOTH), `setup_directories.php` + `health_prod.php` (SQL paths re-pointed), `docs/QUARANTINE_INVENTORY.md` (dated sweep section)
- New: `tests/run_surface_hygiene_tests.php` (35-check gate), `scripts/purge_local_quarantine.php`
- Uncommitted (rides in worktree): `includes/agent_work_map.php` — added `surface-hygiene` mission + suite registration
- Archives: `storage/quarantine/root_scratch_sweep_2026-08-22.zip` (46 files, gitignored); temp `sfbake_stale_copy_2026-08-22.zip`

## 4. User-visible behavior

- Historical nav no longer shows Route Tester (page already absent from deploys — link was dead everywhere except one dev machine). No other role-visible change; all deletions were non-deployed surfaces.

## 5. Business rules preserved

No §4 invariant touched (no demand/pricing/inventory/closeout code modified). Verified intact: invoice redirect-only contract, migration immutability-by-filename, deploy manifest exclusions, i18n en/es parity (key removed symmetrically).

## 6. Tests/checks

- `php -l` clean on every touched/new PHP file
- `run_surface_hygiene_tests.php` **35/0** · `run_navigation_tests.php` PASS · `run_i18n_tests.php` PASS · `run_integrity_tests.php` **76/76** · `run_release_promotion_tests.php` OK · `run_agent_work_map_tests.php` all-pass (before other session's unmapped suites appeared) · `run_agent_homebase_tests.php` **48/48**
- DB-target gotcha for whoever runs suites: `.env` currently says `DB_NAME=bakerysf_local`; suites requiring the test gate need process-isolated `$env:DB_NAME='bakerysf_test'; $env:USE_PROD_DB='false'` (per test-gate skill). Do NOT edit `.env`.

## 7. Unresolved / active hazards

1. **Concurrent agent session live during my window**: new untracked `staff_alerts*`, `production_cut*` work + edits at ~20:51–20:54 to `includes/product_inventory.php`, `billing.php`, `daily_run.php`, `dashboard_command_center.php`. Their `run_production_cut_tests.php` / `run_staff_alert_tests.php` are **unmapped in agent_work_map** (work-map gate currently fails on them — theirs to register). **Do not bulk-checkpoint the WIP tree until that session hands off.**
2. Large pre-existing uncommitted drift in `lang/en.php`/`es.php` (~480 lines each) predates this session — owner should review before any lang-file commit.
3. **Server side**: deleted files very likely still exist on DreamHost staging/live; manifest exclusion only stops future pushes. Guarded remote-cleanup pass needed (owner confirm for live).
4. Owner decisions pending: retire-or-merge `products_new.php`; disposition of disabled `get_customers_no_address.php`.
5. Purge archives after **2026-09-05**: `php scripts/purge_local_quarantine.php --yes --path="<temp>\sfbake_stale_copy_2026-08-22.zip"`.

## 8. Recommended next steps (priority order)

1. Wait for/harvest the concurrent session's handoff, then **one WIP checkpoint commit**, then register its two suites in the work map.
2. **P1 broken-windows batch** (`leads.php` filter bug, dead `customer_upcoming.php` redirect, last i18n keys, zones single-source-of-truth `map.php`→`zones` table) — see `docs/PROJECTS_AND_AGENT_CONTEXT_2026-08-22.md` for full P1–P9 backlog with guardrails/tests/slugs.
3. Server-side cleanup pass (§7.3), then P2 staff-alert digest (may now partially exist via concurrent session's `staff_alerts*` — reconcile first!), then P3 money-visibility phase 1.
4. Small hardeners when convenient: duplicate-migration-prefix warning in `run_migrations.php`; probe/trace/ping deletion pass incl. `health_deploy.php` assertion update.

**Read first:** `BAKERY_PRODUCT_CONTEXT.md` → Homebase brief → `docs/PROJECTS_AND_AGENT_CONTEXT_2026-08-22.md`. Close loops, don't add modules.
