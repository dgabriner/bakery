param(
    [Parameter(Mandatory=$true)][string]$StagingTestedBy,
    [string]$StagingManifest = "",
    [switch]$ValidateOnly
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $bakeryRoot -Parent
$stageReleaseDir = Join-Path $bakeryRoot "storage\deploy\stage\releases"
$candidateDir = Join-Path $bakeryRoot "storage\deploy\releases"
. (Join-Path $PSScriptRoot "deploy_manifest.ps1")

if ([string]::IsNullOrWhiteSpace($StagingManifest)) {
    $latest = Get-ChildItem $stageReleaseDir -Filter "release_*.json" -File | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1
    if (-not $latest) { throw "No successful staging release manifest exists." }
    $StagingManifest = $latest.FullName
}
$manifest = Get-Content -LiteralPath $StagingManifest -Raw -Encoding UTF8 | ConvertFrom-Json
if ($manifest.target -ne 'dreamhost-stage' -or $manifest.lint -ne 'ok') { throw "Staging manifest did not pass target/lint gates." }
$head = (& git -C $repoRoot rev-parse HEAD).Trim()
if ($LASTEXITCODE -ne 0) { throw "Cannot resolve Git HEAD." }
$deployFiles = @(Get-BakeryDeployFileList -BakeryRoot $bakeryRoot)
$stagingCommit = [string]$manifest.git_commit
if ($stagingCommit -ne $head) {
    & git -C $repoRoot merge-base --is-ancestor $stagingCommit $head
    if ($LASTEXITCODE -ne 0) { throw "Staging commit is not an ancestor of current HEAD." }
    & git -C $repoRoot diff --quiet $stagingCommit $head -- @($deployFiles | ForEach-Object { "bakery/$_" })
    if ($LASTEXITCODE -ne 0) { throw "Deployable files changed after the staging manifest. Push the new commit to staging and retest." }
}

$dirty = @(& git -C $repoRoot status --porcelain --untracked-files=all -- @($deployFiles | ForEach-Object { "bakery/$_" }))
if ($dirty.Count -gt 0) { throw "Deployable working tree is dirty. No candidate can be created:`n$($dirty -join "`n")" }

$manifestFiles = @($manifest.files)
if ($manifestFiles.Count -eq 0) { throw "Staging manifest has no file hashes." }
foreach ($entry in $manifestFiles) {
    $full = Join-Path $bakeryRoot ([string]$entry.path -replace '/', '\')
    if (-not (Test-Path -LiteralPath $full)) { throw "Candidate file missing locally: $($entry.path)" }
    $hash = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne ([string]$entry.sha256).ToLowerInvariant()) { throw "Staging/local hash mismatch: $($entry.path)" }
}

if ($ValidateOnly) {
    Write-Host "Release candidate validation passed. No candidate was created."
    Write-Host "Staging commit: $stagingCommit; current commit: $head; files: $($manifestFiles.Count)"
    exit 0
}

New-Item -ItemType Directory -Force -Path $candidateDir | Out-Null
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$id = "candidate_${stamp}_$($head.Substring(0,8))"
$path = Join-Path $candidateDir "$id.json"
[ordered]@{
    format = 1
    release_id = $id
    created_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    git_commit = $head
    staging_git_commit = $stagingCommit
    staging_manifest = (Resolve-Path -LiteralPath $StagingManifest).Path
    staging_deployed_at_utc = $manifest.recorded_at
    staging_smoke_url = $manifest.smoke_url
    staging_tested_by = $StagingTestedBy
    schema_changes = @($manifest.schema_noted)
    files = $manifestFiles
    production_status = "not-promoted"
} | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath $path -Encoding UTF8
Write-Host "Immutable release candidate created: $path"
