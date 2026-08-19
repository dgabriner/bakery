# Phase 3 — DreamHost staging resource plan

**Date:** 2026-08-18
**Branch:** `codex/phase3-dreamhost-staging-plan-20260818`
**Base:** `codex/phase2-local-snapshot-clone-20260818` @ `db0ecd1`
**Follow-on:** Phase 4 (2026-08-18) repointed auto-push to `staging.sourflour.org`
only. See `docs/AUTO_PUSH.md` and `docs/DATA_ENVIRONMENT_STABILIZATION_PLAN.md`.

This document names the DreamHost resources Phase 3 needs. Secrets stay out of
Git. Hosted mutation still requires Gate 2 (use) and Gate 3 (first live copy).

## Current live map (do not change)

These values come from tracked examples and deploy docs. Secrets stay out of Git.

| Role | Current value |
|---|---|
| Production hostname | `https://bakery.sourflour.org/bake/` |
| Production document root / SFTP path | `bakery.sourflour.org/bake` |
| Production MySQL host | `mysql.sourflour.org` |
| Production database | `bakerysf` |
| Production DB user (docs/example) | `bakerysf` |
| Production SFTP host (example) | `iad1-shared-b7-08.dreamhost.com` |
| Production SFTP user (example) | `dh_dp755h` |
| Other hosted roots on the same account | `bakery.sourflour.org` (domain root), `bakery.sourflour.org/breadeducation` |
| Live auto-push | Stopped and disabled (`storage/deploy/.auto_push_disabled` present) |
| Local everyday DB | `bakerysf_stage_local` |
| Local read-only mirror | `bakerysf_local` |
| Disposable tests | `bakerysf_test` |

`scripts/push_sftp.ps1` still prints the live bakery URL and has no staging-root
guard. Leave auto-push disabled until Phase 4 target checks exist.

## Owner-confirmed DreamHost staging (2026-08-18)

The owner named the hosted staging target. Do not confuse this with production
`bakery.sourflour.org/bake` or SFTP user `dh_dp755h`.

| Resource | Confirmed value | Notes |
|---|---|---|
| Hostname | `https://staging.sourflour.org/` | Owner: “we are now at staging.sourflour.org” |
| Machine | Shared Unlimited `iad1-shared-b7-08` | Same DreamHost cluster as live |
| SFTP host | `iad1-shared-b7-08.dreamhost.com` | |
| SFTP user | `bakeryOS` | Dedicated user, as required. Password is **not** in Git. A gitignored local `.env.sftp.stage` holds the earlier bakeryOS SFTP secret from [Bakery folder recent updates](d12878e7-c7b4-4919-afb4-3e697de0f526). |
| SFTP remote root (assumed) | `staging.sourflour.org` | Standard DreamHost fully hosted domain folder under `/home/bakeryOS/`. Confirm in the panel that **staging.sourflour.org is assigned to bakeryOS**. |
| `BASE_URL` | `/` | Dedicated hostname |

A public fetch of `https://staging.sourflour.org/` timed out during this
session. DNS, TLS, or the empty docroot may still be provisioning. That is not
a deploy failure; nothing was uploaded.

`bakeryOS` previously authenticated on this SFTP host (August 2026). Its home
was `/home/bakeryOS/`. Live `sourflour.org` WordPress is **not** this user — do
not point bakeryOS at that WordPress root.

## Owner-offered unused database

The owner stated that DreamHost database **`bakerysoftware`** is currently unused
and should be able to hold the same schema/data role as `bakerysf`. DreamHost
cannot rename a database after creation, so if we adopt `bakerysoftware` it
becomes the permanent staging name.

**Not done:** connecting to DreamHost MySQL, inspecting or emptying
`bakerysoftware`, importing a production dump, or deploying application files.

A database password was supplied in chat. It is **not** in Git. After Gate 2,
put it only in the server `.env` or the DreamHost panel.

## Required DreamHost resources

| Resource | Required? | Value | Status |
|---|---|---|---|
| Staging hostname | Yes | `https://staging.sourflour.org/` | **Named by owner** |
| Separate document root | Yes | `staging.sourflour.org/` (not `/bake`) | **Named; panel assignment to bakeryOS still to confirm** |
| Staging database | Yes, unique, cannot rename | `bakerysoftware` | Candidate; **Gate 2 use not yet explicit** |
| Staging-only MySQL user | Yes; must not also own `bakerysf` | Offered: `lavictoriasf` | **Open:** exclusive to `bakerysoftware`? |
| Staging SFTP user | Yes | `bakeryOS` | **Named by owner** |
| Staging `.env` that cannot be mistaken for production | Yes | Template: `storage/deploy/STAGING_ENV.example` | Template only; not deployed |
| Staging SFTP env file | Later (Phase 4) | Example: `.env.sftp.stage.example`. Local gitignored `.env.sftp.stage` exists | Push remains disabled |

DreamHost panel references:

- Create database: https://help.dreamhost.com/hc/en-us/articles/221691727-Creating-a-MySQL-database
- Separate database user per database: https://help.dreamhost.com/hc/en-us/articles/360060957212-Adding-and-deleting-a-database-user
- Cron (do **not** copy production studio-tick cron onto staging): https://help.dreamhost.com/hc/en-us/articles/215088668-Create-a-cron-job

## Hostname and path

| Choice | Hostname | SFTP `SFTP_REMOTE_ROOT` | `BASE_URL` | Why |
|---|---|---|---|---|
| **Owner-named** | `staging.sourflour.org` | `staging.sourflour.org` | `/` | Separate cookies, TLS, and document root. Hard to confuse with `/bake`. |
| Avoid | `bakery.sourflour.org/stage` | `bakery.sourflour.org/stage` | `/stage/` | Shares the live hostname |

Do not deploy staging into `bakery.sourflour.org/bake`. That is production.

## Environment separation

| Concern | Production | DreamHost staging | Local staging |
|---|---|---|---|
| `APP_ENV` | `production` | `staging` | `local` |
| Database | `bakerysf` | `bakerysoftware` (proposed) | `bakerysf_stage_local` |
| DB user | production-only | staging-only, associated only with the staging DB | `bakery_local` |
| Document root | `bakery.sourflour.org/bake` | `staging.sourflour.org/` | this PC |
| SFTP user | `dh_dp755h` (live) | `bakeryOS` | n/a |
| Mail | SMTP/OAuth when live | `MAIL_DRIVER=log` | `MAIL_DRIVER=log` |
| Square | live only after explicit later work | `SQUARE_ENV=sandbox` | sandbox |
| Maps | live key + live referrers | off, or a key restricted to the staging hostname | off |
| Studio clock cron | `bakerysf` only (`sfb_studio_tick.php`) | **no cron**; existing CLI already refuses any DB except `bakerysf` | `--force` only |
| Customer/driver notifications | real | in-app on the staging copy only; no outbound mail | log |
| Banner | none | strong non-dismissible **STAGING** banner (not implemented yet) | local banner |
| Auto-push / SFTP | disabled | not enabled in Phase 3 | local only |
| Data flow | live operations | one-way copy from a verified production dump after Gate 3 | clone of the same snapshot |

Code still needed **after** Gate 2, before a first upload:

1. Treat `APP_ENV=staging` as hosted-but-not-production (HTTPS on, `IS_LOCAL` off, mail log, staging banner).
2. Database target guards that accept `bakerysoftware` only when `APP_ENV=staging`.
3. Refuse SFTP roots that match `bakery.sourflour.org/bake` when the intended target is staging.
4. Distinct files `.env.sftp.stage` vs `.env.sftp.live` (Phase 4). Auto-push stays off.
5. English and Spanish staging banner strings.
6. Administrator review after each staging refresh (production PII will be in the copy).

## Refresh path (not executed)

When Gate 3 is later approved, the only allowed hosted refresh is:

1. Verified production dump (already the Phase 2 snapshot workflow).
2. Backup current staging DB if it contains anything worth keeping.
3. Import into `bakerysoftware` only.
4. Apply staging-only migrations.
5. Health check on the staging URL.
6. Confirm production row counts and files are unchanged.

Staging never writes to `bakerysf`. Staging never replaces live data.

## What this mission will not do

- Upload application files to `staging.sourflour.org`.
- Connect to `mysql.sourflour.org` or import into `bakerysoftware`.
- Enable auto-push or change `main`.
- Touch production `bakery.sourflour.org/bake`.

## Owner answers

Answered 2026-08-18:

1. **Hostname.** `staging.sourflour.org`.
2. **SFTP host / machine.** `iad1-shared-b7-08.dreamhost.com`, Shared Unlimited.
3. **SFTP user.** `bakeryOS`.
4. **SFTP path.** Recorded as `staging.sourflour.org/` unless the panel shows a different folder.

Still needed (chat reply is enough):

1. **Panel assignment.** Confirm Manage Websites → `staging.sourflour.org` is owned by **bakeryOS**, not `dh_dp755h`.
2. **TLS.** HTTPS enabled for `staging.sourflour.org`.
3. **Database.** Confirm `bakerysoftware` is unused/disposable and may be the permanent staging DB.
4. **Database user.** Confirm `lavictoriasf` can access **only** `bakerysoftware`. If it can also reach `bakerysf`, create a staging-only user.
5. **Gate 2.** Explicit yes to *use* these resources (place a staging `.env` on the server and, after code guards, upload app files to `bakeryOS` / `staging.sourflour.org` only).
6. **Gate 3 stays later.** The first copy of live `bakerysf` into `bakerysoftware` still needs a separate yes.

## After Gate 2

Still without production writes:

- Add `APP_ENV=staging` behavior, banner, and target guards in code.
- Place a staging `.env` on `staging.sourflour.org` (never the live `/bake` `.env`).
- Upload only after SFTP target checks refuse `bakery.sourflour.org/bake`.
- Keep live auto-push disabled.
- Do not import `bakerysf` into `bakerysoftware` until Gate 3.

Until then, local work stays on `bakerysf_stage_local` / `bakerysf_test`.

## Execution evidence (2026-08-18)

Gate 2 and Gate 3 were approved in chat ("yes, use staging.sourflour.org / bakeryOS"
and "move forward with everything suggested").

- SFTP user `bakeryOS` listed `staging.sourflour.org` on `iad1-shared-b7-08.dreamhost.com`.
- Uploaded 383 deployable files, then 26 additional operational root pages, then
  remote `.env` from gitignored `.env.staging.dreamhost`.
- Live `bakery.sourflour.org/bake` and `.env.sftp` were not used. Auto-push stayed disabled.
- `https://staging.sourflour.org/login.php` serves Sour Flour OS with a STAGING banner
  naming `bakerysoftware @ mysql.sourflour.org`.
- Imported verified snapshot
  `storage/dumps/nightly/live_20260819_003445_phase2_baseline.sql.gz` into
  `bakerysoftware` only. Counts: 107 customers, 54 products, 4,441 standing orders,
  6 drivers, 11 users, 878 daily orders.
- Next: phone acceptance. Phase 4 is staging auto-deploy, still never live auto-push.
