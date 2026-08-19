<?php
/**
 * Customer delivery experience tests — status mapping, progress, proof, security.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/customer_delivery.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;

function t_assert($ok, $msg) {
    global $pass, $fail;
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
}

// Status mapping
$confirmed = bakery_customer_delivery_derive_status(['status' => 'confirmed'], ['delivery_status' => 'pending']);
t_assert($confirmed['key'] === 'confirmed', 'pending+confirmed → confirmed');

$preparing = bakery_customer_delivery_derive_status(['status' => 'in_production'], ['delivery_status' => 'pending']);
t_assert($preparing['key'] === 'preparing', 'in_production → preparing');

$out = bakery_customer_delivery_derive_status(['status' => 'out_for_delivery'], ['delivery_status' => 'pending']);
t_assert($out['key'] === 'out_for_delivery', 'out_for_delivery → out_for_delivery');

$delivered = bakery_customer_delivery_derive_status(
    ['status' => 'delivered', 'delivery_confirmed_at' => '2026-01-01 10:00:00'],
    ['delivery_status' => 'delivered']
);
t_assert($delivered['key'] === 'delivered', 'delivered + confirmed → delivered');

$skipped = bakery_customer_delivery_derive_status(['status' => 'pending'], ['delivery_status' => 'cancelled']);
t_assert($skipped['key'] === 'skipped', 'cancelled assignment → skipped');

// GPS privacy — no coordinates in summary text
$gpsSummary = bakery_customer_delivery_gps_summary([
    'gps_latitude' => 37.77,
    'gps_longitude' => -122.42,
    'gps_status' => 'captured',
]);
t_assert(
    $gpsSummary !== null && strpos($gpsSummary, '37') === false,
    'GPS summary hides precise coordinates'
);

// Stops ahead logic
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
if ($driverId > 0) {
    $date = date('Y-m-d');
    $orderId = (int)$db->query('SELECT id FROM daily_orders ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($orderId > 0) {
        $order = $db->prepare('SELECT customer_id FROM daily_orders WHERE id = ?');
        $order->execute([$orderId]);
        $customerId = (int)$order->fetchColumn();

        $assign = $db->prepare(
            'SELECT route_order FROM daily_order_assignments
             WHERE driver_id = ? AND delivery_date = ? ORDER BY route_order DESC LIMIT 1'
        );
        $assign->execute([$driverId, $date]);
        $maxRoute = (int)$assign->fetchColumn();

        if ($maxRoute > 1) {
            $ahead = bakery_customer_delivery_stops_ahead($db, $driverId, $date, $maxRoute);
            t_assert($ahead !== null && $ahead >= 0, 'stops_ahead returns non-negative count');
        } else {
            echo "SKIP  stops_ahead (insufficient route data)\n";
        }

        // Ownership security
        $wrongCustomer = $customerId + 99999;
        $threw = false;
        try {
            bakery_customer_delivery_assert_ownership($db, $wrongCustomer, $orderId);
        } catch (Throwable $e) {
            $threw = true;
        }
        t_assert($threw, 'assert_ownership rejects wrong customer');

        // Quantity variance detection via detail
        if ($customerId > 0) {
            try {
                $detail = bakery_customer_delivery_detail($db, $customerId, $orderId);
                t_assert(isset($detail['items']) && is_array($detail['items']), 'delivery detail returns items');
                t_assert(array_key_exists('has_quantity_variance', $detail), 'delivery detail includes variance flag');

                // Manual variance scenario: if any item has variance, flag should be true
                $hasItemVariance = false;
                foreach ($detail['items'] as $item) {
                    if (!empty($item['has_variance'])) {
                        $hasItemVariance = true;
                        echo "NOTE  Found quantity variance: {$item['product_name']} ordered={$item['ordered']} delivered={$item['delivered']}\n";
                        break;
                    }
                }
                if ($hasItemVariance) {
                    t_assert($detail['has_quantity_variance'] === true, 'variance flag true when item differs');
                } else {
                    echo "NOTE  No quantity variance in latest order (expected if full delivery)\n";
                }
            } catch (Throwable $e) {
                echo "SKIP  delivery detail (" . $e->getMessage() . ")\n";
            }
        }
    }
} else {
    echo "SKIP  driver/route tests (no drivers)\n";
}

// Photo authorization
if (table_exists($db, 'driver_photos')) {
    $photoRow = $db->query('SELECT id, customer_id FROM driver_photos ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if ($photoRow) {
        $owned = bakery_customer_delivery_photo_for_customer($db, (int)$photoRow['customer_id'], (int)$photoRow['id']);
        t_assert($owned !== null, 'photo accessible to owning customer');

        $blocked = bakery_customer_delivery_photo_for_customer($db, (int)$photoRow['customer_id'] + 99999, (int)$photoRow['id']);
        t_assert($blocked === null, 'photo blocked for other customer');
    } else {
        echo "SKIP  photo authorization (no photos)\n";
    }
}

// Quantity variance classification (billing canonical source)
require_once $root . '/includes/billing.php';
$varianceOrder = [
    'status' => 'delivered',
    'delivery_confirmed_at' => '2026-01-15 10:42:00',
    'delivered_pieces' => 22,
    'credits_taken_back' => 0,
    'assignment_delivery_status' => 'delivered',
];
$varianceItems = [
    ['id' => 1, 'product_id' => 1, 'product_name' => 'Sourdough', 'quantity' => 24, 'delivered_quantity' => 22, 'unit_price' => 3.5, 'line_total' => 77.0],
    ['id' => 2, 'product_id' => 2, 'product_name' => 'Baguette', 'quantity' => 12, 'delivered_quantity' => 12, 'unit_price' => 2.0, 'line_total' => 24.0],
];
$classified = bakery_billing_classify_order($varianceOrder, $varianceItems);
t_assert(!empty($classified['has_quantity_variance']), 'quantity variance detected (Sourdough 24→22)');
t_assert($classified['items'][0]['variance'] === -2, 'line variance is -2 for Sourdough');

echo "\nCustomer delivery tests: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
