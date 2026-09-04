<?php
/**
 * Rolling demand horizon: standing → dated, Monday bake/route cadence.
 * CLI / local only. Cleans up the synthetic dates it uses.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/demand_confirmation.php';
require_once $root . '/includes/i18n.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: demand scheduler tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
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

$from = '2026-08-17'; // Monday
$cadence = bakery_demand_cadence_dates($from);
$assert($cadence['bake_day'] === '2026-08-18', 'Monday cadence bake day is Tuesday');
$assert($cadence['route_date'] === '2026-08-19', 'Monday cadence route date is Wednesday');
$assert($cadence['production_date'] === '2026-08-19', 'production sheet is keyed on Wednesday delivery');
$assert($cadence['horizon_end'] === '2026-08-23', 'default horizon is 7 days through Sunday');

$dates = bakery_demand_horizon_date_list($from, 3);
$assert($dates === ['2026-08-17', '2026-08-18', '2026-08-19'], '3-day horizon is Mon–Wed');
$assert(bakery_demand_horizon_date_list('nope') === [], 'invalid from-date yields no horizon');

$tickSrc = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$assert(is_file($root . '/scripts/demand_scheduler.php'), 'cron tick script exists');
$assert(strpos($tickSrc, 'bakery_demand_scheduler_assert_cli') !== false, 'cron script uses the CLI guard');
$assert(strpos($tickSrc, 'bakery.sourflour.org/bake/scripts/demand_scheduler.php') !== false, 'DreamHost path is documented');

$en = bakery_load_lang_catalog('en');
$es = bakery_load_lang_catalog('es');
$assert(!empty($en['cadence.title']) && !empty($es['cadence.title']), 'cadence copy exists in en and es');
$assert(!empty($en['cadence.cover_note']) && !empty($es['cadence.cover_note']), 'cover-window cadence copy exists');
$assert($en['cadence.cover_note'] !== $es['cadence.cover_note'], 'cover-window cadence copy is translated');
$assert(!empty($en['daily_run.demand_horizon_filled']) && !empty($es['daily_run.demand_horizon_filled']), 'horizon flash copy exists');

$genSrc = (string)file_get_contents($root . '/includes/daily_order_generation.php');
$assert(strpos($genSrc, "'overwrite_changed' => false") !== false, 'horizon path keeps overwrite_changed off');

$future = date('Y-m-d', strtotime('+24 days'));
$horizonDates = bakery_demand_horizon_date_list($future, 3);
$cleanup = static function (PDO $db, array $dates): void {
    foreach ($dates as $date) {
        $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date=?)')
            ->execute([$date]);
        $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date=?')->execute([$date]);
        $db->prepare('DELETE FROM daily_orders WHERE order_date=?')->execute([$date]);
        if (table_exists($db, 'demand_confirmations')) {
            $db->prepare('DELETE FROM demand_confirmations WHERE operating_date=?')->execute([$date]);
        }
        if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
            $db->prepare('DELETE FROM operational_events WHERE operational_date=?')->execute([$date]);
        }
    }
};

$cleanup($db, $horizonDates);

$first = bakery_ensure_daily_orders_horizon($db, $future, [
    'record_event' => false,
    'days' => 3,
]);
$assert($first['days'] === 3, 'horizon reports 3 days');
$assert($first['from_date'] === $future, 'horizon starts on requested date');
$assert($first['ran_count'] >= 0, 'horizon ran or skipped standing-empty weekdays');

if ($first['ran_count'] > 0) {
    $second = bakery_ensure_daily_orders_horizon($db, $future, [
        'record_event' => false,
        'days' => 3,
    ]);
    $assert($second['ran_count'] === 0, 'second horizon pass is a no-op');
    $assert(count($second['skipped']) === 3, 'all three dates skipped as already_generated or empty');

    $item = $db->prepare('
        SELECT doi.id, doi.quantity
        FROM daily_order_items doi
        JOIN daily_orders do ON do.id = doi.daily_order_id
        WHERE do.order_date = ?
        LIMIT 1
    ');
    $item->execute([$future]);
    $row = $item->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $newQty = (int)$row['quantity'] + 11;
        $db->prepare('UPDATE daily_order_items SET quantity = ?, line_total = quantity * unit_price WHERE id = ?')
            ->execute([$newQty, (int)$row['id']]);
        bakery_ensure_daily_orders_horizon($db, $future, [
            'record_event' => false,
            'days' => 3,
        ]);
        $check = $db->prepare('SELECT quantity FROM daily_order_items WHERE id = ?');
        $check->execute([(int)$row['id']]);
        $kept = (int)$check->fetchColumn();
        $assert($kept === $newQty, 'dated quantity edit preserved across horizon re-run (got ' . $kept . ')');
    } else {
        echo "NOTE  no daily_order_items on first horizon date — skip edit-preservation assert\n";
    }
} else {
    echo "NOTE  no standing demand on synthetic weekdays — skip generate/preserve asserts\n";
}

$cleanup($db, $horizonDates);

$dashSrc = (string)file_get_contents($root . '/index.php');
$runSrc = (string)file_get_contents($root . '/daily_run.php');
$ordersSrc = (string)file_get_contents($root . '/daily_orders.php');
$prodSrc = (string)file_get_contents($root . '/production.php');
$assert(strpos($dashSrc, 'bakery_fill_demand_horizon') !== false, 'dashboard fills the horizon');
$assert(strpos($dashSrc, 'bakery_render_demand_cadence_strip') !== false, 'dashboard shows cadence strip');
$assert(strpos($runSrc, 'bakery_fill_demand_horizon') !== false, 'Daily Run fills the horizon');
$assert(strpos($ordersSrc, 'bakery_fill_demand_horizon') !== false, 'Daily Orders fills the horizon');
$assert(strpos($prodSrc, 'bakery_fill_demand_horizon') !== false, 'Daily Production fills the horizon');

$schedSrc = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$assert(strpos($schedSrc, 'bakery_cron_record_run') !== false, 'demand_scheduler records cron_run stamps');
$healthSrc = (string)file_get_contents($root . '/health_deploy.php');
$assert(strpos($healthSrc, 'cron.demand_scheduler.age_hours') !== false, 'health_deploy reports demand_scheduler age_hours');
$dashCc = (string)file_get_contents($root . '/includes/dashboard_command_center.php');
$assert(strpos($dashCc, 'cron_overnight_stale') !== false, 'dashboard warns when overnight cron is stale');
require_once $root . '/includes/cron_run.php';
$stamp = bakery_cron_record_run(null, 'demand_scheduler_test', 'ok', ['probe' => 1]);
$assert(($stamp['outcome'] ?? '') === 'ok', 'cron stamp writes outcome');
$assert(bakery_cron_age_hours('demand_scheduler_test') !== null, 'cron age_hours readable after stamp');
@unlink($root . '/storage/cron/demand_scheduler_test.json');

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail > 0 ? 1 : 0);
