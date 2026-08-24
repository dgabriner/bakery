# Run one guarded stage tool on the DreamHost staging host via sftp_upload.py.
# Chain: checkpoint bakerysoftware -> stage tool -> run_migrations --mode=hosted-stage.
# Credentials: gitignored .env.sftp.stage only. Never loads live env files.
# Refuses anything but bakeryOS at staging.sourflour.org (dreamhost-stage).
param(
    [Parameter(Mandatory = $true)][string]$StageTool,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$envSftpPath = Join-Path $bakeryRoot ".env.sftp.stage"
$uploader = Join-Path $PSScriptRoot "sftp_upload.py"

if (-not (Test-Path -LiteralPath $envSftpPath)) {
    throw "Missing staging SFTP env file. Copy .env.sftp.stage.example to .env.sftp.stage."
}

foreach ($name in @("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD", "SFTP_REMOTE_ROOT", "SFTP_TARGET")) {
    [Environment]::SetEnvironmentVariable($name, $null, 'Process')
}
Get-Content -LiteralPath $envSftpPath | ForEach-Object {
    $line = $_.Trim()
    if (-not $line -or $line.StartsWith("#")) { return }
    $eq = $line.IndexOf("=")
    if ($eq -lt 1) { return }
    $key = $line.Substring(0, $eq).Trim()
    $value = $line.Substring($eq + 1).Trim()
    if (
        ($value.StartsWith('"') -and $value.EndsWith('"')) -or
        ($value.StartsWith("'") -and $value.EndsWith("'"))
    ) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    [Environment]::SetEnvironmentVariable($key, $value, 'Process')
}

$user = [Environment]::GetEnvironmentVariable('SFTP_USER', 'Process')
$remoteRoot = [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')
if ($user -ne 'bakeryOS') { throw "Refusing: stage tools require SFTP user bakeryOS." }
if ($remoteRoot -match 'bakery\.sourflour\.org/bake') { throw "Refusing: production root is never a stage-tool target." }
if ($remoteRoot -notmatch 'staging\.sourflour\.org') { throw "Refusing: stage tools require remote root staging.sourflour.org." }
[Environment]::SetEnvironmentVariable('SFTP_TARGET', 'dreamhost-stage', 'Process')

$python = $null
$localAppData = [Environment]::GetFolderPath('LocalApplicationData')
foreach ($candidate in @(
    (Join-Path $localAppData 'Programs\Python\Launcher\py.exe'),
    (Join-Path $localAppData 'Programs\Python\Python314\python.exe'),
    (Join-Path $localAppData 'Programs\Python\Python313\python.exe'),
    (Join-Path $localAppData 'Programs\Python\Python312\python.exe')
)) {
    if ($candidate -and (Test-Path -LiteralPath $candidate)) { $python = $candidate; break }
}
if (-not $python) {
    foreach ($name in @('py', 'python')) {
        try {
            $cmd = Get-Command $name -ErrorAction Stop
            if (([string]$cmd.Source) -notmatch 'WindowsApps') { $python = [string]$cmd.Source; break }
        } catch { }
    }
}
if (-not $python) { throw "Python not found. Install Python 3 or put py/python on PATH." }

$pyArgs = @()
if ((Split-Path $python -Leaf) -in @("py.exe", "py")) { $pyArgs += "-3" }
$pyArgs += @(
    $uploader,
    "--local-root", $bakeryRoot,
    "--stage-tool", (Join-Path $bakeryRoot $StageTool),
    "--run-hosted-stage-migrations"
)
if ($DryRun) { $pyArgs += "--dry-run" }

Write-Host "Stage-tool chain: checkpoint bakerysoftware -> $StageTool -> hosted migrations"
& $python @pyArgs
exit $LASTEXITCODE
