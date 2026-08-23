# Sour Flour OS / Bakery Manager — Next Steps Todo

**Audit date:** 2026-08-07

**Scope:** repository, runtime configuration, migrations, tests, deployment tooling, security boundaries, and representative local UI smoke checks.

**Current posture:** do not deploy the current working tree until the P0 items below are closed.

This document is a handoff from the full-app audit. The working tree is active and contains substantial user/agent changes; the audit did not modify application code. Treat the existing modified and untracked files as user-owned, stage explicit paths only, and re-run the checks against the exact snapshot being reviewed.

## Evidence snapshot

- Stack reality: flat server-rendered PHP 8.3 + MariaDB/MySQL + vanilla JS/CSS; no Composer project, framework, `public/` directory, or standard PHPUnit configuration is present. The root `README.md` and `ARCHITECTURE.md` describe a different MVC/Composer/Artisan application and are stale.
- Size/risk: 294 PHP files exist outside the vendor/runtime areas; the largest active files are `standing_orders_manager.php` (3,854 lines), `driver_assignment.php` (3,338), `daily_orders.php` (2,424), and `customer_schedule.php` (2,240). UI, SQL, request parsing, and business rules are frequently mixed in the same page.
- Working tree: 73 tracked files are modified, with roughly 17k added lines in the current diff, and more than 120 application files are untracked. This tranche should be reviewed and checkpointed before more feature work or auto-push.
- Local safety: `APP_ENV=local`, `USE_PROD_DB=false`, `DB_HOST=127.0.0.1`, `DB_NAME=bakerysf_local`, and `MAIL_DRIVER=log` passed `scripts/verify_local_env.php`.
- UI smoke: the local staff entry redirected to the driver workspace and rendered the local-environment banner, role-specific navigation, and empty-route state. The customer portal entry rendered its English passcode screen. Server-side role routing is active.

### Validation matrix

| Check | Current result | Follow-up |
|---|---|---|
| PHP lint, 233 non-vendor files | 230 parse clean; 3 syntax errors | Fix/quarantine tracked `trace_driver_list.php`; ignored `db_test.php` and `test_db.php` are also invalid |
| `tests/run_navigation_tests.php` | Passes on the current snapshot | Keep it in the release gate |
| `tests/run_i18n_tests.php` | Passes | Add page-level language smoke coverage later |
| `tests/run_characterization.php` | 64 passed, 0 failed | Preserve known source-of-truth contracts until deliberately changed |
| `tests/run_integrity_tests.php` | 25 passed, 0 failed | Add ownership and idempotency cases |
| `tests/run_auth_tests.php` | Fails with exit 255 in HTTP driver step | Test uses `/bakery/driver_list.php` against an app-root server and does not follow the driver redirect; failure then cascades at `count($payload['orders'])` |
| Customer account/notification/order-power tests | Fail before exercising behavior | Reset fixtures have no active `portal_enabled` customer |
| Customer billing | 22 passed, 0 failed on a confirmed-delivery fixture state | Add to the full gate |
| Customer delivery | Green behavior checks | Add assigned-driver and retry/security cases |
| Ingredient planner | 22 passed, 0 failed | Integrate into the full gate |
| Golden-day QA | 37 passed, 0 failed | Promote the scenario to a durable end-to-end regression |
| `git diff --check` | One trailing-whitespace finding in `includes/database.php` | Clean in the relevant patch |

## P0 — release blockers and safety

### 1. Close the public authentication and diagnostic exposure

- [ ] Remove the public auto-login path in `baker.php` for production. Replace fixed role codes in `includes/auth.php` (`BAKERY_BAKER_CODE`, `BAKERY_NIKO_CODE`, `BAKERY_ADMIN_CODE`) with environment-managed credentials or an explicitly designed device-enrollment/kiosk flow. Do not put real secrets in source, fixtures, or migrations.
- [ ] Add rate limiting, attempt throttling, lockout/rotation policy, and durable audit coverage for both staff and customer four-digit-code login in `includes/auth.php`, `includes/customer_portal.php`, and `includes/login_audit.php`.
- [ ] Remove or protect public access to `health_prod.php`, `health_driver.php`, `health_deploy.php`, `driver_pages_probe.php`, `trace_driver_list.php`, and the diagnostic branch of `ping.php`. They currently expose configuration/connection state, file markers, or database-derived operational information without a login gate. Update `includes/auth.php`, `.htaccess`, and `scripts/deploy_manifest.ps1` together; keep only a minimal, non-sensitive health response public.
- [ ] Fix or quarantine the tracked parse-invalid `trace_driver_list.php` before it can be deployed. Do not rely on a runtime auth check to protect code that cannot parse.
- [ ] Audit production users and login/audit logs for side effects from the earlier split-brain assessment run documented in `docs/DEVELOPMENT_ASSESSMENT_2026-08-04.md`; rotate any credential that may have been exposed, following `docs/CREDENTIAL_ROTATION_RUNBOOK.md`.
- [ ] Confirm auto-push is disabled while this tranche is under review. Do not let `auto_push_api.php` or SFTP tooling deploy an unreviewed mixed working tree.

### 2. Make the local test gate impossible to aim at production

- [ ] Add a shared test-target assertion that checks both configuration and the actual PDO connection (`SELECT DATABASE()` plus host/loopback checks). Apply it to `tests/harness.php`, auth/integrity/customer test bootstraps, reset/setup scripts, and any destructive fixture helper. Refuse the run if `USE_PROD_DB=true`, the database is `bakerysf`, or the connection is not loopback/test-scoped.
- [ ] Repair `tests/run_auth_tests.php` to use the server’s actual app-root URL (`/driver.php` or a followed redirect), and make failed HTTP responses print a bounded status/body diagnostic instead of dereferencing a missing `orders` key and fatally terminating.
- [ ] Make the sanitized reset fixture create one portal-enabled, fictional customer with a fictional portal code/phone, or make each portal test provision and clean up its own isolated customer. Then make account, notification, and customer-order-power tests pass from a clean reset.
- [ ] Add one command that resets a disposable local database, applies the complete migration set from zero, lints canonical PHP, runs every test script, and fails closed before any production connection. Make `scripts/dev_workflow.ps1` call that full gate rather than only the older three suites.
- [ ] Standardize test output: pass, fail, skip, and blocked-by-fixture must be distinct; no suite may end in a cascading PHP fatal after the first failed assertion.

### 3. Reconcile the current deployment boundary

- [ ] Build a reviewed baseline commit from the current mixed tranche, split by capability where practical (auth/navigation, driver workflow, billing/portal, deployment tooling, docs), and record exactly what is canonical versus historical. The current working tree is too large and mixed for a safe blind deploy.
- [ ] Verify the deploy manifest excludes all tests, diagnostics, backups, PII-bearing SQL, local credentials, runtime logs, and temporary uploads. Treat any manifest entry that is a debug/probe page as a release blocker.
- [ ] Run the production preflight only after the local gate is green: credential configuration, migrations, HTTPS redirect, auth/role checks, diagnostic denial, and rollback readiness.

## P1 — make one operating day trustworthy

### 4. Establish explicit data ownership and state transitions

- [ ] Publish and test one contract for the canonical daily loop: standing orders are forecast/templates; daily orders are the dated commercial commitment; production plan items are dated targets; inventory movements are the stock ledger; driver loads are custody transfer; delivery quantities and invoice snapshots are the billable record.
- [ ] Decide, document, and implement the cutoff/source-of-truth policy for standing-order generation versus manual daily-order edits. The current `production.php` fallback to standing orders is a known contract, but it should be an explicit workflow state rather than an easy-to-miss ambiguity.
- [ ] Decide whether production plans are keyed by delivery date, bake date, or both; then add lifecycle, owner, completion, variance, and notes to the production work-order concept.
- [ ] Turn the passing golden-day scenario into a durable vertical regression that covers: demand confirmation → production plan → ingredient calculation → production completion → finished goods → driver load → assigned delivery → quantity variance/credit → invoice snapshot → billing export → operating-day closeout.
- [ ] Add duplicate-submit/idempotency tests for delivery confirmation, mark-delivered, skip/unskip, load corrections, invoice marking, and export recording. Preserve transaction/row-lock behavior while making retries safe.

### 5. Finish delivery and inventory reconciliation

- [ ] Complete record-level driver authorization across every driver mutation and read endpoint. `complete_delivery.php`, `get_customer_order_details.php`, and photo operations use assignment checks; `global_gps_handler.php` accepts a posted `driver_id` without calling the shared identity guard and must be fixed and tested.
- [ ] Add explicit reconciliation for waste, damage, returns, samples, transfers, and end-of-day carryover in finished-goods inventory and driver loads.
- [ ] Define offline/retry behavior for the mobile route workflow, including duplicate photos, duplicate confirmations, stale assignments, and network interruption recovery.
- [ ] Consolidate overlapping route surfaces. Declare the canonical paths among Driver Assignment, Daily Route, Standing Routes, Route Manager, Map, Driver Overview/List, and Route Tester; redirect/archive only after confirming unique workflows.

### 6. Choose and harden the billing boundary

- [ ] Declare `includes/billing.php` / Billing Center as the canonical operational billing path, or document a different choice. Reconcile the older `generate_invoice*.php`, `simple_invoice.php`, and customer-facing statement/invoice paths so price/snapshot/status rules cannot diverge.
- [ ] Decide whether this app is the invoice system of record or an operational source that exports to accounting. Document payment/AR ownership, COD collection, credits, remittance, export replay, and reconciliation in `docs/billing_quickbooks_boundary.md` and the UI.
- [ ] Add tests for pricing drift, zero/missing prices, partial deliveries, credits, COD collection, duplicate exports, and invoice status transitions.

### 7. Make the customer portal testable and supportable

- [ ] Add fictional portal fixtures and a complete portal smoke path: login → account/preferences → recurring order → dated override/skip → change request → notification read/filters → delivery detail/photo → invoice/statement/CSV.
- [ ] Keep customer ownership/IDOR checks at the service boundary, not only in pages, and add tests for every customer-facing API and download.
- [ ] Define a safe customer-code lifecycle: enrollment, rotation, failed-attempt handling, deactivation, and audit visibility without exposing the code in logs or telemetry.

### 8. Complete the production/ingredient loop

- [ ] Connect formulas → ingredient requirements → purchasing forecast → receiving/lots → consumption/adjustment. The current ingredient planner is useful, but ingredient inventory and purchasing are not yet a closed operational loop.
- [ ] Add product/formula/weight/price validation that blocks or clearly flags production and billing records that cannot be trusted.

## P2 — reduce complexity and improve operator outcomes

### 9. Correct the schema and migration contract

- [ ] Create a migration ledger with stable IDs, filenames, dependencies, and production verification. Resolve the duplicate numeric prefixes (`010_*`, `021_*`, `025_*`) without breaking already-applied IDs; the runner currently maps migration ID `011_baker_product_lines` to the file `010_baker_product_lines.sql`.
- [ ] Prove a clean rebuild from baseline through migration 028 on a disposable database and compare required tables, columns, indexes, and foreign keys with production expectations.

### 10. Refactor incrementally into a modular monolith

- [ ] Do not rewrite the app. Extract small tested services/repositories for Demand, Production, Inventory, Delivery, Billing, Identity, and Reporting from the largest page controllers.
- [ ] Keep page controllers responsible for authorize → validate → invoke use case → render/redirect. Move duplicated SQL/business rules out of pages and centralize state transitions.
- [ ] Move repeated inline CSS/JS into versioned assets, starting with driver/mobile and portal flows. Add responsive and accessibility checks at the role-workspace level.
- [ ] Decide whether to introduce Composer plus a standard test runner/CI after the release gate is stable; update README/architecture to match the decision instead of documenting an unimplemented Laravel-style layout.

### 11. Replace passive dashboards with exception-driven work

- [ ] Make the command center show actionable exceptions: unassigned stops, production shortfalls, missing confirmations, load discrepancies, failed deliveries, uninvoiced deliveries, pricing issues, stale customers, and closeout blockers.
- [ ] Add plan-vs-actual, fill rate, waste/return, on-time, credits, revenue, margin, and invoice-close metrics only after the underlying ownership/state contracts are stable.

### 12. Clean up documentation and quarantine safely

- [ ] Rewrite `README.md` and `ARCHITECTURE.md` around the actual flat PHP/MariaDB app and supported local workflow. Remove obsolete Composer/Artisan/public-directory instructions.
- [ ] Reconcile `docs/CURRENT_STATE.md`, `docs/STATUS_WHERE_WE_ARE_2026-08-06.md`, `docs/PRODUCTION_DEPLOY.md`, and `docs/POST_0E_DECISIONS.md` so deploy readiness is not described as complete while the test gate is red.
- [ ] Normalize the visible mojibake/encoding problems in docs and UI strings, then rerun i18n tests.
- [ ] Keep `docs/QUARANTINE_INVENTORY.md` as the source of truth. Do not delete backup/debug/test/alternate files until a human reviews unique logic, verifies public access is blocked, and confirms the deploy manifest excludes them. Clean local PII-bearing logs/uploads separately from source control.

## Recommended execution order

1. Disable auto-push and pause production deployment.
2. Lock down public auth/diagnostic surfaces and audit production impact.
3. Repair the local-only test target guard, auth HTTP test, portal fixtures, full lint, and full test command.
4. Review/checkpoint the current working tree into an explicit baseline.
5. Run the golden-day vertical workflow with retry/idempotency and record-level authorization tests.
6. Only then consolidate billing/route entry points and begin the modular refactor.

## Definition of “ready for the next feature”

- [ ] A clean local reset/migration/lint/test command is green and refuses production.
- [ ] No public endpoint exposes database/file/PII diagnostics; no production identity uses a fixed code in tracked source.
- [ ] The golden-day workflow passes from a clean fixture and survives duplicate/retry requests.
- [ ] The canonical source of truth for demand, production, inventory, delivery, and billing is documented and enforced by shared services.
- [ ] The reviewed deploy manifest contains only approved canonical files, and auto-push/deploy has an auditable baseline.
