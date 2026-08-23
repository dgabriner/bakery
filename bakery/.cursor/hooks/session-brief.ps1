# sessionStart: point the agent at the packed brief and the craft poem.
$ErrorActionPreference = "Continue"

function Read-StdinQuick {
    param([int]$FirstWaitMs = 150, [int]$NextWaitMs = 50)
    try {
        $stdin = [Console]::OpenStandardInput()
        if ($null -eq $stdin) { return "" }
        $buffer = New-Object byte[] 32768
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
$stanza = "Do not add a morning. Finish the one that is already in the ovens."
try {
    $raw = & php $cli craft --json 2>$null
    if ($LASTEXITCODE -eq 0 -and $raw) {
        $craft = $raw | ConvertFrom-Json
        if ($craft.stanza) { $stanza = [string]$craft.stanza }
    }
} catch { }

$msg = @"
Sour Flour OS development: $stanza
Run: php scripts/agent_homebase.php brief --agent=SLUG --json
Craft lives on bakerysf_stage_local. Tests wipe bakerysf_test. Manual: docs/AGENT_DEVELOPMENT_MANUAL.md
"@

$payload = @{ additional_context = $msg } | ConvertTo-Json -Compress
[Console]::Out.WriteLine($payload)
exit 0
