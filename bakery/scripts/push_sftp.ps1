# Push changed bakery files to DreamHost. Simple default: upload deltas and record history.
#
#   push.bat
#   .\scripts\push_sftp.ps1
#   .\scripts\push_sftp.ps1 -DryRun
#   .\scripts\push_sftp.ps1 -All
#   push_staging.bat
#   .\scripts\push_sftp.ps1 -Staging -Files closeout_radar.php,includes/closeout_radar.php
#
# Credentials: .env.sftp (see .env.sftp.example)
# Schema SQL is never uploaded; changed .sql files are noted in the history log.
# Default target is production. -Staging never writes LAST_DEPLOY.json.
param(
    [switch]$DryRun,
    [switch]$All,
    [switch]$Confirm,
    [switch]$Staging,
    [ValidateSet('production', 'staging')]
    [string]$Target = 'production',
    [string[]]$Files = @()
)

$ErrorActionPreference = "Stop"

if ($Staging) {
    $Target = 'staging'
}

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$historyDir = Join-Path $deployDir $(if ($Target -eq 'staging') { 'staging_pushes' } else { 'pushes' })
$historyLog = Join-Path $deployDir $(if ($Target -eq 'staging') { 'STAGING_PUSH_HISTORY.jsonl' } else { 'PUSH_HISTORY.jsonl' })
$lastDeployPath = Join-Path $deployDir $(if ($Target -eq 'staging') { 'LAST_STAGING_DEPLOY.json' } else { 'LAST_DEPLOY.json' })
$envSftpPath = Join-Path $bakeryRoot ".env.sftp"

$explicitFiles = @()
foreach ($item in $Files) {
    foreach ($part in @($item -split ',')) {
        $rel = $part.Trim().Replace('\', '/')
        if ($rel -ne '') { $explicitFiles += $rel }
    }
}
$uploader = Join-Path $PSScriptRoot "sftp_upload.py"
$listFile = Join-Path $env:TEMP ("bakery_sftp_files_{0}.txt" -f [guid]::NewGuid().ToString("N"))

. (Join-Path $PSScriptRoot "deploy_manifest.ps1")

function Import-BakerySftpEnv {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return }
    Get-Content $Path | ForEach-Object {
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
        Set-Item -Path "Env:$name" -Value $value
    }
}

function Get-PythonLauncher {
    foreach ($candidate in @("py", "python")) {
        $cmd = Get-Command $candidate -ErrorAction SilentlyContinue
        if ($cmd) { return $cmd.Source }
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
        host            = $env:SFTP_HOST
        remote_root     = $env:SFTP_REMOTE_ROOT
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

Import-BakerySftpEnv -Path $envSftpPath

$requiredCreds = @("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD")
if ($Target -eq 'staging') {
    $requiredCreds += "SFTP_STAGING_REMOTE_ROOT"
} else {
    $requiredCreds += "SFTP_REMOTE_ROOT"
}
$missingCreds = @()
foreach ($required in $requiredCreds) {
    $val = (Get-Item "Env:$required" -ErrorAction SilentlyContinue).Value
    if ([string]::IsNullOrWhiteSpace($val)) { $missingCreds += $required }
}
if ($missingCreds.Count -gt 0) {
    Write-Host "Missing SFTP settings: $($missingCreds -join ', ')"
    Write-Host "Copy .env.sftp.example to .env.sftp and fill in values."
    exit 1
}

if ($Target -eq 'staging') {
    $env:SFTP_REMOTE_ROOT = $env:SFTP_STAGING_REMOTE_ROOT.Trim().Trim('/').Replace('\', '/')
}

$baseline = Read-BakeryDeployState -Path $lastDeployPath
$sinceUtc = [datetime]::MinValue
if ($baseline -and $baseline.recorded_at) {
    try { $sinceUtc = [datetime]::Parse($baseline.recorded_at).ToUniversalTime() } catch { }
}

# Include root web files edited after the last push (covers new pages like test_upload.php)
$deployFiles = @(Get-BakeryDeployFileList -BakeryRoot $bakeryRoot -AlsoIncludeRootModifiedAfterUtc $sinceUtc)
$currentSnapshot = Get-BakeryDeploySnapshot -BakeryRoot $bakeryRoot -AlsoIncludeRootModifiedAfterUtc $sinceUtc
$baselineMap = ConvertTo-BakeryDeployBaselineMap -BaselineFiles $(if ($baseline) { $baseline.files } else { $null })

if ($explicitFiles.Count -gt 0) {
    $toUpload = @()
    foreach ($rel in $explicitFiles) {
        $local = Join-Path $bakeryRoot ($rel.Replace('/', '\'))
        if (-not (Test-Path -LiteralPath $local)) {
            throw "File not found under bakery root: $rel"
        }
        $toUpload += $rel
    }
    $mode = "files"
} elseif ($All -or -not $baseline) {
    if ($Target -eq 'staging' -and -not $All) {
        Write-Host "No staging baseline. Refusing a full-tree upload (that would overwrite SF 2.0)."
        Write-Host "Use: push_staging.bat -Files closeout_radar.php,includes/closeout_radar.php,includes/navigation_catalog.php"
        Write-Host "Or:  push_staging.bat -All -Confirm"
        exit 1
    }
    if ($Target -eq 'staging' -and $All -and -not $DryRun) {
        $Confirm = $true
    }
    $toUpload = @($deployFiles)
    $mode = if ($All) { "all" } else { "all (first push / no baseline)" }
} else {
    $toUpload = @(Get-BakeryChangedDeployPaths -CurrentSnapshot $currentSnapshot -BaselineMap $baselineMap)
    $mode = "changed"
}

$schemaChanges = @(Get-BakerySchemaSqlChanges -BakeryRoot $bakeryRoot -SinceUtc $sinceUtc)

Write-Host ""
Write-Host "Push -> $($env:SFTP_REMOTE_ROOT)  ($mode)"
if ($toUpload.Count -eq 0) {
    Write-Host "Nothing to upload."
    if ($schemaChanges.Count -gt 0) {
        Write-Host "Schema notes (not applied):"
        foreach ($sql in $schemaChanges) { Write-Host "  - $($sql.path)" }
    }
    Write-Host ""
    exit 0
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
    exit 0
}

$detailPath = Write-BakeryPushHistory `
    -BakeryRoot $bakeryRoot `
    -DeployDir $deployDir `
    -HistoryDir $historyDir `
    -HistoryLog $historyLog `
    -LastDeployPath $lastDeployPath `
    -Method $(if ($Target -eq 'staging') { 'sftp-staging' } else { 'sftp' }) `
    -Mode $mode `
    -UploadedFiles $toUpload `
    -SchemaChanges $schemaChanges

Write-Host ""
Write-Host "Recorded: $detailPath"
Write-Host "History:  $historyLog"
if ($Target -eq 'staging') {
    Write-Host "Staging:  https://staging.sourflour.org/closeout_radar.php"
} else {
    Write-Host "Live:     https://bakery.sourflour.org/bake/login.php"
}
Write-Host ""
