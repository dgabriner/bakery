# Current State — Sour Flour OS / Bakery Manager

**Last updated:** 2026-07-28 (Post-0E: canonical pages tracked)  
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

---

## Checkpoint status

| Checkpoint | Status | Evidence |
|------------|--------|----------|
| **0A** | Done | [CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md](CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md) |
| **0B** | Done | Local fail-closed config, `bakerysf_local`, [LOCAL_SETUP.md](LOCAL_SETUP.md) |
| **0C** | Done | 55 pass / 6 findings → [CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md](CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md) |
| **0D** | Done | Commit `8b8a58d` — [CHECKPOINT_0D_AUTH.md](CHECKPOINT_0D_AUTH.md) |
| **0E** | Done | Driver endpoint, zone join fix, integrity guards, quarantine/docs |

**Not authorized yet:** weekday data migration, zone schema migration (`zone_id` column), production deploy, file deletion, UI redesign, framework rewrite, QuickBooks, portal, AI features.

---

## 0E deliverables (landed)

| Agent | Deliverable | Status |
|-------|-------------|--------|
| 1 | Auth + CSRF (0D) | Done — `8b8a58d` |
| 2 | `get_driver_orders.php` per 0C contract + auth/CSRF tests | Done — 45/45 auth tests |
| 3 | Zone name join in `bread_distribution.php`; production/pack_list integrity warnings | Done |
| 4 | Quarantine inventory, ops docs, CURRENT_STATE | Done |

---

## Agent wave reference

All agents start with: [agent-briefs/00_SHARED_CONTEXT.md](agent-briefs/00_SHARED_CONTEXT.md)

| Agent | Brief |
|-------|-------|
| 1 | [01_AGENT_AUTH_HARDENING.md](agent-briefs/01_AGENT_AUTH_HARDENING.md) |
| 2 | [02_AGENT_DRIVER_CONTRACT.md](agent-briefs/02_AGENT_DRIVER_CONTRACT.md) |
| 3 | [03_AGENT_DATA_INTEGRITY_GUARDS.md](agent-briefs/03_AGENT_DATA_INTEGRITY_GUARDS.md) |
| 4 | [04_AGENT_QUARANTINE_AND_DOCS.md](agent-briefs/04_AGENT_QUARANTINE_AND_DOCS.md) |

---

## Local environment (quick reference)

- **Database:** `bakerysf_local` on `127.0.0.1` / localhost only
- **MariaDB:** Scoop user process — [MARIADB_USER_PROCESS.md](MARIADB_USER_PROCESS.md)
- **Setup:** [LOCAL_SETUP.md](LOCAL_SETUP.md)
- **Credentials / rotation:** [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md) (link only — no secrets in docs)
- **Quarantine list:** [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md) — **DO NOT DELETE** listed files until human review
- **Cursor agent rules (draft):** [CURSOR_OPS_DRAFT.md](CURSOR_OPS_DRAFT.md)
- **Post-0E decisions:** [POST_0E_DECISIONS.md](POST_0E_DECISIONS.md)

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe tests\run_characterization.php
C:\php\php.exe tests\run_auth_tests.php
```

Last verified: **55/55 characterization**, **45/45 auth** (2026-07-28).

---

## Known behavior contracts (not fixed)

From 0C characterization — treat as current bugs/contracts until explicitly changed with tests:

1. Sunday `day_of_week` **0** vs **7** mismatch across generate/pack/standing surfaces
2. Delivery updates assignment status only (`daily_orders.status` unchanged)
3. `production.php` aggregates `standing_orders`, not `daily_orders`

**Fixed in 0E:** zone join in `bread_distribution.php` (now uses `c.zone = z.name`); `get_driver_orders.php` implemented.

Full detail: [CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md](CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md)

---

## Safety rails (mandatory)

- Local-only DB; never touch production host/DB (`bakerysf`, DreamHost, sourflour hosts)
- No Windows admin elevation for MariaDB
- Never print or commit secrets (`.env` is gitignored)
- No deleting quarantine/legacy files — inventory only
- No weekday normalization or zone schema migration without coordinator approval
