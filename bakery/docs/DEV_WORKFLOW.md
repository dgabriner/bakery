# Dev Workflow — Local ↔ Production

One menu drives database sync, deploy file tracking, and ZIP uploads for DreamHost.

**Start here:** double-click `dev_workflow.bat` in the `bakery/` folder.

---

## One-time setup

1. **Local app:** follow [LOCAL_SETUP.md](LOCAL_SETUP.md)
2. **Production DB access:** copy `.env.production.pull.example` → `.env.production.pull` and set `PROD_DB_*`
3. **SFTP deploy:** copy `.env.sftp.example` → `.env.sftp`, set credentials, `py -m pip install paramiko`
4. **After your current production deploy:** run option **13** (or let SFTP push auto-record) so change tracking starts from today

---

## Daily loop

| Step | What | Menu option |
|------|------|-------------|
| 1 | Start MariaDB + local server | 1, 2 |
| 2 | Pull latest production data (optional) | 4 |
| 3 | Develop and test locally | — |
| 4 | Run tests before deploy | 14 |
| 5 | See which files changed for production | 9 |
| 6 | Auto-push via Cursor hooks (or `watch_push.bat`) / manual push | hooks / 10–11 |
| 7 | Or build ZIP + DreamHost File Manager extract | 12 + manual |
| 8 | Record deploy complete (ZIP path only; SFTP auto-records) | 13 |

---

## Database sync

### Production → local (safe)

Use when you want local data to match live production.

```
Menu option 4
or: C:\php\php.exe scripts\pull_prod_to_local.php
```

- Read-only against production
- Replaces `bakerysf_local`
- Recreates your local admin login (`danny@sourflour.org` unless you pass `--skip-admin`)

### Local → production (destructive)

Use only when local data should **become** live production data.

```
Menu option 7   preview (dry-run)
Menu option 8   execute (requires typing YES)
or: C:\php\php.exe scripts\push_local_to_prod.php --confirm-push-to-production
```

- Backs up production first → `storage/dumps/bakerysf_prod_pre_push_*.sql`
- By default **does not** overwrite production login users
- Add `--include-auth` only if you intentionally want to copy local logins to production

### Compare only

```
Menu option 5
or: C:\php\php.exe scripts/compare_prod_local.php
```

### Backup production (no local changes)

```
Menu option 6
or: C:\php\php.exe scripts\backup_production.php
```

---

## Production file deploy

### Which files go to production?

Canonical deploy list lives in **`scripts/deploy_manifest.ps1`** (single source of truth).

Includes: tracked PHP pages, `includes/`, `css/`, `assets/`, optional `vendor/phpmailer`.

Excludes: debug/test/backup variants, local `.env`, SQL dumps, quarantine files.

### One-time SFTP setup

1. Copy `.env.sftp.example` → `.env.sftp`
2. Set `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_REMOTE_ROOT` (`bakery.sourflour.org/bake`)
3. Install Python + `paramiko` once: `py -m pip install paramiko`

### See what changed since last deploy

```
Menu option 9
or: .\scripts\list_deploy_changes.ps1
```

Compares against `storage/deploy/LAST_DEPLOY.json` (file timestamps + git commit when available).

### Push changed files via SFTP (preferred)

```
push.bat
# or:
.\scripts\push_sftp.ps1
.\scripts\push_sftp.ps1 -DryRun
.\scripts\push_sftp.ps1 -All
```

Double-click `push.bat` (or run the script). It uploads only files changed since the last push, then records:
- `storage/deploy/LAST_DEPLOY.json` — baseline for the next delta
- `storage/deploy/PUSH_HISTORY.jsonl` — append-only history
- `storage/deploy/pushes/push_YYYYMMDD_HHMMSS.json` — per-push detail

No confirmation prompt (use `-Confirm` if you want one).  
Never uploads `.env`, uploads, dumps, scripts, or tests.  
Schema SQL changes are noted in the log but not applied.

### Auto-push (keep server nearly mirrored)

**Cursor agent edits:** project hooks in `.cursor/hooks.json` queue a debounced upload after file edits and when the agent stops (~20s quiet period). Requires a Trusted workspace.

**Manual / any local edits:** leave this running:

```
watch_push.bat
```

Disable either path by creating `storage/deploy/.auto_push_disabled`.  
Activity log: `storage/deploy/auto_push.log`.

### Build ZIP (fallback)

```
Menu option 12
or: .\scripts\build_deploy_zip.bat
```

Output: `storage/deploy/bakery_deploy_YYYYMMDD_HHMMSS.zip`

Upload via **DreamHost File Manager → Upload → Extract**. Do **not** overwrite server `.env` unless you mean to.

### Mark deploy done

After a **manual ZIP** upload succeeds:

```
Menu option 13
or: .\scripts\record_deploy.ps1
```

Updates `LAST_DEPLOY.json` so option 9 / SFTP push only shows new changes next time. SFTP push already records this for you.

---

## Quick reference

| Task | Command |
|------|---------|
| Open menu | `dev_workflow.bat` |
| Pull prod DB | `php scripts/pull_prod_to_local.php` |
| Push local DB | `php scripts/push_local_to_prod.php --dry-run` |
| Changed deploy files | `.\scripts\list_deploy_changes.ps1` |
| SFTP push changed files | `.\scripts\push_sftp.ps1` |
| Build ZIP | `.\scripts\build_deploy_zip.bat` |
| Record deploy | `.\scripts\record_deploy.ps1` |

---

## Safety rules

- Never commit `.env` or `.env.production.pull`
- Never upload local `.env` to DreamHost
- Pull = safe for production; Push = overwrites live data
- Deploy ZIP = PHP/CSS only; database is separate unless you push
- Run tests (menu 12) before building a deploy ZIP

---

## Related docs

- [LOCAL_SETUP.md](LOCAL_SETUP.md) — MariaDB, local login, first-time setup
- [PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md) — production env vars, auth, verification
- [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md) — files that must never be deployed
