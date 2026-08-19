# Watch local bakery web files and queue SFTP pushes on change.
# Run once and leave open (or use watch_push.bat).
# Live auto-push ON starts this with -AsService in the background.
#
#   .\scripts\watch_and_push.ps1
#   .\scripts\watch_and_push.ps1 -DelaySeconds 15
#   .\scripts\watch_and_push.ps1 -AsService
param(
    [int]$DelaySeconds = 15,
    [switch]$AsService
)

$ErrorActionPreference = "Stop"

$bakeryRoot = Split-Path $PSScriptRoot -Parent
$queue = Join-Path $PSScriptRoot "queue_sftp_push.ps1"
$deployDir = Join-Path $bakeryRoot "storage\deploy"
$logPath = Join-Path $deployDir "auto_push.log"
$pidPath = Join-Path $deployDir ".watch_push.pid"
$disableFlag = Join-Path $deployDir ".auto_push_disabled"

. (Join-Path $PSScriptRoot "deploy_manifest.ps1")

function Write-WatchLog([string]$Message) {
    New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
    $line = "{0}  WATCH  {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Add-Content -Path $logPath -Value $line -Encoding UTF8
    Write-Host $line
}

function Test-WatchPathRelevant {
    param(
        [string]$FullPath,
        [string]$Root
    )

    if (-not (Test-Path -LiteralPath $FullPath -PathType Leaf)) { return $false }

    $name = [System.IO.Path]::GetFileName($FullPath)
    if (Test-BakeryDeploySkipFile $name) { return $false }
    if ($name -like '.env*') { return $false }

    $rootFull = [System.IO.Path]::GetFullPath($Root)
    $full = [System.IO.Path]::GetFullPath($FullPath)
    if (-not $full.StartsWith($rootFull, [System.StringComparison]::OrdinalIgnoreCase)) { return $false }

    $rel = $full.Substring($rootFull.Length).TrimStart('\', '/').Replace('\', '/')
    foreach ($prefix in @('storage/', 'scripts/', 'docs/', 'tests/', 'database/', '.cursor/', 'logs/', 'uploads/', 'vendor/')) {
        if ($rel.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
            return ($rel.StartsWith('uploads/product_photos/catalog/', [System.StringComparison]::OrdinalIgnoreCase))
        }
    }

    $ext = [System.IO.Path]::GetExtension($name).ToLowerInvariant()
    $isCatalogImage = $rel.StartsWith('uploads/product_photos/catalog/', [System.StringComparison]::OrdinalIgnoreCase)
    if ($name -eq '.htaccess') { return $true }
    if ($ext -notin @('.php', '.js', '.css', '.html') -and -not $isCatalogImage) { return $false }

    if ($rel -notmatch '/') { return $true }
    foreach ($dir in (Get-BakeryDeployDirectories)) {
        if ($rel.StartsWith("$dir/", [System.StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    return $false
}

$watchers = @()
$eventJobs = @()
$paths = @($bakeryRoot) + @(Get-BakeryDeployDirectories | ForEach-Object { Join-Path $bakeryRoot $_ })

foreach ($path in $paths) {
    if (-not (Test-Path $path)) { continue }
    $w = New-Object System.IO.FileSystemWatcher
    $w.Path = $path
    $w.Filter = "*.*"
    $w.IncludeSubdirectories = ($path -ne $bakeryRoot)
    $w.NotifyFilter = [IO.NotifyFilters]::FileName -bor [IO.NotifyFilters]::LastWrite -bor [IO.NotifyFilters]::Size
    $w.EnableRaisingEvents = $true
    $watchers += $w

    foreach ($evt in @("Changed", "Created", "Renamed")) {
        $eventJobs += Register-ObjectEvent -InputObject $w -EventName $evt -MessageData @{
            Root = $bakeryRoot
            Queue = $queue
            Delay = $DelaySeconds
            Log = $logPath
            DeployDir = $deployDir
        } -Action {
            $itemPath = $Event.SourceEventArgs.FullPath
            $root = $Event.MessageData.Root
            $queuePath = $Event.MessageData.Queue
            $delay = $Event.MessageData.Delay
            $log = $Event.MessageData.Log
            $deploy = $Event.MessageData.DeployDir

            # Lightweight filter (full skip list lives in push_sftp)
            $name = [System.IO.Path]::GetFileName($itemPath)
            if ($name -like '.env*') { return }
            $ext = [System.IO.Path]::GetExtension($name).ToLowerInvariant()

            $rel = $itemPath
            try {
                $rel = $itemPath.Substring($root.Length).TrimStart('\', '/').Replace('\', '/')
            } catch { }
            foreach ($prefix in @('storage/', 'scripts/', 'docs/', 'tests/', 'database/', '.cursor/', 'logs/', 'uploads/', 'vendor/')) {
                if ($rel.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
                    if (-not $rel.StartsWith('uploads/product_photos/catalog/', [System.StringComparison]::OrdinalIgnoreCase)) { return }
                    break
                }
            }
            $isCatalogImage = $rel.StartsWith('uploads/product_photos/catalog/', [System.StringComparison]::OrdinalIgnoreCase)
            if ($name -ne '.htaccess' -and $ext -notin @('.php', '.js', '.css', '.html') -and -not $isCatalogImage) { return }

            New-Item -ItemType Directory -Force -Path $deploy | Out-Null
            $line = "{0}  WATCH  change {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $rel
            Add-Content -Path $log -Value $line -Encoding UTF8
            & powershell -NoProfile -ExecutionPolicy Bypass -File $queuePath -Reason "watch" -Path $rel -DelaySeconds $delay | Out-Null
        }
    }
}

New-Item -ItemType Directory -Force -Path $deployDir | Out-Null
@{
    pid = $PID
    started = (Get-Date).ToUniversalTime().ToString('o')
    mode = $(if ($AsService) { 'service' } else { 'interactive' })
    delay = $DelaySeconds
} | ConvertTo-Json -Compress | Set-Content -Path $pidPath -Encoding UTF8

if (-not $AsService) {
    Write-Host ""
    Write-Host "Watching bakery for deployable changes..."
    Write-Host "  Root: $bakeryRoot"
    Write-Host "  Debounce: ${DelaySeconds}s then SFTP push"
    Write-Host "  Disable: create storage\deploy\.auto_push_disabled"
    Write-Host "  Log: $logPath"
    Write-Host "Press Ctrl+C to stop."
    Write-Host ""
}
Write-WatchLog ("started delay=${DelaySeconds}s asService=" + [bool]$AsService + " pid=" + $PID)

try {
    while ($true) {
        if (Test-Path $disableFlag) {
            Write-WatchLog "stopping because auto-push disabled"
            break
        }
        Start-Sleep -Seconds 2
    }
} finally {
    $eventJobs | ForEach-Object { Unregister-Event -SourceIdentifier $_.Name -ErrorAction SilentlyContinue }
    foreach ($w in $watchers) {
        $w.EnableRaisingEvents = $false
        $w.Dispose()
    }
    try {
        if (Test-Path $pidPath) {
            $info = Get-Content $pidPath -Raw | ConvertFrom-Json
            if ([int]$info.pid -eq $PID) {
                Remove-Item $pidPath -Force -ErrorAction SilentlyContinue
            }
        }
    } catch {
        Remove-Item $pidPath -Force -ErrorAction SilentlyContinue
    }
    Write-WatchLog ("stopped pid=" + $PID)
}
