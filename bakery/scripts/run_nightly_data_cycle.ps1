param(
    [string]$SnapshotPath = "",
    [switch]$RefreshLocalStage,
    [switch]$Force,
    [switch]$DryRun,
    [int]$RetainSnapshots = 14
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
. (Join-Path $PSScriptRoot "deploy_manifest.ps1")
$php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot
$nightlyDir = Join-Path $bakeryRoot "storage\dumps\nightly"
$opsDir = Join-Path $bakeryRoot "storage\operations"
$lockPath = Join-Path $opsDir "nightly-data-cycle.lock"
$logPath = Join-Path $opsDir "nightly-data-cycle.jsonl"

New-Item -ItemType Directory -Force -Path $nightlyDir, $opsDir | Out-Null
$lock = $null
$started = (Get-Date).ToUniversalTime()
$status = "failed"
$usedSnapshot = $null
$targets = @("bakerysf_local", "bakerysf_test")
if ($RefreshLocalStage) { $targets += "bakerysf_stage_local" }

try {
    try {
        $lock = [System.IO.File]::Open($lockPath, 'CreateNew', 'Write', 'None')
    } catch {
        throw "Nightly data cycle is already running (lock: $lockPath)."
    }

    if ([string]::IsNullOrWhiteSpace($SnapshotPath)) {
        $today = (Get-Date).Date
        $alreadyCaptured = Get-ChildItem $nightlyDir -Filter "live_*_nightly.sql.gz" -File -ErrorAction SilentlyContinue |
            Where-Object { $_.LastWriteTime.Date -eq $today } | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
        if ($alreadyCaptured -and -not $Force) {
            $usedSnapshot = $alreadyCaptured
            $status = "skipped-current"
            Write-Host "Nightly cycle already completed today: $($alreadyCaptured.Name)"
            return
        }
        if ($DryRun) {
            Write-Host "DRY-RUN: would take a read-only production snapshot."
            Write-Host "DRY-RUN: would refresh $($targets -join ', ') from that same snapshot."
            $status = "dry-run"
            return
        }
        & $php (Join-Path $PSScriptRoot "snapshot_production.php") "--label=nightly"
        if ($LASTEXITCODE -ne 0) { throw "Production snapshot failed (exit $LASTEXITCODE)." }
        $usedSnapshot = Get-ChildItem $nightlyDir -Filter "live_*_nightly.sql.gz" -File |
            Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
    } else {
        $resolved = Resolve-Path -LiteralPath $SnapshotPath -ErrorAction Stop
        $usedSnapshot = Get-Item -LiteralPath $resolved.Path
    }

    if (-not $usedSnapshot -or $usedSnapshot.Extension -ne '.gz') { throw "No verified .sql.gz snapshot was found." }
    $metaPath = $usedSnapshot.FullName -replace '\.sql\.gz$', '.json'
    if (-not (Test-Path -LiteralPath $metaPath)) { throw "Snapshot metadata is missing: $metaPath" }
    $meta = Get-Content -LiteralPath $metaPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $actualHash = (Get-FileHash -LiteralPath $usedSnapshot.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actualHash -ne ([string]$meta.sha256).ToLowerInvariant()) { throw "Snapshot SHA-256 does not match metadata." }

    foreach ($target in $targets) {
        Write-Host "Refreshing $target from $($usedSnapshot.Name)..."
        & $php (Join-Path $PSScriptRoot "refresh_local_from_snapshot.php") "--snapshot=$($usedSnapshot.FullName)" "--target=$target"
        if ($LASTEXITCODE -ne 0) { throw "Refresh failed for $target (exit $LASTEXITCODE)." }
    }

    if ([string]::IsNullOrWhiteSpace($SnapshotPath)) {
        $snapshots = @(Get-ChildItem $nightlyDir -Filter "live_*.sql.gz" -File | Sort-Object LastWriteTimeUtc -Descending)
        foreach ($old in ($snapshots | Select-Object -Skip ([Math]::Max(1, $RetainSnapshots)))) {
            $oldMeta = $old.FullName -replace '\.sql\.gz$', '.json'
            Remove-Item -LiteralPath $old.FullName -Force
            if (Test-Path -LiteralPath $oldMeta) { Remove-Item -LiteralPath $oldMeta -Force }
        }
    }

    $status = "success"
    Write-Host "Nightly data cycle complete. Local mirror and test use the same production snapshot."
} catch {
    Write-Error $_
    exit 1
} finally {
    if ($lock) { $lock.Dispose() }
    if (Test-Path -LiteralPath $lockPath) { Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue }
    [ordered]@{
        started_at_utc = $started.ToString('o')
        finished_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        status = $status
        snapshot = if ($usedSnapshot) { $usedSnapshot.FullName } else { $null }
        targets = $targets
        source = if ([string]::IsNullOrWhiteSpace($SnapshotPath)) { "production-read-only" } else { "provided-snapshot" }
    } | ConvertTo-Json -Compress | Add-Content -LiteralPath $logPath -Encoding UTF8
}
