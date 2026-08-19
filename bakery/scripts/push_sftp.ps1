# Push changed bakery files to DreamHost. Simple default: upload deltas and record history.
#
#   push.bat
#   .\scripts\push_sftp.ps1
#   .\scripts\push_sftp.ps1 -DryRun
#   .\scripts\push_sftp.ps1 -All
#
# Credentials: .env.sftp.live if present, else .env.sftp (see .env.sftp.live.example).
# Requires SFTP_TARGET=dreamhost-live. Refuses bakeryOS and staging.sourflour.org.
# Auto-push never calls this script; use push_sftp_stage.ps1 for staging.
# Schema SQL is never uploaded; changed .sql files are noted in the history log.
param(
    [switch]$DryRun,
    [switch]$All,
    [switch]$Confirm
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$historyDir = Join-Path $deployDir "pushes"
$historyLog = Join-Path $deployDir "PUSH_HISTORY.jsonl"
$lastDeployPath = Join-Path $deployDir "LAST_DEPLOY.json"
$liveEnvPreferred = Join-Path $bakeryRoot ".env.sftp.live"
$legacyEnvPath = Join-Path $bakeryRoot ".env.sftp"
$envSftpPath = if (Test-Path -LiteralPath $liveEnvPreferred) { $liveEnvPreferred } else { $legacyEnvPath }
$uploader = Join-Path $PSScriptRoot "sftp_upload.py"
$listFile = Join-Path $env:TEMP ("bakery_sftp_files_{0}.txt" -f [guid]::NewGuid().ToString("N"))

. (Join-Path $PSScriptRoot "deploy_manifest.ps1")

function Import-BakerySftpEnv {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return }
    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = $_.Trim()
        if (-not $line -or $line.StartsWith("#")) { return }
        $eq = $line.IndexOf("=")
        if ($eq -lt 1) { return }
        $name = $line.Substring(0, $eq).Trim()
        $value = $line.Substring($eq + 1).Trim()
        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        # Set via .NET API — avoids Env: provider duplicate-key crashes.
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

function Get-PythonLauncher {
    $localAppData = [Environment]::GetFolderPath('LocalApplicationData')
    $candidates = @(
        (Join-Path $localAppData 'Programs\Python\Launcher\py.exe'),
        (Join-Path $localAppData 'Programs\Python\Python314\python.exe'),
        (Join-Path $localAppData 'Programs\Python\Python313\python.exe'),
        (Join-Path $localAppData 'Programs\Python\Python312\python.exe'),
        'C:\Python314\python.exe',
        'C:\Python313\python.exe',
        'C:\Python312\python.exe'
    )
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    foreach ($name in @('py', 'python')) {
        try {
            $cmd = Get-Command $name -ErrorAction Stop
            $source = [string]$cmd.Source
            # Skip the WindowsApps alias stub (often breaks non-interactive runs).
            if ($source -match 'WindowsApps') { continue }
            if ($source -and (Test-Path -LiteralPath $source)) { return $source }
        } catch {
            # try next
        }
    }

    throw "Python not found. Install Python 3 and ensure 'py' or 'python' is on PATH."
}

function Get-BakeryGitCommit {
    param([string]$BakeryRoot)
    $repoRoot = $BakeryRoot
    while ($repoRoot -and -not (Test-Path (Join-Path $repoRoot ".git"))) {
        $parent = Split-Path $repoRoot -Parent
        if ($parent -eq $repoRoot) { break }
        $repoRoot = $parent
    }
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { return $null }
    if (-not (Test-Path (Join-Path $repoRoot ".git"))) { return $null }
    $commit = (& git -C $repoRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0) { return $null }
    return $commit
}

function Write-BakeryPushHistory {
    param(
        [string]$BakeryRoot,
        [string]$DeployDir,
        [string]$HistoryDir,
        [string]$HistoryLog,
        [string]$LastDeployPath,
        [string]$Method,
        [string]$Mode,
        [string[]]$UploadedFiles,
        [object[]]$SchemaChanges
    )

    New-Item -ItemType Directory -Force -Path $DeployDir | Out-Null
    New-Item -ItemType Directory -Force -Path $HistoryDir | Out-Null

    $stamp = Get-Date -Format "yyyyMMdd_HHmmss"
    $recordedAt = (Get-Date).ToUniversalTime().ToString("o")
    $gitCommit = Get-BakeryGitCommit -BakeryRoot $BakeryRoot
    $snapshot = Get-BakeryDeploySnapshot -BakeryRoot $BakeryRoot
    $schemaPaths = @($SchemaChanges | ForEach-Object { $_.path })

    $historyEntry = [ordered]@{
        recorded_at     = $recordedAt
        stamp           = $stamp
        method          = $Method
        mode            = $Mode
        host            = [Environment]::GetEnvironmentVariable('SFTP_HOST', 'Process')
        remote_root     = [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')
        git_commit      = $gitCommit
        file_count      = $UploadedFiles.Count
        files           = @($UploadedFiles)
        schema_noted    = $schemaPaths
    }

    $detailPath = Join-Path $HistoryDir ("push_{0}.json" -f $stamp)
    $historyEntry | ConvertTo-Json -Depth 6 | Set-Content -Path $detailPath -Encoding UTF8

    $line = ($historyEntry | ConvertTo-Json -Compress -Depth 6)
    Add-Content -Path $HistoryLog -Value $line -Encoding UTF8

    $baseline = [ordered]@{
        recorded_at    = $recordedAt
        method         = $Method
        mode           = $Mode
        push_stamp     = $stamp
        push_detail    = ("pushes/push_{0}.json" -f $stamp)
        uploaded_files = @($UploadedFiles)
        zip_name       = $null
        zip_path       = $null
        git_commit     = $gitCommit
        files          = $snapshot
    }
    $baseline | ConvertTo-Json -Depth 6 | Set-Content -Path $LastDeployPath -Encoding UTF8

    return $detailPath
}

foreach ($name in @("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD", "SFTP_REMOTE_ROOT", "SFTP_TARGET")) {
    [Environment]::SetEnvironmentVariable($name, $null, 'Process')
}
Import-BakerySftpEnv -Path $envSftpPath

$missingCreds = @()
foreach ($required in @("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD", "SFTP_REMOTE_ROOT")) {
    # Avoid Get-Item Env:... - on some Windows setups the Env: provider throws
    # "An item with the same key has already been added" when PATH/Path collide.
    $val = [Environment]::GetEnvironmentVariable($required, 'Process')
    if ([string]::IsNullOrWhiteSpace($val)) {
        $val = [Environment]::GetEnvironmentVariable($required)
    }
    if ([string]::IsNullOrWhiteSpace($val)) { $missingCreds += $required }
}
if ($missingCreds.Count -gt 0) {
    Write-Host "Missing SFTP settings: $($missingCreds -join ', ')"
    Write-Host "Copy .env.sftp.live.example to .env.sftp.live and fill in values."
    exit 1
}

$liveRoot = [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')
$liveUser = [Environment]::GetEnvironmentVariable('SFTP_USER', 'Process')
$liveTarget = [Environment]::GetEnvironmentVariable('SFTP_TARGET', 'Process')
if ([string]::IsNullOrWhiteSpace($liveTarget)) {
    [Environment]::SetEnvironmentVariable('SFTP_TARGET', 'dreamhost-live', 'Process')
    $liveTarget = 'dreamhost-live'
}
if ($liveTarget -ne 'dreamhost-live') {
    throw "Refusing: live SFTP_TARGET must be dreamhost-live."
}
if ($liveUser -eq 'bakeryOS') {
    throw "Refusing: live push cannot use bakeryOS (staging user)."
}
if ($liveRoot -match 'staging\.sourflour\.org') {
    throw "Refusing: live push cannot use staging.sourflour.org."
}
if ($liveRoot -notmatch 'bakery\.sourflour\.org/bake') {
    throw "Refusing: live remote root must be bakery.sourflour.org/bake."
}

$pythonCheck = Get-PythonLauncher
$checkArgs = @()
if ((Split-Path $pythonCheck -Leaf) -in @("py.exe", "py")) { $checkArgs += "-3" }
$checkArgs += @($uploader, "--local-root", $bakeryRoot, "--check-target")
& $pythonCheck @checkArgs
if ($LASTEXITCODE -ne 0) { throw "Live SFTP target check failed (exit $LASTEXITCODE)." }

# Serialize with the background worker so UI Sync and hooks cannot corrupt LAST_DEPLOY.json.
$lockPath = Join-Path $deployDir ".push_lock"
$ownsLock = $false
$lockDeadline = (Get-Date).AddMinutes(3)
while (Test-Path -LiteralPath $lockPath) {
    try {
        $lock = Get-Content -LiteralPath $lockPath -Raw -Encoding UTF8 | ConvertFrom-Json
        $lockPid = [int]$lock.pid
        if ($lockPid -gt 0 -and $lockPid -eq $PID) { break }
        if ($lockPid -gt 0 -and -not (Get-Process -Id $lockPid -ErrorAction SilentlyContinue)) {
            Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
            break
        }
    } catch {
        Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
        break
    }
    if ((Get-Date) -gt $lockDeadline) {
        Write-Host "Another push is still running (lock timeout). Try Sync again in a minute."
        exit 1
    }
    Start-Sleep -Seconds 2
}
if (-not (Test-Path -LiteralPath $lockPath)) {
    New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
    @{ pid = $PID; at = (Get-Date).ToUniversalTime().ToString("o"); source = "push_sftp" } |
        ConvertTo-Json -Compress |
        Set-Content -Path $lockPath -Encoding UTF8
    $ownsLock = $true
}

try {

$baseline = Read-BakeryDeployState -Path $lastDeployPath
$sinceUtc = [datetime]::MinValue
if ($baseline -and $baseline.recorded_at) {
    try { $sinceUtc = [datetime]::Parse($baseline.recorded_at).ToUniversalTime() } catch { }
}

# Include root web files edited after the last push (covers new pages like test_upload.php)
$deployFiles = @(Get-BakeryDeployFileList -BakeryRoot $bakeryRoot -AlsoIncludeRootModifiedAfterUtc $sinceUtc)
$currentSnapshot = Get-BakeryDeploySnapshot -BakeryRoot $bakeryRoot -AlsoIncludeRootModifiedAfterUtc $sinceUtc
$baselineMap = ConvertTo-BakeryDeployBaselineMap -BaselineFiles $(if ($baseline) { $baseline.files } else { $null })

if ($All -or -not $baseline) {
    $toUpload = @($deployFiles)
    $mode = if ($All) { "all" } else { "all (first push / no baseline)" }
} else {
    $toUpload = @(Get-BakeryChangedDeployPaths -CurrentSnapshot $currentSnapshot -BaselineMap $baselineMap)
    $mode = "changed"
}

$schemaChanges = @(Get-BakerySchemaSqlChanges -BakeryRoot $bakeryRoot -SinceUtc $sinceUtc)

Write-Host ""
Write-Host ("Push -> {0}  ({1})" -f [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process'), $mode)
if ($toUpload.Count -eq 0) {
    Write-Host "Nothing to upload."
    if ($schemaChanges.Count -gt 0) {
        Write-Host "Schema notes (not applied):"
        foreach ($sql in $schemaChanges) { Write-Host "  - $($sql.path)" }
    }
    Write-Host ""
    return
}

Write-Host "Uploading $($toUpload.Count) file(s):"
foreach ($rel in $toUpload) { Write-Host "  - $rel" }
if ($schemaChanges.Count -gt 0) {
    Write-Host "Schema notes (not uploaded/applied):"
    foreach ($sql in $schemaChanges) { Write-Host "  - $($sql.path)" }
}

if ($Confirm -and -not $DryRun) {
    $answer = Read-Host "Continue? Type YES"
    if ($answer -ne "YES") {
        Write-Host "Cancelled."
        exit 1
    }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllLines($listFile, $toUpload, $utf8NoBom)

$python = Get-PythonLauncher
$pyArgs = @()
if ((Split-Path $python -Leaf) -in @("py.exe", "py")) { $pyArgs += "-3" }
$pyArgs += @($uploader, "--local-root", $bakeryRoot, "--list", $listFile)
if ($DryRun) { $pyArgs += "--dry-run" }

try {
    & $python @pyArgs
    if ($LASTEXITCODE -ne 0) { throw "SFTP upload failed (exit $LASTEXITCODE)." }
} finally {
    Remove-Item -Force -ErrorAction SilentlyContinue $listFile
}

if ($DryRun) {
    Write-Host "Dry run only - nothing recorded."
    return
}

$detailPath = Write-BakeryPushHistory `
    -BakeryRoot $bakeryRoot `
    -DeployDir $deployDir `
    -HistoryDir $historyDir `
    -HistoryLog $historyLog `
    -LastDeployPath $lastDeployPath `
    -Method "sftp" `
    -Mode $mode `
    -UploadedFiles $toUpload `
    -SchemaChanges $schemaChanges

Write-Host ""
Write-Host "Recorded: $detailPath"
Write-Host "History:  $historyLog"
Write-Host "Live:     https://bakery.sourflour.org/bake/login.php"
Write-Host ""

} finally {
    if ($ownsLock -and (Test-Path -LiteralPath $lockPath)) {
        try {
            $lock = Get-Content -LiteralPath $lockPath -Raw -Encoding UTF8 | ConvertFrom-Json
            if ([int]$lock.pid -eq $PID) {
                Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
            }
        } catch {
            Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
        }
    }
}