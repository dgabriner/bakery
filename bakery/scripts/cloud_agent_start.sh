#!/usr/bin/env bash
# Per-boot: make sure the local MariaDB daemon is up before tests run.
# Idempotent; used by .cursor/environment.json `start` and by the install script.
set -euo pipefail

if ! command -v mariadb >/dev/null 2>&1; then
  echo "[cloud_agent_start] mariadb not installed; run scripts/cloud_agent_install.sh" >&2
  exit 1
fi

if sudo mariadb -e 'SELECT 1' >/dev/null 2>&1; then
  echo "[cloud_agent_start] mariadb already running"
  exit 0
fi

sudo service mariadb start >/dev/null 2>&1 || sudo mysqld_safe --user=mysql >/dev/null 2>&1 &
for _ in $(seq 1 30); do
  if sudo mariadb -e 'SELECT 1' >/dev/null 2>&1; then
    echo "[cloud_agent_start] mariadb ready"
    exit 0
  fi
  sleep 1
done
echo "[cloud_agent_start] mariadb did not become ready" >&2
exit 1
