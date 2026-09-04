<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once dirname(__DIR__) . '/includes/route_manager_cash.php';

function route_manager_cash_assert_same($expected, $actual, string $message): void
{
    if ($expected === $actual) {
        echo "PASS  {$message}\n";
        return;
    }

    fwrite(STDERR, "FAIL  {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
    exit(1);
}

$summary = route_manager_compute_cash_summary([
    ['payment_collection' => 'cod', 'delivery_status' => 'delivered', 'amount_collected' => 11.25, 'total_amount' => 99],
    ['payment_collection' => 'cod', 'delivery_status' => 'delivered', 'amount_collected' => null, 'total_amount' => 88, 'delivery_order_total' => 85.50],
    ['payment_collection' => 'cod', 'delivery_status' => 'pending', 'total_amount' => 8],
    ['payment_collection' => 'signature', 'delivery_status' => 'delivered', 'amount_collected' => 100, 'total_amount' => 100],
]);

route_manager_cash_assert_same([
    'cod_stop_count' => 3,
    'cod_delivered_count' => 2,
    'cash_recorded_count' => 1,
    'cash_unrecorded_count' => 1,
    'cash_on_hand' => 96.75,
    'expected_remaining' => 8.0,
    'turn_in_total' => 104.75,
    'cash_turned_in' => null,
    'cash_still_owed' => 96.75,
    'total_sold' => 204.75,
], $summary, 'Route Manager includes completed Pan Dulce cash from the final delivery total when cash was not recorded');

$defaultCod = route_manager_compute_cash_summary([
    // Missing payment_collection must default to COD (schema default), not signature.
    ['delivery_status' => 'delivered', 'amount_collected' => 12.5, 'total_amount' => 12.5],
    ['delivery_status' => 'cancelled', 'amount_collected' => 50, 'total_amount' => 50],
]);

route_manager_cash_assert_same([
    'cod_stop_count' => 1,
    'cod_delivered_count' => 1,
    'cash_recorded_count' => 1,
    'cash_unrecorded_count' => 0,
    'cash_on_hand' => 12.5,
    'expected_remaining' => 0.0,
    'turn_in_total' => 12.5,
    'cash_turned_in' => null,
    'cash_still_owed' => 12.5,
    'total_sold' => 12.5,
], $defaultCod, 'Missing payment_collection defaults to COD and cancelled stops are excluded from sold');

$withTurnin = route_manager_compute_cash_summary([
    ['payment_collection' => 'cod', 'delivery_status' => 'delivered', 'amount_collected' => 40.00, 'total_amount' => 40],
], 25.00);
route_manager_cash_assert_same(25.0, $withTurnin['cash_turned_in'], 'cash summary accepts recorded turn-in amount');
route_manager_cash_assert_same(15.0, $withTurnin['cash_still_owed'], 'still owed is collected minus turned in');

$codSrc = (string)file_get_contents(dirname(__DIR__) . '/includes/cod_turnins.php');
route_manager_cash_assert_same(
    true,
    strpos($codSrc, 'function bakery_cod_turnin_record') !== false
        && is_file(dirname(__DIR__) . '/database/schema/080_cod_turnins.sql'),
    'COD turn-in helper and migration 080 exist'
);

$phone = (string)file_get_contents(dirname(__DIR__) . '/includes/manager_phone.php');
$closeout = (string)file_get_contents(dirname(__DIR__) . '/route_closeout.php');
route_manager_cash_assert_same(
    true,
    strpos($phone, 'bakery_inventory_reconcile_driver_load') === false
        && strpos($phone, 'route_closeout.php') !== false
        && strpos($closeout, 'bakery_inventory_reconcile_driver_load') !== false
        && strpos($phone, 'data-van-math="loaded - delivered - credits - returned - wasted"') !== false,
    'phone close reuses route_closeout reconcile helper and asserts van math by name'
);
route_manager_cash_assert_same(
    true,
    strpos($phone, 'phone_cod_turnin') !== false
        && strpos($phone, 'cod_collected_vs_turned_in') !== false,
    'manager phone Routes shows collected vs turned in and records turn-in'
);

$rmPhp = (string)file_get_contents(dirname(__DIR__) . '/route_manager.php');
route_manager_cash_assert_same(
    true,
    strpos($rmPhp, 'record_cod_turnin') !== false,
    'Route Manager can record COD turn-in'
);

echo "All route manager cash checks passed\n";
