# Local development setup (Checkpoint 0B)

This guide configures a **local-only** Bakery Manager environment that cannot fall back to production credentials.

## Agent wave pointer (0D/0E)

For branch, checkpoint status, parallel agent ownership, and quarantine policy, see:

- [CURRENT_STATE.md](CURRENT_STATE.md) — branch, checkpoint table, quick commands
- [agent-briefs/00_SHARED_CONTEXT.md](agent-briefs/00_SHARED_CONTEXT.md) — mandatory shared context for all agents
- [QUARANTINE_INVENTORY.md](QUARANTINE_INVENTORY.md) — backup/debug/test files (**DO NOT DELETE** in this wave)
- [MARIADB_USER_PROCESS.md](MARIADB_USER_PROCESS.md) — Scoop MariaDB without Windows admin
- [CREDENTIAL_ROTATION_RUNBOOK.md](CREDENTIAL_ROTATION_RUNBOOK.md) — production credential rotation (link only; no secrets here)
- [CURSOR_OPS_DRAFT.md](CURSOR_OPS_DRAFT.md) — draft Cursor/AGENTS.md guidance

## Prerequisites

| Tool | Notes |
|------|-------|
| PHP 8.3+ | This workstation: `C:\php\php.exe` (`pdo_mysql` enabled) |
| MariaDB/MySQL | Prefer **user-scoped Scoop** (no admin). Avoid Windows services if you lack elevation. |

### Recommended: Scoop MariaDB (no admin)

```powershell
# One-time: install Scoop + MariaDB into your user profile
irm get.scoop.sh | iex
scoop install mariadb

# Start MariaDB as a user process (NOT a Windows service — no admin)
mysqld --standalone --console
# Or backgrounded:
# Start-Process mysqld.exe -ArgumentList '--standalone','--console' -WindowStyle Hidden

# Stop: find the process and end it (no admin)
Get-Process mysqld -ErrorAction SilentlyContinue | Stop-Process
```

Create local DB user (example; choose your own local password):

```sql
CREATE DATABASE IF NOT EXISTS bakerysf_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'bakery_local'@'127.0.0.1' IDENTIFIED BY 'your_local_password';
CREATE USER IF NOT EXISTS 'bakery_local'@'localhost' IDENTIFIED BY 'your_local_password';
GRANT ALL PRIVILEGES ON bakerysf_local.* TO 'bakery_local'@'127.0.0.1';
GRANT ALL PRIVILEGES ON bakerysf_local.* TO 'bakery_local'@'localhost';
FLUSH PRIVILEGES;
```

Do **not** reuse production passwords. Do **not** register MariaDB as a Windows service unless you have admin rights and intentionally want a service.

### Other install options (may require admin)

1. `winget install --id MariaDB.Server -e` (often needs elevation)
2. `winget install --id Oracle.MySQL -e`
3. XAMPP via winget

After any install, point `bakery/.env` at `127.0.0.1` / `bakerysf_local` only.

## Configuration method (no Composer)

Checkpoint 0B uses a **minimal PHP `.env` loader** (`includes/env_loader.php`):

1. **Local:** `bakery/.env` (gitignored) loaded by `includes/config.php`
2. **Production (DreamHost/Apache):** set `DB_*`, mail, and OAuth via panel/Apache env vars **or** a config file outside the document root — **no hardcoded production fallbacks remain in PHP**

## First-time setup

```powershell
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
copy .env.example .env
# Edit .env: set DB_USER / DB_PASS for your local server; keep DB_NAME=bakerysf_local
# Keep MAIL_DRIVER=log and MAPS_ENABLED=false

C:\php\php.exe scripts\verify_local_env.php
C:\php\php.exe scripts\setup_local_db.php --reset
C:\php\php.exe scripts\seed_local_users.php
C:\php\php.exe scripts\verify_local_env.php
```

If `bakery_local` access is denied after recreating MariaDB data, sync the DB user to `.env` (does not print secrets):

```powershell
C:\php\php.exe scripts\sync_local_db_user.php
```

### Reset fixtures

```powershell
C:\php\php.exe scripts\setup_local_db.php --reset
C:\php\php.exe scripts\seed_local_users.php
```

## Authentication (Checkpoint 0D)

Login is required for all app pages except `login.php` and `health_local.php`.

| Email | Role | Local password |
|-------|------|----------------|
| `admin@local.test` | administrator | `LocalAdmin!234` |
| `manager@local.test` | manager | `LocalManager!234` |
| `driver@local.test` | driver | `LocalDriver!234` |

These are **nonproduction fixtures only**. Never reuse them in production.

Emergency local admin reset (CLI, `APP_ENV=local` only):

```powershell
C:\php\php.exe scripts\reset_local_admin.php
# or with a custom password:
C:\php\php.exe scripts\reset_local_admin.php "YourNewLocalPassword"
```

There is **no** permanent `AUTH_ENABLED=false` bypass.

### Auth / CSRF tests

```powershell
C:\php\php.exe tests\run_auth_tests.php
```

## Run the app locally

From the `bakery` directory (or parent with router):

```powershell
cd C:\Users\918825809\CascadeProjects\windsurf-project
C:\php\php.exe -S 127.0.0.1:8080 -t bakery
```

Then open: `http://127.0.0.1:8080/login.php`

Expected:

- Amber **LOCAL ENVIRONMENT** banner showing `bakerysf_local @ 127.0.0.1`
- Unauthenticated `/index.php` redirects to login
- Email actions write to `bakery/logs/mail.log` instead of sending

**Note:** Many pages hardcode `/bakery/` asset paths. If CSS 404s under the built-in server, either:

- Use Apache/XAMPP with an alias `/bakery` → this folder, or
- Open via `http://127.0.0.1/bakery/` once a vhost is configured (`simple_vhost.ps1` exists as a helper)

## Safety rails (verified in code)

- Missing `DB_*` env → hard fail (no production password fallback)
- `APP_ENV=local` + `DB_NAME=bakerysf` → refused
- Local/dev + host containing `sourflour` / `dreamhost` → refused
- `MAIL_DRIVER=log` → no SMTP/OAuth send
- `MAPS_ENABLED=false` → empty Maps key acceptable
- Auth gate on web requests after DB connect; CSRF required on state-changing methods
- Diagnostics restricted to administrator (+ Apache `.htaccess` deny rules)

## Production deploy warning

Deploying this config change to production **requires** setting environment variables first (see `docs/CREDENTIAL_ROTATION_RUNBOOK.md`). The old hardcoded fallbacks were removed intentionally. Seed users must **not** be deployed.

## Demo data (nonproduction)

After fixtures load:

- Customers: Demo Cafe Alpha, Demo Market Beta, Demo Spot Gamma
- Drivers: Demo Driver Ava, Demo Driver Ben
- Products: Demo Country Loaf, Demo Batard, Demo Concha, Demo Sandwich Loaf

## Pull production data (optional)

One-way copy of DreamHost `bakerysf` into local `bakerysf_local`. **Destroys** current local data. App `.env` stays on `127.0.0.1` / `bakerysf_local` — do not point runtime at production.

1. Whitelist your public IP for MySQL user `bakerysf` in [DreamHost MySQL Databases](https://panel.dreamhost.com/index.cgi?tree=mysql.databases) → Allowable Hosts (keep `%.dreamhost.com`).
2. Ensure local MariaDB is running (`scripts/start_local_mariadb.ps1`).
3. Copy credentials file (gitignored):

```powershell
cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery
copy .env.production.pull.example .env.production.pull
# Edit .env.production.pull: set PROD_DB_PASS (and host/user if needed)
```

4. Run the pull (recreates `danny@sourflour.org` as administrator unless `--skip-admin`):

```powershell
$env:Path = "$env:USERPROFILE\scoop\shims;" + $env:Path
C:\php\php.exe scripts\pull_prod_to_local.php --admin-password="YourLocalLoginPassword"
```

Or set `LOCAL_ADMIN_PASSWORD` inside `.env` / `.env.production.pull` and omit the flag.

After any `setup_local_db.php --reset` or `seed_local_users.php`, run (or rely on automatic call):

```powershell
C:\php\php.exe scripts\ensure_local_admin.php
```

`LOCAL_ADMIN_*` in `.env` is the durable source so login survives fixture resets. Avoid `--reset` unless you intend to wipe a production pull.

5. Verify:

```powershell
C:\php\php.exe scripts\verify_local_env.php
# Login: http://localhost:8080/bakery/login.php
```

Dumps are written under `storage/dumps/` (gitignored, contains PII). Do not commit them.

## Proof production cannot be reached accidentally

1. `scripts/verify_local_env.php` fails if host/name look like production.
2. `includes/config.php` `bakery_assert_safe_database_target()` aborts the request.
3. `scripts/setup_local_db.php` refuses non-loopback hosts and `bakerysf` name.
4. `.env` is gitignored; `.env.example` contains only placeholders.
5. Production pull uses a separate `.env.production.pull` (gitignored) and never changes app `.env`.
