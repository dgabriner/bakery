param(
    [switch]$Execute
)

$ErrorActionPreference = 'Stop'
$bakeryRoot = Split-Path $PSScriptRoot -Parent
$repoRoot = Split-Path $bakeryRoot -Parent
. (Join-Path $PSScriptRoot 'deploy_manifest.ps1')

$deployFiles = @(Get-BakeryDeployFileList -BakeryRoot $bakeryRoot)
$dirty = @(& git -C $repoRoot status --porcelain --untracked-files=all -- @($deployFiles | ForEach-Object { "bakery/$_" }))
if ($dirty.Count -gt 0) { throw "Deployable working tree is dirty. Commit or set aside changes first:`n$($dirty -join "`n")" }

Write-Host "Direct local -> Live preflight: $($deployFiles.Count) deployable files."
if (-not $Execute) { Write-Host 'DRY-RUN ONLY. Live production was not contacted or changed.'; exit 0 }
if ($env:BAKERY_ENABLE_LIVE_PROMOTION -ne 'YES') { throw 'Live promotion locked. Set BAKERY_ENABLE_LIVE_PROMOTION=YES for this approved run.' }

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) { throw 'PHP CLI is required to take the production backup.' }
$before = @(Get-ChildItem (Join-Path $bakeryRoot 'storage\dumps') -Filter 'bakerysf_prod_backup_*.sql' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1)
& $php.Source (Join-Path $bakeryRoot 'scripts\backup_production.php') '--label=before_local_direct'
if ($LASTEXITCODE -ne 0) { throw 'Production backup failed. Live was not changed.' }
$after = @(Get-ChildItem (Join-Path $bakeryRoot 'storage\dumps') -Filter 'bakerysf_prod_backup_*.sql' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTimeUtc -Descending | Select-Object -First 1)
if ($after.Count -eq 0 -or ($before.Count -gt 0 -and $after[0].FullName -eq $before[0].FullName)) { throw 'Could not verify a new production backup. Live was not changed.' }

& powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'push_sftp.ps1') -All -Confirm -ConfirmText YES
if ($LASTEXITCODE -ne 0) { throw 'Live upload failed. The backup remains available.' }
$promotionDir = Join-Path $bakeryRoot 'storage\deploy\promotions'
New-Item -ItemType Directory -Force -Path $promotionDir | Out-Null
[ordered]@{
    release_id = "local-direct-$((Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ'))"
    git_commit = (& git -C $repoRoot rev-parse HEAD).Trim()
    promoted_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    backup_file = $after[0].FullName
    live_changed = $true
    method = 'promote_local_direct.ps1'
} | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $promotionDir "local-direct-$((Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')).json") -Encoding UTF8
Write-Host "Direct local -> Live promotion complete. Backup: $($after[0].FullName)"
