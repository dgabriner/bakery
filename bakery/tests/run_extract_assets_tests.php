<?php
/**
 * Prompt 50 — extracted chrome assets for the six god-pages.
 * Usage: php tests/run_extract_assets_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

$pages = [
    'route_manager' => 'window.__ROUTE_MANAGER__',
    'standing_orders_manager' => 'window.__STANDING_ORDERS_MANAGER__',
    'driver_overview' => 'window.__DRIVER_OVERVIEW__',
    'driver_assignment' => 'window.__DRIVER_ASSIGNMENT__',
    'customer_schedule' => 'window.__CUSTOMER_SCHEDULE__',
    'standing_routes' => null,
];

foreach ($pages as $slug => $bootstrap) {
    $phpPath = $root . '/' . $slug . '.php';
    $cssPath = $root . '/css/' . $slug . '.css';
    $jsPath = $root . '/includes/' . $slug . '.js';
    $src = (string)file_get_contents($phpPath);
    $assert(is_readable($cssPath), "$slug css exists");
    $assert(is_readable($jsPath), "$slug js exists");
    $assert(strpos($src, '<style>') === false && strpos($src, '<style ') === false, "$slug has no inline <style>");
    $assert(strpos($src, 'css/' . $slug . '.css') !== false, "$slug links css via bakery_asset_href path");
    $assert(strpos($src, 'includes/' . $slug . '.js') !== false, "$slug links js via bakery_asset_href path");
    if ($bootstrap !== null) {
        $assert(strpos($src, $bootstrap) !== false, "$slug keeps JSON bootstrap $bootstrap");
    }
    // No large inline script bodies beyond bootstrap (< 2k chars).
    if (preg_match_all('/<script\b([^>]*)>(.*?)<\\/script>/is', $src, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            if (stripos($m[1], 'src=') !== false) {
                continue;
            }
            $len = strlen(trim($m[2]));
            $assert($len < 2000, "$slug inline script is bootstrap-sized ($len chars)");
        }
    }
    $lines = substr_count($src, "\n") + 1;
    echo "NOTE  $slug.php shell lines=$lines\n";
}

$css = (string)file_get_contents($root . '/css/route_manager.css');
$assert(strpos($css, 'data-color="14"') !== false, 'route_manager.css ships full driver color palette');

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
