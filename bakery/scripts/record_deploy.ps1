# Record that a production deploy was completed (updates LAST_DEPLOY.json).
# Run this after uploading and extracting the deploy ZIP on DreamHost.
# Usage:
#   .\scripts\record_deploy.ps1
#   .\scripts\record_deploy.ps1 -ZipPath "C:\...\bakery_deploy_20260728_123456.zip"
param(
    [string]$ZipPath = ""
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$lastDeployPath = Join-Path $deployDir "LAST_DEPLOY.json"

. (Join-Path $PSScriptRoot "deploy_manifest.ps1")

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null

if ($ZipPath -eq "") {
    $latest = Get-ChildItem $deployDir -Filter "bakery_deploy_*.zip" -ErrorAction SilentlyContinue |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
    if ($latest) {
        $ZipPath = $latest.FullName
    }
}

$gitCommit = $null
$repoRoot = $bakeryRoot
while ($repoRoot -and -not (Test-Path (Join-Path $repoRoot ".git"))) {
    $parent = Split-Path $repoRoot -Parent
    if ($parent -eq $repoRoot) { break }
    $repoRoot = $parent
}
if ((Get-Command git -ErrorAction SilentlyContinue) -and (Test-Path (Join-Path $repoRoot ".git"))) {
    $gitCommit = (& git -C $repoRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0) { $gitCommit = $null }
}

$snapshot = Get-BakeryDeploySnapshot -BakeryRoot $bakeryRoot
$record = [ordered]@{
    recorded_at = (Get-Date).ToUniversalTime().ToString('o')
    zip_name = if ($ZipPath) { Split-Path $ZipPath -Leaf } else { $null }
    zip_path = if ($ZipPath) { $ZipPath } else { $null }
    git_commit = $gitCommit
    files = $snapshot
}

$record | ConvertTo-Json -Depth 6 | Set-Content -Path $lastDeployPath -Encoding UTF8

Write-Host ""
Write-Host "Recorded production deploy baseline:"
Write-Host "  $lastDeployPath"
if ($ZipPath) {
    Write-Host "  ZIP: $(Split-Path $ZipPath -Leaf)"
}
if ($gitCommit) {
    Write-Host "  Git commit: $gitCommit"
}
Write-Host "  Files tracked: $($snapshot.Count)"
Write-Host ""
Write-Host "Next time, run  .\scripts\list_deploy_changes.ps1  to see only what changed."
Write-Host ""
