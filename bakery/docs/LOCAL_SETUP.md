# Local development setup (Checkpoint 0B)

This guide configures a **local-only** Bakery Manager environment that cannot fall back to production credentials.

## Prerequisites

| Tool | Status on this workstation (2026-07-28) | Notes |
|------|------------------------------------------|-------|
| PHP 8.3 | Found at `C:\php\php.exe` (`pdo_mysql` enabled) | Add to PATH optionally |
| MySQL or MariaDB | **Not installed** at inspection time | Required before DB boot |
| Docker | Not found | Optional alternative |

Until a local MySQL/MariaDB server is installed and running, schema load and page boot against the database cannot complete.

### Install options (choose one)

1. **MariaDB** via winget: `winget install --id MariaDB.Server -e`
2. **MySQL** via winget: `winget install --id Oracle.MySQL -e`
3. **XAMPP** (includes MariaDB): `winget install --id ApacheFriends.Xampp.8.2 -e`

After install, create a local DB user (example):

```sql
CREATE USER 'bakery_local'@'localhost' IDENTIFIED BY 'choose_a_local_password';
CREATE DATABASE bakerysf_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON bakerysf_local.* TO 'bakery_local'@'localhost';
FLUSH PRIVILEGES;
```

Do **not** reuse production passwords in chat, commits, or screenshots.

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
C:\php\php.exe scripts\verify_local_env.php
```

### Reset fixtures

```powershell
C:\php\php.exe scripts\setup_local_db.php --reset
```

## Run the app locally

From the `bakery` directory (or parent with router):

```powershell
cd C:\Users\918825809\CascadeProjects\windsurf-project
C:\php\php.exe -S 127.0.0.1:8080 -t bakery
```

Then open: `http://127.0.0.1:8080/index.php`

Expected:

- Amber **LOCAL ENVIRONMENT** banner showing `bakerysf_local @ 127.0.0.1`
- No production hostnames
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

## Production deploy warning

Deploying this Checkpoint 0B config change to production **requires** setting environment variables first (see `docs/CREDENTIAL_ROTATION_RUNBOOK.md`). The old hardcoded fallbacks were removed intentionally.

## Test credentials (nonproduction)

After fixtures load, there is still **no login system** (Checkpoint 0D). Use the fictional fixture customers/drivers in the UI:

- Customers: Demo Cafe Alpha, Demo Market Beta, Demo Spot Gamma
- Drivers: Demo Driver Ava, Demo Driver Ben
- Products: Demo Country Loaf, Demo Batard, Demo Concha, Demo Sandwich Loaf

## Proof production cannot be reached accidentally

1. `scripts/verify_local_env.php` fails if host/name look like production.
2. `includes/config.php` `bakery_assert_safe_database_target()` aborts the request.
3. `scripts/setup_local_db.php` refuses non-loopback hosts and `bakerysf` name.
4. `.env` is gitignored; `.env.example` contains only placeholders.
