# Interactive dev workflow menu for Bakery Manager.
# Usage:
#   Double-click dev_workflow.bat
#   .\dev_workflow.bat
#   powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\dev_workflow.ps1
$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$scriptsDir = $PSScriptRoot

. (Join-Path $scriptsDir "deploy_manifest.ps1")

$php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot

function Invoke-BakeryPhp {
    param([string[]]$Arguments)
    & $php @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed (exit $($LASTEXITCODE)): php $($Arguments -join ' ')"
    }
}

function Show-Header {
    Write-Host ""
    Write-Host "========================================"
    Write-Host "  Sour Flour OS - Dev Workflow"
    Write-Host "  $bakeryRoot"
    Write-Host "========================================"
    Write-Host ""
}

function Pause-ForUser {
    Write-Host ""
    Read-Host "Press Enter to return to menu"
}

function Confirm-Action {
    param([string]$Message)
    $answer = Read-Host "$Message Type YES to continue"
    return ($answer -eq 'YES')
}

while ($true) {
    Show-Header
    Write-Host "LOCAL"
    Write-Host "  1) Start MariaDB"
    Write-Host "  2) Start local PHP server"
    Write-Host "  3) Verify local environment"
    Write-Host ""
    Write-Host "PROTECTED DATA WORKFLOWS"
    Write-Host "  4) Run verified production snapshot -> local mirror + test"
    Write-Host "  5) Refresh local staging from latest verified snapshot"
    Write-Host "  6) Compare production vs local"
    Write-Host "  7) Create verified weekly production backup"
    Write-Host "  8) Run disposable backup restore drill"
    Write-Host ""
    Write-Host "STAGING FILES"
    Write-Host "  9) Show staging auto-push status"
    Write-Host " 10) Preview staging SFTP sync"
    Write-Host " 11) Sync changed files to staging"
    Write-Host " 12) Create immutable release candidate (after phone test)"
    Write-Host ""
    Write-Host "TESTS"
    Write-Host " 14) Run local test suite"
    Write-Host ""
    Write-Host "  0) Exit"
    Write-Host ""

    $choice = Read-Host "Choose an option"
    try {
        switch ($choice) {
            '1' {
                & (Join-Path $scriptsDir "start_local_mariadb.ps1")
            }
            '2' {
                & (Join-Path $scriptsDir "start_local_server.ps1")
            }
            '3' {
                Invoke-BakeryPhp @((Join-Path $scriptsDir "verify_local_env.php"))
            }
            '4' {
                Write-Host ""
                Write-Host "This reads production once, verifies the snapshot, then refreshes bakerysf_local and bakerysf_test."
                Write-Host "bakerysf_stage_local is deliberately preserved."
                if (-not (Confirm-Action "Continue?")) { continue }
                & (Join-Path $scriptsDir "run_nightly_data_cycle.ps1") -Force
            }
            '5' {
                $snapshot = Get-ChildItem (Join-Path $bakeryRoot "storage\dumps\nightly") -Filter "live_*.sql.gz" -File -ErrorAction SilentlyContinue |
                    Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
                if (-not $snapshot) { throw "No verified local snapshot found under storage\dumps\nightly." }
                Write-Host ""
                Write-Host "This replaces bakerysf_stage_local from $($snapshot.Name)."
                Write-Host "A checkpoint is created first. It does not contact production or staging."
                if (-not (Confirm-Action "Refresh local staging?")) { continue }
                Invoke-BakeryPhp @((Join-Path $scriptsDir "refresh_local_from_snapshot.php"), "--snapshot=$($snapshot.FullName)", '--target=bakerysf_stage_local')
            }
            '6' {
                Invoke-BakeryPhp @((Join-Path $scriptsDir "compare_prod_local.php"))
            }
            '7' {
                & (Join-Path $scriptsDir "run_weekly_backup.ps1") -Force
            }
            '8' {
                & (Join-Path $scriptsDir "run_restore_drill.ps1") -Force
            }
            '9' {
                & (Join-Path $scriptsDir "auto_push_watcher_ctl.ps1") status
            }
            '10' {
                & (Join-Path $scriptsDir "push_sftp_stage.ps1") -DryRun
            }
            '11' {
                & (Join-Path $scriptsDir "push_sftp_stage.ps1")
            }
            '12' {
                $tester = Read-Host "Who completed the staging phone test?"
                & (Join-Path $scriptsDir "create_release_candidate.ps1") -StagingTestedBy $tester
            }
            '14' {
                & (Join-Path $scriptsDir "run_local_test_gate.ps1")
                if ($LASTEXITCODE -ne 0) { throw "Local test gate failed (exit $LASTEXITCODE)." }
            }
            '0' { exit 0 }
            default {
                Write-Host "Unknown option: $choice"
            }
        }
    } catch {
        Write-Host ""
        Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
    }

    if ($choice -ne '0') {
        Pause-ForUser
    }
}
