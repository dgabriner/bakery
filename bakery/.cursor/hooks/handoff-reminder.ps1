# Cursor stop hook: remind only when a Homebase session is still open.
$ErrorActionPreference = "Continue"

function Read-StdinQuick {
    param([int]$FirstWaitMs = 200, [int]$NextWaitMs = 80)
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

[void](Read-StdinQuick)

$hookDir = $PSScriptRoot
$bakeryRoot = (Resolve-Path (Join-Path $hookDir "..\..")).Path
$cli = Join-Path $bakeryRoot "scripts\agent_homebase.php"

$followup = $null
try {
    $raw = & php $cli nag-check --json 2>$null
    if ($LASTEXITCODE -eq 0 -and $raw) {
        $state = $raw | ConvertFrom-Json
        if ($state.should_remind -and $state.followup) {
            $followup = [string]$state.followup
        }
    }
} catch { }

if ($followup) {
    $payload = @{ followup_message = $followup } | ConvertTo-Json -Compress
    [Console]::Out.WriteLine($payload)
} else {
    [Console]::Out.WriteLine("{}")
}
exit 0
