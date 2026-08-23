# Cursor Ops Draft — Sour Flour OS / Bakery Manager

**Status:** Draft for human review (Agent 4, 2026-07-28)  
**Purpose:** Recommended content for root `AGENTS.md` and/or `.cursor/rules/` before creating live rule files.

Live rule files were **not** created in this wave — prefer reviewing this draft first.

---

## Suggested `AGENTS.md` (monorepo root or `bakery/`)

```markdown
# Sour Flour OS (Bakery Manager)

PHP SSR app under `bakery/`. Modernization checkpoints 0A–0E — no framework rewrite.

## Read first

1. `bakery/docs/agent-briefs/00_SHARED_CONTEXT.md`
2. `bakery/docs/CURRENT_STATE.md`
3. `bakery/docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md`

## Local-only

- Database: `bakerysf_local` on 127.0.0.1 only
- MariaDB via Scoop user process (`bakery/docs/MARIADB_USER_PROCESS.md`)
- Never connect to production DB or DreamHost/sourflour hosts

## Git

- Branch: `chore/checkpoint-0a-repo-safety` (see CURRENT_STATE for updates)
- Stage explicit paths only — never `git add bakery/`
- Do not commit `.env`, logs, uploads, or PII SQL dumps
- Credential rotation: link `bakery/docs/CREDENTIAL_ROTATION_RUNBOOK.md` only

## Checkpoints (do not skip)

| Done | Next |
|------|------|
| 0A repo safety, 0B local config, 0C characterization | 0D auth (in progress), then 0E narrow fixes |

Unauthorized: prod deploy, file deletion, weekday data migration, zone_id schema migration, UI redesign, Laravel/Symfony rewrite.

## Quarantine

- See `bakery/docs/QUARANTINE_INVENTORY.md`
- DO NOT DELETE backup/debug/test/Copy files during modernization

## Tests

```powershell
cd bakery
C:\php\php.exe tests\run_characterization.php
C:\php\php.exe tests\run_auth_tests.php
```

## File ownership (0E wave)

- Auth: Agent 1 — `includes/auth.php`, `login.php`, `.htaccess`, auth tests
- Driver API: Agent 2 — `get_driver_orders.php`
- Data guards: Agent 3 — `bread_distribution.php`, `production.php`
- Docs/quarantine: Agent 4 — `docs/QUARANTINE_INVENTORY.md`, `docs/CURRENT_STATE.md`

Ask coordinator before editing another agent's owned files.
```

---

## Suggested `.cursor/rules/bakery-safety.mdc`

```markdown
---
description: Bakery Manager local safety and checkpoint boundaries
globs: bakery/**
alwaysApply: false
---

# Bakery / Sour Flour OS safety

- Local DB only: `bakerysf_local` @ 127.0.0.1
- Fail-closed config in `includes/config.php` — do not reintroduce production credential fallbacks
- Characterization findings in `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md` are contracts/bugs — document or guard; do not silently "fix" without tests
- Never delete files listed in `docs/QUARANTINE_INVENTORY.md`
- Never commit secrets; never paste `.env` contents into chat or docs
- Prefer narrow diffs; no broad `git add bakery/`
- Link credential guidance: `docs/CREDENTIAL_ROTATION_RUNBOOK.md` (no duplication)
```

---

## Suggested `.cursor/rules/bakery-docs-trust.mdc`

```markdown
---
description: Documentation trust hierarchy for bakery agents
globs: bakery/docs/**, bakery/**/*.md
alwaysApply: false
---

# Doc trust order (highest first)

1. `docs/agent-briefs/00_SHARED_CONTEXT.md` + agent brief 01–04
2. Checkpoint evidence: 0A classification, 0C characterization findings
3. Ops: LOCAL_SETUP, MARIADB_USER_PROCESS, CREDENTIAL_ROTATION_RUNBOOK
4. README / ARCHITECTURE (may be stale)
5. Chat memory — never overrides characterization findings
```

---

## When to promote draft → live files

Promote only if:

1. Coordinator approves checkpoint boundaries unchanged
2. Rules stay narrow (safety + doc hierarchy + git staging)
3. No duplication of secrets or long runbook text

Suggested promotion paths:

| Draft section | Live target |
|---------------|-------------|
| AGENTS.md block | `windsurf-project/AGENTS.md` or `bakery/AGENTS.md` |
| Safety rule | `.cursor/rules/bakery-safety.mdc` |
| Docs trust rule | `.cursor/rules/bakery-docs-trust.mdc` |

---

## Related

- [CURRENT_STATE.md](CURRENT_STATE.md)
- [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md)
- [LOCAL_SETUP.md](LOCAL_SETUP.md)
- [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md)
