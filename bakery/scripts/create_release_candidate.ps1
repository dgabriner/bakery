param(
    [Parameter(Mandatory=$true)][string]$StagingTestedBy,
    [string]$StagingManifest = "",
    [switch]$ValidateOnly
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
$candidateDir = Join-Path $bakeryRoot "storage\deploy\releases"
. (Join-Path $PSScriptRoot "deploy_manifest.ps1")
$repoRoot = Get-BakeryRepoRoot -BakeryRoot $bakeryRoot

$selected = Get-BakeryEffectiveStagingRelease -BakeryRoot $bakeryRoot -ExplicitPath $StagingManifest
$manifest = $selected.Manifest
$StagingManifest = [string]$selected.Path
if ($manifest.target -ne 'dreamhost-stage' -or $manifest.lint -ne 'ok') {
    throw "Staging manifest did not pass target/lint gates."
}

$stagingCommit = [string]$manifest.git_commit
if ($stagingCommit -eq '') { throw "Staging manifest is missing git_commit." }
$head = (& git -C $repoRoot rev-parse HEAD).Trim()
$manifestFiles = @($manifest.files)
if ($manifestFiles.Count -eq 0) { throw "Staging manifest has no file hashes." }
Write-Host "Using complete staging baseline plus $($selected.OverlayCount) later incremental file(s). Effective commit: $stagingCommit"

Restore-BakeryReleaseFiles -RepoRoot $repoRoot -BakeryRoot $bakeryRoot -Commit $stagingCommit -Files $manifestFiles

if ($ValidateOnly) {
    Write-Host "Release candidate validation passed. No candidate was created."
    Write-Host "Staging commit: $stagingCommit; local HEAD: $head (not required to match); files: $($manifestFiles.Count)"
    Write-Host "STAGING_COMMIT=$stagingCommit"
    exit 0
}

New-Item -ItemType Directory -Force -Path $candidateDir | Out-Null
$stamp = Get-Date -Format "yyyyMMdd_HHmmss"
$id = "candidate_${stamp}_$($stagingCommit.Substring(0,8))"
$path = Join-Path $candidateDir "$id.json"
$payload = [ordered]@{
    format = 1
    release_id = $id
    created_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    git_commit = $stagingCommit
    staging_git_commit = $stagingCommit
    staging_manifest = $StagingManifest
    staging_deployed_at_utc = $manifest.recorded_at
    staging_smoke_url = $manifest.smoke_url
    staging_tested_by = $StagingTestedBy
    schema_changes = @($manifest.schema_noted)
    files = $manifestFiles
    production_status = "not-promoted"
    local_head_at_creation = $head
    ignores_dirty_working_tree = $true
}
Write-BakeryJsonFile -Path $path -Object $payload -Depth 8
Write-Host "Immutable release candidate created: $path"
Write-Host "CANDIDATE_PATH=$path"
Write-Host "STAGING_COMMIT=$stagingCommit"
