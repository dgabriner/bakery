param(
    [Parameter(Mandatory=$true)][string]$Candidate,
    [switch]$Execute,
    [string]$ConfirmReleaseId = ""
)

$ErrorActionPreference = "Stop"
$bakeryRoot = Split-Path $PSScriptRoot -Parent
. (Join-Path $PSScriptRoot 'deploy_manifest.ps1')
$repoRoot = Get-BakeryRepoRoot -BakeryRoot $bakeryRoot
$candidatePath = (Resolve-Path -LiteralPath $Candidate -ErrorAction Stop).Path
$release = Read-BakeryJsonFile -Path $candidatePath
$head = (& git -C $repoRoot rev-parse HEAD).Trim()
if ($release.production_status -ne 'not-promoted') { throw "Candidate is not in not-promoted state." }
$commit = [string]$release.git_commit
if ($commit -eq '') { throw "Candidate is missing git_commit." }
$files = @($release.files)
if ($files.Count -lt 50) { throw "Candidate is not a complete staging release ($($files.Count) files)." }

$liveHashes = Read-BakeryLiveHashIndex -BakeryRoot $bakeryRoot
$split = Select-BakeryChangedReleaseFiles -Files $files -LiveHashes $liveHashes
$toUpload = @($split.Changed)
Write-Host "Live already has $($split.Unchanged.Count) unchanged file(s); $($toUpload.Count) differ and will be uploaded."

$exportRoot = Join-Path $env:TEMP ("bakery_promote_{0}" -f [guid]::NewGuid().ToString('N'))
$listPath = $null
New-Item -ItemType Directory -Force -Path $exportRoot | Out-Null
try {
    if ($toUpload.Count -gt 0) {
        Restore-BakeryReleaseFiles -RepoRoot $repoRoot -BakeryRoot $bakeryRoot -Commit $commit -Files $toUpload -DestinationRoot $exportRoot
    }

    Write-Host "Release: $($release.release_id)"
    Write-Host "Commit:  $commit"
    Write-Host "Local HEAD: $head (not required to match; in-progress working tree is not uploaded)"
    Write-Host "Candidate files: $($files.Count)"
    Write-Host "Upload:  $($toUpload.Count) changed, $($split.Unchanged.Count) already on Live"
    Write-Host "Schema:  $(@($release.schema_changes).Count)"
    Write-Host "Staging: tested by $($release.staging_tested_by)"
    if (-not $Execute) {
        Write-Host "DRY-RUN ONLY. Live production was not contacted or changed."
        return
    }
    if ($env:BAKERY_ENABLE_LIVE_PROMOTION -ne 'YES' -or $ConfirmReleaseId -ne [string]$release.release_id) {
        throw "Live promotion locked. It requires BAKERY_ENABLE_LIVE_PROMOTION=YES and exact -ConfirmReleaseId."
    }

    if (@($release.schema_changes).Count -gt 0) {
        throw "Candidate contains schema changes. Apply and verify those migrations in a separate production promotion run first."
    }

    $statusAfter = $null
    if ($toUpload.Count -gt 0) {
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

        $listPath = Join-Path $env:TEMP ("bakery_promote_files_{0}.txt" -f [guid]::NewGuid().ToString('N'))
        $utf8NoBom = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllLines($listPath, @($toUpload | ForEach-Object { [string]$_.path }), $utf8NoBom)

        Write-Host "Step 2/2: Uploading $($toUpload.Count) changed file(s) to Live..."
        $pushScript = Join-Path $bakeryRoot 'scripts\push_sftp.ps1'
        & powershell -NoProfile -ExecutionPolicy Bypass -File $pushScript -Confirm -ConfirmText YES -UploadList $listPath -SourceRoot $exportRoot -RecordedCommit $commit
        if ($LASTEXITCODE -ne 0) { throw "Live file upload failed (exit $LASTEXITCODE). Keep the backup and investigate before retrying." }
    } else {
        Write-Host 'Live already matches this candidate. No files uploaded.'
    }

    Merge-BakeryLiveHashIndex -BakeryRoot $bakeryRoot -Files $files -GitCommit $commit -Source 'promote_release.ps1'

    $promotionDir = Join-Path $bakeryRoot 'storage\deploy\promotions'
    New-Item -ItemType Directory -Force -Path $promotionDir | Out-Null
    $promotion = [ordered]@{
        release_id = [string]$release.release_id
        git_commit = $commit
        local_head = $head
        promoted_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        backup_file = if ($statusAfter) { [string]$statusAfter[0].FullName } else { $null }
        live_changed = ($toUpload.Count -gt 0)
        uploaded_count = $toUpload.Count
        unchanged_count = $split.Unchanged.Count
        method = 'promote_release.ps1'
        source = 'staging-tested-commit'
        ignored_dirty_working_tree = $true
    }
    Write-BakeryJsonFile -Path (Join-Path $promotionDir "$($release.release_id).json") -Object $promotion -Depth 6

    $release.production_status = 'promoted'
    $release | Add-Member -NotePropertyName promoted_at_utc -NotePropertyValue $promotion.promoted_at_utc -Force
    Write-BakeryJsonFile -Path $candidatePath -Object $release -Depth 8

    Write-Host "Live promotion complete for $($release.release_id)."
} finally {
    if (Test-Path -LiteralPath $exportRoot) {
        Remove-Item -LiteralPath $exportRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
    if ($listPath -and (Test-Path -LiteralPath $listPath)) {
        Remove-Item -LiteralPath $listPath -Force -ErrorAction SilentlyContinue
    }
}
