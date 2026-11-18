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
require_once $root . '/includes/pan_carry.php';
require_once $root . '/includes/time_clock.php';

$force = in_array('--force', $argv, true);
$json = in_array('--json', $argv, true);
$backfill = in_array('--backfill', $argv, true);

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
    $carry = ['carried' => 0, 'deduped' => 0, 'restored' => 0, 'skipped' => 0];
    foreach (bakery_pan_carry_forward_recent($db, $today) as $dayCounts) {
        foreach ($carry as $key => $value) {
            $carry[$key] = $value + (int)($dayCounts[$key] ?? 0);
        }
    }
    $routeStats = bakery_route_load_rebuild($db);
    $clockClose = bakery_time_clock_autoclose_stale($db, null, $backfill);
    $result['pan_carry'] = $carry;
    $result['route_load'] = $routeStats;
    $result['time_clock_closed'] = count($clockClose['closed']);
    $result['time_clock_reported'] = count($clockClose['reported']);
    bakery_cron_record_run($db, 'demand_scheduler', 'ok', [
        'from_date' => $result['from_date'] ?? null,
        'through_date' => $result['through_date'] ?? null,
        'ran_count' => (int)($result['ran_count'] ?? 0),
        'carried' => (int)$carry['carried'],
        'restored' => (int)$carry['restored'],
        'route_service_stats' => (int)($routeStats['service'] ?? 0),
        'route_drive_stats' => (int)($routeStats['drive'] ?? 0),
        'time_clock_closed' => (int)$result['time_clock_closed'],
        'time_clock_reported' => (int)$result['time_clock_reported'],
        'time_clock_backfill' => $backfill,
    ]);
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo sprintf(
            "horizon=%s..%s generated_days=%d orders=%d items=%d preserved=%d routes=%d carried=%d restored=%d db=%s\n",
            $result['from_date'],
            $result['through_date'],
            (int)$result['ran_count'],
            (int)$result['orders_created'],
            (int)$result['items_created'] + (int)$result['items_updated'],
            (int)$result['items_preserved'],
            (int)$result['drivers_assigned'],
            (int)$carry['carried'],
            (int)$carry['restored'],
            (string)$db->query('SELECT DATABASE()')->fetchColumn()
        );
        echo sprintf(
            "time_clock_closed=%d time_clock_reported=%d backfill=%s\n",
            (int)$result['time_clock_closed'],
            (int)$result['time_clock_reported'],
            $backfill ? 'yes' : 'no'
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
