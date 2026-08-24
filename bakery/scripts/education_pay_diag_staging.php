<?php
/** Read-only Square SANDBOX diagnostic: where does the order id live for a
 *  created payment link? Prints response shapes, no credentials. */
define('ACCESS_ALLOWED', true);
$root = '/home/bakeryOS/staging.sourflour.org';
require_once $root . '/includes/env_loader.php';
bakery_clear_env_keys(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'APP_ENV', 'USE_PROD_DB']);
bakery_load_env_file($root . '/.env', true);
putenv('APP_ENV=staging');
$_ENV['APP_ENV'] = 'staging';
$_SERVER['APP_ENV'] = 'staging';
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/sf_baker.php';
require_once $root . '/includes/square_config.php';

$db = check_mysql_connection();
bakery_assert_dreamhost_staging_target($db);

$linkId = isset($argv[1]) ? (string)$argv[1] : '';
if ($linkId === '') {
    $row = $db->query('SELECT square_payment_link_id FROM sfb_offering_purchases WHERE square_payment_link_id IS NOT NULL ORDER BY id DESC LIMIT 1')->fetchColumn();
    $linkId = (string)$row;
}
echo "inspecting payment link: {$linkId}\n";

$resp = square_api_request('GET', '/v2/online-checkout/payment-links/' . rawurlencode($linkId));
echo "\n-- top-level keys --\n";
echo implode(', ', array_keys($resp)) . "\n";
$pl = $resp['payment_link'] ?? [];
echo "-- payment_link keys --\n";
echo implode(', ', array_keys($pl)) . "\n";
echo "-- payment_link scalar values (sandbox refs only) --\n";
foreach ($pl as $k => $v) {
    if (is_scalar($v)) {
        echo "{$k} = {$v}\n";
    } elseif (is_array($v)) {
        echo "{$k} = [" . implode(',', array_map('strval', $v)) . "]\n";
    }
}
if (isset($resp['related_orders'])) {
    echo "-- related_orders --\n";
    echo json_encode($resp['related_orders']) . "\n";
}

echo "\n-- orders/search by reference_id os-edu-* --\n";
$search = square_api_request('POST', '/v2/orders/search', [
    'location_ids' => [SQUARE_LOCATION_ID],
    'return_entries' => false,
    'query' => ['filter' => ['reference_id' => ['exact' => 'os-edu-1']]],
]);
$orderObjs = $search['order_entries'] ?? $search['orders'] ?? [];
echo json_encode(array_map(static function ($o) {
    $o = $o['order'] ?? $o;
    return [
        'id' => $o['id'] ?? null,
        'reference_id' => $o['reference_id'] ?? null,
        'state' => $o['state'] ?? null,
    ];
}, is_array($orderObjs) ? $orderObjs : [])) . "\n";
echo "\n";
