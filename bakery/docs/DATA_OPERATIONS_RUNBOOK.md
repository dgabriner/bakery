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
