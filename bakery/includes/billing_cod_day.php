<?php
/**
 * Daily COD cash by driver. Expected cash versus what the driver collected.
 * Invoice and check accounts are not in this list.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/billing_header.php';
require_once __DIR__ . '/cod_square_guard.php';

/**
 * @return list<array{driver_id:int,driver_name:string,expected:float,collected:float,stop_count:int}>
 */
function bakery_billing_cod_driver_day(PDO $db, string $date): array
{
    if (!function_exists('bakery_billing_query_orders')) {
        require_once __DIR__ . '/billing.php';
    }
    $orders = bakery_billing_query_orders($db, [
        'start_date' => $date,
        'end_date' => $date,
        'collection' => 'cod',
        'status' => 'all',
    ]);
    $ids = [];
    foreach ($orders as $order) {
        if (function_exists('bakery_billing_is_fixture_noise') && bakery_billing_is_fixture_noise($order)) {
            continue;
        }
        $ids[] = (int)$order['id'];
    }
    $itemsByOrder = function_exists('bakery_billing_load_items')
        ? bakery_billing_load_items($db, $ids)
        : [];
    $byDriver = [];
    foreach ($orders as $order) {
        if (function_exists('bakery_billing_is_fixture_noise') && bakery_billing_is_fixture_noise($order)) {
            continue;
        }
        $orderId = (int)$order['id'];
        $lines = $itemsByOrder[$orderId] ?? [];
        if (function_exists('bakery_stop_square_prepaid')) {
            $order['square_prepaid'] = bakery_stop_square_prepaid($order);
        }
        if (trim((string)($order['cod_square_override_reason'] ?? '')) !== '') {
            $order['cod_override_reason'] = (string)$order['cod_square_override_reason'];
        }
        $expected = bakery_billing_cod_expected_amount($order, $lines);
        $collectedRaw = $order['amount_collected'] ?? null;
        $collected = ($collectedRaw !== null && $collectedRaw !== '') ? round((float)$collectedRaw, 2) : 0.0;
        if (!empty($order['square_prepaid']) && trim((string)($order['cod_override_reason'] ?? '')) === '') {
            $collected = 0.0;
        }
        $driverId = (int)($order['assigned_driver_id'] ?? 0);
        if ($driverId <= 0) {
            $driverId = (int)($order['driver_id'] ?? 0);
        }
        $driverName = trim((string)($order['assigned_driver_name'] ?? ''));
        if ($driverName === '') {
            $driverName = function_exists('bakery_t') ? bakery_t('billing.cod_unassigned') : 'Unassigned';
        }
        if (!isset($byDriver[$driverId])) {
            $byDriver[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => $driverName,
                'expected' => 0.0,
                'collected' => 0.0,
                'stop_count' => 0,
            ];
        }
        $byDriver[$driverId]['expected'] = round($byDriver[$driverId]['expected'] + $expected, 2);
        $byDriver[$driverId]['collected'] = round($byDriver[$driverId]['collected'] + $collected, 2);
        $byDriver[$driverId]['stop_count']++;
    }
    $rows = array_values($byDriver);
    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });
    return $rows;
}
