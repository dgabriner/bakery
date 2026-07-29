# Post-0E Decisions — Coordinator Guide

**Date:** 2026-07-28 (updated after coordinator authorization)  
**Context:** All post-0E blocks authorized. Migrations and deploy prep implemented locally.

---

## Decision log

| Decision | Status | Date | Notes |
|----------|--------|------|-------|
| Close Checkpoint 0E | Approved | 2026-07-28 | Tests green |
| Track canonical app pages | **Done** | 2026-07-28 | `b803b78` |
| Weekday migration | **Done** | 2026-07-28 | Code + `003_weekday_normalize.sql`; canonical Sunday=7 |
| Zone schema migration | **Done** | 2026-07-28 | `004_zone_id.sql` + `run_migrations.php` |
| Production deploy | **Ready** | 2026-07-28 | See [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md) — execute on DreamHost |
| Quarantine deletion pass | Pending | — | Human review only; no auto-delete |

---

## 1. Git tracking — DONE

Canonical ops pages tracked in `b803b78`. Quarantine patterns in `.gitignore`.

---

## 2. Weekday migration — DONE

- **Canonical encoding:** 1=Mon … 7=Sun
- **Migration:** `database/schema/003_weekday_normalize.sql` — `UPDATE ... SET day_of_week=7 WHERE day_of_week=0`
- **Runner:** `scripts/run_migrations.php` (idempotent)
- **Applied after:** `setup_local_db.php --reset`, `pull_prod_to_local.php`

Legacy read compatibility via `bakery_standing_day_match_values()` retained until prod data fully migrated.

---

## 3. Zone schema migration — DONE

- **Column:** `customers.zone_id INT NULL` FK → `zones.id`
- **Backfill:** text `customers.zone` matched to `zones.name`
- **Code:** `bakery_customer_zone_join_sql()`, `bakery_zone_id_for_name()` in `common_functions.php`
- **Pages updated:** `bread_distribution.php`, `customers.php`, `zones.php`

Text `zone` column retained for display/backward compatibility; kept in sync on save.

---

## 4. Production deploy — READY (next major step)

**Coordinator authorized.** Implementation complete locally; **execution on DreamHost is the next major step.**

Follow [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md):

1. Rotate credentials ([CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md))
2. Run migrations on production DB (weekday + zone_id)
3. Deploy tracked canonical PHP + includes + css/assets
4. Configure Apache/env vars (no secrets in git)
5. Create production auth users (`create_user_once.php`)
6. Post-deploy verification checklist

---

## 5. Quarantine cleanup — PENDING (human only)

See [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md). Do not delete until after production auth is verified live.

---

## Local prod pull workflow

1. Copy `.env.production.pull.example` → `.env.production.pull`
2. Whitelist IP in DreamHost Allowable Hosts
3. `C:\php\php.exe scripts\pull_prod_to_local.php`
4. Login at `http://localhost:8080/bakery/login.php` as `danny@sourflour.org`

Local `.env` stays on `127.0.0.1` / `bakerysf_local` always.
