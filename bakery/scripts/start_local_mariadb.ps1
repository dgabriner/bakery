# Start Scoop MariaDB as a user process (no admin / no Windows service)
$ErrorActionPreference = "Stop"
$env:Path = "$env:USERPROFILE\scoop\shims;" + $env:Path

$mysqld = Get-Command mysqld.exe -ErrorAction SilentlyContinue
if (-not $mysqld) {
    Write-Error "mysqld.exe not found. Install with: scoop install mariadb"
}

$listening = netstat -an | Select-String "LISTENING" | Select-String ":3306"
if ($listening) {
    Write-Host "MariaDB already listening on 3306"
    exit 0
}

$logDir = Join-Path $env:LOCALAPPDATA "sourflour-tools"
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$log = Join-Path $logDir "mysqld.log"

Write-Host "Starting mysqld --standalone --console (user process)..."
$proc = Start-Process -FilePath $mysqld.Source -ArgumentList "--standalone","--console" -WindowStyle Hidden -RedirectStandardError $log -PassThru
Start-Sleep -Seconds 3

$listening = netstat -an | Select-String "LISTENING" | Select-String ":3306"
if (-not $listening) {
    Write-Error "mysqld started (PID $($proc.Id)) but port 3306 is not listening. Check $log"
}

Write-Host "MariaDB running as PID $($proc.Id) on 3306"
Write-Host "Log: $log"
