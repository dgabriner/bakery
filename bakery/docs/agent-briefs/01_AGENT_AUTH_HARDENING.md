# Agent 1 — Auth hardening (Checkpoint 0D) — **COMPLETE**

> **Status:** Landed in commit `8b8a58d`. Use this brief as reference only; do not re-implement. Proceed with Agents 2–4 for Checkpoint 0E.

## Role

Finish and verify Checkpoint **0D**: session authentication, CSRF, role gates, and auth test green on local `bakerysf_local`.

## Goal

Ship a coherent 0D unit: login/logout/session work; pages and mutating endpoints require auth; diagnostics are administrator-only; local seed/reset/sync scripts work; `tests/run_auth_tests.php` passes; document non-secret local seed usage in `docs/LOCAL_SETUP.md` only.

Read first: `docs/agent-briefs/00_SHARED_CONTEXT.md`.

## Own files

| Path | Action |
|------|--------|
| `includes/auth.php` | Verify/complete session, roles, CSRF helpers, public/diagnostic/driver allowlists |
| `includes/csrf.js` | Client CSRF token attach for POSTs |
| `login.php`, `logout.php` | Login/logout flows |
| `database/schema/002_auth.sql` | Users/roles schema applied via local setup |
| `scripts/seed_local_users.php` | Seed local users (no secrets in git) |
| `scripts/reset_local_admin.php` | Local admin reset |
| `scripts/sync_local_db_user.php` | Sync MariaDB app user if needed for local |
| `tests/run_auth_tests.php` | Make green |
| `.htaccess` | Align with auth/routing safety as drafted |
| `docs/LOCAL_SETUP.md` | Local seed credential **pointers** only (placeholders, not real secrets) |
| Page/endpoint require-auth wiring | Add `bakery_*` require calls consistently where 0D intends protection |

Roles expected: **administrator** | **manager** | **driver** (match `includes/auth.php` naming).

## Do not touch

- `get_driver_orders.php` / driver contract implementation → **Agent 2**
- `bread_distribution.php` zone join fix, production weight guards, weekday stored-value changes → **Agent 3**
- Quarantine deletes, inventory file authorship → **Agent 4** (you may list diagnostics in allowlists; do not delete files)
- Weekday data migration, zone_id schema migration, prod deploy, UI redesign, framework rewrite
- Broad `git add bakery/`

## Safety boundaries

- Local-only (`bakerysf_local`); never production DB/host
- No admin Windows elevation; MariaDB via Scoop user process only
- Never print/commit secrets; `.env` gitignored
- No deleting legacy/backup/diagnostic files
- No weekday normalization of stored data
- No zone schema migration
- Commit per coherent unit; never broad `git add bakery/`
- Characterization findings are current bugs — do not silently “fix” without tests

## Commands

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe scripts\sync_local_db_user.php
C:\php\php.exe scripts\setup_local_db.php --reset
C:\php\php.exe scripts\seed_local_users.php
C:\php\php.exe tests\run_auth_tests.php
C:\php\php.exe tests\run_characterization.php
```

Optional smoke: open `login.php` / `health_local.php` via local PHP server per `docs/LOCAL_SETUP.md`.

## Commit message style (0D)

Prefer explicit paths and a message like:

```text
feat(auth): complete Checkpoint 0D session auth and CSRF

Local-only users/roles, CSRF on mutating POSTs, admin-only diagnostics,
and green tests/run_auth_tests.php against bakerysf_local.
```

Stage only 0D paths. Do not bundle Agent 2–4 work.

## Acceptance criteria

- [ ] Unauthenticated users cannot reach protected app pages/endpoints (redirect or 403 as designed)
- [ ] Roles `administrator` | `manager` | `driver` enforced per `includes/auth.php` maps
- [ ] CSRF required on mutating POSTs; `includes/csrf.js` usable by forms/fetch
- [ ] Diagnostic/debug/test scripts in `bakery_diagnostic_scripts()` are **administrator-only** after login
- [ ] `scripts/sync_local_db_user.php` (if needed) + seed/reset leave a usable local login without committing secrets
- [ ] `tests/run_auth_tests.php` **green**
- [ ] Characterization suite still runs (do not weaken 0C findings to fake a pass)
- [ ] `docs/LOCAL_SETUP.md` documents how to seed/login locally without secret sprawl
- [ ] Coherent 0D commit(s) with explicit file staging — no `git add bakery/`

## Out of scope (explicit)

Driver endpoint implementation, zone filter fix, quarantine file deletion, weekday migration, production deploy.
