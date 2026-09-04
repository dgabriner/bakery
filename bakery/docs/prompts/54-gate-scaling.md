# Prompt 54 — Gate that scales with the surface

Wave 3 (scalability). `--agent=gate-scaling`. Depends on Prompt 30.

---

Eighty-two suites take under a minute on fixtures today but grow with every mission. The gate should run mapped suites per change and the full set on `main`, and CI should run without the owner's laptop.

## Read first

- `scripts/run_test_gate.sh`, `includes/agent_work_map.php` (`bakery_agent_work_map_suggest`)
- `scripts/cloud_agent_install.sh`
- `.cursor/rules/git-staging-live-sync.mdc` (CI must never deploy)

## Ship

1. `--changed-since=<ref>` already maps changed files → suites; add `--report=json` for CI and a per-mission `expected_suites_seconds` note in the work map for the heavy ones.
2. `.github/workflows/test-gate.yml`: on PR → `mariadb:10.11` service, PHP 8.3, `setup_local_db.php --database=bakerysf_test`, `run_test_gate.sh --changed-since=origin/main --no-reset`; on push to `main` → full gate. No secrets, no SFTP, no deploy.
3. `docs/GROK_AND_CLOUD_AGENT_DEPLOY.md`: CI green is required before Staging sync; CI ≠ Staging ≠ Live.

## Constraints

CI must fail closed on `USE_PROD_DB=true` or any non-loopback `DB_HOST` (the guard already does — assert it).

## Done when

A PR touching `billing_center.php` runs only billing suites in CI and reports pass/fail on the PR; `main` runs everything.
