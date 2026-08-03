<?php
/**
 * Production diagnostic for driver_list.php / driver_assignment.php blank pages.
 * No login required. Upload via deploy ZIP, visit, delete when done.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

header('Content-Type: text/plain; charset=utf-8');

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nSHUTDOWN FATAL: {$err['message']}\n{$err['file']}:{$err['line']}\n";
    }
});

function step(string $label): void
{
    echo $label . "\n";
    @flush();
}

$root = __DIR__;

try {
    step('=== Driver pages probe ===');
    step('PHP ' . PHP_VERSION);
    step('Time ' . date('c'));
    step('');

    foreach (['driver_list.php', 'driver_assignment.php', 'includes/common_functions.php', 'includes/production_errors.php'] as $rel) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            step("MISSING FILE: {$rel}");
            continue;
        }
        $size = filesize($path);
        step("OK {$rel} = {$size} bytes");
        $head = file_get_contents($path, false, null, 0, 200);
        if ($head !== false && strpos($head, '<?php') !== 0) {
            step("  WARN: {$rel} does not start with <?php (BOM/whitespace?)");
        }
    }

    step('');
    step('--- Markers ---');
    $driverList = file_get_contents($root . '/driver_list.php');
    step('driver_list BUILD marker: ' . (strpos($driverList, 'BUILD: driver-list-20260729') !== false ? 'yes' : 'NO'));
    step('driver_list __DIR__ requires: ' . (strpos($driverList, "__DIR__ . '/includes/config.php'") !== false ? 'yes' : 'NO'));
    step('driver_list function guard: ' . (strpos($driverList, 'bakery_json_for_html') !== false && strpos($driverList, 'function_exists') !== false ? 'yes' : 'NO'));

    $driverAssign = file_get_contents($root . '/driver_assignment.php');
    step('driver_assignment BUILD marker: ' . (strpos($driverAssign, 'BUILD: driver-assignment-20260729') !== false ? 'yes' : 'NO'));

    step('');
    step('--- Bootstrap ---');
    require_once $root . '/includes/env_loader.php';
    bakery_load_env_file($root . '/.env');
    require_once $root . '/includes/config.php';
    step('config OK APP_ENV=' . APP_ENV . ' BASE_URL=' . BASE_URL);
    step('show errors: ' . (function_exists('bakery_show_errors_enabled') && bakery_show_errors_enabled() ? 'ON' : 'off'));

    require_once $root . '/includes/database.php';
    step('database OK');

    step('bakery_get_drivers: ' . (function_exists('bakery_get_drivers') ? 'yes' : 'NO'));
    step('bakery_json_for_html: ' . (function_exists('bakery_json_for_html') ? 'yes' : 'NO'));

    if (!function_exists('bakery_get_drivers')) {
        throw new RuntimeException('common_functions.php is missing bakery_get_drivers — redeploy full ZIP');
    }

    $drivers = bakery_get_drivers($db);
    step('drivers count: ' . count($drivers));

    step('');
    step('--- driver_list query smoke test ---');
    if (!empty($drivers)) {
        $driverId = (int)$drivers[0]['id'];
        $today = date('Y-m-d');
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM daily_orders do
             INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
             WHERE doa.driver_id = ? AND do.order_date = ?'
        );
        $stmt->execute([$driverId, $today]);
        step("route rows today for driver #{$driverId}: " . $stmt->fetchColumn());
    }

    step('');
    step('--- driver_assignment query smoke test ---');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $dow = date('N', strtotime($tomorrow));
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM daily_orders do
         JOIN customers c ON do.customer_id = c.id
         LEFT JOIN daily_order_assignments doa ON do.id = doa.daily_order_id AND doa.delivery_date = do.order_date
         WHERE do.order_date = ?'
    );
    $stmt->execute([$tomorrow]);
    step("assignment page orders for {$tomorrow}: " . $stmt->fetchColumn());

    $stmt = $db->prepare('SELECT COUNT(*) FROM standing_routes WHERE day_of_week = ?');
    $stmt->execute([$dow]);
    step("standing routes for dow {$dow}: " . $stmt->fetchColumn());

    step('');
    step('--- JSON embed smoke test ---');
    $sample = ['customer_name' => "Test O'Brien</script>", 'address' => '123 Main'];
    $json = bakery_json_for_html($sample, '{}');
    step('bakery_json_for_html sample len=' . strlen($json));
    if (strpos($json, '</script>') !== false) {
        step('WARN: JSON still contains </script>');
    }

    step('');
    step('--- css/js assets ---');
    foreach (['css/driver.css', 'includes/global_tracking.js', 'includes/csrf.js'] as $rel) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        step((is_file($path) ? 'OK' : 'MISSING') . " {$rel}");
    }

    step('');
    step('ALL CHECKS PASSED.');
    step('If driver_list.php is still blank while logged in:');
    step('  1. Set BAKERY_SHOW_ERRORS=1 in .env');
    step('  2. Visit driver_list.php?probe=1 while logged in');
    step('  3. Check browser Network tab for HTTP status (302 vs 500 vs 200)');
    step('Delete driver_pages_probe.php when finished.');
} catch (Throwable $e) {
    step('');
    step('FAILED: ' . $e->getMessage());
    step($e->getFile() . ':' . $e->getLine());
}
