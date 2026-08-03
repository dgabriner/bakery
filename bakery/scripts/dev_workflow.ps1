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
    Write-Host "DATABASE SYNC"
    Write-Host "  4) Pull production -> local  (safe, read-only on prod)"
    Write-Host "  5) Compare production vs local"
    Write-Host "  6) Backup production only"
    Write-Host "  7) Push local -> production   (preview / dry-run)"
    Write-Host "  8) Push local -> production   (EXECUTE - destructive)"
    Write-Host ""
    Write-Host "PRODUCTION FILES"
    Write-Host "  9) Show changed files to deploy"
    Write-Host " 10) Push changed files via SFTP (preview)"
    Write-Host " 11) Push changed files via SFTP"
    Write-Host " 12) Build production deploy ZIP (fallback)"
    Write-Host " 13) Record deploy complete (after manual ZIP upload)"
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
                Write-Host "This replaces local bakerysf_local with a copy of production data."
                if (-not (Confirm-Action "Continue?")) { continue }
                Invoke-BakeryPhp @((Join-Path $scriptsDir "pull_prod_to_local.php"))
            }
            '5' {
                Invoke-BakeryPhp @((Join-Path $scriptsDir "compare_prod_local.php"))
            }
            '6' {
                Invoke-BakeryPhp @((Join-Path $scriptsDir "backup_production.php"))
            }
            '7' {
                Invoke-BakeryPhp @((Join-Path $scriptsDir "push_local_to_prod.php"), '--dry-run')
            }
            '8' {
                Write-Host ""
                Write-Host "WARNING: This OVERWRITES production database tables with local data."
                Write-Host "A production backup is created first."
                Write-Host "Login users are NOT overwritten unless you choose include-auth."
                $includeAuth = Read-Host "Include auth tables too? (y/N)"
                if (-not (Confirm-Action "Push local database to production?")) { continue }
                $args = @((Join-Path $scriptsDir "push_local_to_prod.php"), '--confirm-push-to-production')
                if ($includeAuth -match '^[yY]') {
                    $args += '--include-auth'
                }
                Invoke-BakeryPhp $args
            }
            '9' {
                & (Join-Path $scriptsDir "list_deploy_changes.ps1")
            }
            '10' {
                & (Join-Path $scriptsDir "push_sftp.ps1") -DryRun
            }
            '11' {
                & (Join-Path $scriptsDir "push_sftp.ps1")
            }
            '12' {
                & (Join-Path $scriptsDir "build_deploy_zip.ps1")
            }
            '13' {
                & (Join-Path $scriptsDir "record_deploy.ps1")
            }
            '14' {
                Invoke-BakeryPhp @((Join-Path $bakeryRoot "tests\run_characterization.php"))
                Invoke-BakeryPhp @((Join-Path $bakeryRoot "tests\run_auth_tests.php"))
                Invoke-BakeryPhp @((Join-Path $bakeryRoot "tests\run_integrity_tests.php"))
                Write-Host ""
                Write-Host "All test suites finished."
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
