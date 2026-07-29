# Production Deploy — Sour Flour OS / Bakery Manager

**Status:** Ready for deploy after local verification and credential rotation.  
**Target:** DreamHost shared hosting (`mysql.sourflour.org`, document root for bakery app)

---

## Prerequisites (complete before deploy)

| Step | Status | Notes |
|------|--------|-------|
| Local test suite green | Required | `run_characterization.php` (57+), `run_auth_tests.php` (45), `run_integrity_tests.php` (11+) |
| Canonical pages tracked in git | Done | Commit `b803b78` + post-0E commits |
| Auth/CSRF on protected endpoints | Done | Checkpoint 0D/0E |
| Migrations tested locally | Required | `scripts/run_migrations.php` after prod pull |
| Credential rotation | **Required** | [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md) |
| DreamHost env vars configured | Required | See § Environment below |
| Diagnostic scripts blocked | Done | `.htaccess` + auth administrator gate |

---

## Deploy sequence

### 1. Rotate credentials (if not done recently)

Follow [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md):

- MySQL password for `bakerysf` user
- SMTP / Gmail OAuth if email is live
- Google Maps API key restriction

### 2. Run migrations on production database

Connect to production MySQL (DreamHost panel or whitelisted IP) and apply:

```sql
-- Weekday normalize (003)
UPDATE standing_orders SET day_of_week = 7 WHERE day_of_week = 0;
UPDATE standing_routes SET day_of_week = 7 WHERE day_of_week = 0;
```

Then run zone migration via local pull workflow or manually:

```bash
# From local machine with prod pull configured:
C:\php\php.exe scripts\pull_prod_to_local.php
# Migrations run automatically after import; verify locally first
```

For **direct production migration**, run equivalent SQL from `database/schema/003_weekday_normalize.sql` and `004_zone_id.sql` (add `zone_id` column first if missing — see `scripts/run_migrations.php`).

### 3. Deploy application files

Deploy **tracked canonical files only** — never deploy quarantine/debug/backup variants.

```powershell
# Example: rsync or git pull on DreamHost (adjust paths)
# Exclude: .env, storage/dumps/, uploads/, quarantine files
```

**Minimum deploy set:**

- All tracked `*.php` canonical pages
- `includes/` (auth, config, database, common_functions, nav, csrf.js)
- `css/`, `assets/`
- `.htaccess`
- `database/schema/` (for reference; do not run baseline on prod)

**Do NOT deploy:**

- `.env` with local credentials
- `storage/dumps/`
- Files in [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md)
- `scripts/pull_prod_to_local.php`, `ensure_local_admin.php` (local-only tools)

### 4. Configure production environment

Set on DreamHost (Apache env or config outside docroot):

| Variable | Production value |
|----------|------------------|
| `APP_ENV` | `production` |
| `DB_HOST` | `mysql.sourflour.org` |
| `DB_NAME` | `bakerysf` |
| `DB_USER` | `bakerysf` |
| `DB_PASS` | *(rotated secret — not in git)* |
| `BASE_URL` | `/` or `/bakery/` per vhost |
| `MAIL_DRIVER` | `smtp` or `oauth` |
| `MAPS_ENABLED` | `true` (if Maps used) |

Remove hardcoded production fallbacks from `includes/config.php` on prod — env vars must be set.

### 5. Seed production auth users

Production does **not** use `ensure_local_admin.php` (local-only). Create users via:

```powershell
C:\php\php.exe scripts/create_user_once.php --email=you@sourflour.org --password=... --role=administrator --name="Your Name"
```

Run against production DB only from a whitelisted IP with production `.env` — or insert via SQL after generating `password_hash` locally.

### 6. Post-deploy verification

- [ ] `login.php` loads over HTTPS
- [ ] Unauthenticated access to `index.php` redirects to login
- [ ] Driver can access `driver_list.php`; manager can access ops pages
- [ ] `get_driver_orders.php` returns JSON with auth + CSRF
- [ ] Diagnostic URLs return 403/denied (`test.php`, `debug.php`, etc.)
- [ ] Sunday order generation works (day 7)
- [ ] Zone filter on bread distribution works

---

## Rollback

1. Restore previous PHP files from git tag or backup
2. Database migrations are **forward-only** — weekday/zone_id changes are safe to leave in place
3. If auth breaks: use DreamHost MySQL panel to reset `users.password_hash` or run `create_user_once.php` locally against prod

---

## Local vs production

| Concern | Local | Production |
|---------|-------|------------|
| Database | `bakerysf_local` on 127.0.0.1 | `bakerysf` on mysql.sourflour.org |
| Admin bootstrap | `ensure_local_admin.php` | `create_user_once.php` or manual |
| Data sync | `pull_prod_to_local.php` (one-way) | Never push local → prod |
| Mail | `MAIL_DRIVER=log` | SMTP/OAuth |

---

## Next major step after deploy

1. Monitor auth/login and driver workflow for one delivery cycle
2. Human quarantine cleanup pass ([QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md))
3. Feature backlog ([ideas-for-development.md](../ideas-for-development.md))
