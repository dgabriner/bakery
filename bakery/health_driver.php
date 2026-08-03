<?php
/**
 * Production-safe probe for driver page dependencies. Upload temporarily, visit, delete.
 */
define('ACCESS_ALLOWED', true);

header('Content-Type: text/plain; charset=utf-8');

$steps = [];

try {
    require_once __DIR__ . '/includes/env_loader.php';
    bakery_load_env_file(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/database.php';

    $steps[] = 'Connected: ' . DB_NAME . '@' . DB_HOST;

    $drivers = bakery_get_drivers($db);
    $steps[] = 'bakery_get_drivers: OK (' . count($drivers) . ' drivers)';

    $today = date('Y-m-d');
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM daily_order_assignments doa
         INNER JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.order_date = ?'
    );
    $stmt->execute([$today]);
    $steps[] = 'daily_order_assignments join daily_orders: OK (' . $stmt->fetchColumn() . ' rows today)';

    $stmt = $db->prepare(
        'SELECT do.id, c.name, c.address
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.order_date = ?
         LIMIT 3'
    );
    $stmt->execute([$today]);
    $sampleOrders = $stmt->fetchAll();
    $steps[] = 'daily_orders sample rows: ' . count($sampleOrders);

    foreach ($sampleOrders as $row) {
        $json = bakery_json_for_html($row);
        if ($json === 'null') {
            throw new RuntimeException('JSON encode failed for order id ' . $row['id']);
        }
        if (strpos($json, '</script>') !== false) {
            throw new RuntimeException('Unsafe JSON still contains </script> for order id ' . $row['id']);
        }
    }
    $steps[] = 'bakery_json_for_html: OK on sample order rows';

    if (!empty($drivers)) {
        $driverId = (int)$drivers[0]['id'];
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM daily_orders do
             INNER JOIN customers c ON do.customer_id = c.id
             INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
             INNER JOIN drivers d ON doa.driver_id = d.id
             WHERE doa.driver_id = ? AND do.order_date = ?'
        );
        $stmt->execute([$driverId, $today]);
        $steps[] = 'driver_list route query: OK (' . $stmt->fetchColumn() . ' stops for driver #' . $driverId . ' today)';
    }

    $steps[] = '';
    $steps[] = 'Driver page dependencies look good. Deploy updated driver_list.php, driver_assignment.php, includes/common_functions.php';
    $steps[] = 'Delete this file after debugging.';
} catch (Throwable $e) {
    $steps[] = '';
    $steps[] = 'FAILED: ' . $e->getMessage();
}

echo implode("\n", $steps) . "\n";
