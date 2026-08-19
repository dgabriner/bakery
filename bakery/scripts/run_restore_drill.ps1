param(
    [string]$SnapshotPath = "",
    [int]$MinimumAgeDays = 28,
    [switch]$Force,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
. (Join-Path $PSScriptRoot "deploy_manifest.ps1")
$php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot
$opsDir = Join-Path $bakeryRoot "storage\operations"
$statePath = Join-Path $opsDir "restore-drill-state.json"
New-Item -ItemType Directory -Force -Path $opsDir | Out-Null

if (-not $Force -and (Test-Path -LiteralPath $statePath)) {
    try {
        $state = Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json
        $last = [datetime]::Parse([string]$state.verified_at_utc).ToUniversalTime()
        if ($last -gt (Get-Date).ToUniversalTime().AddDays(-$MinimumAgeDays)) {
            Write-Host "Restore drill is current (last passed $($last.ToString('o')))."
            exit 0
        }
    } catch { }
}
if ($DryRun) {
    Write-Host "DRY-RUN: would restore the newest verified backup into bakerysf_restore_drill, validate it, and drop it."
    exit 0
}
$args = @((Join-Path $PSScriptRoot "verify_backup_restore.php"))
if (-not [string]::IsNullOrWhiteSpace($SnapshotPath)) { $args += "--snapshot=$SnapshotPath" }
& $php @args
if ($LASTEXITCODE -ne 0) { throw "Restore drill failed (exit $LASTEXITCODE)." }
[ordered]@{ verified_at_utc = (Get-Date).ToUniversalTime().ToString('o'); snapshot = $SnapshotPath } |
    ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding UTF8
