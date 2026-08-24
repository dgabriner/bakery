---
name: test-gate
description: >-
  Run Sour Flour OS PHP tests. Use when choosing which tests/run_*.php to run,
  running the local test gate, verifying bakery changes, or when tempted to
  add PHPUnit or Composer. Never invent a second test runner.
---

# Test gate

There is no PHPUnit. Suites are `tests/run_*.php` on **`bakerysf_test` only**.

## Pick the suite

1. Read `mission_packet.tests` from `php scripts/agent_homebase.php brief --agent=SLUG --json`.
2. Or `php scripts/agent_homebase.php tests-for --files="billing_center.php,lang/en.php" --json`.
3. If you touched `lang/en.php` or `lang/es.php`, also run `php tests/run_i18n_tests.php`.

## Minimum gate for any repo edit

Any change that ships — including JS, CSS, and doc-sync commits — needs at
least `php -l` on touched PHP plus the mapped suites for those paths. Touched
`lang/*` or `includes/` always means `run_i18n_tests.php` and
`run_integrity_tests.php`. "Ops only" (SMS sends, Live reads) is the sole
exemption, and handoff field 6 must say why no suite ran. Never skip the gate
because the edit is small; session 59 shipped filter behavior with zero suites.

```text
php tests/run_invoice_send_tests.php
php tests/run_agent_homebase_tests.php
php tests/run_agent_work_map_tests.php
```

On Windows, if `.env` still points at the nightly mirror, isolate the process:

```powershell
$env:DB_NAME = 'bakerysf_test'
$env:USE_PROD_DB = 'false'
php tests/run_invoice_send_tests.php
```

## Full gate (heavy)

Refreshes `bakerysf_test` from the verified production snapshot, lints PHP, runs every `tests/run_*.php`:

```powershell
.\scripts\run_local_test_gate.ps1
```

Do not run this against `bakerysf_local`. Do not invent PHPUnit. Do not deploy.
