<?php
/**
 * DreamHost cron: open the next sell day's dual survey before end-of-day texts.
 *
 * Unforced runs are production (bakerysf) or DreamHost staging.
 * Local one-shot: php scripts/survey_dual_open.php --force
 *
 * Does not run from a web GET. Survey Center and survey.php only look up rows.
 *
 * DreamHost, 5:00 PM America/Los_Angeles:
 *   0 17 * * * /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/survey_dual_open.php
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
require_once $root . '/includes/survey_dual_open.php';
require_once $root . '/includes/cron_run.php';

$force = in_array('--force', $argv ?? [], true);
$json = in_array('--json', $argv ?? [], true);

$db = null;
$exitCode = 0;
try {
    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    bakery_demand_scheduler_assert_cli($db, $force);
    $result = bakery_survey_dual_open_next($db);
    bakery_cron_record_run($db, 'survey_dual_open', 'ok', [
        'today' => $result['today'] ?? null,
        'delivery_date' => $result['delivery_date'] ?? null,
        'opened' => count($result['opened'] ?? []),
    ]);
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo sprintf(
            "today=%s delivery_date=%s opened=%d db=%s\n",
            (string)($result['today'] ?? ''),
            (string)($result['delivery_date'] ?? ''),
            count($result['opened'] ?? []),
            (string)$db->query('SELECT DATABASE()')->fetchColumn()
        );
    }
} catch (Throwable $e) {
    $exitCode = 1;
    if ($db instanceof PDO) {
        bakery_cron_record_run($db, 'survey_dual_open', 'failed', [
            'error' => substr($e->getMessage(), 0, 200),
        ]);
    } else {
        bakery_cron_record_run(null, 'survey_dual_open', 'failed', [
            'error' => substr($e->getMessage(), 0, 200),
        ]);
    }
    fwrite(STDERR, $e->getMessage() . "\n");
}
exit($exitCode);
