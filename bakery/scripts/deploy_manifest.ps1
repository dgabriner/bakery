# Shared production deploy manifest for Bakery Manager.
# Dot-source from build_deploy_zip.ps1, list_deploy_changes.ps1, record_deploy.ps1

function Get-BakeryDeployRootFiles {
    return @(
        '.htaccess',
        'staging-robots.txt',
        'index.php', 'login.php', 'logout.php', 'baker.php', 'build_id.php', 'qr_login.php', 'customer_qr_login.php',
        'customers.php', 'customer_schedule.php', 'customer_overview.php', 'customer_routes.php',
        'zones.php', 'leads.php', 'pan_dulce_pricing.php', 'pan_dulce_quantities.php',
        'products.php', 'dough_types.php', 'formulas.php', 'ingredients.php',
        'daily_orders.php', 'standing_orders.php', 'standing_orders_manager.php', 'orders.php',
        'bread_distribution.php', 'product_distribution.php',
        'production.php', 'pack_list.php',
        'standing_routes.php', 'daily_route.php',
        'drivers.php', 'driver.php', 'driver_list.php', 'driver_assignment.php', 'driver_overview.php',
        'route_manager.php', 'route_summary.php', 'map.php', 'call_headquarters.php',
        'complete_delivery.php', 'get_driver_orders.php', 'get_customer_order_details.php', 'global_gps_handler.php',
        'upload_driver_photo.php',
        'daily_run.php', 'daily_run_api.php', 'daily_brief.php',
        'manager.php', 'billing_center.php', 'billing_api.php', 'billing_export.php', 'production_center.php', 'production_manager.php',
        'customer_login.php', 'customer_portal.php', 'customer_portal_tip.php', 'customer_portal_regular.php',
        'customer_portal_account.php', 'customer_portal_notifications.php',
        'customer_portal_delivery.php', 'customer_catalog.php', 'customer_upcoming_edit.php', 'customer_upcoming.php',
        'customer_record.php',
        'square_webhook.php',
        'text_comms.php', 'text_comms_api.php', 'twilio_webhook.php', 'survey.php', 'text_media.php',
        'route_closeout.php', 'route_analysis.php',
        'driver_load.php', 'driver_stops.php', 'driver_session_ping.php',
        'users.php', 'walkthroughs.php', 'guias.php', 'login_history.php',
        'generate_invoice.php', 'oauth_callback.php',
        'sfb_dashboard.php', 'sfb_starters.php', 'sfb_ingredients.php', 'sfb_formulas.php',
        'sfb_batches.php', 'sfb_batch.php', 'sfb_resources.php', 'sfb_community.php',
        'sfb_community_topic.php', 'sfb_shared_batch.php',
        'sfb_admin_overview.php', 'sfb_admin_batch.php', 'sfb_admin_impersonate.php',
        'sfb_admin_studio.php', 'sfb_admin_studio_baker.php',
        'agent_homebase.php', 'deploy_status.php', 'migration_status.php', 'schema_status.php'
    )
}

function Get-BakeryDeployDirectories {
    return @('includes', 'css', 'assets', 'lang')
}

function Get-BakeryDeployOptionalPaths {
    return @(
        @{ Path = 'vendor\phpmailer'; Required = $false },
        @{ Path = 'uploads\driver_photos'; Required = $false; Files = @('.htaccess') },
        @{ Path = 'uploads\product_photos\catalog'; Required = $false }
    )
}

function Get-BakeryDeployExcludeNamePatterns {
    return @(
        '*_backup.php', '*backup.php', '*_fixed.php', '*_optimized.php', '*_working.php',
        '*Copy*.php', 'debug*.php', 'simple-debug.php', 'simple_performance_test.php',
        'health_local.php', 'health_prod.php', 'health_driver.php', 'health_deploy.php',
        'driver_pages_probe.php', 'trace_driver_list.php', 'ping.php',
        'run_sql_setup.php', 'db_test.php', 'setup_directories.php', 'oauth_setup.php',
        'auto_push_api.php', 'sourflour.html',
        'tmp_*.php', 'tmp_*.js', 'tmp_*.txt'
    )
}

function Test-BakeryDeploySkipFile {
    param([string]$Name)
    foreach ($pattern in (Get-BakeryDeployExcludeNamePatterns)) {
        if ($Name -like $pattern) { return $true }
    }
    return $false
}

function Test-BakeryDeployWebRootFile {
    param([System.IO.FileInfo]$File)
    if (Test-BakeryDeploySkipFile $File.Name) { return $false }
    $ext = $File.Extension.ToLowerInvariant()
    return ($File.Name -eq '.htaccess' -or $ext -in @('.php', '.js', '.css', '.html'))
}

function Get-BakeryDeployFileList {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        # Accepted for caller compatibility (push_sftp_stage.ps1 / push_sftp.ps1 still pass it).
        # Deliberate no-op: the root sweep below is unconditional and authoritative, so every
        # deployable root web file ships regardless of mtime. Closes the bug where a newly
        # added root PHP page 404'd on staging while the sync looked green because it was
        # missing from the hardcoded whitelist and older than the push baseline.
        [datetime]$AlsoIncludeRootModifiedAfterUtc = [datetime]::MinValue
    )

    $files = New-Object System.Collections.Generic.List[string]
    $seen = @{}

    function Add-DeployPath([string]$Rel) {
        $norm = $Rel.Replace('\', '/')
        if ($seen.ContainsKey($norm)) { return }
        $seen[$norm] = $true
        $files.Add($norm)
    }

    # Authoritative sweep: EVERY root file passing Test-BakeryDeployWebRootFile deploys.
    # -Force so hidden files (a hidden .htaccess) are not silently skipped.
    Get-ChildItem -Path $BakeryRoot -File -Force | ForEach-Object {
        if (-not (Test-BakeryDeployWebRootFile $_)) { return }
        Add-DeployPath $_.Name
    }

    # Hardcoded whitelist kept only as an ordering/completeness aid: scripts outside this
    # manifest (build_deploy_zip.ps1) iterate Get-BakeryDeployRootFiles directly, and
    # Test-Path here also catches whitelisted entries that Get-ChildItem cannot see.
    # Add-DeployPath de-duplicates everything the sweep above already covered.
    foreach ($file in (Get-BakeryDeployRootFiles)) {
        $src = Join-Path $BakeryRoot $file
        if (Test-Path $src) { Add-DeployPath $file }
    }

    foreach ($dir in (Get-BakeryDeployDirectories)) {
        $srcDir = Join-Path $BakeryRoot $dir
        if (-not (Test-Path $srcDir)) { continue }
        Get-ChildItem $srcDir -Recurse -File | ForEach-Object {
            if (Test-BakeryDeploySkipFile $_.Name) { return }
            $rel = $_.FullName.Substring($srcDir.Length).TrimStart('\').Replace('\', '/')
            Add-DeployPath "$dir/$rel"
        }
    }

    foreach ($opt in (Get-BakeryDeployOptionalPaths)) {
        $srcDir = Join-Path $BakeryRoot $opt.Path
        if (-not (Test-Path $srcDir)) { continue }
        if ($opt.Files) {
            foreach ($f in $opt.Files) {
                $src = Join-Path $srcDir $f
                if (Test-Path $src) {
                    Add-DeployPath ("{0}/{1}" -f ($opt.Path -replace '\\', '/'), $f)
                }
            }
            continue
        }
        Get-ChildItem $srcDir -Recurse -File | ForEach-Object {
            $rel = $_.FullName.Substring($srcDir.Length).TrimStart('\').Replace('\', '/')
            Add-DeployPath ("{0}/{1}" -f ($opt.Path -replace '\\', '/'), $rel)
        }
    }

    return @($files | Sort-Object -Unique)
}

function Get-BakeryDeployFileFingerprint {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [Parameter(Mandatory = $true)][string]$RelativePath
    )

    $full = Join-Path $BakeryRoot ($RelativePath -replace '/', '\')
    if (-not (Test-Path -LiteralPath $full)) {
        return $null
    }
    try {
        $item = Get-Item -LiteralPath $full -ErrorAction Stop
    } catch {
        return $null
    }
    return @{
        path = ($RelativePath -replace '\\', '/')
        size = $item.Length
        mtime = $item.LastWriteTimeUtc.ToString('o')
    }
}

function Get-BakeryDeploySnapshot {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [datetime]$AlsoIncludeRootModifiedAfterUtc = [datetime]::MinValue
    )

    $snapshot = @{}
    foreach ($rel in (Get-BakeryDeployFileList -BakeryRoot $BakeryRoot -AlsoIncludeRootModifiedAfterUtc $AlsoIncludeRootModifiedAfterUtc)) {
        if ($snapshot.ContainsKey($rel)) { continue }
        $fp = Get-BakeryDeployFileFingerprint -BakeryRoot $BakeryRoot -RelativePath $rel
        if ($null -ne $fp) {
            $snapshot[$rel] = $fp
        }
    }
    return $snapshot
}

function Get-BakeryPhpExecutable {
    param([string]$BakeryRoot)

    $candidates = @(
        'C:\php\php.exe',
        (Join-Path $BakeryRoot '..\php\php.exe'),
        'php'
    )
    foreach ($candidate in $candidates) {
        if ($candidate -eq 'php') {
            $cmd = Get-Command php -ErrorAction SilentlyContinue
            if ($cmd) { return $cmd.Source }
            continue
        }
        if (Test-Path $candidate) { return $candidate }
    }
    return 'php'
}

function Read-BakeryDeployState {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    try {
        return Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        Write-Host "Warning: could not parse deploy baseline at $Path - $($_.Exception.Message)"
        return $null
    }
}

function ConvertTo-BakeryDeployBaselineMap {
    param($BaselineFiles)

    $baselineMap = @{}
    if (-not $BaselineFiles) { return $baselineMap }
    foreach ($entry in $BaselineFiles.PSObject.Properties) {
        # ConvertFrom-Json hydrates ISO timestamps as DateTime values. Comparing
        # their culture-formatted string form with the current ISO fingerprint
        # makes every file look changed on the next incremental staging push.
        $mtime = $entry.Value.mtime
        try {
            $mtime = ([datetime]$mtime).ToUniversalTime().ToString('o')
        } catch {
            $mtime = [string]$mtime
        }
        $baselineMap[$entry.Name] = @{
            size = [int64]$entry.Value.size
            mtime = $mtime
        }
    }
    return $baselineMap
}

function Get-BakeryChangedDeployPaths {
    param(
        [Parameter(Mandatory = $true)][hashtable]$CurrentSnapshot,
        [hashtable]$BaselineMap = @{}
    )

    $changed = New-Object System.Collections.Generic.List[string]
    foreach ($path in ($CurrentSnapshot.Keys | Sort-Object)) {
        $cur = $CurrentSnapshot[$path]
        if (-not $BaselineMap.ContainsKey($path)) {
            $changed.Add($path)
            continue
        }
        $base = $BaselineMap[$path]
        if ($cur.size -ne $base.size -or $cur.mtime -ne $base.mtime) {
            $changed.Add($path)
        }
    }
    return @($changed)
}

function Get-BakerySchemaSqlChanges {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [datetime]$SinceUtc = [datetime]::MinValue
    )

    $schemaDir = Join-Path $BakeryRoot "database\schema"
    if (-not (Test-Path $schemaDir)) { return @() }

    Get-ChildItem $schemaDir -Filter "*.sql" -File |
        Where-Object {
            $_.LastWriteTimeUtc -gt $SinceUtc -and
            $_.Name -match '^(\d{3})_' -and
            [int]$Matches[1] -ge 50
        } |
        Sort-Object Name |
        ForEach-Object {
            @{
                path = ("database/schema/{0}" -f $_.Name)
                mtime = $_.LastWriteTimeUtc.ToString('o')
                size = $_.Length
            }
        }
}

function Read-BakeryJsonFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    $raw = [System.IO.File]::ReadAllText($Path)
    if ($raw.Length -gt 0 -and [int][char]$raw[0] -eq 0xFEFF) {
        $raw = $raw.Substring(1)
    }
    return $raw | ConvertFrom-Json
}

function Write-BakeryJsonFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)]$Object,
        [int]$Depth = 8
    )
    $json = $Object | ConvertTo-Json -Depth $Depth
    $utf8 = New-Object System.Text.UTF8Encoding $false
    $dir = Split-Path -Parent $Path
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
    [System.IO.File]::WriteAllText($Path, $json, $utf8)
}

function Get-BakeryRepoRoot {
    param([Parameter(Mandatory = $true)][string]$BakeryRoot)
    $repoRoot = $BakeryRoot
    while ($repoRoot -and -not (Test-Path (Join-Path $repoRoot '.git'))) {
        $parent = Split-Path $repoRoot -Parent
        if ($parent -eq $repoRoot) { break }
        $repoRoot = $parent
    }
    if (-not (Test-Path (Join-Path $repoRoot '.git'))) {
        throw "Cannot find Git repository root above $BakeryRoot"
    }
    return $repoRoot
}

function Get-BakeryGitPath {
    param([Parameter(Mandatory = $true)][string]$RelativePath)
    return ('bakery/' + ($RelativePath -replace '\\', '/')).TrimEnd('/')
}

function Test-BakeryGitPathExists {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)][string]$RelativePath
    )
    $gitPath = Get-BakeryGitPath $RelativePath
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & git -C $RepoRoot cat-file -e "${Commit}:${gitPath}" 2>$null | Out-Null
        return ($LASTEXITCODE -eq 0)
    } finally {
        $ErrorActionPreference = $prev
    }
}

function Save-BakeryGitPath {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)][string]$RelativePath,
        [Parameter(Mandatory = $true)][string]$Destination
    )
    $gitPath = Get-BakeryGitPath $RelativePath
    $git = (Get-Command git -ErrorAction Stop).Source
    $destDir = Split-Path -Parent $Destination
    if ($destDir -and -not (Test-Path -LiteralPath $destDir)) {
        New-Item -ItemType Directory -Force -Path $destDir | Out-Null
    }
    $errFile = Join-Path $env:TEMP ("bakery_git_show_err_{0}.txt" -f [guid]::NewGuid().ToString('N'))
    $p = Start-Process -FilePath $git -ArgumentList @(
        '-C', $RepoRoot,
        '-c', 'core.autocrlf=false',
        '-c', 'core.eol=lf',
        'show', "${Commit}:${gitPath}"
    ) -RedirectStandardOutput $Destination -RedirectStandardError $errFile -NoNewWindow -Wait -PassThru
    $errText = ''
    if (Test-Path -LiteralPath $errFile) {
        $errText = [string](Get-Content -LiteralPath $errFile -Raw -ErrorAction SilentlyContinue)
        Remove-Item -LiteralPath $errFile -Force -ErrorAction SilentlyContinue
    }
    if ($p.ExitCode -ne 0) {
        throw "git show failed for ${gitPath} at ${Commit}: $errText"
    }
}

function Test-BakeryTextReleasePath {
    param([Parameter(Mandatory = $true)][string]$RelativePath)
    $name = [System.IO.Path]::GetFileName($RelativePath).ToLowerInvariant()
    if ($name -eq '.htaccess') { return $true }
    $ext = [System.IO.Path]::GetExtension($name)
    return $ext -in @('.php', '.css', '.js', '.html', '.htm', '.json', '.md', '.txt', '.svg', '.xml', '.csv', '.sql')
}

function Convert-BakeryFileNewlines {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][ValidateSet('lf', 'crlf')][string]$Style
    )
    $utf8 = New-Object System.Text.UTF8Encoding $false
    $text = $utf8.GetString([System.IO.File]::ReadAllBytes($Path))
    $normalized = $text -replace "`r`n", "`n"
    if ($Style -eq 'crlf') {
        $normalized = $normalized -replace "`n", "`r`n"
    }
    [System.IO.File]::WriteAllBytes($Path, $utf8.GetBytes($normalized))
}

function Get-BakeryFileSha256 {
    param([Parameter(Mandatory = $true)][string]$Path)
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Resolve-BakeryReleaseFile {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)][string]$RelativePath,
        [Parameter(Mandatory = $true)][string]$ExpectedSha256,
        [string]$Destination = ""
    )
    $expected = $ExpectedSha256.ToLowerInvariant()
    $relWin = $RelativePath -replace '/', '\'
    $disk = Join-Path $BakeryRoot $relWin
    $source = $null
    $via = $null

    if (Test-Path -LiteralPath $disk) {
        $diskHash = Get-BakeryFileSha256 $disk
        if ($diskHash -eq $expected) {
            $source = $disk
            $via = 'working-tree'
        }
    }

    if (-not $source -and (Test-BakeryGitPathExists -RepoRoot $RepoRoot -Commit $Commit -RelativePath $RelativePath)) {
        $gitTmp = Join-Path $env:TEMP ("bakery_git_blob_{0}_{1}" -f [guid]::NewGuid().ToString('N'), ($relWin -replace '[\\/]', '_'))
        Save-BakeryGitPath -RepoRoot $RepoRoot -Commit $Commit -RelativePath $RelativePath -Destination $gitTmp
        $gitHash = Get-BakeryFileSha256 $gitTmp
        if ($gitHash -ne $expected -and (Test-BakeryTextReleasePath $RelativePath)) {
            Copy-Item -LiteralPath $gitTmp -Destination ($gitTmp + '.crlf') -Force
            Convert-BakeryFileNewlines -Path ($gitTmp + '.crlf') -Style crlf
            if ((Get-BakeryFileSha256 ($gitTmp + '.crlf')) -eq $expected) {
                Remove-Item -LiteralPath $gitTmp -Force -ErrorAction SilentlyContinue
                $gitTmp = $gitTmp + '.crlf'
                $gitHash = $expected
            } else {
                Convert-BakeryFileNewlines -Path $gitTmp -Style lf
                if ((Get-BakeryFileSha256 $gitTmp) -eq $expected) {
                    Remove-Item -LiteralPath ($gitTmp + '.crlf') -Force -ErrorAction SilentlyContinue
                    $gitHash = $expected
                } else {
                    Remove-Item -LiteralPath ($gitTmp + '.crlf') -Force -ErrorAction SilentlyContinue
                }
            }
        }
        if ($gitHash -ne $expected) {
            Remove-Item -LiteralPath $gitTmp -Force -ErrorAction SilentlyContinue
            throw "Git blob for $RelativePath at $Commit does not match the staging SHA-256."
        }
        $source = $gitTmp
        $via = 'git'
    }

    if (-not $source) {
        throw "Cannot reconstruct staging-tested $RelativePath from disk or git commit $Commit. In-progress local edits are ignored; re-sync that complete staging release if the tested bytes are gone."
    }

    if ($Destination) {
        $destDir = Split-Path -Parent $Destination
        if ($destDir -and -not (Test-Path -LiteralPath $destDir)) {
            New-Item -ItemType Directory -Force -Path $destDir | Out-Null
        }
        Copy-Item -LiteralPath $source -Destination $Destination -Force
    }
    if ($via -eq 'git' -and $source -and ($source -ne $Destination)) {
        Remove-Item -LiteralPath $source -Force -ErrorAction SilentlyContinue
    }
    return $via
}

function Get-BakeryLatestCompleteStagingManifest {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [string]$ExplicitPath = ""
    )
    if (-not [string]::IsNullOrWhiteSpace($ExplicitPath)) {
        $resolved = (Resolve-Path -LiteralPath $ExplicitPath).Path
        return @{
            Path = $resolved
            Manifest = (Read-BakeryJsonFile -Path $resolved)
        }
    }

    $dir = Join-Path $BakeryRoot 'storage\deploy\stage\releases'
    $files = @(Get-ChildItem -LiteralPath $dir -Filter 'release_*.json' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTimeUtc -Descending)
    if ($files.Count -eq 0) {
        throw "No successful staging release manifest exists under storage/deploy/stage/releases."
    }

    $deployCount = @(Get-BakeryDeployFileList -BakeryRoot $BakeryRoot).Count
    $minComplete = [Math]::Max(50, [int]($deployCount * 0.85))
    $latest = Read-BakeryJsonFile -Path $files[0].FullName
    foreach ($file in $files) {
        $manifest = Read-BakeryJsonFile -Path $file.FullName
        $mode = [string]$manifest.mode
        $lint = [string]$manifest.lint
        $target = [string]$manifest.target
        $count = @($manifest.files).Count
        if ($target -eq 'dreamhost-stage' -and $lint -eq 'ok' -and $mode -eq 'all' -and $count -ge $minComplete) {
            return @{
                Path = $file.FullName
                Manifest = $manifest
                LatestPath = $files[0].FullName
                LatestCommit = [string]$latest.git_commit
                LatestMode = [string]$latest.mode
                LatestFileCount = @($latest.files).Count
            }
        }
    }

    throw "No complete staging release (mode=all, lint=ok, at least $minComplete files) exists. Latest was $([string]$latest.git_commit) mode=$([string]$latest.mode) files=$(@($latest.files).Count). Run a full staging sync (push_sftp_stage.ps1 -All), phone-test, then approve again."
}

function Get-BakeryPythonLauncher {
    $localAppData = [Environment]::GetFolderPath('LocalApplicationData')
    $candidates = @(
        (Join-Path $localAppData 'Programs\Python\Launcher\py.exe'),
        (Join-Path $localAppData 'Programs\Python\Python314\python.exe'),
        (Join-Path $localAppData 'Programs\Python\Python313\python.exe'),
        (Join-Path $localAppData 'Programs\Python\Python312\python.exe'),
        'C:\Python314\python.exe',
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
    throw "Python not found. Install Python 3 so staging-tested files can be fetched when Git/disk do not have them."
}

function Import-BakeryDotEnvProcess {
    param([Parameter(Mandatory = $true)][string]$Path)
    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = $_.Trim()
        if (-not $line -or $line.StartsWith('#')) { return }
        $eq = $line.IndexOf('=')
        if ($eq -lt 1) { return }
        $name = $line.Substring(0, $eq).Trim()
        $value = $line.Substring($eq + 1).Trim().Trim('"').Trim("'")
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

function Get-BakeryEffectiveStagingRelease {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [string]$ExplicitPath = ""
    )
    $complete = Get-BakeryLatestCompleteStagingManifest -BakeryRoot $BakeryRoot -ExplicitPath $ExplicitPath
    $manifest = $complete.Manifest
    $hashes = [ordered]@{}
    foreach ($entry in @($manifest.files)) {
        $hashes[[string]$entry.path] = [string]$entry.sha256
    }
    $commit = [string]$manifest.git_commit
    $schema = @($manifest.schema_noted)
    $recordedAt = $manifest.recorded_at
    $completeTime = (Get-Item -LiteralPath $complete.Path).LastWriteTimeUtc
    $dir = Join-Path $BakeryRoot 'storage\deploy\stage\releases'
    $later = @(Get-ChildItem -LiteralPath $dir -Filter 'release_*.json' -File | Where-Object { $_.LastWriteTimeUtc -gt $completeTime } | Sort-Object LastWriteTimeUtc)
    $overlayCount = 0
    foreach ($file in $later) {
        $next = Read-BakeryJsonFile -Path $file.FullName
        if ([string]$next.target -ne 'dreamhost-stage' -or [string]$next.lint -ne 'ok') { continue }
        foreach ($entry in @($next.files)) {
            $hashes[[string]$entry.path] = [string]$entry.sha256
            $overlayCount++
        }
        if ([string]$next.git_commit -ne '') { $commit = [string]$next.git_commit }
        if ($next.recorded_at) { $recordedAt = $next.recorded_at }
        if (@($next.schema_noted).Count -gt 0) { $schema = @($next.schema_noted) }
    }
    $files = @(
        $hashes.GetEnumerator() | ForEach-Object {
            [pscustomobject]@{ path = $_.Key; sha256 = $_.Value }
        }
    )
    $effective = [pscustomobject]@{
        target = 'dreamhost-stage'
        lint = 'ok'
        mode = 'effective'
        git_commit = $commit
        recorded_at = $recordedAt
        smoke_url = $manifest.smoke_url
        schema_noted = $schema
        files = $files
        file_count = $files.Count
    }
    return @{
        Path = [string]$complete.Path
        Manifest = $effective
        CompletePath = [string]$complete.Path
        OverlayCount = $overlayCount
        LatestCommit = $commit
    }
}

function Fetch-BakeryStagingFiles {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [Parameter(Mandatory = $true)][string[]]$RelativePaths,
        [Parameter(Mandatory = $true)][string]$DestinationRoot
    )
    $stageEnv = Join-Path $BakeryRoot '.env.sftp.stage'
    if (-not (Test-Path -LiteralPath $stageEnv)) {
        throw 'Missing .env.sftp.stage; cannot fetch staging-tested files that are not in Git or the working tree.'
    }
    $keys = @('SFTP_HOST', 'SFTP_USER', 'SFTP_PASSWORD', 'SFTP_REMOTE_ROOT', 'SFTP_TARGET')
    $saved = @{}
    foreach ($name in $keys) {
        $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, $null, 'Process')
    }
    $listFile = Join-Path $env:TEMP ("bakery_fetch_{0}.txt" -f [guid]::NewGuid().ToString('N'))
    try {
        Import-BakeryDotEnvProcess -Path $stageEnv
        $root = [Environment]::GetEnvironmentVariable('SFTP_REMOTE_ROOT', 'Process')
        $user = [Environment]::GetEnvironmentVariable('SFTP_USER', 'Process')
        $target = [Environment]::GetEnvironmentVariable('SFTP_TARGET', 'Process')
        if ($root -match 'bakery\.sourflour\.org/bake' -or $user -ne 'bakeryOS' -or $target -ne 'dreamhost-stage') {
            throw 'Refusing fetch: .env.sftp.stage is not locked to bakeryOS / staging.sourflour.org / dreamhost-stage.'
        }
        New-Item -ItemType Directory -Force -Path $DestinationRoot | Out-Null
        $utf8 = New-Object System.Text.UTF8Encoding $false
        [System.IO.File]::WriteAllLines($listFile, $RelativePaths, $utf8)
        $python = Get-BakeryPythonLauncher
        $uploader = Join-Path $BakeryRoot 'scripts\sftp_upload.py'
        $pyArgs = @()
        if ((Split-Path $python -Leaf) -in @('py.exe', 'py')) { $pyArgs += '-3' }
        $pyArgs += @($uploader, '--local-root', $DestinationRoot, '--list', $listFile, '--fetch')
        & $python @pyArgs
        if ($LASTEXITCODE -ne 0) { throw "Staging fetch failed (exit $LASTEXITCODE)." }
    } finally {
        Remove-Item -LiteralPath $listFile -Force -ErrorAction SilentlyContinue
        foreach ($name in $keys) {
            [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process')
        }
    }
}

function Restore-BakeryReleaseFiles {
    param(
        [Parameter(Mandatory = $true)][string]$RepoRoot,
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [Parameter(Mandatory = $true)][string]$Commit,
        [Parameter(Mandatory = $true)]$Files,
        [string]$DestinationRoot = ""
    )
    if (@($Files).Count -eq 0) { return }
    $failed = New-Object System.Collections.Generic.List[object]
    foreach ($entry in @($Files)) {
        $rel = [string]$entry.path
        $dest = if ($DestinationRoot) { Join-Path $DestinationRoot ($rel -replace '/', '\') } else { '' }
        try {
            [void](Resolve-BakeryReleaseFile -RepoRoot $RepoRoot -BakeryRoot $BakeryRoot -Commit $Commit -RelativePath $rel -ExpectedSha256 ([string]$entry.sha256) -Destination $dest)
        } catch {
            $failed.Add($entry) | Out-Null
        }
    }
    if ($failed.Count -eq 0) { return }

    Write-Host "Fetching $($failed.Count) staging-tested file(s) that are not in Git or the current working tree..."
    $fetchRoot = if ($DestinationRoot) { $DestinationRoot } else { Join-Path $env:TEMP ("bakery_fetch_tree_{0}" -f [guid]::NewGuid().ToString('N')) }
    Fetch-BakeryStagingFiles -BakeryRoot $BakeryRoot -RelativePaths @($failed | ForEach-Object { [string]$_.path }) -DestinationRoot $fetchRoot
    $still = New-Object System.Collections.Generic.List[string]
    foreach ($entry in $failed) {
        $rel = [string]$entry.path
        $got = Join-Path $fetchRoot ($rel -replace '/', '\')
        $expected = ([string]$entry.sha256).ToLowerInvariant()
        if (-not (Test-Path -LiteralPath $got) -or (Get-BakeryFileSha256 $got) -ne $expected) {
            $still.Add($rel) | Out-Null
        }
    }
    if ($still.Count -gt 0) {
        throw "Staging no longer has the tested bytes for: $($still -join ', '). A later incremental push overwrote them. Run a complete staging sync from a clean commit, phone-test, and approve again."
    }
    if (-not $DestinationRoot -and (Test-Path -LiteralPath $fetchRoot)) {
        Remove-Item -LiteralPath $fetchRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Get-BakeryLiveHashIndexPath {
    param([Parameter(Mandatory = $true)][string]$BakeryRoot)
    return (Join-Path $BakeryRoot 'storage\deploy\LIVE_HASHES.json')
}

function Read-BakeryLiveHashIndex {
    param([Parameter(Mandatory = $true)][string]$BakeryRoot)
    $path = Get-BakeryLiveHashIndexPath -BakeryRoot $BakeryRoot
    $map = @{}
    if (Test-Path -LiteralPath $path) {
        $data = Read-BakeryJsonFile -Path $path
        foreach ($entry in @($data.files)) {
            $rel = [string]$entry.path
            $hash = ([string]$entry.sha256).ToLowerInvariant()
            if ($rel -and $hash) { $map[$rel] = $hash }
        }
        return $map
    }

    $lastPath = Join-Path $BakeryRoot 'storage\deploy\LAST_DEPLOY.json'
    if (-not (Test-Path -LiteralPath $lastPath)) { return $map }
    $last = Read-BakeryJsonFile -Path $lastPath
    if ([string]$last.mode -ne 'candidate') { return $map }
    $commit = [string]$last.git_commit
    $candDir = Join-Path $BakeryRoot 'storage\deploy\releases'
    $seed = $null
    foreach ($file in @(Get-ChildItem -LiteralPath $candDir -Filter 'candidate_*.json' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTimeUtc -Descending)) {
        $cand = Read-BakeryJsonFile -Path $file.FullName
        if ([string]$cand.production_status -ne 'promoted') { continue }
        $candCommit = [string]$cand.git_commit
        if ($commit -ne '' -and $candCommit -ne '' -and $commit -ne $candCommit -and -not $commit.StartsWith($candCommit) -and -not $candCommit.StartsWith($commit)) { continue }
        if (@($cand.files).Count -lt 50) { continue }
        $seed = $cand
        break
    }
    if ($null -eq $seed) { return $map }
    foreach ($entry in @($seed.files)) {
        $rel = [string]$entry.path
        $hash = ([string]$entry.sha256).ToLowerInvariant()
        if ($rel -and $hash) { $map[$rel] = $hash }
    }
    Write-BakeryLiveHashIndex -BakeryRoot $BakeryRoot -Hashes $map -GitCommit $commit -Source 'seed-from-promoted-candidate'
    return $map
}

function Write-BakeryLiveHashIndex {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [Parameter(Mandatory = $true)][hashtable]$Hashes,
        [string]$GitCommit = "",
        [string]$Source = ""
    )
    $files = @(
        $Hashes.GetEnumerator() | Sort-Object Name | ForEach-Object {
            [pscustomobject]@{ path = [string]$_.Name; sha256 = ([string]$_.Value).ToLowerInvariant() }
        }
    )
    $payload = [ordered]@{
        recorded_at_utc = (Get-Date).ToUniversalTime().ToString('o')
        git_commit = $GitCommit
        source = $Source
        file_count = $files.Count
        files = $files
    }
    Write-BakeryJsonFile -Path (Get-BakeryLiveHashIndexPath -BakeryRoot $BakeryRoot) -Object $payload -Depth 6
}

function Select-BakeryChangedReleaseFiles {
    param(
        [Parameter(Mandatory = $true)]$Files,
        [hashtable]$LiveHashes = @{}
    )
    $changed = New-Object System.Collections.Generic.List[object]
    $unchanged = New-Object System.Collections.Generic.List[object]
    foreach ($entry in @($Files)) {
        $rel = [string]$entry.path
        $hash = ([string]$entry.sha256).ToLowerInvariant()
        if ($rel -and $hash -and $LiveHashes.ContainsKey($rel) -and $LiveHashes[$rel] -eq $hash) {
            $unchanged.Add($entry) | Out-Null
        } else {
            $changed.Add($entry) | Out-Null
        }
    }
    return [pscustomobject]@{
        Changed = $changed.ToArray()
        Unchanged = $unchanged.ToArray()
    }
}

function Merge-BakeryLiveHashIndex {
    param(
        [Parameter(Mandatory = $true)][string]$BakeryRoot,
        [Parameter(Mandatory = $true)]$Files,
        [string]$GitCommit = "",
        [string]$Source = ""
    )
    $map = Read-BakeryLiveHashIndex -BakeryRoot $BakeryRoot
    foreach ($entry in @($Files)) {
        $rel = [string]$entry.path
        $hash = ([string]$entry.sha256).ToLowerInvariant()
        if ($rel -and $hash) { $map[$rel] = $hash }
    }
    Write-BakeryLiveHashIndex -BakeryRoot $BakeryRoot -Hashes $map -GitCommit $GitCommit -Source $Source
}
