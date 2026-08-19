# Fail-closed, local-only reset/migration/lint/regression gate. Never deploys.
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
$php = 'php'

& $php (Join-Path $root 'scripts\verify_local_env.php')
if ($LASTEXITCODE -ne 0) { throw 'Local environment verification failed.' }
& $php (Join-Path $root 'scripts\setup_local_db.php') --reset --force-reset --database=bakerysf_test
if ($LASTEXITCODE -ne 0) { throw 'Isolated test database reset failed.' }
$env:DB_NAME = 'bakerysf_test'
$env:USE_PROD_DB = 'false'

$lintExclude = @('vendor', 'storage', 'tmp', 'tmp_catalog_repairs', 'breadeducation', 'domain_root')
$lintFiles = Get-ChildItem $root -Recurse -Filter '*.php' -File | Where-Object {
    $relative = $_.FullName.Substring($root.Length).TrimStart('\')
    ($lintExclude | ForEach-Object { $relative -notlike "$_\*" }) -notcontains $false -and
    $_.Name -notin @('db_test.php', 'test_db.php')
}
foreach ($file in $lintFiles) {
    & $php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($file.FullName)" }
}

Get-ChildItem (Join-Path $root 'tests') -Filter 'run_*.php' -File | Sort-Object Name | ForEach-Object {
    & $php $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "Test failed: $($_.Name)" }
}
Write-Host 'PASS  Local test gate completed without any production target.'
