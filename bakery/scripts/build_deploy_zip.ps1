# Build a production deploy ZIP for DreamHost File Manager upload (no FTP client needed).

# Usage:

#   cd C:\Users\918825809\CascadeProjects\windsurf-project\bakery

#   .\scripts\build_deploy_zip.ps1

#

# Then: DreamHost panel -> Files -> bakery.sourflour.org -> Upload zip -> Extract

$ErrorActionPreference = "Stop"



$bakeryRoot = Split-Path $PSScriptRoot -Parent

$outDir = Join-Path $bakeryRoot "storage\deploy"

$stamp = Get-Date -Format "yyyyMMdd_HHmmss"

$staging = Join-Path $env:TEMP "bakery_deploy_staging_$stamp"

$zipPath = Join-Path $outDir "bakery_deploy_$stamp.zip"



. (Join-Path $PSScriptRoot "deploy_manifest.ps1")



function Write-DeployZip {

    param(

        [Parameter(Mandatory = $true)][string]$SourceDir,

        [Parameter(Mandatory = $true)][string]$DestinationZip

    )



    Add-Type -AssemblyName System.IO.Compression

    Add-Type -AssemblyName System.IO.Compression.FileSystem



    if (Test-Path $DestinationZip) {

        Remove-Item $DestinationZip -Force

    }



    $zip = [System.IO.Compression.ZipFile]::Open(

        $DestinationZip,

        [System.IO.Compression.ZipArchiveMode]::Create

    )

    try {

        Get-ChildItem $SourceDir -Recurse -File | ForEach-Object {

            $rel = $_.FullName.Substring($SourceDir.Length).TrimStart('\').Replace('\', '/')

            $entry = $zip.CreateEntry($rel, [System.IO.Compression.CompressionLevel]::Optimal)

            $entryStream = $entry.Open()

            try {

                $fileStream = [System.IO.File]::Open(

                    $_.FullName,

                    [System.IO.FileMode]::Open,

                    [System.IO.FileAccess]::Read,

                    [System.IO.FileShare]::ReadWrite

                )

                try {

                    $fileStream.CopyTo($entryStream)

                } finally {

                    $fileStream.Dispose()

                }

            } finally {

                $entryStream.Dispose()

            }

        }

    } finally {

        $zip.Dispose()

    }

}



New-Item -ItemType Directory -Force -Path $outDir | Out-Null

if (Test-Path $staging) { Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue }

New-Item -ItemType Directory -Force -Path $staging | Out-Null



$copied = 0

$skipped = 0

$manifest = New-Object System.Collections.Generic.List[string]



try {

    foreach ($file in (Get-BakeryDeployRootFiles)) {

        $src = Join-Path $bakeryRoot $file

        if (-not (Test-Path $src)) {

            Write-Warning "Missing (skipped): $file"

            $skipped++

            continue

        }

        $dest = Join-Path $staging $file

        $destDir = Split-Path $dest -Parent

        if ($destDir -and -not (Test-Path $destDir)) {

            New-Item -ItemType Directory -Force -Path $destDir | Out-Null

        }

        Copy-Item $src $dest -Force

        $manifest.Add($file)

        $copied++

    }



    foreach ($dir in (Get-BakeryDeployDirectories)) {

        $srcDir = Join-Path $bakeryRoot $dir

        if (-not (Test-Path $srcDir)) {

            Write-Warning "Missing directory: $dir"

            continue

        }

        $destDir = Join-Path $staging $dir

        New-Item -ItemType Directory -Force -Path $destDir | Out-Null

        Get-ChildItem $srcDir -Recurse -File | ForEach-Object {

            if (Test-BakeryDeploySkipFile $_.Name) {

                $skipped++

                return

            }

            $rel = $_.FullName.Substring($srcDir.Length).TrimStart('\')

            $target = Join-Path $destDir $rel

            $targetParent = Split-Path $target -Parent

            if (-not (Test-Path $targetParent)) {

                New-Item -ItemType Directory -Force -Path $targetParent | Out-Null

            }

            Copy-Item $_.FullName $target -Force

            $manifest.Add(("$dir/$rel" -replace '\\', '/'))

            $copied++

        }

    }



    foreach ($opt in (Get-BakeryDeployOptionalPaths)) {

        $srcDir = Join-Path $bakeryRoot $opt.Path

        if (-not (Test-Path $srcDir)) {

            if ($opt.Required) { Write-Warning "Missing optional dir: $($opt.Path)" }

            continue

        }

        if ($opt.Files) {

            foreach ($f in $opt.Files) {

                $src = Join-Path $srcDir $f

                if (Test-Path $src) {

                    $dest = Join-Path $staging (Join-Path $opt.Path $f)

                    $destParent = Split-Path $dest -Parent

                    New-Item -ItemType Directory -Force -Path $destParent | Out-Null

                    Copy-Item $src $dest -Force

                    $manifest.Add("$($opt.Path -replace '\\','/')/$f")

                    $copied++

                }

            }

            continue

        }

        $destDir = Join-Path $staging $opt.Path

        Copy-Item $srcDir $destDir -Recurse -Force

        $manifest.Add("$($opt.Path -replace '\\','/')/ (folder)")

        $copied++

    }



    $logsDir = Join-Path $staging "logs"

    New-Item -ItemType Directory -Force -Path $logsDir | Out-Null

    $gitkeep = Join-Path $logsDir ".gitkeep"

    if (-not (Test-Path $gitkeep)) { Set-Content $gitkeep "" }

    $manifest.Add("logs/")



    $envTemplate = @"

APP_ENV=production

APP_NAME=Sour Flour OS



DB_HOST=mysql.sourflour.org

DB_PORT=3306

DB_NAME=bakerysf

DB_USER=bakerysf

DB_PASS=PASTE_PRODUCTION_PASSWORD_HERE



# Application URL path where this deploy lives (must match the folder URL).
# Example: /bakery/ or /6/ if you extracted the ZIP into a folder named 6.
BASE_URL=/bakery/

# Temporary: set to 1 to show PHP fatal errors on blank pages (turn off after debugging)
BAKERY_SHOW_ERRORS=0

MAIL_DRIVER=smtp

MAPS_ENABLED=true

"@

    Set-Content -Path (Join-Path $staging "env.production.template.txt") -Value $envTemplate -Encoding UTF8

    $manifest.Add("env.production.template.txt (rename to .env on server)")

    $sizeManifest = @{}
    foreach ($rel in (Get-BakeryDeployFileList -BakeryRoot $bakeryRoot)) {
        $fp = Get-BakeryDeployFileFingerprint -BakeryRoot $bakeryRoot -RelativePath $rel
        if ($null -ne $fp) {
            $sizeManifest[$rel] = [int]$fp.size
        }
    }
    $sizeManifest | ConvertTo-Json | Set-Content -Path (Join-Path $outDir "deploy_file_sizes.json") -Encoding UTF8
    Copy-Item (Join-Path $outDir "deploy_file_sizes.json") (Join-Path $staging "deploy_file_sizes.json") -Force
    $manifest.Add("deploy_file_sizes.json")

    Write-DeployZip -SourceDir $staging -DestinationZip $zipPath



    $manifestPath = Join-Path $outDir "manifest_$stamp.txt"

    $manifest | Set-Content $manifestPath -Encoding UTF8



    $builtState = [ordered]@{

        built_at = (Get-Date).ToUniversalTime().ToString('o')

        zip_name = Split-Path $zipPath -Leaf

        zip_path = $zipPath

        files = (Get-BakeryDeploySnapshot -BakeryRoot $bakeryRoot)

    }

    $builtState | ConvertTo-Json -Depth 6 | Set-Content -Path (Join-Path $outDir "LAST_BUILT.json") -Encoding UTF8

    Write-Host ""

    Write-Host "Deploy package ready:"

    Write-Host "  ZIP:       $zipPath"

    Write-Host "  Manifest:  $manifestPath"

    Write-Host "  Files:     $copied copied, $skipped skipped"

    Write-Host ""

    Write-Host "NEXT - DreamHost File Manager (no FTP):"

    Write-Host "  1. Panel -> Manage Websites -> bakery.sourflour.org -> Manage -> Files"

    Write-Host "  2. Open the bakery folder (where login.php lives)"

    Write-Host "  3. Upload: bakery_deploy_$stamp.zip"

    Write-Host "  4. Extract zip into that folder (overwrite when asked)"

    Write-Host "  5. Do NOT overwrite server .env unless you intend to"

    Write-Host "  6. Visit https://bakery.sourflour.org/bakery/login.php"

    Write-Host "  7. Run  .\scripts\record_deploy.ps1  after upload"

    Write-Host ""

    Write-Host "Do NOT upload your local .env file."

    Write-Host ""

}

finally {

    if (Test-Path $staging) {

        Remove-Item $staging -Recurse -Force -ErrorAction SilentlyContinue

    }

}



Get-ChildItem $outDir -Directory -Filter "staging_*" -ErrorAction SilentlyContinue |

    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

