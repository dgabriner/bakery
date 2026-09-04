# Prompt 30 — Agent environment: cloud agents can run the gate

Wave 0 (foundation). `--agent=agent-env`. Sister: `31-docs-truth.md`.

---

You are making it possible for any Cursor agent — desktop, cloud, or mobile — to prove a change with the repo's own `tests/run_*.php` suites. Today only the owner's Windows laptop can run the gate.

## Shared contract

- Stack stays flat PHP + MariaDB. No PHPUnit, no Composer, no Docker in the repo.
- Tests target exactly `bakerysf_test` on loopback (`includes/test_target_guard.php`). Never the mirror, Staging, or Live.
- A cloud VM has no production snapshot. Build `bakerysf_test` from `database/schema` + `database/fixtures` via `scripts/setup_local_db.php --reset --force-reset --database=bakerysf_test`.
- `.env` is gitignored; the install script writes a local-only one from `.env.example`.

## Read first

- `scripts/run_local_test_gate.ps1`, `tests/isolate_test_db.php`, `tests/harness.php`
- `scripts/setup_local_db.php`, `scripts/run_migrations.php`
- `.cursor/skills/test-gate/SKILL.md`, `docs/GROK_AND_CLOUD_AGENT_DEPLOY.md`

## Ship

1. Repo-root `.cursor/environment.json` with `install` → `bakery/scripts/cloud_agent_install.sh` and `start` → `bakery/scripts/cloud_agent_start.sh`.
2. `scripts/run_test_gate.sh`: lint → reset (snapshot if present, fixtures otherwise) → suites; flags `--files=`, `--suites=`, `--changed-since=`, `--no-lint`, `--no-reset`, `--include-desktop-only`. Suites that need snapshot data are listed in `DESKTOP_ONLY_SUITES` and skipped by name.
3. `tests/isolate_test_db.php` falls back to the fixture reset when no snapshot exists.
4. Any migration that a fresh build proves missing gets wired in `scripts/run_migrations.php` (025_customer_account_preferences was).
5. Docs: test-gate skill + cloud deploy manual.

## Tests

`bash scripts/run_test_gate.sh` — every non-desktop-only suite green on a fresh VM.

## Done when

- A fresh cloud VM boots, `php tests/run_auth_tests.php` and `php tests/run_navigation_tests.php` pass, and the full Linux gate reports `failed=0`.
- The handoff names which suites were skipped as desktop-only.

**Status: shipped (this branch). Gate on fixture DB: 74 passed, 0 failed, 8 desktop-only skipped.**
