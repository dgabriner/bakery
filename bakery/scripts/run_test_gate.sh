#!/usr/bin/env bash
# Linux / cloud twin of scripts/run_local_test_gate.ps1 (Mission 30 `agent-env`).
#
# Fail-closed, local-only. Targets exactly bakerysf_test on loopback. Never
# deploys, never touches the mirror, Staging, or Live.
#
#   bash scripts/run_test_gate.sh                       # lint + reset + every suite
#   bash scripts/run_test_gate.sh --files=a.php,b.php   # suites mapped by includes/agent_work_map.php
#   bash scripts/run_test_gate.sh --suites=run_auth_tests,run_navigation_tests
#   bash scripts/run_test_gate.sh --changed-since=origin/main   # suites for files changed vs a ref
#   flags: --no-lint  --no-reset  --include-desktop-only  --report=json
#
# Reset source: a verified production snapshot under storage/dumps/nightly when
# present (desktop), otherwise database/schema + database/fixtures (cloud).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Suites that need production-snapshot data or gitignored quarantine files
# (simple_invoice.php, generate_invoice_simple.php). They pass on the owner's
# desktop gate; on fixture-only databases they report environment gaps, not bugs.
DESKTOP_ONLY_SUITES=(
  run_exception_desk_tests
  run_invoice_send_tests
  run_live_product_pack_yields_migration_tests
  run_product_pack_yield_tests
  run_sfb_studio_clock_tests
  run_surface_hygiene_tests
  run_text_comms_media_tests
)

LINT=1
RESET=1
INCLUDE_DESKTOP_ONLY=0
REPORT=""
FILES=""
SUITES=""
CHANGED_SINCE=""
for arg in "$@"; do
  case "$arg" in
    --no-lint) LINT=0 ;;
    --no-reset) RESET=0 ;;
    --include-desktop-only) INCLUDE_DESKTOP_ONLY=1 ;;
    --report=json) REPORT=json ;;
    --files=*) FILES="${arg#--files=}" ;;
    --suites=*) SUITES="${arg#--suites=}" ;;
    --changed-since=*) CHANGED_SINCE="${arg#--changed-since=}" ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) echo "Unknown flag: $arg" >&2; exit 2 ;;
  esac
done

export DB_NAME=bakerysf_test
export USE_PROD_DB=false
export APP_ENV=local

if [ ! -f "$ROOT/.env" ]; then
  echo "FAIL  bakery/.env missing. Run scripts/cloud_agent_install.sh (cloud) or copy .env.example (desktop)." >&2
  exit 1
fi

# Fail closed if someone exported a production target into this shell.
if [ "${USE_PROD_DB:-false}" = "true" ] || [ "${USE_PROD_DB:-0}" = "1" ]; then
  echo "FAIL  USE_PROD_DB must be false for the test gate" >&2
  exit 1
fi

REPO_ROOT="$ROOT"
if ! git -C "$ROOT" rev-parse --show-toplevel >/dev/null 2>&1; then
  REPO_ROOT="$(cd "$ROOT/.." && pwd)"
fi

if [ -n "$CHANGED_SINCE" ]; then
  if [ "$REPO_ROOT" != "$ROOT" ]; then
    changed="$(git -C "$REPO_ROOT" diff --name-only "$CHANGED_SINCE" -- bakery/ | sed 's#^bakery/##' | paste -sd, -)"
  else
    changed="$(git -C "$ROOT" diff --name-only "$CHANGED_SINCE" -- . | sed 's#^bakery/##' | paste -sd, -)"
  fi
  if [ -z "$changed" ]; then
    echo "NOTE  no files changed since $CHANGED_SINCE; nothing to map"
    if [ "$REPORT" = "json" ]; then
      printf '%s\n' '{"passed":0,"failed":0,"skipped":0,"suites":[],"ok":true,"note":"no changes"}'
    fi
    exit 0
  fi
  FILES="${FILES:+$FILES,}$changed"
fi

if [ "$LINT" -eq 1 ]; then
  echo "== php -l"
  lint_fail=0
  while IFS= read -r -d '' f; do
    if ! php -l "$f" >/dev/null 2>&1; then
      echo "FAIL  lint: $f"; php -l "$f" 2>&1 | head -3; lint_fail=1
    fi
  done < <(find "$ROOT" -type f -name '*.php' \
      -not -path "$ROOT/vendor/*" -not -path "$ROOT/storage/*" -not -path "$ROOT/tmp/*" \
      -not -path "$ROOT/tmp_catalog_repairs/*" -not -path "$ROOT/breadeducation/*" \
      -not -path "$ROOT/domain_root/*" -not -name db_test.php -not -name test_db.php -print0)
  if [ "$lint_fail" -ne 0 ]; then echo "FAIL  PHP lint"; exit 1; fi
  echo "PASS  lint"
fi

if [ "$RESET" -eq 1 ]; then
  snapshot="$(ls -1 "$ROOT"/storage/dumps/nightly/live_*.sql.gz 2>/dev/null | sort -r | head -n1 || true)"
  if [ -n "$snapshot" ]; then
    echo "== reset bakerysf_test from snapshot $(basename "$snapshot")"
    php scripts/refresh_local_from_snapshot.php "--snapshot=$snapshot" --target=bakerysf_test || { echo "FAIL  snapshot refresh"; exit 1; }
  else
    echo "== reset bakerysf_test from database/schema + fixtures (no snapshot present)"
    php scripts/setup_local_db.php --reset --force-reset --database=bakerysf_test >/dev/null || { echo "FAIL  fixture reset"; exit 1; }
  fi
fi

declare -a run_list=()
if [ -n "$SUITES" ]; then
  IFS=',' read -ra picked <<< "$SUITES"
  for s in "${picked[@]}"; do
    s="${s%.php}"; s="${s#tests/}"
    run_list+=("tests/${s}.php")
  done
elif [ -n "$FILES" ]; then
  echo "== mapping files → suites via includes/agent_work_map.php"
  mapped="$(php scripts/agent_homebase.php tests-for "--files=$FILES" --json \
    | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["tests"]??[]) as $t){echo $t,"\n";}')"
  if [ -z "$mapped" ]; then
    echo "NOTE  work map has no suites for: $FILES — patch includes/agent_work_map.php in this mission."
    exit 1
  fi
  while IFS= read -r t; do run_list+=("$t"); done <<< "$mapped"
else
  while IFS= read -r t; do run_list+=("$t"); done < <(ls -1 tests/run_*.php | sort)
fi

is_desktop_only() {
  local name="$1"
  for d in "${DESKTOP_ONLY_SUITES[@]}"; do [ "$d" = "$name" ] && return 0; done
  return 1
}

pass=0; fail=0; skipped=0
failed_list=()
declare -a report_suites=()
echo "== suites (${#run_list[@]})"
for t in "${run_list[@]}"; do
  name="$(basename "$t" .php)"
  if [ ! -f "$t" ]; then
    if [ -n "$SUITES" ]; then
      echo "FAIL  missing suite $t"; fail=$((fail+1)); failed_list+=("$t")
      report_suites+=("{\"name\":\"$name\",\"status\":\"fail\",\"reason\":\"missing\"}")
    else
      echo "SKIP  $name (mapped in agent_work_map but not written yet)"; skipped=$((skipped+1))
      report_suites+=("{\"name\":\"$name\",\"status\":\"skip\",\"reason\":\"not_written\"}")
    fi
    continue
  fi
  if [ "$INCLUDE_DESKTOP_ONLY" -eq 0 ] && is_desktop_only "$name" && [ -z "$SUITES" ]; then
    echo "SKIP  $name (desktop-only: needs production snapshot data or quarantine files)"
    skipped=$((skipped+1))
    report_suites+=("{\"name\":\"$name\",\"status\":\"skip\",\"reason\":\"desktop_only\"}")
    continue
  fi
  start_ts=$(date +%s)
  if out="$(timeout 600 php "$t" 2>&1)"; then
    elapsed=$(( $(date +%s) - start_ts ))
    pass=$((pass+1)); echo "PASS  $name"
    report_suites+=("{\"name\":\"$name\",\"status\":\"pass\",\"seconds\":$elapsed}")
  else
    elapsed=$(( $(date +%s) - start_ts ))
    fail=$((fail+1)); failed_list+=("$t")
    echo "FAIL  $name"
    echo "$out" | grep -E "FAIL|Fatal|Refusing|rror" | head -5 | sed 's/^/      /'
    report_suites+=("{\"name\":\"$name\",\"status\":\"fail\",\"seconds\":$elapsed}")
  fi
done

echo "== gate: passed=$pass failed=$fail skipped=$skipped"
if [ "$REPORT" = "json" ]; then
  suites_json=$(printf '%s,' "${report_suites[@]:-}" | sed 's/,$//')
  ok_json=true
  [ "$fail" -eq 0 ] || ok_json=false
  printf '{"passed":%s,"failed":%s,"skipped":%s,"ok":%s,"suites":[%s]}\n' \
    "$pass" "$fail" "$skipped" "$ok_json" "$suites_json"
fi
if [ "$fail" -ne 0 ]; then
  printf '      %s\n' "${failed_list[@]}"
  exit 1
fi
echo "PASS  Test gate completed without any production target."
