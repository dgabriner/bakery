# Phase 4 — staging-only auto-deploy

**Status:** implemented 2026-08-18. Production `/bake` is not an auto-push target.
**Owner Gate 4 (production promotion) is not part of this phase.**

## What changed

Editor hooks, the local **Staging auto-push** toggle, **Sync to staging**, `push.bat`, and `watch_push.bat` all call `scripts/push_sftp_stage.ps1`. That script loads only gitignored `.env.sftp.stage` (user `bakeryOS`, root `staging.sourflour.org`, `SFTP_TARGET=dreamhost-stage`).

Live file push remains an explicit command: `.\scripts\push_sftp.ps1` with `.env.sftp.live` (or legacy `.env.sftp`) and `SFTP_TARGET=dreamhost-live`. Auto-push never loads those files.

## Batch (each staging deploy)

1. Debounce local edits (`queue_sftp_push.ps1` / worker).
2. Identify changed deployable files (or full set on first baseline).
3. `php -l` changed PHP; abort on lint failure.
4. Upload files to `bakeryOS` / `staging.sourflour.org`. Incremental auto-push does **not** rewrite remote `.env`.
5. If a new `050+` schema SQL file changed and a prior staging baseline exists, publish it to the private migration vault. The `bakeryOS` SSH account uploads a private copy of the canonical tools, checkpoints `bakerysoftware` on DreamHost, and only then runs `scripts/run_migrations.php --mode=hosted-stage` beside the database. Never `bakerysf`.
6. The hosted command must stop if the checkpoint fails. This removes the old requirement that DreamHost authorize the developer workstation as a MySQL client. Additive retries verify already-present columns or indexes before continuing and recording the ledger ID.
7. Smoke `https://staging.sourflour.org/login.php` for `staging-env-banner`, `STAGING`, and `bakerysoftware`.
8. Record `storage/deploy/stage/releases/release_*.json` (git commit, SHA-256 of uploaded files, migrations) and `LAST_DEPLOY.json`.

Uncommitted edits may auto-deploy to staging. Only a committed, tested release can become a production candidate (Phase 5).

## Safety

- Queue skips unless `.env.sftp.stage` exists, and exits 1 if that file names `bakery.sourflour.org/bake`.
- Worker hard-codes `push_sftp_stage.ps1`.
- Staging SFTP user must be `bakeryOS`; live push refuses that user and the staging root.
- `storage/deploy/.auto_push_disabled` still pauses hooks/watcher. When the toggle is ON, uploads go to staging only.
- Do not enable production auto-push. Do not start Phase 5 without a new explicit owner yes.

## Commands

```text
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\push_sftp_stage.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\push_sftp_stage.ps1 -DryRun
php tests/run_phase4_auto_deploy_tests.php
php tests/run_staging_env_tests.php
php scripts/run_migrations.php --mode=dreamhost-stage
```

`--mode=dreamhost-stage` remains a guarded diagnostic/recovery mode for an
authorized workstation. Normal Sync uses `--mode=hosted-stage` privately over
the existing `bakeryOS` SSH channel.
