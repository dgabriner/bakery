# Push domain-root redirect (bakery.sourflour.org/index.html -> /bake/)
#
#   .\scripts\push_domain_root_sftp.ps1
#   .\scripts\push_domain_root_sftp.ps1 -DryRun
#
# Credentials: .env.sftp.domain_root (see .env.sftp.domain_root.example)
# Falls back to .env.sftp host/user/password when domain_root env is missing.
param(
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$siteRoot = Join-Path $bakeryRoot "domain_root"
$deployDir = Join-Path $bakeryRoot "storage\deploy\domain_root"
$historyLog = Join-Path $deployDir "PUSH_HISTORY.jsonl"
$envPath = Join-Path $bakeryRoot ".env.sftp.domain_root"
$fallbackEnvPath = Join-Path $bakeryRoot ".env.sftp"
$uploader = Join-Path $PSScriptRoot "sftp_upload.py"
$listFile = Join-Path $env:TEMP ("domain_root_sftp_{0}.txt" -f [guid]::NewGuid().ToString("N"))

function Import-DomainRootSftpEnv {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return }
    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = $_.Trim()
        if (-not $line -or $line.StartsWith("#")) { return }
        $eq = $line.IndexOf("=")
        if ($eq -lt 1) { return }
        $name = $line.Substring(0, $eq).Trim()
        $value = $line.Substring($eq + 1).Trim()
        if (
            ($value.StartsWith('"') -and $value.EndsWith('"')) -or
            ($value.StartsWith("'") -and $value.EndsWith("'"))
        ) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

function Get-PythonLauncher {
    $localAppData = [Environment]::GetFolderPath('LocalApplicationData')
    $candidates = @(
        (Join-Path $localAppData 'Programs\Python\Launcher\py.exe'),
        (Join-Path $localAppData 'Programs\Python\Python313\python.exe'),
        (Join-Path $localAppData 'Programs\Python\Python312\python.exe'),
        'C:\Python313\python.exe',
        'C:\Python312\python.exe'
    )
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) { return $candidate }
    }
    foreach ($name in @('py', 'python')) {
        try {
            $cmd = Get-Command $name -ErrorAction Stop
            $source = [string]$cmd.Source
            if ($source -match 'WindowsApps') { continue }
            if ($source -and (Test-Path -LiteralPath $source)) { return $source }
        } catch { }
    }
    throw "Python not found. Install Python 3 and ensure 'py' or 'python' is on PATH."
}

if (-not (Test-Path -LiteralPath $siteRoot)) {
    Write-Host "Missing domain_root/ folder at $siteRoot"
    exit 1
}

Import-DomainRootSftpEnv -Path $envPath
if (-not [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')) {
    Import-DomainRootSftpEnv -Path $fallbackEnvPath
    [Environment]::SetEnvironmentVariable('SFTP_REMOTE_ROOT', 'bakery.sourflour.org', 'Process')
}

$missingCreds = @()
foreach ($required in @("SFTP_HOST", "SFTP_USER", "SFTP_PASSWORD", "SFTP_REMOTE_ROOT")) {
    $val = [Environment]::GetEnvironmentVariable($required, 'Process')
    if ([string]::IsNullOrWhiteSpace($val)) { $missingCreds += $required }
}
if ($missingCreds.Count -gt 0) {
    Write-Host "Missing SFTP settings: $($missingCreds -join ', ')"
    Write-Host "Copy .env.sftp.domain_root.example to .env.sftp.domain_root or use .env.sftp."
    exit 1
}

$toUpload = @(
    Get-ChildItem -Path $siteRoot -Recurse -File |
        ForEach-Object {
            $_.FullName.Substring($siteRoot.Length).TrimStart('\').Replace('\', '/')
        } |
        Sort-Object -Unique
)

if ($toUpload.Count -eq 0) {
    Write-Host "No files found in domain_root/."
    exit 1
}

Write-Host ""
Write-Host ("Domain root -> {0}" -f [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process'))
Write-Host "Uploading $($toUpload.Count) file(s):"
foreach ($rel in $toUpload) { Write-Host "  - $rel" }

$utf8NoBom = New-Object System.Text.UTF8Encoding $false
[System.IO.File]::WriteAllLines($listFile, $toUpload, $utf8NoBom)

$python = Get-PythonLauncher
$pyArgs = @()
if ((Split-Path $python -Leaf) -in @("py.exe", "py")) { $pyArgs += "-3" }
$pyArgs += @($uploader, "--local-root", $siteRoot, "--list", $listFile)
if ($DryRun) { $pyArgs += "--dry-run" }

try {
    & $python @pyArgs
    if ($LASTEXITCODE -ne 0) { throw "SFTP upload failed (exit $LASTEXITCODE)." }
} finally {
    Remove-Item -Force -ErrorAction SilentlyContinue $listFile
}

if ($DryRun) {
    Write-Host "Dry run only."
    exit 0
}

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
$recordedAt = (Get-Date).ToUniversalTime().ToString("o")
$historyEntry = [ordered]@{
    recorded_at = $recordedAt
    method      = "sftp"
    host        = [Environment]::GetEnvironmentVariable('SFTP_HOST', 'Process')
    remote_root = [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')
    file_count  = $toUpload.Count
    files       = @($toUpload)
}
$line = ($historyEntry | ConvertTo-Json -Compress -Depth 4)
Add-Content -Path $historyLog -Value $line -Encoding UTF8

Write-Host ""
Write-Host "Live: https://bakery.sourflour.org/ should redirect to /bake/"
Write-Host ""
