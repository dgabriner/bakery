# Start / stop / status for the background file watcher used when staging auto-push is ON.
#
#   .\scripts\auto_push_watcher_ctl.ps1 status
#   .\scripts\auto_push_watcher_ctl.ps1 start
#   .\scripts\auto_push_watcher_ctl.ps1 stop
param(
    [Parameter(Position = 0)]
    [ValidateSet('status', 'start', 'stop', 'ensure')]
    [string]$Action = 'status',
    [int]$DelaySeconds = 15
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$pidPath = Join-Path $deployDir ".watch_push.pid"
$logPath = Join-Path $deployDir "auto_push.log"
$disableFlag = Join-Path $deployDir ".auto_push_disabled"
$watchScript = Join-Path $PSScriptRoot "watch_and_push.ps1"

function Write-CtlLog([string]$Message) {
    New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
    $line = "{0}  WATCHCTL  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $logPath -Value $line -Encoding UTF8
}

function Get-WatcherState {
    if (-not (Test-Path $pidPath)) {
        return @{ running = $false; pid = $null }
    }
    try {
        $info = Get-Content $pidPath -Raw | ConvertFrom-Json
        $procId = [int]$info.pid
        if ($procId -gt 0 -and (Get-Process -Id $procId -ErrorAction SilentlyContinue)) {
            return @{ running = $true; pid = $procId; started = $info.started }
        }
    } catch { }
    Remove-Item $pidPath -Force -ErrorAction SilentlyContinue
    return @{ running = $false; pid = $null }
}

function Stop-Watcher {
    $state = Get-WatcherState
    if (-not $state.running) {
        Remove-Item $pidPath -Force -ErrorAction SilentlyContinue
        Write-CtlLog "stop (not running)"
        return @{ ok = $true; running = $false; message = "Watcher already stopped" }
    }
    try {
        Stop-Process -Id ([int]$state.pid) -Force -ErrorAction Stop
        Write-CtlLog ("stop pid=" + $state.pid)
    } catch {
        Write-CtlLog ("stop error: " + $_.Exception.Message)
    }
    Remove-Item $pidPath -Force -ErrorAction SilentlyContinue
    return @{ ok = $true; running = $false; message = "Watcher stopped" }
}

function Start-Watcher {
    if (Test-Path $disableFlag) {
        return @{ ok = $false; running = $false; message = "Auto-push disabled; not starting watcher" }
    }

    $state = Get-WatcherState
    if ($state.running) {
        return @{ ok = $true; running = $true; pid = $state.pid; message = "Watcher already running" }
    }

    New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
    $proc = Start-Process -FilePath "powershell.exe" -WindowStyle Hidden -PassThru -ArgumentList @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", $watchScript,
        "-DelaySeconds", "$DelaySeconds",
        "-AsService"
    )
    Start-Sleep -Milliseconds 800
    if (-not (Get-Process -Id $proc.Id -ErrorAction SilentlyContinue)) {
        Write-CtlLog "start failed (process exited)"
        return @{ ok = $false; running = $false; message = "Watcher failed to start" }
    }

    # watch_and_push -AsService writes the pid file; keep a fallback
    if (-not (Test-Path $pidPath)) {
        @{
            pid = $proc.Id
            started = (Get-Date).ToUniversalTime().ToString('o')
            mode = 'ctl-fallback'
        } | ConvertTo-Json -Compress | Set-Content -Path $pidPath -Encoding UTF8
    }

    Write-CtlLog ("start pid=" + $proc.Id)
    return @{ ok = $true; running = $true; pid = $proc.Id; message = "Watcher started" }
}

switch ($Action) {
    'status' {
        $state = Get-WatcherState
        [pscustomobject]@{
            ok = $true
            running = [bool]$state.running
            pid = $state.pid
            disabled = (Test-Path $disableFlag)
        } | ConvertTo-Json -Compress
    }
    'stop' {
        Stop-Watcher | ConvertTo-Json -Compress
    }
    'start' {
        Start-Watcher | ConvertTo-Json -Compress
    }
    'ensure' {
        if (Test-Path $disableFlag) {
            Stop-Watcher | Out-Null
            [pscustomobject]@{ ok = $true; running = $false; message = "Disabled" } | ConvertTo-Json -Compress
        } else {
            Start-Watcher | ConvertTo-Json -Compress
        }
    }
}
