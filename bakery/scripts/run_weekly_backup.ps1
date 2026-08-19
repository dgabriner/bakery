param(
    [string]$SourceSnapshot = "",
    [string]$OffsiteDirectory = $env:BAKERY_OFFSITE_BACKUP_DIR,
    [int]$RetainWeeks = 12,
    [switch]$Force,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
. (Join-Path $PSScriptRoot "deploy_manifest.ps1")
$php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot
$nightlyDir = Join-Path $bakeryRoot "storage\dumps\nightly"
$weeklyDir = Join-Path $bakeryRoot "storage\dumps\weekly"
$opsDir = Join-Path $bakeryRoot "storage\operations"
$logPath = Join-Path $opsDir "weekly-backup.jsonl"
New-Item -ItemType Directory -Force -Path $nightlyDir, $weeklyDir, $opsDir | Out-Null

$started = (Get-Date).ToUniversalTime()
$status = "failed"
$source = $null
$destination = $null
try {
    $now = Get-Date
    $calendar = [System.Globalization.CultureInfo]::InvariantCulture.Calendar
    $weekNumber = $calendar.GetWeekOfYear($now, [System.Globalization.CalendarWeekRule]::FirstFourDayWeek, [DayOfWeek]::Monday)
    $week = "{0}-W{1:D2}" -f $now.Year, $weekNumber
    $existingWeek = Get-ChildItem $weeklyDir -Filter "$week`_*.sql.gz" -File -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($existingWeek -and -not $Force -and [string]::IsNullOrWhiteSpace($SourceSnapshot)) {
        $destination = $existingWeek.FullName
        $status = "skipped-current"
        Write-Host "Weekly backup already exists: $destination"
        return
    }
    if ([string]::IsNullOrWhiteSpace($SourceSnapshot)) {
        if ($DryRun) {
            Write-Host "DRY-RUN: would take a read-only production snapshot and preserve it weekly."
            $status = "dry-run"
            return
        }
        & $php (Join-Path $PSScriptRoot "snapshot_production.php") "--label=weekly"
        if ($LASTEXITCODE -ne 0) { throw "Weekly production snapshot failed (exit $LASTEXITCODE)." }
        $source = Get-ChildItem $nightlyDir -Filter "live_*_weekly.sql.gz" -File | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
    } else {
        $source = Get-Item -LiteralPath (Resolve-Path -LiteralPath $SourceSnapshot).Path
    }
    if (-not $source) { throw "No weekly source snapshot found." }
    $sourceMeta = $source.FullName -replace '\.sql\.gz$', '.json'
    if (-not (Test-Path -LiteralPath $sourceMeta)) { throw "Snapshot metadata is missing." }
    $meta = Get-Content -LiteralPath $sourceMeta -Raw -Encoding UTF8 | ConvertFrom-Json
    $hash = (Get-FileHash -LiteralPath $source.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne ([string]$meta.sha256).ToLowerInvariant()) { throw "Snapshot SHA-256 verification failed." }

    $destination = Join-Path $weeklyDir ("{0}_{1}" -f $week, $source.Name)
    $destinationMeta = $destination -replace '\.sql\.gz$', '.json'
    if (-not (Test-Path -LiteralPath $destination)) {
        Copy-Item -LiteralPath $source.FullName -Destination $destination
        Copy-Item -LiteralPath $sourceMeta -Destination $destinationMeta
        (Get-Item -LiteralPath $destination).IsReadOnly = $true
        (Get-Item -LiteralPath $destinationMeta).IsReadOnly = $true
    }

    if (-not [string]::IsNullOrWhiteSpace($OffsiteDirectory)) {
        New-Item -ItemType Directory -Force -Path $OffsiteDirectory | Out-Null
        Copy-Item -LiteralPath $destination, $destinationMeta -Destination $OffsiteDirectory -Force
    }

    $weekly = @(Get-ChildItem $weeklyDir -Filter "*.sql.gz" -File | Sort-Object LastWriteTimeUtc -Descending)
    foreach ($old in ($weekly | Select-Object -Skip ([Math]::Max(1, $RetainWeeks)))) {
        $oldMeta = $old.FullName -replace '\.sql\.gz$', '.json'
        $old.IsReadOnly = $false
        Remove-Item -LiteralPath $old.FullName -Force
        if (Test-Path -LiteralPath $oldMeta) { (Get-Item $oldMeta).IsReadOnly = $false; Remove-Item -LiteralPath $oldMeta -Force }
    }
    $status = "success"
    Write-Host "Weekly immutable local backup verified: $destination"
} catch {
    Write-Error $_
    exit 1
} finally {
    [ordered]@{
        started_at_utc = $started.ToString('o')
        finished_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        status = $status
        source = if ($source) { $source.FullName } else { $null }
        destination = $destination
        offsite = if ([string]::IsNullOrWhiteSpace($OffsiteDirectory)) { $null } else { $OffsiteDirectory }
    } | ConvertTo-Json -Compress | Add-Content -LiteralPath $logPath -Encoding UTF8
}
