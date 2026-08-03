# Background debounce worker for queue_sftp_push.ps1 (do not run directly).
param(
    [Parameter(Mandatory = $true)][string]$BakeryRoot,
    [Parameter(Mandatory = $true)][int]$DelaySeconds
)

$ErrorActionPreference = "Stop"

$deployDir = Join-Path $BakeryRoot "storage\deploy"
$requestPath = Join-Path $deployDir ".push_request"
$lockPath = Join-Path $deployDir ".push_lock"
$workerFlag = Join-Path $deployDir ".push_worker"
$logPath = Join-Path $deployDir "auto_push.log"
$pushScript = Join-Path $BakeryRoot "scripts\push_sftp.ps1"

function Write-AutoPushLog([string]$Message) {
    $line = "{0}  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $logPath -Value $line -Encoding UTF8
}

try {
    @{ pid = $PID; started = (Get-Date).ToUniversalTime().ToString("o") } |
        ConvertTo-Json -Compress |
        Set-Content -Path $workerFlag -Encoding UTF8

    while ($true) {
        if (-not (Test-Path $requestPath)) { break }
        $lastWrite = (Get-Item $requestPath).LastWriteTimeUtc
        $quietFor = ([datetime]::UtcNow - $lastWrite).TotalSeconds
        if ($quietFor -ge $DelaySeconds) { break }
        Start-Sleep -Seconds 2
    }

    if (-not (Test-Path $requestPath)) { exit 0 }

    $deadline = (Get-Date).AddMinutes(5)
    while (Test-Path $lockPath) {
        try {
            $lock = Get-Content $lockPath -Raw | ConvertFrom-Json
            $lockPid = [int]$lock.pid
            if ($lockPid -gt 0 -and -not (Get-Process -Id $lockPid -ErrorAction SilentlyContinue)) {
                Remove-Item $lockPath -Force -ErrorAction SilentlyContinue
                break
            }
        } catch {
            Remove-Item $lockPath -Force -ErrorAction SilentlyContinue
            break
        }
        if ((Get-Date) -gt $deadline) {
            Write-AutoPushLog "ERROR lock timeout"
            exit 1
        }
        Start-Sleep -Seconds 3
    }

    @{ pid = $PID; at = (Get-Date).ToUniversalTime().ToString("o") } |
        ConvertTo-Json -Compress |
        Set-Content -Path $lockPath -Encoding UTF8

    Remove-Item $requestPath -Force -ErrorAction SilentlyContinue
    Write-AutoPushLog "PUSH start"
    & powershell -NoProfile -ExecutionPolicy Bypass -File $pushScript *>> $logPath 2>&1
    Write-AutoPushLog ("PUSH done exit=" + $LASTEXITCODE)
} catch {
    Write-AutoPushLog ("ERROR " + $_.Exception.Message)
} finally {
    Remove-Item $lockPath -Force -ErrorAction SilentlyContinue
    Remove-Item $workerFlag -Force -ErrorAction SilentlyContinue
}
