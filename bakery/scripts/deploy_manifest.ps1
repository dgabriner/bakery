# Shared production deploy manifest for Bakery Manager.
# Dot-source from build_deploy_zip.ps1, list_deploy_changes.ps1, record_deploy.ps1

function Get-BakeryDeployRootFiles {
    return @(
        '.htaccess',
        'index.php', 'login.php', 'logout.php', 'baker.php',
        'customers.php', 'customer_schedule.php', 'customer_overview.php', 'customer_routes.php',
        'zones.php', 'leads.php', 'pan_dulce_pricing.php',
        'products.php', 'dough_types.php', 'formulas.php', 'ingredients.php',
        'daily_orders.php', 'standing_orders.php', 'standing_orders_manager.php', 'orders.php',
        'bread_distribution.php', 'product_distribution.php',
        'production.php', 'pack_list.php',
        'standing_routes.php', 'daily_route.php',
        'drivers.php', 'driver.php', 'driver_list.php', 'driver_assignment.php', 'driver_overview.php',
        'route_manager.php', 'map.php', 'call_headquarters.php',
        'complete_delivery.php', 'get_driver_orders.php', 'get_customer_order_details.php', 'global_gps_handler.php',
        'upload_driver_photo.php',
        'generate_invoice.php', 'oauth_callback.php',
        'driver_pages_probe.php', 'health_driver.php', 'health_deploy.php', 'trace_driver_list.php', 'ping.php'
    )
}

function Get-BakeryDeployDirectories {
    return @('includes', 'css', 'assets')
}

function Get-BakeryDeployOptionalPaths {
    return @(
        @{ Path = 'vendor\phpmailer'; Required = $false },
        @{ Path = 'uploads\driver_photos'; Required = $false; Files = @('.htaccess') }
    )
}

function Get-BakeryDeployExcludeNamePatterns {
    return @(
        '*_backup.php', '*backup.php', '*_fixed.php', '*_optimized.php', '*_working.php',
        '*Copy*.php', 'debug*.php', 'simple-debug.php', 'simple_performance_test.php',
        'health_local.php', 'health_prod.php',
        'run_sql_setup.php', 'db_test.php', 'setup_directories.php', 'oauth_setup.php',
        'auto_push_api.php', 'sourflour.html'
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

    foreach ($file in (Get-BakeryDeployRootFiles)) {
        $src = Join-Path $BakeryRoot $file
        if (Test-Path $src) { Add-DeployPath $file }
    }

    # New/edited root web files since last push (so test_upload.php etc. are not ignored)
    if ($AlsoIncludeRootModifiedAfterUtc -gt [datetime]::MinValue) {
        Get-ChildItem -Path $BakeryRoot -File | ForEach-Object {
            if (-not (Test-BakeryDeployWebRootFile $_)) { return }
            if ($_.LastWriteTimeUtc -le $AlsoIncludeRootModifiedAfterUtc) { return }
            Add-DeployPath $_.Name
        }
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
    if (-not (Test-Path $full)) {
        return $null
    }
    $item = Get-Item $full
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
    if (-not (Test-Path $Path)) { return $null }
    return Get-Content $Path -Raw | ConvertFrom-Json
}

function ConvertTo-BakeryDeployBaselineMap {
    param($BaselineFiles)

    $baselineMap = @{}
    if (-not $BaselineFiles) { return $baselineMap }
    foreach ($entry in $BaselineFiles.PSObject.Properties) {
        $baselineMap[$entry.Name] = @{
            size = [int64]$entry.Value.size
            mtime = [string]$entry.Value.mtime
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
        Where-Object { $_.LastWriteTimeUtc -gt $SinceUtc } |
        Sort-Object Name |
        ForEach-Object {
            @{
                path = ("database/schema/{0}" -f $_.Name)
                mtime = $_.LastWriteTimeUtc.ToString('o')
                size = $_.Length
            }
        }
}
