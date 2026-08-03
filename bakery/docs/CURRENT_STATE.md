# Current State — Sour Flour OS / Bakery Manager

**Last updated:** 2026-07-28 (Post-0E: migrations + deploy prep)  
**Application path:** `bakery/` in monorepo `windsurf-project`

Short pointer for humans and parallel agents. For full operating rules, read the shared brief first.

---

## Git / branch

| Item | Value |
|------|-------|
| Monorepo root | `C:\Users\918825809\CascadeProjects\windsurf-project` |
| Working branch | `chore/checkpoint-0a-repo-safety` |
| Application directory | `bakery/` |
| Remote | `origin` → `https://github.com/dgabriner/bakery.git` |

Stage explicit paths only. **Never** `git add bakery/` as a broad add.

**Git tracking:** Canonical ops pages tracked (`b803b78`). Quarantine/debug variants gitignored.

---

## Checkpoint status

| Checkpoint | Status | Evidence |
|------------|--------|----------|
| **0A** | Done | [CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md](CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md) |
| **0B** | Done | Local fail-closed config, `bakerysf_local`, [LOCAL_SETUP.md](LOCAL_SETUP.md) |
| **0C** | Done | 57+ pass / 2 findings → [CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md](CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md) |
| **0D** | Done | Commit `8b8a58d` — [CHECKPOINT_0D_AUTH.md](CHECKPOINT_0D_AUTH.md) |
| **0E** | Done | Driver endpoint, zone join, integrity guards, quarantine/docs |
| **Post-0E** | Done | Weekday + zone_id migrations, prod pull, deploy prep |

---

## Post-0E deliverables

| Item | Status |
|------|--------|
| Canonical pages in git | Done — `b803b78` |
| Sunday weekday (canonical 7) | Done — code + migration `003` |
| `customers.zone_id` FK | Done — migration `004` |
| Prod pull → local | Done — `pull_prod_to_local.php` |
| Durable local login | Done — `ensure_local_admin.php`, `danny@sourflour.org` |
| Production deploy guide | Done — [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md) |

---

## Local environment (quick reference)

- **Database:** `bakerysf_local` on `127.0.0.1` / localhost only
- **MariaDB:** Scoop user process — [MARIADB_USER_PROCESS.md](MARIADB_USER_PROCESS.md)
- **Setup:** [LOCAL_SETUP.md](LOCAL_SETUP.md)
- **Prod pull:** `.env.production.pull` + `scripts/pull_prod_to_local.php`
- **Migrations:** `scripts/run_migrations.php` (auto after setup/pull)
- **Deploy:** [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md)
- **Dev workflow (menu, DB sync, deploy ZIP):** [DEV_WORKFLOW.md](DEV_WORKFLOW.md) — run `dev_workflow.bat`
- **Decisions:** [POST_0E_DECISIONS.md](POST_0E_DECISIONS.md)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe scripts\run_migrations.php
C:\php\php.exe tests\run_characterization.php
C:\php\php.exe tests\run_auth_tests.php
C:\php\php.exe tests\run_integrity_tests.php
```

Last verified: characterization + auth + integrity (2026-07-28).

---

## Known behavior contracts (not fixed)

1. `production.php` aggregates `standing_orders`, not `daily_orders`
2. Delivery updates assignment status only (`daily_orders.status` unchanged)

**Fixed post-0E:** Sunday encoding (7); zone join + `zone_id`; `get_driver_orders.php`.

---

## Safety rails (mandatory)

- Local-only DB for dev; prod access via explicit pull script only
- Never print or commit secrets (`.env`, `.env.production.pull` gitignored)
- No deleting quarantine files without human review
- Deploy tracked canonical files only — see PRODUCTION_DEPLOY.md
