#!/usr/bin/env bash
# Cloud Agent bootstrap for Sour Flour OS (Mission 30 `agent-env`).
#
# Installs PHP 8.3 CLI + MariaDB, provisions the disposable `bakerysf_test`
# database (never the mirror, never Staging, never Live), writes a local-only
# bakery/.env from .env.example, and builds the schema + demo fixtures so
# `tests/run_*.php` can run. Idempotent: safe to re-run.
#
# Local desktops keep using scripts/run_local_test_gate.ps1 with a production
# snapshot. This path is for Linux/cloud where no snapshot exists.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_PASS_LOCAL="${BAKERY_CLOUD_DB_PASS:-bakery_local_test}"

log() { printf '[cloud_agent_install] %s\n' "$*"; }

if ! command -v php >/dev/null 2>&1 || ! command -v mariadb >/dev/null 2>&1; then
  log "installing php8.3 + mariadb"
  export DEBIAN_FRONTEND=noninteractive
  sudo apt-get update -qq
  sudo apt-get install -y -qq \
    php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd \
    mariadb-server python3 python3-paramiko >/dev/null
else
  log "php and mariadb already installed"
fi
if ! python3 -c 'import paramiko' >/dev/null 2>&1; then
  log "installing python3-paramiko for scripts/sftp_upload.py target checks"
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3 python3-paramiko >/dev/null
fi

bash "$ROOT/scripts/cloud_agent_start.sh"

log "provisioning bakerysf_test + bakerysf_stage_local for bakery_local@loopback"
sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS bakerysf_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS bakerysf_stage_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'bakery_local'@'127.0.0.1' IDENTIFIED BY '${DB_PASS_LOCAL}';
CREATE USER IF NOT EXISTS 'bakery_local'@'localhost' IDENTIFIED BY '${DB_PASS_LOCAL}';
ALTER USER 'bakery_local'@'127.0.0.1' IDENTIFIED BY '${DB_PASS_LOCAL}';
ALTER USER 'bakery_local'@'localhost' IDENTIFIED BY '${DB_PASS_LOCAL}';
GRANT ALL PRIVILEGES ON bakerysf_test.* TO 'bakery_local'@'127.0.0.1';
GRANT ALL PRIVILEGES ON bakerysf_test.* TO 'bakery_local'@'localhost';
GRANT ALL PRIVILEGES ON bakerysf_stage_local.* TO 'bakery_local'@'127.0.0.1';
GRANT ALL PRIVILEGES ON bakerysf_stage_local.* TO 'bakery_local'@'localhost';
FLUSH PRIVILEGES;
SQL

if [ ! -f "$ROOT/.env" ]; then
  log "writing local-only bakery/.env from .env.example (DB_NAME=bakerysf_test)"
  # 0000 is what tests use as the guaranteed-bad code, so local fixture users
  # take the documented desktop defaults instead (ensure_local_admin: 9741,
  # SFAdmin: 9099).
  sed -e 's/^DB_NAME=.*/DB_NAME=bakerysf_test/' \
      -e "s/^DB_PASS=.*/DB_PASS=${DB_PASS_LOCAL}/" \
      -e 's/^LOCAL_ADMIN_CODE=.*/LOCAL_ADMIN_CODE=9741/' \
      -e 's/^SFB_AGENT_ADMIN_CODE=.*/SFB_AGENT_ADMIN_CODE=9099/' \
      "$ROOT/.env.example" > "$ROOT/.env"
else
  log "bakery/.env already present; leaving it alone"
fi

log "building bakerysf_test from database/schema + fixtures"
(cd "$ROOT" && php scripts/setup_local_db.php --reset --force-reset --database=bakerysf_test >/dev/null)

log "smoke: auth + navigation suites"
(cd "$ROOT" && DB_NAME=bakerysf_test USE_PROD_DB=false php tests/run_navigation_tests.php >/dev/null)
(cd "$ROOT" && DB_NAME=bakerysf_test USE_PROD_DB=false php tests/run_auth_tests.php >/dev/null)
log "ready — run: bash bakery/scripts/run_test_gate.sh [--files=a.php,b.php | --suites=run_x_tests,run_y_tests]"
