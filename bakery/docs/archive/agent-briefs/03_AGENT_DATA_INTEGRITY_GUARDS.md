# Agent 3 — Data integrity guards + zone code fix

## Role

Execute **narrow Checkpoint 0E** data-integrity repairs: fix the bread_distribution zone join/filter to use zone **names**, add actionable guards where missing weights/formulas silently zero production, and optionally surface clearer Sunday mismatch warnings — **without** migrating stored weekday or zone schema.

## Goal

Make local operators see honest errors/warnings instead of silent wrong/zero results for known integrity issues; fix the characterized zone join bug with a **code-only** change. Keep the characterization suite documenting known Sunday bugs unless Agent 1/coordinator explicitly agrees to change expectations.

Read first: `docs/agent-briefs/00_SHARED_CONTEXT.md` and `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md`.

## Own files

| Path | Action |
|------|--------|
| `bread_distribution.php` | Confirm `customers.zone` is text names (0C already); change filter/join from `zones.id` / int cast to **name** matching (`c.zone = z.name` or filter by name); update zone `<select>` values accordingly |
| `production.php` | Add actionable guards/errors when missing `weight_grams` or formulas would silently yield zero/misleading totals |
| Optional: `daily_orders.php` / `pack_list.php` | Clearer **runtime warnings** about Sunday 0 vs 7 mismatch — **do not** rewrite stored `day_of_week` |
| `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md` | Update only if behavior change is intentional and coordinator-approved; otherwise leave Sunday findings as current bugs |
| Tests | Add/adjust tests that prove zone filter works by name; do **not** greenwash Sunday bugs by changing suite expectations without agreement |

### Known findings you must treat as current (from 0C)

- Sunday generate uses day **0** vs standing Sunday **7**
- `bread_distribution` zone join uses `zones.id` vs `customers.zone` text names ← **you fix (code)**
- `pack_list` Sunday `date('w')=0` misses day **7** ← warn/document only, no data rewrite
- Delivery updates assignment status only ← out of scope unless coordinator expands
- `get_driver_orders.php` missing ← **Agent 2**

Verified zone representation (0C): `customers.zone` stores **text names** matching `zones.name`. Evidence in current code: `(int)$_GET['zone']` and `LEFT JOIN zones z ON c.zone = z.id`.

## Do not touch

- Full weekday normalization / rewriting stored `day_of_week` values
- Schema migration adding `zone_id` / dropping text zones
- Deleting backup/debug/orphan files → **Agent 4**
- Auth implementation / seed scripts → **Agent 1**
- Implementing `get_driver_orders.php` → **Agent 2**
- Broad `git add bakery/`

## Safety boundaries

- Local-only (`bakerysf_local`); never production DB/host
- No admin Windows elevation; MariaDB via Scoop user process only
- Never print/commit secrets; `.env` gitignored
- No deleting legacy/backup/diagnostic files
- No weekday normalization of stored data yet
- No zone schema migration yet (code fix for join only after confirming text zones)
- Commit per coherent unit; never broad `git add bakery/`
- Characterization findings are CURRENT bugs to document/guard, not silently “fix” without tests

## Commands

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe tests\run_characterization.php
```

Manually: open `bread_distribution.php`, filter by a named zone that exists on fixture customers; confirm rows appear. Open `production.php` with a product missing weight/formula and confirm a visible error/warning (not a quiet zero).

## Acceptance criteria

- [ ] Confirmed (or re-verified against local DB) that `customers.zone` is text names matching `zones.name`
- [ ] `bread_distribution.php` filter/join uses **name**, not `zones.id` / int cast of id
- [ ] Zone UI filter options submit name (or otherwise match the join) so filtering works on fixtures
- [ ] `production.php` surfaces actionable errors/warnings when missing weights/formulas would silently zero or mislead
- [ ] No UPDATE/migration rewriting `day_of_week` or adding `zone_id`
- [ ] Sunday mismatch: at most clearer warnings; characterization still documents Sunday generate/pack_list bugs unless coordinator agrees otherwise
- [ ] `tests/run_characterization.php` still honest about remaining Sunday findings
- [ ] Narrow commit(s) with explicit paths only

## Out of scope (explicit)

Full weekday migration, zone_id schema, file deletion, auth finish, driver endpoint, UI redesign.
