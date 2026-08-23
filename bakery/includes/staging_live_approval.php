<?php
/**
 * Staging-only hosted promotion approval.
 *
 * Approval snapshots the exact bytes currently on Staging. A restricted,
 * read-only Live worker pulls this manifest and those files; Git and localhost
 * are deliberately not part of the promotion gate.
 */

function bakery_staging_live_approval_available(): bool {
    return defined('IS_STAGING') && IS_STAGING
        && function_exists('bakery_user_has_role')
        && bakery_user_has_role(['administrator']);
}

function bakery_staging_live_approval_path(): string {
    return bakery_staging_live_export_root() . DIRECTORY_SEPARATOR . 'ready_for_live.json';
}

function bakery_staging_live_export_root(): string {
    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '.sourflour-promotion-export';
}

function bakery_staging_live_approval_latest(): ?array {
    $path = bakery_staging_live_approval_path();
    if (!is_file($path)) return null;
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)@file_get_contents($path));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function bakery_staging_live_root_files(): array {
    return [
        '.htaccess',
        'index.php', 'login.php', 'logout.php', 'baker.php', 'build_id.php', 'qr_login.php', 'customer_qr_login.php',
        'customers.php', 'customer_schedule.php', 'customer_overview.php', 'customer_routes.php',
        'zones.php', 'leads.php', 'pan_dulce_pricing.php',
        'products.php', 'dough_types.php', 'formulas.php', 'ingredients.php',
        'daily_orders.php', 'standing_orders.php', 'standing_orders_manager.php', 'orders.php',
        'bread_distribution.php', 'product_distribution.php', 'production.php', 'pack_list.php',
        'standing_routes.php', 'daily_route.php', 'drivers.php', 'driver.php', 'driver_list.php',
        'driver_assignment.php', 'driver_overview.php', 'route_manager.php', 'route_summary.php',
        'map.php', 'call_headquarters.php', 'complete_delivery.php', 'get_driver_orders.php',
        'get_customer_order_details.php', 'global_gps_handler.php', 'upload_driver_photo.php',
        'daily_run.php', 'daily_run_api.php', 'daily_brief.php', 'manager.php', 'billing_center.php',
        'production_center.php', 'customer_login.php', 'customer_portal.php', 'customer_portal_tip.php',
        'customer_portal_regular.php',
        'customer_portal_account.php', 'customer_portal_notifications.php', 'customer_portal_delivery.php',
        'customer_catalog.php', 'customer_upcoming_edit.php', 'customer_record.php', 'route_closeout.php',
        'route_analysis.php', 'driver_load.php', 'driver_stops.php', 'driver_session_ping.php',
        'users.php', 'walkthroughs.php', 'guias.php', 'login_history.php', 'generate_invoice.php',
        'oauth_callback.php', 'sfb_dashboard.php', 'sfb_starters.php', 'sfb_ingredients.php',
        'sfb_formulas.php', 'sfb_batches.php', 'sfb_batch.php', 'sfb_resources.php', 'sfb_community.php',
        'sfb_community_topic.php', 'sfb_shared_batch.php', 'sfb_admin_overview.php', 'sfb_admin_batch.php',
        'sfb_admin_impersonate.php', 'sfb_admin_studio.php', 'sfb_admin_studio_baker.php',
        'agent_homebase.php', 'deploy_status.php', 'migration_status.php', 'schema_status.php',
    ];
}

function bakery_staging_live_skip_name(string $name): bool {
    foreach ([
        '*_backup.php', '*backup.php', '*_fixed.php', '*_optimized.php', '*_working.php',
        '*Copy*.php', 'debug*.php', 'simple-debug.php', 'simple_performance_test.php',
        'health_local.php', 'health_prod.php', 'health_driver.php', 'health_deploy.php',
        'driver_pages_probe.php', 'trace_driver_list.php', 'ping.php', 'run_sql_setup.php',
        'db_test.php', 'setup_directories.php', 'oauth_setup.php', 'auto_push_api.php',
        'sourflour.html', 'tmp_*.php', 'tmp_*.js', 'tmp_*.txt',
    ] as $pattern) {
        if (fnmatch($pattern, $name, FNM_CASEFOLD)) return true;
    }
    return false;
}

function bakery_staging_live_add_tree(array &$paths, string $root, string $relativeRoot, ?array $onlyNames = null): void {
    $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
    if (!is_dir($absolute)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || bakery_staging_live_skip_name($file->getFilename())) continue;
        if ($onlyNames !== null && !in_array($file->getFilename(), $onlyNames, true)) continue;
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $paths[$relative] = $file->getPathname();
    }
}

function bakery_staging_live_snapshot_files(): array {
    $root = dirname(__DIR__);
    $paths = [];
    foreach (bakery_staging_live_root_files() as $relative) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute) && !is_link($absolute)) $paths[$relative] = $absolute;
    }
    foreach (['includes', 'css', 'assets', 'lang', 'vendor/phpmailer'] as $dir) {
        bakery_staging_live_add_tree($paths, $root, $dir);
    }
    ksort($paths, SORT_STRING);
    if (count($paths) < 50 || count($paths) > 2000) {
        throw new RuntimeException('Staging file snapshot is incomplete or unexpectedly large. Promotion was not queued.');
    }
    $files = [];
    $totalBytes = 0;
    foreach ($paths as $relative => $absolute) {
        $size = (int)filesize($absolute);
        $totalBytes += $size;
        if ($size > 50 * 1024 * 1024 || $totalBytes > 500 * 1024 * 1024) {
            throw new RuntimeException('Staging file snapshot exceeds the promotion safety limit.');
        }
        $files[] = ['path' => $relative, 'size' => $size, 'sha256' => hash_file('sha256', $absolute)];
    }
    return $files;
}

function bakery_staging_live_status(): ?array {
    $context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents('https://bakery.sourflour.org/bake/deploy_status.php', false, $context);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function bakery_staging_live_approval_submit(string $releaseId = '', string $commit = ''): array {
    if (!bakery_staging_live_approval_available()) {
        throw new RuntimeException('Live promotion is available only to administrators on Staging.');
    }
    $files = bakery_staging_live_snapshot_files();
    $releaseId = 'stage-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $path = bakery_staging_live_approval_path();
    $exportRoot = bakery_staging_live_export_root();
    $releasesRoot = $exportRoot . DIRECTORY_SEPARATOR . 'releases';
    if (!is_dir($releasesRoot) && !@mkdir($releasesRoot, 0700, true) && !is_dir($releasesRoot)) {
        throw new RuntimeException('Staging approval storage is not writable.');
    }
    $user = bakery_current_user();
    $record = [
        'format' => 2,
        'status' => 'approved_for_live',
        'release_id' => $releaseId,
        'approved_at' => gmdate('c'),
        'approved_at_local' => date('c'),
        'approved_by' => (string)($user['email'] ?? $user['username'] ?? 'administrator'),
        'environment' => 'staging',
        'file_count' => count($files),
        'files' => $files,
    ];
    $releaseTemp = $releasesRoot . DIRECTORY_SEPARATOR . $releaseId . '.tmp-' . bin2hex(random_bytes(3));
    $releasePath = $releasesRoot . DIRECTORY_SEPARATOR . $releaseId;
    if (!@mkdir($releaseTemp . DIRECTORY_SEPARATOR . 'files', 0700, true)) {
        throw new RuntimeException('Could not create the private Staging release export.');
    }
    $webRoot = dirname(__DIR__);
    foreach ($files as $entry) {
        $relative = (string)$entry['path'];
        $source = $webRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $releaseTemp . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir(dirname($destination)) && !@mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) {
            throw new RuntimeException('Could not prepare the private Staging release export.');
        }
        if (!@copy($source, $destination) || !hash_equals((string)$entry['sha256'], hash_file('sha256', $destination))) {
            throw new RuntimeException('Could not verify the private Staging release export.');
        }
        @chmod($destination, 0600);
    }
    if (@file_put_contents(
        $releaseTemp . DIRECTORY_SEPARATOR . 'release.json',
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX
    ) === false || !@rename($releaseTemp, $releasePath)) {
        throw new RuntimeException('Could not finalize the private Staging release export.');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX) === false
        || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Could not queue the Staging promotion.');
    }
    return $record;
}
