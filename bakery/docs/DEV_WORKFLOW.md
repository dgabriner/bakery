# Dev Workflow — Local → Staging → Hosted Promotion

Start with `dev_workflow.bat`. The normal development path cannot push a local
database or local files directly to production.

## Daily loop

1. Work against `bakerysf_stage_local`.
2. Run the local test gate; tests use only `bakerysf_test`.
3. Auto-sync deployable edits to `https://staging.sourflour.org/`.
4. Test staging from the phone.
5. On Staging Manager, follow the one **Next** action. Apply an exact named
   database migration before files when the board says Live is behind; then
   wait for **Match** and a successful file worker.
6. Commit finished work to an additive Git branch at a sensible checkpoint.

Git stores application history. It does not contain database dumps or secrets,
and commit/push does not deploy production. Git HEAD, a clean local tree, and a
localhost PowerShell process are not required to promote tested Staging files.

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
```

`push_local_to_prod.php` is legacy recovery tooling and is not exposed in the
menu or normal documentation. Whole staging/local database copies must never be
imported over production.

Cloud agents (Grok Bot, Cursor on the web) do not use laptop SFTP. Give them
[GROK_AND_CLOUD_AGENT_DEPLOY.md](GROK_AND_CLOUD_AGENT_DEPLOY.md).

New root-level PHP pages must be listed in `scripts/deploy_manifest.ps1`
(`Get-BakeryDeployRootFiles`). Otherwise local Sync can succeed while Staging
returns 404 for that URL.

See [DATA_OPERATIONS_RUNBOOK.md](DATA_OPERATIONS_RUNBOOK.md) and
[PRODUCTION_DEPLOY.md](PRODUCTION_DEPLOY.md).
