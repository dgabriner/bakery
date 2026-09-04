<?php
/**
 * Verify deployed files on production match expected sizes/markers.
 * Upload temporarily, visit in browser, delete when done.
 */
define('ACCESS_ALLOWED', true);

header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$lines = [];

function line(string $msg): void
{
    global $lines;
    $lines[] = $msg;
}

function check_file(string $root, string $rel, int $minBytes, array $needles = []): void
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($path)) {
        line("MISSING: {$rel}");
        return;
    }
    $size = filesize($path);
    if ($size === false || $size < $minBytes) {
        line("BAD SIZE: {$rel} = {$size} bytes (expected >= {$minBytes}) — upload likely truncated");
        return;
    }
    line("OK size: {$rel} = {$size} bytes");
    if (!empty($needles)) {
        $contents = file_get_contents($path);
        foreach ($needles as $needle) {
            if (strpos($contents, $needle) === false) {
                line("  MISSING MARKER in {$rel}: {$needle}");
            }
        }
    }
}

try {
    require_once $root . '/includes/env_loader.php';
    bakery_load_env_file($root . '/.env');
    require_once $root . '/includes/config.php';
    require_once $root . '/includes/database.php';

    line('=== Bakery deploy verification ===');
    line('PHP: ' . PHP_VERSION);
    line('APP_ENV: ' . APP_ENV);
    line('BASE_URL: ' . BASE_URL);
    line('Script path: ' . ($_SERVER['SCRIPT_NAME'] ?? ''));
    line('Show errors flag: ' . (function_exists('bakery_show_errors_enabled') && bakery_show_errors_enabled() ? 'ON' : 'off'));
    line('');

    line('--- Critical files (size + markers) ---');
    check_file($root, 'includes/common_functions.php', 24000, ['function bakery_json_for_html', 'function bakery_get_drivers']);
    check_file($root, 'includes/page_probe.php', 500, ['function bakery_page_probe_arm']);
    check_file($root, 'includes/production_errors.php', 400, ['function bakery_register_production_error_probe']);
    check_file($root, 'includes/config.php', 8000, ['function bakery_resolve_base_url', 'production_errors.php']);
    check_file($root, 'includes/auth.php', 7000, ['function bakery_enforce_request_security']);
    check_file($root, 'driver_list.php', 20000, [
        'function toggleOrderDetails(stopItem, customerId, customerName)',
    ]);
    check_file($root, 'driver_assignment.php', 64000, [
        '<!-- BUILD: driver-assignment-append-20260801 -->',
        'pageLoadError',
    ]);
    check_file($root, 'dough_types.php', 35000, [
        'BUILD: dough-types-20260804',
        'showDoughTypeModal',
        "require_once 'includes/footer.php'",
    ]);
    check_file($root, 'css/driver.css', 5000, ['.driver-list-container']);
    check_file($root, 'includes/csrf.js', 500, ['X-CSRF-Token']);
    line('');

    line('--- Runtime checks ---');
    line('bakery_json_for_html: ' . (function_exists('bakery_json_for_html') ? 'yes' : 'NO'));
    line('bakery_get_drivers: ' . (function_exists('bakery_get_drivers') ? 'yes' : 'NO'));
    $db = check_mysql_connection();
    line('database: connected');
    $drivers = bakery_get_drivers($db);
    line('drivers count: ' . count($drivers));
    line('');

    if (!function_exists('bakery_cron_age_hours')) {
        require_once $root . '/includes/cron_run.php';
    }
    line('--- Overnight cron ---');
    // Keys reported to operators (also asserted by Mission 60 suites):
    // cron.demand_scheduler.age_hours / cron.staff_alert_digest.age_hours
    foreach (['demand_scheduler', 'staff_alert_digest'] as $cronName) {
        $age = bakery_cron_age_hours($cronName);
        $key = 'cron.' . $cronName . '.age_hours';
        line($key . '=' . ($age === null ? 'null' : (string)$age));
    }
    line('');

    $manifest = $root . '/storage/deploy/deploy_file_sizes.json';
    if (!is_readable($manifest)) {
        $manifest = $root . '/deploy_file_sizes.json';
    }
    if (is_readable($manifest)) {
        line('--- Manifest from deploy ZIP ---');
        $expected = json_decode(file_get_contents($manifest), true);
        if (is_array($expected)) {
            foreach ($expected as $rel => $size) {
                $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (!is_file($path)) {
                    line("MANIFEST MISSING: {$rel}");
                    continue;
                }
                $actual = filesize($path);
                if ((int)$actual !== (int)$size) {
                    line("MANIFEST MISMATCH: {$rel} server={$actual} expected={$size}");
                }
            }
            line('Manifest compare complete.');
        }
    } else {
        line('No deploy_file_sizes.json on server (optional — build a new ZIP locally).');
    }

    line('');
    line('If any BAD SIZE / MISSING MARKER lines appear, re-upload a fresh deploy ZIP.');
    line('To see PHP fatals on broken pages, add BAKERY_SHOW_ERRORS=1 to .env temporarily.');
    line('Delete health_deploy.php when finished.');
} catch (Throwable $e) {
    line('');
    line('FAILED: ' . $e->getMessage());
}

echo implode("\n", $lines) . "\n";
