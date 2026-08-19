<?php
/**
 * Login History insight contracts.
 * Usage: php tests/run_login_history_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

require_once dirname(__DIR__) . '/includes/i18n.php';
require_once dirname(__DIR__) . '/includes/navigation_catalog.php';
require_once dirname(__DIR__) . '/includes/login_history_insights.php';

$failed = 0;
function login_history_assert($condition, $message): void
{
    global $failed;
    if ($condition) {
        echo "PASS  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL  {$message}\n");
    $failed++;
}

login_history_assert(bakery_login_history_page_key('/bakery/production.php?date=2026-08-17') === 'production', 'page key strips path, query, and suffix');
login_history_assert(bakery_login_history_page_key('pack_list.php') === 'pack_list', 'page key accepts a bare script name');
login_history_assert(bakery_login_history_page_key('') === '', 'empty path has no page key');

$filters = bakery_login_history_parse_filters(['range' => '7d', 'page' => 2], '2026-08-17');
login_history_assert($filters['from'] === '2026-08-11' && $filters['until'] === '2026-08-17', '7-day range fills from/until');
login_history_assert($filters['view'] === 'overview', 'missing view defaults to overview');
login_history_assert($filters['page'] === 2, 'pagination page is kept separate from module filter');

$moduleFilters = bakery_login_history_parse_filters(['module' => 'Daily_Orders.php', 'subject' => 's-4', 'view' => 'usage']);
login_history_assert($moduleFilters['module'] === 'daily_orders', 'module filter normalizes to a page key');
login_history_assert($moduleFilters['user_id'] === 4 && $moduleFilters['subject'] === 's-4', 'staff subject maps to user_id');

$customerFilters = bakery_login_history_parse_filters(['subject' => 'c-9']);
login_history_assert($customerFilters['customer_id'] === 9 && $customerFilters['user_id'] === 0, 'customer subject maps to customer_id');

login_history_assert(bakery_login_history_duration(12) === '12 sec' || bakery_login_history_duration(12) === bakery_t('login_history.duration_sec', ['n' => 12]), 'short duration uses seconds');
login_history_assert(bakery_login_history_is_live(['outcome' => 'success', 'logout_at' => null, 'last_seen_at' => date('Y-m-d H:i:s')], time()), 'recent successful session is live');
login_history_assert(!bakery_login_history_is_live(['outcome' => 'success', 'logout_at' => date('Y-m-d H:i:s'), 'last_seen_at' => date('Y-m-d H:i:s')]), 'logged-out session is not live');

$days = bakery_login_history_fill_days('2026-08-01', '2026-08-03', ['2026-08-02' => ['signins' => 4]], ['signins' => 0, 'pages' => 0]);
login_history_assert(count($days) === 3 && (int)$days[1]['signins'] === 4 && (int)$days[0]['signins'] === 0, 'daily series fills missing days');

$heat = bakery_login_history_heatmap_grid([['weekday' => 0, 'hour' => 6, 'n' => 5], ['weekday' => 0, 'hour' => 6, 'n' => 2]]);
login_history_assert($heat['grid'][0][6] === 7 && $heat['max'] === 7, 'heatmap accumulates matching cells and tracks max');

$transitions = bakery_login_history_transitions_from_paths([
    ['production', 'production', 'pack_list', 'driver_load'],
    ['production', 'pack_list'],
]);
login_history_assert($transitions[0]['from'] === 'production' && $transitions[0]['to'] === 'pack_list' && $transitions[0]['n'] === 2, 'workflow transitions collapse repeats and count pairs');

$url = bakery_login_history_url(['view' => 'overview', 'user_id' => 0], ['view' => 'time', 'q' => 'Ana']);
login_history_assert(strpos($url, 'view=') === false && strpos($url, 'q=Ana') !== false, 'overview view is omitted from URLs and empty ids drop');

$defaultFilters = bakery_login_history_parse_filters([], '2026-08-17');
login_history_assert($defaultFilters['range'] === '14d' && $defaultFilters['from'] === '2026-08-04' && $defaultFilters['until'] === '2026-08-17', 'empty GET defaults to the last 14 days');
login_history_assert(!bakery_login_history_has_filters($defaultFilters), 'default 14-day window is not treated as an extra filter');

$prev = bakery_login_history_previous_window($defaultFilters);
login_history_assert($prev !== null && $prev['from'] === '2026-07-21' && $prev['until'] === '2026-08-03' && (int)$prev['days'] === 14, 'previous window is the equal-length period before from');

$deltaUp = bakery_login_history_delta(12, 8);
login_history_assert($deltaUp['direction'] === 'up' && $deltaUp['pct'] === 50, 'delta reports percent up versus previous');
$deltaFlat = bakery_login_history_delta(100, 99);
login_history_assert($deltaFlat['direction'] === 'flat', 'delta treats a 1% change as flat');

login_history_assert(in_array('login_audit_api', bakery_login_history_noise_pages(), true), 'heartbeat and API pages are treated as telemetry noise');
login_history_assert(bakery_login_history_screen_href('login_audit_api') === '', 'noise pages do not get an open-screen link');
login_history_assert(strpos(bakery_login_history_screen_href('production'), 'production.php') !== false, 'real screens get an open-screen href');

$grouped = bakery_login_history_group_timeline([
    ['timestamp' => strtotime('2026-08-17 08:00:00'), 'title' => 'a'],
    ['timestamp' => strtotime('2026-08-17 09:00:00'), 'title' => 'b'],
    ['timestamp' => strtotime('2026-08-16 18:00:00'), 'title' => 'c'],
]);
login_history_assert(count($grouped) === 2 && count($grouped['2026-08-17']) === 2 && count($grouped['2026-08-16']) === 1, 'investigation timeline groups by calendar day');

$matched = bakery_login_history_match_workflows([
    ['from_key' => 'production', 'to_key' => 'pack_list', 'n' => 4],
    ['from_key' => 'other', 'to_key' => 'nowhere', 'n' => 9],
]);
login_history_assert(count($matched) === 1 && $matched[0]['from_key'] === 'production', 'known bakery workflows match consecutive screen pairs');

$ago = bakery_login_history_ago(date('Y-m-d H:i:s', time() - 30), time());
login_history_assert($ago === 'Just now' || $ago === bakery_t('login_history.ago_just_now'), 'relative time uses just-now for recent events');

$csv = bakery_login_history_csv_row([
    'login_at' => '2026-08-17 08:00:00',
    'display_name' => 'Ana',
    'gps_latitude' => '32.1',
    'gps_longitude' => '-110.9',
]);
login_history_assert(!in_array('32.1', $csv, true) && !in_array('-110.9', $csv, true), 'CSV export does not include GPS coordinates');

$brief = bakery_login_history_briefing_lines([
    'live_count' => 0,
    'failures' => 3,
    'peak_hour' => 0,
    'quiet_roles' => ['Bakers'],
    'delta_signins' => ['direction' => 'down', 'pct' => 20],
]);
login_history_assert(count($brief) >= 3, 'briefing covers quiet floor, failures, peak hour, and comparison');

$production = bakery_login_history_page_meta('production.php');
login_history_assert($production['key'] === 'production' && $production['area'] === 'production', 'catalog maps Daily Production into the production area');
$portal = bakery_login_history_page_meta('customer_portal_calendar.php');
login_history_assert($portal['area'] === 'customer_portal', 'customer portal screens have their own area');

$root = dirname(__DIR__);
$page = file_get_contents($root . '/login_history.php');
$css = file_get_contents($root . '/css/login_history.css');
$insights = file_get_contents($root . '/includes/login_history_insights.php');
login_history_assert($page !== false && strpos($page, 'login_history_insights.php') !== false, 'portal loads insight helpers');
login_history_assert($page !== false && strpos($page, "view' => 'time'") !== false, 'portal exposes an over-time layer');
login_history_assert($page !== false && strpos($page, "view' => 'usage'") !== false, 'portal exposes a usage and workflow layer');
login_history_assert($page !== false && strpos($page, "view' => 'live'") !== false, 'portal exposes a live-session layer');
login_history_assert($page !== false && strpos($page, 'css/login_history.css') !== false, 'portal uses extracted login history CSS');
login_history_assert($css !== false && strpos($css, '.login-history-heat') !== false, 'CSS includes the weekday/hour heatmap');
login_history_assert($insights !== false && strpos($insights, 'LAG(') !== false, 'workflow query uses consecutive page steps');
login_history_assert($insights !== false && strpos($insights, "'skip_time' => true") !== false, 'live presence ignores the historical date window');
login_history_assert($page !== false && strpos($page, 'login-history-briefing') !== false, 'portal renders a briefing strip');
login_history_assert($page !== false && strpos($page, "export' => 'csv'") !== false, 'portal exposes CSV export');
login_history_assert($page !== false && strpos($page, 'login-history-roles') !== false, 'portal exposes a role presence board');
login_history_assert($page !== false && strpos($page, 'history-day') !== false, 'investigation timeline is grouped by day');
login_history_assert($page !== false && strpos($page, 'login-history-filters__more') !== false, 'advanced filters sit behind a collapsed details block');
login_history_assert($css !== false && strpos($css, '.login-history-briefing') !== false, 'CSS includes briefing chrome');
login_history_assert($insights !== false && strpos($insights, 'bakery_login_history_load_dwell') !== false, 'dwell time is aggregated from consecutive page views');

$auditHelper = file_get_contents($root . '/includes/login_audit.php');
$migrations = file_get_contents($root . '/scripts/run_migrations.php');
login_history_assert($auditHelper !== false && strpos($auditHelper, '036_login_audit_activity.sql') !== false, 'runtime schema ensure can create login_audit_activity');
login_history_assert($migrations !== false && strpos($migrations, 'Applying migration 036_login_audit_activity (table missing despite applied flag)') !== false, 'migration 036 recreates the activity table if the applied flag is stale');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$enKeys = array_values(array_filter(array_keys($en), static function ($key) {
    return strpos((string)$key, 'login_history.') === 0 || $key === 'page.login_history';
}));
$missingEs = [];
foreach ($enKeys as $key) {
    if (!array_key_exists($key, $es)) {
        $missingEs[] = $key;
    }
}
login_history_assert($missingEs === [], 'login history i18n keys exist in es.php' . ($missingEs ? (' missing: ' . implode(',', $missingEs)) : ''));
login_history_assert(($en['page.login_history'] ?? '') === 'Login History', 'English page title exists');

$adminItems = [];
foreach (bakery_navigation_groups_for_role('administrator') as $group) {
    $adminItems = array_merge($adminItems, array_column($group['items'], 'href'));
}
login_history_assert(in_array('login_history.php', $adminItems, true), 'administrators still receive Login History in Administration');

exit($failed > 0 ? 1 : 0);
