# Debounced SFTP push. Many rapid triggers coalesce into one upload.
#
#   .\scripts\queue_sftp_push.ps1
#   .\scripts\queue_sftp_push.ps1 -Reason "afterFileEdit" -Path "driver.php"
#   .\scripts\queue_sftp_push.ps1 -DelaySeconds 20
param(
    [string]$Reason = "manual",
    [string]$Path = "",
    [int]$DelaySeconds = 20
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$requestPath = Join-Path $deployDir ".push_request"
$workerFlag = Join-Path $deployDir ".push_worker"
$logPath = Join-Path $deployDir "auto_push.log"
$disableFlag = Join-Path $deployDir ".auto_push_disabled"
$workerScript = Join-Path $PSScriptRoot "sftp_push_worker.ps1"

function Write-AutoPushLog {
    param([string]$Message)
    New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $logPath -Value $line -Encoding UTF8
}

if (Test-Path $disableFlag) {
    Write-AutoPushLog "SKIP disabled ($Reason) $Path"
    exit 0
}

if (-not (Test-Path (Join-Path $bakeryRoot ".env.sftp"))) {
    Write-AutoPushLog "SKIP missing .env.sftp ($Reason)"
    exit 0
}

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
$payload = @{
    at     = (Get-Date).ToUniversalTime().ToString("o")
    reason = $Reason
    path   = $Path
} | ConvertTo-Json -Compress
Set-Content -Path $requestPath -Value $payload -Encoding UTF8
Write-AutoPushLog "QUEUED $Reason $Path"

if (Test-Path $workerFlag) {
    try {
        $worker = Get-Content $workerFlag -Raw | ConvertFrom-Json
        $pidCheck = [int]$worker.pid
        if ($pidCheck -gt 0 -and (Get-Process -Id $pidCheck -ErrorAction SilentlyContinue)) {
            exit 0
        }
    } catch { }
}

Start-Process -FilePath "powershell.exe" -WindowStyle Hidden -ArgumentList @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", $workerScript,
    "-BakeryRoot", $bakeryRoot,
    "-DelaySeconds", "$DelaySeconds"
) | Out-Null

exit 0
