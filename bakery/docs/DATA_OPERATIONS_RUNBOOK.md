# Data Operations Runbook

## Automatic jobs

Install or repair the current-user tasks with:

```powershell
.\scripts\install_data_tasks.ps1 -DryRun
.\scripts\install_data_tasks.ps1
```

| Task | Schedule | Behavior |
|---|---|---|
| `SourFlour-NightlyDataCycle` | 2:00 AM + catch-up at logon | Read-only live snapshot; verify; refresh mirror and test |
| `SourFlour-WeeklyBackup` | Sunday 3:00 AM | New immutable weekly live snapshot; keep 12 |
| `SourFlour-MonthlyRestoreDrill` | Sunday 4:00 AM | Runs only when last proof is 28+ days old |
| `SourFlour-StagingWatcher` | At local sign-in | Restarts staging-only auto-sync when its local toggle is on |

Logs and state are gitignored under `storage/operations/`. Failed imports retain
the prior target because refreshes use a verified temporary import and a local
checkpoint.

## DreamHost demand scheduler

Dated orders fill from standing orders on a rolling 7-day horizon when someone
opens Daily Run, Daily Orders, the dashboard, or Daily Production. Overnight
coverage (so Monday already has Wednesday's route) is:

```text
/usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/demand_scheduler.php
```

Install that as a DreamHost cron once a day (early morning). Do not point it at
staging unless `APP_ENV=staging`. Local machines should not run it against live
`bakerysf`; opening the app fills the horizon, or `php scripts/demand_scheduler.php --force` for a one-shot local database.

## Backup locations

- Nightly: `storage/dumps/nightly/` (minimum 14)
- Weekly immutable: `storage/dumps/weekly/` (minimum 12)
- Local pre-refresh checkpoints: `storage/dumps/local-checkpoints/`
- Restore proof receipts: `storage/dumps/restore-drills/`

Set `BAKERY_OFFSITE_BACKUP_DIR` to an encrypted external/cloud-synced directory
to copy each weekly pair off this PC. Production-derived data contains customer
information and must never enter GitHub.

## Manual verification

```powershell
.\scripts\run_restore_drill.ps1 -Force -SnapshotPath "storage\dumps\nightly\live_....sql.gz"
Get-ScheduledTask -TaskName 'SourFlour-*'
```

The disposable `bakerysf_refresh_local` database is always dropped after the
check. It is never an application or test data source.

## DreamHost production backup capture

The Windows tasks are a local refresh/test fallback. For protection while the
PC is off, install `scripts/dreamhost_nightly_backup.php` outside the public
document root and create a locked DreamHost cron job:

```text
/usr/local/bin/php /home/YOUR_USER/bin/dreamhost_nightly_backup.php /home/YOUR_USER/.bakery-backup.env /home/YOUR_USER/bakery-backups
```

The mode-600 environment file must contain only `DB_HOST`, `DB_PORT`,
`DB_NAME=bakerysf`, `DB_USER`, and `DB_PASS`. The runner is CLI-only, refuses
any database other than `bakerysf`, writes compressed SHA-256 sidecars, and
retains 14 snapshots. Keep the environment file and backup directory outside
the web document root. Installing the cron/home-directory job remains a
DreamHost panel or shell operation; SFTP alone cannot safely install it.
