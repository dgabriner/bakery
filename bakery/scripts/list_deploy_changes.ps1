# List deployable files changed since the last recorded production deploy.
# Usage:
#   .\scripts\list_deploy_changes.ps1
#   .\scripts\list_deploy_changes.ps1 -SinceLastBuild
param(
    [switch]$SinceLastBuild
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$lastDeployPath = Join-Path $deployDir "LAST_DEPLOY.json"
$lastBuiltPath = Join-Path $deployDir "LAST_BUILT.json"
$baselinePath = if ($SinceLastBuild -and (Test-Path $lastBuiltPath)) { $lastBuiltPath } else { $lastDeployPath }

. (Join-Path $PSScriptRoot "deploy_manifest.ps1")

function Read-DeployState {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    return Get-Content $Path -Raw | ConvertFrom-Json
}

function Get-GitChangedDeployFiles {
    param(
        [string]$RepoRoot,
        [string[]]$DeployFiles,
        [string]$SinceRef
    )

    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { return @() }
    if (-not (Test-Path (Join-Path $RepoRoot ".git"))) { return @() }

    $args = @('-C', $RepoRoot, 'diff', '--name-only')
    if ($SinceRef) {
        $args += @($SinceRef, 'HEAD')
    }
    $raw = & git @args 2>$null
    if (-not $raw) { return @() }

    $changed = @()
    foreach ($line in $raw) {
        $normalized = ($line -replace '\\', '/').Trim()
        if ($normalized.StartsWith('bakery/')) {
            $normalized = $normalized.Substring(7)
        }
        if ($DeployFiles -contains $normalized) {
            $changed += $normalized
        }
    }
    return $changed | Sort-Object -Unique
}

function Get-UncommittedDeployFiles {
    param(
        [string]$RepoRoot,
        [string[]]$DeployFiles
    )

    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { return @() }
    if (-not (Test-Path (Join-Path $RepoRoot ".git"))) { return @() }

    $raw = & git -C $RepoRoot status --porcelain 2>$null
    if (-not $raw) { return @() }

    $changed = @()
    foreach ($line in $raw) {
        if ($line.Length -lt 4) { continue }
        $path = $line.Substring(3).Trim('"')
        $normalized = ($path -replace '\\', '/')
        if ($normalized.StartsWith('bakery/')) {
            $normalized = $normalized.Substring(7)
        }
        if ($DeployFiles -contains $normalized) {
            $changed += $normalized
        }
    }
    return $changed | Sort-Object -Unique
}

function Compare-DeploySnapshots {
    param(
        [hashtable]$Current,
        [hashtable]$Baseline
    )

    $changed = New-Object System.Collections.Generic.List[string]
    foreach ($path in $Current.Keys) {
        $cur = $Current[$path]
        if (-not $Baseline.ContainsKey($path)) {
            $changed.Add("$path  (new since last deploy)")
            continue
        }
        $base = $Baseline[$path]
        if ($cur.size -ne $base.size -or $cur.mtime -ne $base.mtime) {
            $changed.Add($path)
        }
    }
    foreach ($path in $Baseline.Keys) {
        if (-not $Current.ContainsKey($path)) {
            $changed.Add("$path  (removed locally)")
        }
    }
    return $changed
}

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null

$deployFiles = @(Get-BakeryDeployFileList -BakeryRoot $bakeryRoot)
$currentSnapshot = Get-BakeryDeploySnapshot -BakeryRoot $bakeryRoot
$baseline = Read-DeployState -Path $baselinePath

$repoRoot = $bakeryRoot
while ($repoRoot -and -not (Test-Path (Join-Path $repoRoot ".git"))) {
    $parent = Split-Path $repoRoot -Parent
    if ($parent -eq $repoRoot) { break }
    $repoRoot = $parent
}

Write-Host ""
Write-Host "Production deploy file check"
Write-Host "  Bakery root: $bakeryRoot"
Write-Host "  Deployable files tracked: $($deployFiles.Count)"
Write-Host ""

$snapshotChanges = @()
if ($baseline -and $baseline.files) {
    $baselineMap = @{}
    foreach ($entry in $baseline.files.PSObject.Properties) {
        $baselineMap[$entry.Name] = @{
            size = [int64]$entry.Value.size
            mtime = [string]$entry.Value.mtime
        }
    }
    $snapshotChanges = @(Compare-DeploySnapshots -Current $currentSnapshot -Baseline $baselineMap)
}

$gitSince = @()
$gitUncommitted = @()
if ($baseline -and $baseline.git_commit -and (Get-Command git -ErrorAction SilentlyContinue)) {
    $gitSince = @(Get-GitChangedDeployFiles -RepoRoot $repoRoot -DeployFiles $deployFiles -SinceRef $baseline.git_commit)
}
$gitUncommitted = @(Get-UncommittedDeployFiles -RepoRoot $repoRoot -DeployFiles $deployFiles)

$allChanged = @($snapshotChanges + $gitSince + $gitUncommitted | Sort-Object -Unique)

if (-not $baseline) {
    Write-Host "No LAST_DEPLOY.json yet."
    Write-Host "After your first upload, run:  .\scripts\record_deploy.ps1"
    Write-Host ""
    Write-Host "All deployable files ($($deployFiles.Count)) would be included in a full ZIP deploy."
    exit 0
}

Write-Host "Baseline: $(Split-Path $baselinePath -Leaf) from $($baseline.recorded_at)"
if ($baseline.zip_name) {
    Write-Host "Last ZIP: $($baseline.zip_name)"
}
Write-Host ""

if ($allChanged.Count -eq 0) {
    Write-Host "No deployable file changes detected since last recorded deploy."
    Write-Host "If production still looks stale, run  .\scripts\build_deploy_zip.ps1  anyway."
    exit 0
}

Write-Host "Changed deploy files ($($allChanged.Count)):"
foreach ($file in $allChanged) {
    Write-Host "  - $file"
}

Write-Host ""
Write-Host "Next steps:"
Write-Host "  1. Test locally"
Write-Host "  2. .\scripts\push_sftp.ps1 -DryRun"
Write-Host "  3. .\scripts\push_sftp.ps1"
Write-Host "  Or ZIP fallback: .\scripts\build_deploy_zip.ps1 then DreamHost File Manager + record_deploy.ps1"
Write-Host ""

exit 0
