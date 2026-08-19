# Push bakery deploy files to DreamHost STAGING only.
#
#   .\scripts\push_sftp_stage.ps1
#   .\scripts\push_sftp_stage.ps1 -All
#   .\scripts\push_sftp_stage.ps1 -All -DryRun
#   .\scripts\push_sftp_stage.ps1 -EnvOnly
#
# Credentials: gitignored .env.sftp.stage
# Never loads .env.sftp (live) or .env.sftp.live.
# Refuses bakery.sourflour.org/bake. Auto-push calls this script, not push_sftp.ps1.
# Do not re-upload remote .env on incremental auto-push (only -All or -EnvOnly).
param(
    [switch]$DryRun,
    [switch]$All,
    [switch]$EnvOnly
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy\stage"
$lockDir = Join-Path $bakeryRoot "storage\deploy"
$historyDir = Join-Path $deployDir "releases"
$historyLog = Join-Path $deployDir "PUSH_HISTORY.jsonl"
$lastDeployPath = Join-Path $deployDir "LAST_DEPLOY.json"
$envSftpPath = Join-Path $bakeryRoot ".env.sftp.stage"
$stagingEnvPath = Join-Path $bakeryRoot ".env.staging.dreamhost"
$uploader = Join-Path $PSScriptRoot "sftp_upload.py"
$liveEnvPath = Join-Path $bakeryRoot ".env.sftp"
$liveEnvPreferred = Join-Path $bakeryRoot ".env.sftp.live"
$listFile = Join-Path $env:TEMP ("bakery_sftp_stage_files_{0}.txt" -f [guid]::NewGuid().ToString("N"))

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
            if ($source -match 'WindowsApps') { continue }
            if ($source -and (Test-Path -LiteralPath $source)) { return $source }
        } catch { }
    }
    throw "Python not found. Install Python 3 and ensure py or python is on PATH."
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

function Invoke-BakerySftpPython {
    param([string[]]$ExtraArgs)
    $python = Get-PythonLauncher
    $pyArgs = @()
    if ((Split-Path $python -Leaf) -in @("py.exe", "py")) { $pyArgs += "-3" }
    $pyArgs += @($uploader, "--local-root", $bakeryRoot) + $ExtraArgs
    & $python @pyArgs
    if ($LASTEXITCODE -ne 0) { throw "Staging SFTP failed (exit $LASTEXITCODE)." }
}

function Assert-BakeryPhpLint {
    param([string[]]$RelativePaths)
    $php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot
    foreach ($rel in $RelativePaths) {
        if ($rel -notlike '*.php') { continue }
        $full = Join-Path $bakeryRoot $rel
        $out = & $php -l $full 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw ("PHP lint failed for {0}: {1}" -f $rel, (($out | Out-String).Trim()))
        }
    }
}

function Get-BakerySchemaChangePaths {
    param([object[]]$SchemaChanges)
    if ($null -eq $SchemaChanges -or $SchemaChanges.Count -eq 0) { return @() }
    return @($SchemaChanges | ForEach-Object { $_.path })
}

function Invoke-BakeryStagingSnapshot {
    param([object[]]$SchemaChanges, [bool]$IsDryRun)
    $paths = @(Get-BakerySchemaChangePaths -SchemaChanges $SchemaChanges)
    if ($paths.Count -eq 0) { return }
    Write-Host "Schema SQL changed:"
    foreach ($p in $paths) { Write-Host "  - $p" }
    if ($IsDryRun) {
        Write-Host "Dry run: skipped bakerysoftware snapshot."
        return
    }
    $php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot
    $snap = Join-Path $PSScriptRoot "snapshot_dreamhost_staging.php"
    Write-Host "Snapshot bakerysoftware before staging migrations..."
    & $php $snap "--confirm-snapshot-staging"
    if ($LASTEXITCODE -ne 0) {
        throw "Staging DB snapshot failed (bakerysoftware). Files were not uploaded. Production bakerysf was not targeted."
    }
}

function Invoke-BakeryStagingMigrations {
    param([object[]]$SchemaChanges, [bool]$IsDryRun)
    $paths = @(Get-BakerySchemaChangePaths -SchemaChanges $SchemaChanges)
    if ($paths.Count -eq 0) { return @() }
    if ($IsDryRun) {
        Write-Host "Dry run: skipped --mode=dreamhost-stage."
        return @()
    }
    $php = Get-BakeryPhpExecutable -BakeryRoot $bakeryRoot
    $mig = Join-Path $PSScriptRoot "run_migrations.php"
    Write-Host "Running migrations with --mode=dreamhost-stage (bakerysoftware only)..."
    $migOut = & $php $mig "--mode=dreamhost-stage" 2>&1
    Write-Host ($migOut | Out-String)
    if ($LASTEXITCODE -ne 0) {
        throw "Staging migrations failed. Production bakerysf was not targeted."
    }
    return $paths
}

function Assert-BakeryStagingSmoke {
    $url = "https://staging.sourflour.org/login.php"
    $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 45
    if ([int]$resp.StatusCode -ne 200) {
        throw "Staging smoke HTTP $($resp.StatusCode)"
    }
    $body = [string]$resp.Content
    if ($body -notmatch 'staging-env-banner') {
        throw "Staging smoke: login page missing staging-env-banner"
    }
    if ($body -notmatch 'bakerysoftware') {
        throw "Staging smoke: login page did not name bakerysoftware"
    }
    if ($body -notmatch 'STAGING') {
        throw "Staging smoke: login page missing STAGING marker"
    }
    Write-Host "Smoke OK: $url (STAGING + bakerysoftware)"
}

if (-not (Test-Path -LiteralPath $envSftpPath)) {
    throw "Missing staging SFTP env file. Copy .env.sftp.stage.example to .env.sftp.stage."
}

# Never inherit a live SFTP root from the process or .env.sftp.
foreach ($name in @("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD", "SFTP_REMOTE_ROOT", "SFTP_TARGET")) {
    [Environment]::SetEnvironmentVariable($name, $null, 'Process')
}
Import-BakerySftpEnv -Path $envSftpPath

$root = [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')
$user = [Environment]::GetEnvironmentVariable('SFTP_USER', 'Process')
$target = [Environment]::GetEnvironmentVariable('SFTP_TARGET', 'Process')
if ([string]::IsNullOrWhiteSpace($target)) {
    [Environment]::SetEnvironmentVariable('SFTP_TARGET', 'dreamhost-stage', 'Process')
    $target = 'dreamhost-stage'
}
if ($target -ne 'dreamhost-stage') {
    throw "Refusing: SFTP_TARGET must be dreamhost-stage."
}
if ($root -match 'bakery\.sourflour\.org/bake') {
    throw "Refusing: staging push cannot use bakery.sourflour.org/bake."
}
if ($root -notmatch 'staging\.sourflour\.org') {
    throw "Refusing: staging remote root must be staging.sourflour.org."
}
if ($user -ne 'bakeryOS') {
    throw "Refusing: staging SFTP user must be bakeryOS."
}

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
New-Item -ItemType Directory -Force -Path $historyDir | Out-Null
New-Item -ItemType Directory -Force -Path $lockDir | Out-Null

Write-Host ("Staging SFTP user={0} root={1} target={2}" -f $user, $root, $target)
if ($DryRun) { Write-Host "Dry run only." }

Invoke-BakerySftpPython -ExtraArgs @("--check-target")

$lockPath = Join-Path $lockDir ".push_lock"
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
    @{ pid = $PID; at = (Get-Date).ToUniversalTime().ToString("o"); source = "push_sftp_stage" } |
        ConvertTo-Json -Compress |
        Set-Content -Path $lockPath -Encoding UTF8
    $ownsLock = $true
}

try {

$uploadEnv = $EnvOnly -or $All
$toUpload = @()
$mode = "none"
$schemaChanges = @()
$hasDeployBaseline = $false

if (-not $EnvOnly) {
    $baseline = Read-BakeryDeployState -Path $lastDeployPath
    $hasDeployBaseline = [bool]$baseline
    $sinceUtc = [datetime]::MinValue
    if ($baseline -and $baseline.recorded_at) {
        try { $sinceUtc = [datetime]::Parse($baseline.recorded_at).ToUniversalTime() } catch { }
    }

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
    Write-Host ("Push -> {0}  ({1})" -f $root, $mode)
    if ($toUpload.Count -eq 0 -and -not $uploadEnv -and $schemaChanges.Count -eq 0) {
        Write-Host "Nothing to upload."
        Write-Host "Live auto-push was not used. Production bakery.sourflour.org/bake was not targeted."
        return
    }

    if ($toUpload.Count -gt 0) {
        Write-Host "Linting changed PHP..."
        Assert-BakeryPhpLint -RelativePaths $toUpload
        Write-Host "Uploading $($toUpload.Count) file(s):"
        foreach ($rel in $toUpload) { Write-Host "  - $rel" }
    }

    if ($schemaChanges.Count -gt 0 -and $hasDeployBaseline) {
        Invoke-BakeryStagingSnapshot -SchemaChanges $schemaChanges -IsDryRun $DryRun
    } elseif ($schemaChanges.Count -gt 0) {
        Write-Host "First staging baseline: schema SQL noted; hosted migrations skipped."
    }

    if ($toUpload.Count -gt 0) {
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllLines($listFile, $toUpload, $utf8NoBom)
        try {
            $extra = @("--list", $listFile)
            if ($DryRun) { $extra += "--dry-run" }
            Invoke-BakerySftpPython -ExtraArgs $extra
        } finally {
            Remove-Item -Force -ErrorAction SilentlyContinue $listFile
        }
    }
}

$envUploaded = $false
if ($uploadEnv -and (Test-Path -LiteralPath $stagingEnvPath)) {
    Write-Host "Uploading staging .env from .env.staging.dreamhost (not local .env)."
    $extra = @("--from-file", $stagingEnvPath, "--to-name", ".env")
    if ($DryRun) { $extra += "--dry-run" }
    Invoke-BakerySftpPython -ExtraArgs $extra
    $envUploaded = $true
} elseif ($uploadEnv) {
    Write-Host "No .env.staging.dreamhost present; skipped remote .env upload."
} else {
    Write-Host "Skipped remote .env upload (incremental auto-push)."
}

if ($DryRun) {
    Write-Host "Dry run only - nothing recorded."
    Write-Host "Staging URL: https://staging.sourflour.org/login.php"
    Write-Host "Live auto-push was not used. Production bakery.sourflour.org/bake was not targeted."
    return
}

$migrationsApplied = @()
if ($EnvOnly -eq $false -and $schemaChanges.Count -gt 0 -and $hasDeployBaseline) {
    $migrationsApplied = @(Invoke-BakeryStagingMigrations -SchemaChanges $schemaChanges -IsDryRun $false)
}

Assert-BakeryStagingSmoke

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$recordedAt = (Get-Date).ToUniversalTime().ToString("o")
$gitCommit = Get-BakeryGitCommit -BakeryRoot $bakeryRoot
$snapshot = Get-BakeryDeploySnapshot -BakeryRoot $bakeryRoot
$hashed = @()
foreach ($rel in $toUpload) {
    $full = Join-Path $bakeryRoot $rel
    if (-not (Test-Path -LiteralPath $full)) { continue }
    $hashed += [ordered]@{
        path = $rel
        sha256 = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash.ToLower()
    }
}

$release = [ordered]@{
    recorded_at = $recordedAt
    stamp = $stamp
    target = "dreamhost-stage"
    user = $user
    remote_root = $root
    git_commit = $gitCommit
    mode = $mode
    lint = "ok"
    env_uploaded = $envUploaded
    schema_noted = @($schemaChanges | ForEach-Object { $_.path })
    migrations_applied = $migrationsApplied
    file_count = $toUpload.Count
    files = $hashed
    smoke_url = "https://staging.sourflour.org/login.php"
}
$releasePath = Join-Path $historyDir ("release_{0}.json" -f $stamp)
$release | ConvertTo-Json -Depth 6 | Set-Content -Path $releasePath -Encoding UTF8
Add-Content -Path $historyLog -Value ($release | ConvertTo-Json -Compress -Depth 6) -Encoding UTF8

$baselineOut = [ordered]@{
    recorded_at = $recordedAt
    method = "sftp-stage"
    mode = $mode
    push_stamp = $stamp
    push_detail = ("releases/release_{0}.json" -f $stamp)
    uploaded_files = @($toUpload)
    git_commit = $gitCommit
    target = "dreamhost-stage"
    user = $user
    remote_root = $root
    files = $snapshot
}
$baselineOut | ConvertTo-Json -Depth 6 | Set-Content -Path $lastDeployPath -Encoding UTF8
$baselineOut | ConvertTo-Json -Depth 6 | Set-Content -Path (Join-Path $deployDir "LAST_STAGE_PUSH.json") -Encoding UTF8

Write-Host ""
Write-Host "Recorded: $releasePath"
Write-Host "History:  $historyLog"
Write-Host "Staging URL: https://staging.sourflour.org/login.php"
Write-Host "Live auto-push was not used. Production bakery.sourflour.org/bake was not targeted."
if ((Test-Path -LiteralPath $liveEnvPath) -or (Test-Path -LiteralPath $liveEnvPreferred)) {
    Write-Host "Live .env.sftp remains unused by this script."
}

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
