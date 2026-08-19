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

    # Wait until any in-flight push_sftp (UI Sync or prior worker) finishes.
    # push_sftp.ps1 owns .push_lock — do not acquire it here or the child deadlocks.
    $deadline = (Get-Date).AddMinutes(5)
    while (Test-Path -LiteralPath $lockPath) {
        try {
            $lock = Get-Content -LiteralPath $lockPath -Raw -Encoding UTF8 | ConvertFrom-Json
            $lockPid = [int]$lock.pid
            if ($lockPid -gt 0 -and -not (Get-Process -Id $lockPid -ErrorAction SilentlyContinue)) {
                Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
                break
            }
        } catch {
            Remove-Item -LiteralPath $lockPath -Force -ErrorAction SilentlyContinue
            break
        }
        if ((Get-Date) -gt $deadline) {
            Write-AutoPushLog "ERROR lock timeout waiting for in-flight push"
            exit 1
        }
        Start-Sleep -Seconds 3
    }

    Remove-Item $requestPath -Force -ErrorAction SilentlyContinue
    Write-AutoPushLog "PUSH start"

    # Invoke the child directly instead of Start-Process. Some GUI hosts provide
    # both PATH and Path in their inherited environment. Windows accepts that,
    # but Windows PowerShell's Start-Process copies the variables into a
    # case-insensitive dictionary and throws before the push script can run.
    # A direct native invocation preserves the inherited environment as-is.
    $prevEap = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    $outLog = Join-Path $deployDir ("push_stdout_{0}.log" -f $PID)
    $errLog = Join-Path $deployDir ("push_stderr_{0}.log" -f $PID)
    $pushExit = 1
    try {
        Push-Location $BakeryRoot
        try {
            & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $pushScript `
                1> $outLog 2> $errLog
            $pushExit = [int]$LASTEXITCODE
        } finally {
            Pop-Location
        }

        foreach ($part in @($outLog, $errLog)) {
            if (-not (Test-Path -LiteralPath $part)) { continue }
            try {
                $text = Get-Content -LiteralPath $part -Raw -ErrorAction SilentlyContinue
                if ($text) {
                    # Normalize to UTF-8 lines in the main auto_push log.
                    $text -split "`r?`n" | Where-Object { $_ -ne '' } | ForEach-Object {
                        Add-Content -Path $logPath -Value $_ -Encoding UTF8
                    }
                }
            } catch { }
            Remove-Item -LiteralPath $part -Force -ErrorAction SilentlyContinue
        }
    } finally {
        $ErrorActionPreference = $prevEap
        Remove-Item -LiteralPath $outLog, $errLog -Force -ErrorAction SilentlyContinue
    }

    Write-AutoPushLog ("PUSH done exit=" + $pushExit)
    if ($pushExit -ne 0) {
        exit $pushExit
    }
} catch {
    Write-AutoPushLog ("ERROR " + $_.Exception.Message)
} finally {
    Remove-Item $workerFlag -Force -ErrorAction SilentlyContinue
}
