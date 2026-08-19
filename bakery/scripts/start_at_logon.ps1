# Start the local Sour Flour stack after user sign-in (no administrator rights needed).
# The production pull is one-way and read-only against production; it recreates
# bakerysf_local as a fresh local mirror before starting the web server.
param(
    [switch]$SkipProductionSync
)

$ErrorActionPreference = 'Stop'
$bakery = Split-Path $PSScriptRoot -Parent
$projectRoot = Split-Path $bakery -Parent
$logPath = Join-Path $bakery 'logs\local-startup.log'

function Write-StartupLog([string]$message) {
    $line = '{0}  {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $message
    Add-Content -LiteralPath $logPath -Value $line
}

function Test-Listening([int]$port) {
    return [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
}

New-Item -ItemType Directory -Force -Path (Split-Path $logPath -Parent) | Out-Null
Write-StartupLog 'Startup task began.'

try {
    if (-not (Test-Listening 3306)) {
        Write-StartupLog 'Starting MariaDB.'
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'start_local_mariadb.ps1') | Out-Null
    } else {
        Write-StartupLog 'MariaDB is already listening on 3306.'
    }

    $deadline = (Get-Date).AddSeconds(20)
    while (-not (Test-Listening 3306) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 1
    }
    if (-not (Test-Listening 3306)) {
        throw 'MariaDB did not start within 20 seconds.'
    }

    if ($SkipProductionSync) {
        Write-StartupLog 'Production mirror sync skipped by parameter.'
    } else {
        Write-StartupLog 'Refreshing bakerysf_local from production.'
        & 'C:\php\php.exe' (Join-Path $PSScriptRoot 'pull_prod_to_local.php') 2>&1 |
            ForEach-Object { Write-StartupLog ([string]$_) }
        if ($LASTEXITCODE -ne 0) {
            throw "Production mirror pull failed with exit code $LASTEXITCODE."
        }
        Write-StartupLog 'Production mirror refresh completed.'
    }
} catch {
    Write-StartupLog ('Startup preparation failed: ' + $_.Exception.Message)
} finally {
    if (-not (Test-Listening 8080)) {
        Write-StartupLog 'Starting PHP server on 127.0.0.1:8080.'
        Start-Process -FilePath 'C:\php\php.exe' -ArgumentList @('-S', '127.0.0.1:8080', '-t', 'bakery') -WorkingDirectory $projectRoot -WindowStyle Hidden
    } else {
        Write-StartupLog 'PHP server is already listening on 8080.'
    }
    Write-StartupLog 'Startup task finished.'
}
