# Agent 4 — Quarantine inventory + Cursor ops docs

## Role

Produce documentation that lets humans and later agents **see** dangerous/legacy files and local operating state — without deleting anything or implementing app features.

## Goal

1. Create a quarantine **inventory** markdown listing backup / fixed / optimized / Copy / debug / test / orphan invoice files (**DO NOT DELETE**).
2. Update `docs/LOCAL_SETUP.md` and add a short `docs/CURRENT_STATE.md` (or equivalent pointer) so parallel agents know branch, checkpoints, and safety rails.
3. Draft recommended `.cursor/rules` or `AGENTS.md` **content in docs** first; only create live rule files if clearly useful and narrowly scoped.
4. Link the existing credential rotation runbook — **do not duplicate secrets**.

Read first: `docs/agent-briefs/00_SHARED_CONTEXT.md`, `docs/CHECKPOINT_0A_REPOSITORY_CLASSIFICATION.md`, `docs/CREDENTIAL_ROTATION_RUNBOOK.md`.

## Own files

| Path | Action |
|------|--------|
| `docs/QUARANTINE_INVENTORY.md` (or `docs/agent-briefs/QUARANTINE_INVENTORY.md` if you prefer briefs co-location — prefer `docs/QUARANTINE_INVENTORY.md`) | Create inventory; status = quarantine / review; **no deletes** |
| `docs/CURRENT_STATE.md` | Short pointer: branch, 0A–0C done, 0D in progress, 0E next, link SHARED brief + LOCAL_SETUP + 0C findings |
| `docs/LOCAL_SETUP.md` | Small updates: point to CURRENT_STATE, MariaDB user-process, auth seed section **owned with Agent 1** — coordinate if both edit; prefer appending a “Agent wave” pointer section |
| `docs/CURSOR_OPS_DRAFT.md` (or similar) | Draft AGENTS.md / `.cursor/rules` outline: local-only, no secret commit, no broad git add, no delete quarantine, checkpoint order |
| Live `.cursor/rules/*` or root `AGENTS.md` | **Optional** — only if draft is tight and useful; prefer docs draft first |

### Inventory categories to scan (non-exhaustive starters)

Search under `bakery/` for names matching patterns such as:

- `*backup*`, `*_backup*`, `*Copy*`
- `*_fixed*`, `*_optimized*`, `*_working*`
- `debug*`, `test*`, `table_debug*`, `simple-debug*`
- Orphan / alternate invoices: `generate_invoice*.php`, `simple_invoice.php`, etc.
- `.htaccess.bak`, duplicate standing_routes copies

For each entry record: path, category, why quarantined, recommended later action (`review` / `keep` / `delete-later-human-only`), and **do not delete now**.

## Do not touch

- App feature code (`login.php`, `get_driver_orders.php`, `bread_distribution.php`, `production.php`, etc.)
- DB migrations / schema changes / fixture rewrites
- Auth implementation → **Agent 1**
- Credential values — link `docs/CREDENTIAL_ROTATION_RUNBOOK.md` only
- Actually deleting any quarantined file
- Broad `git add bakery/`

## Safety boundaries

- Local-only (`bakerysf_local`); never production DB/host
- No admin Windows elevation; MariaDB via Scoop user process only
- Never print/commit secrets; `.env` gitignored
- No deleting legacy/backup/diagnostic files (quarantine inventory only)
- No weekday normalization of stored data
- No zone schema migration
- Commit per coherent unit; never broad `git add bakery/`
- Characterization findings are current bugs to document — link them; do not claim fixed

## Commands

No app feature tests required. Optional verification:

```powershell
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
# Inventory helpers (examples — adjust as needed)
Get-ChildItem -Recurse -File | Where-Object { $_.Name -match 'backup|fixed|optimized|Copy|debug|test_' } | Select-Object -ExpandProperty FullName
```

Do not run destructive cleanups. Do not open or paste `.env` contents into docs.

## Acceptance criteria

- [ ] Quarantine inventory markdown exists with categorized paths and explicit **DO NOT DELETE** policy
- [ ] Inventory covers backup/fixed/optimized/Copy/debug/test/orphan-invoice style files found on disk (evidence-based; no invented paths)
- [ ] `docs/CURRENT_STATE.md` (or agreed name) points agents to branch + checkpoint status + SHARED brief
- [ ] `docs/LOCAL_SETUP.md` updated with short pointer(s); no secret values added
- [ ] Credential rotation: **link only** to `docs/CREDENTIAL_ROTATION_RUNBOOK.md`
- [ ] Cursor ops guidance drafted under `docs/`; live rules only if clearly scoped
- [ ] No application PHP/SQL feature changes; no file deletions
- [ ] Docs-only commit(s) with explicit paths if committing

## Out of scope (explicit)

App feature code, DB migrations, auth implementation, driver endpoint, zone/weekday fixes, production deploy, any deletion pass.
