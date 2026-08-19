param(
    [Parameter(Mandatory=$true)][string]$Candidate,
    [switch]$Execute,
    [string]$ConfirmReleaseId = ""
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $bakeryRoot -Parent
$candidatePath = (Resolve-Path -LiteralPath $Candidate -ErrorAction Stop).Path
$release = Get-Content -LiteralPath $candidatePath -Raw -Encoding UTF8 | ConvertFrom-Json
$head = (& git -C $repoRoot rev-parse HEAD).Trim()
if ($release.production_status -ne 'not-promoted') { throw "Candidate is not in not-promoted state." }
if ([string]$release.git_commit -ne $head) { throw "Candidate Git commit is not current HEAD." }
foreach ($entry in @($release.files)) {
    $full = Join-Path $bakeryRoot ([string]$entry.path -replace '/', '\')
    if (-not (Test-Path -LiteralPath $full)) { throw "Candidate file missing: $($entry.path)" }
    $hash = (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($hash -ne ([string]$entry.sha256).ToLowerInvariant()) { throw "Candidate hash changed: $($entry.path)" }
}

Write-Host "Release: $($release.release_id)"
Write-Host "Commit:  $($release.git_commit)"
Write-Host "Files:   $(@($release.files).Count)"
Write-Host "Schema:  $(@($release.schema_changes).Count)"
Write-Host "Staging: tested by $($release.staging_tested_by)"
if (-not $Execute) {
    Write-Host "DRY-RUN ONLY. Live production was not contacted or changed."
    Write-Host "Execution remains locked until a separately approved promotion run adds backup and rollback artifacts."
    exit 0
}
if ($env:BAKERY_ENABLE_LIVE_PROMOTION -ne 'YES' -or $ConfirmReleaseId -ne [string]$release.release_id) {
    throw "Live promotion locked. It requires BAKERY_ENABLE_LIVE_PROMOTION=YES and exact -ConfirmReleaseId."
}

# A candidate containing schema changes needs a separately reviewed migration
# run; never silently turn a file promotion into a database deployment.
if (@($release.schema_changes).Count -gt 0) {
    throw "Candidate contains schema changes. Apply and verify those migrations in a separate production promotion run first."
}

# Never let the general SFTP uploader pick up unrelated uncommitted work from
# another agent. The promotion source must be a clean deployable tree.
. (Join-Path $PSScriptRoot 'deploy_manifest.ps1')
$deployFiles = @(Get-BakeryDeployFileList -BakeryRoot $bakeryRoot)
$dirty = @(& git -C $repoRoot status --porcelain --untracked-files=all -- @($deployFiles | ForEach-Object { "bakery/$_" }))
if ($dirty.Count -gt 0) {
    throw "Deployable working tree is dirty. Commit or set aside these files before promoting:`n$($dirty -join "`n")"
}

$statusBefore = @(Get-ChildItem (Join-Path $bakeryRoot 'storage\dumps') -Filter 'bakerysf_prod_backup_*.sql' -File -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1)
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { throw 'PHP CLI is required to take the production backup.' }

Write-Host 'Step 1/2: Taking a fresh read-only production backup...'
& $php.Source (Join-Path $bakeryRoot 'scripts\backup_production.php') "--label=before_$($release.release_id)"
if ($LASTEXITCODE -ne 0) { throw "Production backup failed (exit $LASTEXITCODE). Live was not changed." }
$statusAfter = @(Get-ChildItem (Join-Path $bakeryRoot 'storage\dumps') -Filter 'bakerysf_prod_backup_*.sql' -File -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1)
if ($statusAfter.Count -eq 0 -or ($statusBefore.Count -gt 0 -and $statusAfter[0].FullName -eq $statusBefore[0].FullName)) {
    throw 'Could not verify a new production backup. Live was not changed.'
}

Write-Host 'Step 2/2: Uploading the approved candidate files to Live...'
$pushScript = Join-Path $bakeryRoot 'scripts\push_sftp.ps1'
& powershell -NoProfile -ExecutionPolicy Bypass -File $pushScript -Confirm
if ($LASTEXITCODE -ne 0) { throw "Live file upload failed (exit $LASTEXITCODE). Keep the backup and investigate before retrying." }

$promotionDir = Join-Path $bakeryRoot 'storage\deploy\promotions'
New-Item -ItemType Directory -Force -Path $promotionDir | Out-Null
$promotion = [ordered]@{
    release_id = [string]$release.release_id
    git_commit = [string]$release.git_commit
    promoted_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    backup_file = $statusAfter[0].FullName
    live_changed = $true
    method = 'promote_release.ps1'
}
$promotion | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $promotionDir "$($release.release_id).json") -Encoding UTF8
Write-Host "Live promotion complete for $($release.release_id)."
