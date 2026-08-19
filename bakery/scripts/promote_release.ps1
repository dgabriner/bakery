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
throw "Live execution is intentionally not enabled in this infrastructure phase. Use a separately authorized production-promotion mission."
