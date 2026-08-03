<?php
/**
 * Step-by-step bootstrap trace for driver pages. Upload, visit, delete.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);
header('Content-Type: text/plain; charset=utf-8');

function step(string $label): void
{
    echo $label . "\n";
    if (function_exists('flush')) {
        @flush();
    }
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "\nSHUTDOWN FATAL: {$err['message']}\n";
        echo "File: {$err['file']}:{$err['line']}\n";
    }
});

try {
    step('1 start');

    $driverList = __DIR__ . '/driver_list.php';
    if (!is_file($driverList)) {
        throw new RuntimeException('driver_list.php not found');
    }
    $size = filesize($driverList);
    step('2 driver_list.php size=' . $size);
    if ($size < 1000) {
        throw new RuntimeException('driver_list.php looks empty/truncated on server');
    }

    step('3 load env_loader');
    require_once __DIR__ . '/includes/env_loader.php';
    bakery_load_env_file(__DIR__ . '/.env');

    step('4 load config');
    require_once __DIR__ . '/includes/config.php');

    step('5 connect database (auth skipped for this trace)');
    require_once __DIR__ . '/includes/database.php';

    step('6 common_functions checks');
    step('   bakery_get_drivers=' . (function_exists('bakery_get_drivers') ? 'yes' : 'NO'));
    step('   bakery_json_for_html=' . (function_exists('bakery_json_for_html') ? 'yes' : 'NO'));

    if (!function_exists('bakery_get_drivers')) {
        throw new RuntimeException('Upload includes/common_functions.php from latest ZIP');
    }

    step('7 bakery_get_drivers');
    $drivers = bakery_get_drivers($db);
    step('   drivers=' . count($drivers));

    step('8 driver_list route query');
    $driverId = 0;
    foreach ($drivers as $d) {
        $driverId = (int)$d['id'];
        if ($driverId > 0) {
            break;
        }
    }
    if ($driverId > 0) {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM daily_orders do
             INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
             WHERE doa.driver_id = ? AND do.order_date = CURDATE()'
        );
        $stmt->execute([$driverId]);
        step('   stops today for driver #' . $driverId . ' = ' . $stmt->fetchColumn());
    }

    step('9 driver_list.php markers');
    $code = file_get_contents($driverList);
    step('   BUILD marker=' . (strpos($code, 'BUILD: driver-list-20260729') !== false ? 'yes' : 'NO'));
    step('   uses __DIR__ includes=' . (strpos($code, "__DIR__ . '/includes/config.php'") !== false ? 'yes' : 'NO'));

    step('10 auth.php public trace entry=' . (strpos(file_get_contents(__DIR__ . '/includes/auth.php'), 'trace_driver_list.php') !== false ? 'yes' : 'NO'));

    step('11 DONE — DB + includes OK.');
    step('If driver_list.php is still blank while logged in, add BAKERY_SHOW_ERRORS=1 to .env and reload driver_list.');
} catch (Throwable $e) {
    step('');
    step('FAILED: ' . $e->getMessage());
    step('File: ' . $e->getFile() . ':' . $e->getLine());
}
