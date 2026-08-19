# Dev Workflow — Local → Staging → Controlled Release

Start with `dev_workflow.bat`. The normal development path cannot push a local
database or local files directly to production.

## Daily loop

1. Work against `bakerysf_stage_local`.
2. Run the local test gate; tests use only `bakerysf_test`.
3. Auto-sync deployable edits to `https://staging.sourflour.org/`.
4. Test staging from the phone.
5. Commit the exact tested files to an additive Git branch.
6. Sync that clean commit to staging and create an immutable release candidate.
7. Production promotion is a separate, owner-authorized mission.

Git stores application history. It does not contain database dumps or secrets,
and commit/push does not deploy production.

## Data roles

| Database | Role | Normal writes |
|---|---|---|
| `bakerysf_local` | Nightly production mirror | No |
| `bakerysf_stage_local` | Everyday local development | Yes |
| `bakerysf_test` | Disposable regression database | Tests only |
| DreamHost `bakerysoftware` | Hosted staging | Staging acceptance only |
| DreamHost `bakerysf` | Live operations | Application + approved migrations |

The mirror and test database are rebuilt from the same verified production
snapshot. Local staging is refreshed only deliberately so unfinished work is
not erased.

## Safe commands

```powershell
.\scripts\run_nightly_data_cycle.ps1
php scripts/refresh_local_from_snapshot.php --snapshot=PATH --target=bakerysf_stage_local
.\scripts\run_restore_drill.ps1 -Force
.\scripts\push_sftp_stage.ps1 -DryRun
.\scripts\push_sftp_stage.ps1
.\scripts\auto_push_watcher_ctl.ps1 status
.\scripts\create_release_candidate.ps1 -StagingTestedBy "NAME"
```

`push_local_to_prod.php` is legacy recovery tooling and is not exposed in the
menu or normal documentation. Whole staging/local database copies must never be
imported over production.

See [DATA_OPERATIONS_RUNBOOK.md](DATA_OPERATIONS_RUNBOOK.md) and
[PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md).
