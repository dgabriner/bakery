# Cursor hook entry: always queue a debounced SFTP push (never block on stdin).
# afterFileEdit / stop / afterTabFileEdit all use this script.
$ErrorActionPreference = "Continue"

$hookDir = $PSScriptRoot
$bakeryRoot = (Resolve-Path (Join-Path $hookDir "..\..")).Path
$queue = Join-Path $bakeryRoot "scripts\queue_sftp_push.ps1"
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$logPath = Join-Path $deployDir "auto_push.log"

function Write-HookLog([string]$Message) {
    try {
        New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
        $line = "{0}  HOOK  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
        Add-Content -Path $logPath -Value $line -Encoding UTF8
    } catch { }
}

# Non-blocking stdin read (Cursor can leave stdin open; ReadToEnd would hang until timeout)
function Read-StdinQuick {
    param([int]$FirstWaitMs = 300, [int]$NextWaitMs = 100)
    try {
        $stdin = [Console]::OpenStandardInput()
        if ($null -eq $stdin) { return "" }
        $buffer = New-Object byte[] 65536
        $ms = New-Object System.IO.MemoryStream
        $task = $stdin.ReadAsync($buffer, 0, $buffer.Length)
        $wait = $FirstWaitMs
        while ($true) {
            if (-not $task.Wait($wait)) { break }
            $n = $task.Result
            if ($n -le 0) { break }
            $ms.Write($buffer, 0, $n)
            $task = $stdin.ReadAsync($buffer, 0, $buffer.Length)
            $wait = $NextWaitMs
        }
        return [System.Text.Encoding]::UTF8.GetString($ms.ToArray())
    } catch {
        return ""
    }
}

$reason = "cursor-hook"
$path = ""
$raw = Read-StdinQuick
if ($raw) {
    try {
        $payload = $raw | ConvertFrom-Json
        if ($payload.hook_event_name) { $reason = [string]$payload.hook_event_name }
        if ($payload.file_path) { $path = [string]$payload.file_path }
    } catch { }
}

Write-HookLog "fired reason=$reason path=$path"

if (-not (Test-Path -LiteralPath $queue)) {
    Write-HookLog "ERROR missing queue script: $queue"
    exit 0
}

# Always queue. push_sftp.ps1 decides what (if anything) is deployable.
try {
    & $queue -Reason $reason -Path $path -DelaySeconds 15
    Write-HookLog "queued ok"
} catch {
    Write-HookLog ("ERROR queue failed: " + $_.Exception.Message)
}

exit 0
