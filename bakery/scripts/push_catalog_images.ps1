# Upload app-owned catalog images without uploading unrelated workspace files.
param(
    [switch]$Distinct
)
$ErrorActionPreference = 'Stop'

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$envSftpPath = Join-Path $bakeryRoot '.env.sftp'
$uploader = Join-Path $PSScriptRoot 'sftp_upload.py'

if (-not (Test-Path -LiteralPath $envSftpPath)) {
    throw 'Missing bakery/.env.sftp'
}

foreach ($line in Get-Content -LiteralPath $envSftpPath) {
    $trim = $line.Trim()
    if (-not $trim -or $trim.StartsWith('#')) { continue }
    $eq = $trim.IndexOf('=')
    if ($eq -lt 1) { continue }
    $name = $trim.Substring(0, $eq).Trim()
    $value = $trim.Substring($eq + 1).Trim()
    if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    [Environment]::SetEnvironmentVariable($name, $value, 'Process')
}

$catalogDir = Join-Path $bakeryRoot 'uploads\product_photos\catalog'
$imageFiles = if ($Distinct) {
    Get-ChildItem -LiteralPath $catalogDir -File -Filter '*-distinct.jpg'
} else {
    Get-ChildItem -LiteralPath $catalogDir -File
}
$paths = @($imageFiles | ForEach-Object {
    $_.FullName.Substring($bakeryRoot.Length).TrimStart('\', '/').Replace('\', '/')
})
if ($paths.Count -eq 0) { throw 'No catalog images found.' }

$listFile = Join-Path ([System.IO.Path]::GetTempPath()) ('bakery_catalog_images_' + [guid]::NewGuid().ToString('N') + '.txt')
[System.IO.File]::WriteAllLines($listFile, $paths, (New-Object System.Text.UTF8Encoding($false)))
try {
    $python = Get-Command py -ErrorAction SilentlyContinue
    if ($python) {
        & $python.Source -3 $uploader --local-root $bakeryRoot --list $listFile
    } else {
        & python $uploader --local-root $bakeryRoot --list $listFile
    }
    if ($LASTEXITCODE -ne 0) { throw "Catalog image upload failed (exit $LASTEXITCODE)." }
} finally {
    Remove-Item -LiteralPath $listFile -Force -ErrorAction SilentlyContinue
}
