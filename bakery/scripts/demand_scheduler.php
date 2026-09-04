<?php
/**
 * DreamHost cron: keep dated orders materialized from standing orders.
 *
 * Unforced runs are production (bakerysf) or DreamHost staging.
 * Local one-shot: php scripts/demand_scheduler.php --force
 *
 * DreamHost (daily, early morning):
 *   /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/demand_scheduler.php
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/operational_timeline.php';
require_once $root . '/includes/cron_run.php';

$force = in_array('--force', $argv, true);
$json = in_array('--json', $argv, true);

$db = null;
$exitCode = 0;
try {
    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    bakery_demand_scheduler_assert_cli($db, $force);
    $today = date('Y-m-d');
    $result = bakery_ensure_daily_orders_horizon($db, $today, [
        'record_event' => true,
        'assign_routes' => true,
        'skip_if_closed' => true,
    ]);
    bakery_cron_record_run($db, 'demand_scheduler', 'ok', [
        'from_date' => $result['from_date'] ?? null,
        'through_date' => $result['through_date'] ?? null,
        'ran_count' => (int)($result['ran_count'] ?? 0),
    ]);
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo sprintf(
            "horizon=%s..%s generated_days=%d orders=%d items=%d preserved=%d routes=%d db=%s\n",
            $result['from_date'],
            $result['through_date'],
            (int)$result['ran_count'],
            (int)$result['orders_created'],
            (int)$result['items_created'] + (int)$result['items_updated'],
            (int)$result['items_preserved'],
            (int)$result['drivers_assigned'],
            (string)$db->query('SELECT DATABASE()')->fetchColumn()
        );
    }
} catch (Throwable $e) {
    $exitCode = 1;
    if ($db instanceof PDO) {
        bakery_cron_record_run($db, 'demand_scheduler', 'failed', [
            'error' => substr($e->getMessage(), 0, 200),
        ]);
    } else {
        bakery_cron_record_run(null, 'demand_scheduler', 'failed', [
            'error' => substr($e->getMessage(), 0, 200),
        ]);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
}
exit($exitCode);
