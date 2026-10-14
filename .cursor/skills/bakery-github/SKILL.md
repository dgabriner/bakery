---
name: Bakery GitHub
description: >-
  Use when changing SourFlour bakery application source (PRs, diffs, cloud-agent
  code work). Not SFTP/WebFTP. Not Staging sync. Not Live promote.
---
# Bakery GitHub

Use when changing SourFlour bakery application source (PRs, diffs, cloud-agent code work). Not SFTP/WebFTP. Not Staging sync. Not Live promote.

## Origin (pinned 2026-08-26)

Canonical Cloud GitHub is https://github.com/dgabriner/bakery.git on **main**. SheepMiner/Bakery is old. Do not clone SheepMiner or open PRs there. Do not merge `chore/checkpoint-*` into main. PR numbers are per repo: say `dgabriner/bakery#N` or the branch. A SheepMiner PR number is not this repo.

## How to change code

1. Do not clone onto this computer or the user’s computer. Non-trivial code goes to a Cursor cloud agent (Auto or grok-4.7 only) on **dgabriner/bakery**. **REQUIRED:** Cursor cloud agent. Hand off the outcome, not a line-by-line patch.
2. Narrow lookups: `gh` / GitHub API on `dgabriner/bakery`.
3. Additive branches only. No force-push. Commit application source only (PHP, CSS, JS, `database/schema/`, docs). Secret-scan: no `.env`, dumps, credentials.
4. Close loops; do not add modules or top-level pages unless asked.
5. User-facing strings: write both `lang/en.php` and `lang/es.php`.
6. Catalog weights and formulas are Bakery operator work on Staging, not Git. Live bakery ops stay in SourFlour OS.

## Local tools on the shared agent computer

PHP 8.4 CLI + Composer are installed. Prefer `php tests/run_*.php` only when `bakerysf_test` exists. Never point tests at Live or Staging production DBs. No XAMPP / Apache / MySQL server here.

## Deploy is a different skill

Merge is not a deploy. GitHub commit is not Staging. Live is not a Git push.

**REQUIRED:** Git to Staging for the ship path. Do not WebFTP or File Manager files onto Staging as the repeatable loop.

## Report back

PR or commit link on `dgabriner/bakery`, files touched, and that Live was not touched.
