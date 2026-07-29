# Shared context — Sour Flour OS / Bakery Manager (0D/0E wave)

Hand this file to **every** parallel agent and to the human coordinator. Agent-specific briefs (`01`–`04`) add ownership and acceptance criteria; they do not replace this document.

## Product

**Bakery Manager** is the working PHP app under `bakery/`. The modernization product name is **Sour Flour OS**. Same codebase; do not rename the directory or invent a greenfield rewrite.

## Stack (verified)

| Layer | Reality |
|-------|---------|
| App style | Flat PHP SSR (page scripts + `includes/`), vanilla JS/CSS |
| DB | MySQL/MariaDB; **local only** DB name `bakerysf_local` |
| Auth historically | None (open pages) |
| Auth now | Checkpoint **0D** drafting session auth + CSRF (`includes/auth.php`, `login.php`, `logout.php`, roles `administrator` \| `manager` \| `driver`) |
| Framework | None authorized — no Laravel/Symfony rewrite |
| Git | Monorepo root `windsurf-project`; branch `chore/checkpoint-0a-repo-safety` |

## Doc trust hierarchy (highest → lowest)

1. `docs/agent-briefs/00_SHARED_CONTEXT.md` + your agent brief (`01`–`04`)
2. Checkpoint evidence docs: `docs/CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md`, `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md`
3. Ops: `docs/LOCAL_SETUP.md`, `docs/MARIADB_USER_PROCESS.md`, `docs/CREDENTIAL_ROTATION_RUNBOOK.md`
4. In-repo README / `ARCHITECTURE.md` (may be stale; defer to checkpoints on conflict)
5. Informal notes / chat memory — **never** override characterization findings

Characterization findings are **current bugs / contracts**, not fixed behavior. Document and guard; do not silently “fix” without tests and coordinator agreement.

## Checkpoint status (do not invent beyond this)

| Checkpoint | Status |
|------------|--------|
| **0A** | Done — gitignore / classification / safety |
| **0B** | Done — local fail-closed config, `bakerysf_local`, Scoop MariaDB user-process |
| **0C** | Done — characterization **39 pass / 8 findings** → `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md` |
| **0D** | **Complete** — commit `8b8a58d`: auth, roles, CSRF, login/logout, `tests/run_auth_tests.php` 23/23 |
| **0E** | **Next** — narrow repairs only (Agents 2–3; see briefs `02`–`04`) |
| Beyond | **NOT authorized:** weekday data migration, zone schema migration, prod deploy, file deletion, UI redesign, framework rewrite, QuickBooks, portal, AI |

### 0D artifacts already on disk (verify, don’t recreate blindly)

- `includes/auth.php`, `includes/csrf.js`
- `login.php`, `logout.php`
- `database/schema/002_auth.sql`
- `scripts/seed_local_users.php`, `scripts/reset_local_admin.php`, `scripts/sync_local_db_user.php`
- `tests/run_auth_tests.php`
- `.htaccess` (and related auth wiring)

## North-star workflow (one paragraph)

Standing orders and routes feed daily generation; managers assign drivers and produce pack/production lists; drivers complete stops against daily assignments. Local modernization must make that loop **safe** (auth/CSRF, fail-closed config) and **honest** (characterization-backed contracts and guards) before any data migration or production deploy. Prefer narrow, tested repairs over broad cleanup.

## Safety boundaries (mandatory for all agents)

- **Local-only:** `bakerysf_local` on `127.0.0.1` / localhost. Never touch production DB/host (`bakerysf`, DreamHost, sourflour hosts).
- **No Windows admin elevation.** MariaDB via Scoop **user process** only (`docs/MARIADB_USER_PROCESS.md`, `scripts/start_local_mariadb.ps1` / `stop_local_mariadb.ps1`).
- **Never print or commit secrets.** `.env` is gitignored; seed credential docs point at `LOCAL_SETUP` patterns only — no secret sprawl.
- **No deleting** legacy / backup / diagnostic / `*Copy*` / orphan invoice files — quarantine inventory only (Agent 4).
- **No weekday normalization** of stored `day_of_week` values yet.
- **No zone schema migration** (`zone_id` column, etc.). Code may join/filter by **zone name text** after confirming characterization.
- **Commit per coherent unit.** Never broad `git add bakery/`. Stage explicit paths only.
- Characterization findings are **bugs/contracts to document or guard**, not silent fixes without tests.

## How the four agents divide work

| Agent | Brief | Owns (wave) |
|-------|-------|-------------|
| 1 | `01_AGENT_AUTH_HARDENING.md` | Finish Checkpoint **0D** auth/CSRF, protect pages, auth tests green, commit 0D |
| 2 | `02_AGENT_DRIVER_CONTRACT.md` | Implement `get_driver_orders.php` per 0C contract + auth/CSRF + tests |
| 3 | `03_AGENT_DATA_INTEGRITY_GUARDS.md` | Zone **name** join fix in bread_distribution; production missing-weight/formula guards; Sunday warnings only (no data rewrite) |
| 4 | `04_AGENT_QUARANTINE_AND_DOCS.md` | Quarantine inventory markdown; LOCAL_SETUP / CURRENT_STATE pointers; draft Cursor ops docs (prefer docs first) |

## Integration rules — file ownership (do not stomp)

| Path / area | Primary owner |
|-------------|---------------|
| `includes/auth.php`, `login.php`, `logout.php`, `includes/csrf.js`, `.htaccess`, `database/schema/002_auth.sql`, `scripts/seed_local_users.php`, `scripts/reset_local_admin.php`, `scripts/sync_local_db_user.php`, `tests/run_auth_tests.php`, auth docs in `LOCAL_SETUP.md` | **Agent 1** |
| `get_driver_orders.php` (new), tests covering that endpoint; minimal CSRF/auth hooks **only if Agent 1 already shipped helpers** | **Agent 2** |
| `bread_distribution.php` (zone filter/join), `production.php` (guards/warnings), optional clearer Sunday warnings in generate/pack surfaces **without** changing stored days | **Agent 3** |
| `docs/` quarantine inventory, `docs/CURRENT_STATE.md` (or short pointer), drafts under `docs/` for rules/`AGENTS.md`; link-only to credential runbook | **Agent 4** |
| Shared read-only | `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md`, `tests/run_characterization.php`, `tests/harness.php`, `includes/config.php` / env loader (change only with coordinator) |

**Conflict protocol:** If you need a file another agent owns, stop and ask the coordinator. Prefer composing on Agent 1’s auth APIs rather than forking session/CSRF logic.

**`driver_list.php`:** Agent 2 may read the server query (~lines 47–66) as the canonical SQL shape; do **not** rewrite the driver UI. Agent 1 may add require-auth wrappers if coordinating; avoid competing edits in the same hunks.

## Local MariaDB / tests / health

```powershell
# From repo-aware paths; adjust if cwd is bakery/
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1

cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe scripts\setup_local_db.php --reset   # when fixtures need reset
C:\php\php.exe scripts\seed_local_users.php          # after 0D schema present
C:\php\php.exe tests\run_characterization.php
C:\php\php.exe tests\run_auth_tests.php
```

- Health: `health_local.php` (public allowlist in auth).
- App serve (optional): `C:\php\php.exe -S 127.0.0.1:8080 -t bakery` from monorepo root — see `docs/LOCAL_SETUP.md`.
- Stop MariaDB: `bakery\scripts\stop_local_mariadb.ps1`.

## Characterization findings all agents must respect

Source: `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md`

1. Sunday generate uses day **0** vs standing Sunday **7**
2. `bread_distribution` zone join uses `zones.id` vs `customers.zone` **text names**
3. `pack_list` Sunday `date('w')=0` misses day **7**
4. Delivery updates **assignment** status only (`daily_orders.status` unchanged)
5. `get_driver_orders.php` **missing** — contract documented in 0C (Agent 2 implements)

Also documented: dual Sunday encoding in bread_distribution UI; production reads standing_orders not daily_orders.

## Definition of done — this multi-agent wave

1. **0D complete (Agent 1):** done in `8b8a58d` — auth + CSRF verified; `tests/run_auth_tests.php` green; diagnostics admin-only.
2. **0E narrow items:**
   - Agent 2: `get_driver_orders.php` matches 0C contract, auth+CSRF, test coverage.
   - Agent 3: zone name join/filter fix; actionable production guards; Sunday mismatch documented/warned **without** weekday data migration; characterization suite still reflects known Sunday bug unless coordinator agrees to change expectations.
   - Agent 4: quarantine inventory (no deletes); CURRENT_STATE / LOCAL_SETUP pointers; ops doc drafts; runbook linked not duplicated.
3. No unauthorized work: no prod deploy, no file deletion, no zone_id schema migration, no weekday stored-value rewrite, no UI redesign / framework / QB / portal / AI.

## Coordinator checklist

- [ ] Assign one brief each to Agents 1–4; all read this SHARED file first
- [x] Agent 1 lands 0D — done (`8b8a58d`)
- [ ] Review diffs with explicit paths only — reject `git add bakery/`
- [ ] Confirm characterization still documents Sunday bugs after Agent 3
- [ ] Leave quarantine list for human review; do not delete files in this wave
