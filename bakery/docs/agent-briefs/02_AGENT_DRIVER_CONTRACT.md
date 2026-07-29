# Agent 2 — Driver orders contract (`get_driver_orders.php`)

## Role

Implement the missing driver JSON endpoint that `driver_list.php` already calls, exactly as characterized in Checkpoint 0C.

## Goal

Add `get_driver_orders.php` matching the **exact** 0C contract; require auth (driver/manager/admin) + CSRF on POST; align SQL with `driver_list.php` server query (~lines 47–66); add characterization and/or auth tests. Do not invent response fields.

Read first: `docs/agent-briefs/00_SHARED_CONTEXT.md` and `docs/CHECKPOINT_0C_CHARACTERIZATION_FINDINGS.md` (§ get_driver_orders).

**Dependency:** Prefer Agent 1’s auth/CSRF helpers (`includes/auth.php`, CSRF token pattern). If 0D is not merged yet, coordinate — do not fork a second auth system.

## Own files

| Path | Action |
|------|--------|
| `get_driver_orders.php` | **Create** — POST handler returning JSON |
| `tests/run_characterization.php` and/or `tests/run_auth_tests.php` / new focused test include | Assert contract + auth/CSRF behavior |
| `includes/auth.php` | **Read-only preferred** — may add `get_driver_orders.php` to driver allowlist **only** if Agent 1 agrees / file not mid-edit |

### Exact contract (from 0C — do not extend)

```
POST application/x-www-form-urlencoded
driver_id={int}&date={YYYY-MM-DD}
(+ CSRF field/header per Agent 1 convention)

Response JSON:
{
  "success": true,
  "orders": [
    {
      "daily_order_id": int,
      "customer_name": string,
      "customer_address": string,
      "zone": string,
      "route_order": int,
      "scheduled_delivery_time": string|null,
      "total_amount": number
    }
  ]
}
```

Canonical SQL source: `driver_list.php` lines ~47–66 — join `daily_orders`, `customers`, `daily_order_assignments`, `drivers`; filter `doa.driver_id` + `do.order_date`; order by `route_order`, zone, name.

**Response field set is the contract list above.** Internal query may select extra columns for filtering, but JSON `orders[]` objects must not invent fields beyond the contract (omit extras from JSON).

Caller evidence: `driver_list.php` `fetch('get_driver_orders.php', { method: 'POST', ... })`.

## Do not touch

- Rewriting `driver_list.php` UI / delivery mode / localStorage delivery truth
- Photos, GPS (`global_gps_handler.php`), complete-delivery redesign
- Zone join in `bread_distribution.php`, production guards, Sunday data migration → **Agent 3**
- Auth core implementation / seed scripts → **Agent 1** (consume their APIs)
- Quarantine deletes → **Agent 4**
- Broad `git add bakery/`

## Safety boundaries

- Local-only (`bakerysf_local`); never production DB/host
- No admin Windows elevation; MariaDB via Scoop user process only
- Never print/commit secrets; `.env` gitignored
- No deleting legacy/backup/diagnostic files
- No weekday normalization of stored data
- No zone schema migration
- Commit per coherent unit; never broad `git add bakery/`
- Characterization findings are current bugs — implement the **missing endpoint** as specified; do not “fix” unrelated 0C bugs in this task

## Commands

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File bakery\scripts\start_local_mariadb.ps1
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe tests\run_auth_tests.php
C:\php\php.exe tests\run_characterization.php
```

Manual smoke (after login as driver/manager/admin): POST `driver_id` + `date` + CSRF; confirm JSON shape and empty-array success when no assignments.

## Acceptance criteria

- [ ] `get_driver_orders.php` exists and answers POST `driver_id` + `date`
- [ ] JSON matches 0C: `success` + `orders[]` with **only** the documented fields
- [ ] SQL aligns with `driver_list.php` ~47–66 filter/join/order semantics
- [ ] Unauthenticated request rejected; roles allowed: driver, manager, administrator
- [ ] CSRF enforced on POST (same convention as Agent 1)
- [ ] Test coverage: characterization and/or auth test asserts contract (and auth failure path)
- [ ] No UI rewrite of `driver_list.php`; no new unrelated API fields
- [ ] Commit only endpoint + tests (+ minimal allowlist touch if required)

## Out of scope (explicit)

Driver list UI rewrite, localStorage as source of truth, photos/GPS, zone/weekday migrations, auth system rewrite.
