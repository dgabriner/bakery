<?php
/**
 * DreamHost cron entry for the Synthetic Studio clock.
 *
 * Unforced ticks run on production (bakerysf) only. This machine does
 * not schedule the clock — use Synthetic Manager → Run tick now, or
 * pass --force for a one-shot local tick.
 *
 * DreamHost (every minute):
 *   /usr/local/bin/php /home/YOUR_USER/bakery.sourflour.org/bake/scripts/sfb_studio_tick.php
 *
 * Local one-shot:
 *   C:\php\php.exe scripts\sfb_studio_tick.php --force
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
require_once $root . '/includes/sfb_studio_clock.php';

$force = in_array('--force', $argv, true);
$json = in_array('--json', $argv, true);

try {
    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    bakery_sfb_studio_assert_tick_cli($db, $force);
    $result = bakery_sfb_studio_tick($db, $force ? ['force' => true] : []);
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo sprintf(
            "tick=%s bakers=%d actions=%d skipped=%d errors=%d enrolled=%d clock=%s db=%s\n",
            $result['tick_id'],
            (int)$result['bakers'],
            (int)$result['actions'],
            (int)$result['skipped'],
            (int)$result['errors'],
            (int)$result['enrolled'],
            !empty($result['clock_enabled']) ? 'on' : 'off',
            (string)$db->query('SELECT DATABASE()')->fetchColumn()
        );
    }
    exit((int)$result['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
