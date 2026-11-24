<?php
/**
 * Driver reports: role gate, driven order, flags, and photo fallback.
 *
 * Usage: php tests/run_driver_reports_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/driver_reports.php';
require_once $root . '/includes/navigation_catalog.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== Role gate, same as route photo journey ===\n";
$pageAccess = [
    'logged out' => [null, 302],
    'driver' => [['role_slug' => 'driver'], 403],
    'driver assistant' => [['role_slug' => 'driver_assistant'], 403],
    'cashier' => [['role_slug' => 'cashier'], 403],
    'manager' => [['role_slug' => 'manager'], 200],
    'administrator' => [['role_slug' => 'administrator'], 200],
];
foreach ($pageAccess as $label => $pair) {
    $status = bakery_driver_reports_access_status($pair[0]);
    $assert($status === $pair[1], 'driver reports access for ' . $label . ' is ' . $pair[1]);
    $assert(
        $status === bakery_route_photo_journey_access_status($pair[0]),
        'driver reports uses the photo journey gate for ' . $label
    );
}

$roles = bakery_navigation_roles_for_script('driver_reports.php');
$assert(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'catalog allows administrator and manager');
$assert(!in_array('driver', $roles, true) && !in_array('cashier', $roles, true), 'catalog does not allow driver or cashier');

$nav = (string)file_get_contents($root . '/includes/nav.php');
$catalog = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$manager = (string)file_get_contents($root . '/manager.php');
$phone = (string)file_get_contents($root . '/includes/manager_phone.php');
$pageSrc = (string)file_get_contents($root . '/driver_reports.php');
$includeSrc = (string)file_get_contents($root . '/includes/driver_reports.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');

$navJourney = strpos($nav, 'route_photo_journey.php');
$navReports = strpos($nav, 'driver_reports.php');
$assert($navJourney !== false && $navReports !== false && $navReports > $navJourney, 'manager nav lists driver reports after route photo journey');
$navGap = substr($nav, $navJourney, $navReports - $navJourney);
$assert(substr_count($navGap, "['href'") === 1, 'nothing sits between the journey nav item and driver reports');

$catalogJourney = strpos($catalog, "'href' => 'route_photo_journey.php'");
$catalogReports = strpos($catalog, "'href' => 'driver_reports.php'");
$assert($catalogJourney !== false && $catalogReports !== false && $catalogReports > $catalogJourney, 'catalog lists driver reports after route photo journey');

$managerJourney = strpos($manager, 'route_photo_journey.php');
$managerReports = strpos($manager, 'driver_reports.php');
$assert($managerJourney !== false && $managerReports !== false && $managerReports > $managerJourney, 'manager page button follows the photo journey button');
$assert(strpos($phone, 'driver_reports.php') !== false, 'phone manager page links driver reports');
$assert(strpos($pageSrc, 'bakery_driver_reports_access_status') !== false, 'page checks the role gate');
$assert(strpos($pageSrc, 'name="driver_id"') === false && strpos($pageSrc, 'bakery_is_driver_route_role') === false, 'the page does not scope a manager to one driver');
$assert(stripos($pageSrc, 'method="post"') === false && stripos($pageSrc, "method='post'") === false, 'the page is read only');
$assert(strpos($includeSrc, 'bakery_driver_assign_orders') === false, 'reports do not assign stops');
$assert(strpos($includeSrc, 'bakery_driver_transfer_assignments') === false, 'reports do not move stops');
$assert(strpos($includeSrc, 'bakery_inventory_save_driver_load') === false, 'reports do not write pickup loads');
$assert(strpos($includeSrc, 'bakery_inventory_reconcile_driver_load') === false, 'reports do not close routes');
$assert(strpos($includeSrc, 'total_amount') === false && strpos($pageSrc, 'total_amount') === false, 'report code does not read order totals');
$assert(strpos($includeSrc, 'sold_amount') === false && strpos($pageSrc, 'sold_amount') === false, 'report code does not read sold amounts');

$assert(strpos($manifest, "'driver_reports.php'") !== false, 'deploy root list includes the page');
$skipBody = '';
if (preg_match('/function Get-BakeryDeployExcludeNamePatterns[\s\S]*?return @\(([\s\S]*?)\)\s*\}/', $manifest, $skipMatch)) {
    $skipBody = $skipMatch[1];
}
$assert(strpos($skipBody, 'driver_reports.php') === false, 'deploy skip patterns were not edited to exclude the page');
$assert(strpos($skipBody, 'staging_update.php') !== false && strpos($skipBody, 'oven_light.php') !== false && strpos($skipBody, 'proof_window.php') !== false, 'tour page skip patterns are unchanged');

echo "\n=== Driven order ===\n";
$sorted = bakery_driver_reports_sort_driven([
    reports_stop('Capp', 3, '2026-10-21 11:00:00', '2026-10-21 11:12:00', 'pod', 'delivered'),
    reports_stop('Bay', 1, '2026-10-21 09:05:00', '2026-10-21 09:20:00', 'pod', 'delivered'),
    reports_stop('Never', 2, '', '', 'pending', 'pending'),
    reports_stop('Stamp only', 4, '', '2026-10-21 15:00:00', 'pending', 'delivered'),
]);
$assert(array_column($sorted, 'customer_name') === ['Bay', 'Capp', 'Never', 'Stamp only'], 'arrival order comes first, then plan order for stops with no arrival');
$assert(array_column($sorted, 'driven_order') === [1, 2, null, null], 'only stops with an arrival time get a driven number');
$assert(array_column($sorted, 'route_order') === [1, 3, 2, 4], 'plan numbers stay on the stop');
$assert(array_column($sorted, 'mark') === ['with_photo', 'with_photo', 'no_info', 'delivered_no_photo'], 'marks are with photo, delivered without photo, and no info');
$bayLabel = bakery_driver_reports_stop_label($sorted[0]);
$assert(strpos($bayLabel, '1.') !== false && strpos($bayLabel, 'Bay') !== false && strpos($bayLabel, '#1') !== false, 'driven label names the store and the plan stop');

$two = bakery_driver_reports_summary_from_journey([
    'drivers' => [
        ['id' => 7, 'name' => 'Juan', 'stops' => [reports_stop('Amigos', 1, '2026-10-21 10:00:00', '2026-10-21 10:10:00', 'pod', 'delivered')]],
        ['id' => 2, 'name' => 'Laura', 'stops' => [reports_stop('Capp', 3, '2026-10-21 09:00:00', '2026-10-21 09:08:00', 'pod', 'delivered')]],
    ],
], [], []);
$assert(array_column($two, 'name') === ['Juan', 'Laura'], 'a manager summary keeps every driver');

echo "\n=== Flags ===\n";
$flagStops = [
    reports_stop('Open', 1, '2026-10-21 09:00:00', '', 'arrived_open', 'pending', [
        ['id' => 1, 'photo_type' => 'Before', 'file_path' => '2026/10/driver1_customer1_Before_20261021_090000_aaaaaaaa.jpg'],
    ]),
    reports_stop('A', 2, '2026-10-21 10:00:00', '2026-10-21 10:00:00', 'pod', 'delivered'),
    reports_stop('B', 3, '2026-10-21 10:02:00', '2026-10-21 10:02:00', 'pod', 'delivered'),
    reports_stop('C', 4, '2026-10-21 10:05:00', '2026-10-21 10:05:00', 'pod', 'delivered'),
    reports_stop('Later', 5, '2026-10-21 11:00:00', '2026-10-21 11:10:00', 'pod', 'delivered'),
    reports_stop('Missed', 6, '', '', 'pending', 'pending'),
    reports_stop('No photo', 7, '', '2026-10-21 12:00:00', 'pending', 'delivered'),
];
$openFlags = bakery_driver_reports_flags($flagStops, [
    'driver_id' => 2,
    'loaded_units' => 0,
    'load_id' => null,
    'is_reconciled' => false,
]);
$assert($openFlags['arrived_not_closed'] === ['Open'], 'arrived and not closed is flagged');
$assert($openFlags['bulk_stamps'] === ['A', 'B', 'C'], 'three stamps inside 5 minutes are a bulk stamp');
$assert(!in_array('Later', $openFlags['bulk_stamps'], true), 'a later stamp is not part of the 5 minute cluster');
$assert($openFlags['never_visited'] === ['Missed'], 'a planned stop with no arrival and no stamp was never visited');
$assert(!in_array('No photo', $openFlags['never_visited'], true), 'a delivery stamp without a photo was not skipped');
$assert($openFlags['no_pickup_load'] === true, 'no load row is no pickup load');
$assert($openFlags['route_not_closed'] === true, 'an unreconciled route is not closed');

$pair = bakery_driver_reports_flags([
    reports_stop('A', 1, '2026-10-21 10:00:00', '2026-10-21 10:00:00', 'pod', 'delivered'),
    reports_stop('B', 2, '2026-10-21 10:04:00', '2026-10-21 10:04:00', 'pod', 'delivered'),
], ['loaded_units' => 12, 'load_id' => 4, 'is_reconciled' => true]);
$assert($pair['bulk_stamps'] === [], 'two stamps are not several');
$assert($pair['no_pickup_load'] === false && $pair['route_not_closed'] === false, 'a reconciled load is not an open route');

$wide = bakery_driver_reports_flags([
    reports_stop('A', 1, '', '2026-10-21 10:00:00', 'pod', 'delivered'),
    reports_stop('B', 2, '', '2026-10-21 10:03:00', 'pod', 'delivered'),
    reports_stop('C', 3, '', '2026-10-21 10:06:00', 'pod', 'delivered'),
], ['loaded_units' => 1, 'load_id' => 1, 'is_reconciled' => false]);
$assert($wide['bulk_stamps'] === [], 'stamps spanning more than 5 minutes are not a bulk stamp');
$assert($wide['route_not_closed'] === true && $wide['no_pickup_load'] === false, 'a saved load can still be an open route');

$quiet = bakery_driver_reports_flags([], null);
$assert($quiet['no_pickup_load'] === false && $quiet['route_not_closed'] === false, 'a driver with no stops is not flagged for load or closeout');

$exact = bakery_driver_reports_bulk_stamp_names([
    reports_stop('A', 1, '', '2026-10-21 10:00:00', 'pod', 'delivered'),
    reports_stop('B', 2, '', '2026-10-21 10:02:30', 'pod', 'delivered'),
    reports_stop('C', 3, '', '2026-10-21 10:05:00', 'pod', 'delivered'),
]);
$assert($exact === ['A', 'B', 'C'], 'a cluster that lands on 5 minutes exactly is flagged');

echo "\n=== Photo fallback ===\n";
$after = '2026/10/driver2_customer70_After_20261021_110000_aaaaaaaa.jpg';
$before = '2026/10/driver2_customer70_Before_20261021_105000_bbbbbbbb.jpg';
$receipt = '2026/10/driver2_customer70_Receipt_20261021_110500_cccccccc.jpg';
$both = bakery_driver_reports_thumbnail([
    ['id' => 2, 'photo_type' => 'Before', 'file_path' => $before],
    ['id' => 1, 'photo_type' => 'After', 'file_path' => $after, 'url' => '/uploads/driver_photos/secret.jpg'],
]);
$assert(is_array($both) && ($both['id'] ?? 0) === 1 && ($both['kind'] ?? '') === 'proof', 'proof or departure photo wins over arrival');
$assert(is_array($both) && strpos((string)$both['url'], 'driver_photo.php') !== false, 'thumbnail URL goes through driver_photo.php');
$assert(is_array($both) && strpos((string)$both['url'], 'uploads/driver_photos') === false, 'thumbnail URL does not point at the uploads folder');

$receiptOnly = bakery_driver_reports_thumbnail([
    ['id' => 4, 'photo_type' => 'Before', 'file_path' => $before],
    ['id' => 5, 'photo_type' => 'Receipt', 'file_path' => $receipt],
]);
$assert(is_array($receiptOnly) && ($receiptOnly['id'] ?? 0) === 5 && ($receiptOnly['kind'] ?? '') === 'proof', 'a receipt counts as proof when there is no departure photo');

$arrivalOnly = bakery_driver_reports_thumbnail([
    ['id' => 8, 'photo_type' => 'Before', 'file_path' => $before],
]);
$assert(is_array($arrivalOnly) && ($arrivalOnly['id'] ?? 0) === 8 && ($arrivalOnly['kind'] ?? '') === 'arrival', 'arrival is the fallback when there is no proof');
$assert(bakery_driver_reports_thumbnail([]) === null, 'no photo stays empty');
$assert(bakery_driver_reports_thumbnail([
    ['id' => 9, 'photo_type' => 'After', 'url' => 'https://bakery.sourflour.org/uploads/driver_photos/x.jpg', 'file_path' => ''],
]) === null, 'a public uploads URL is not used when there is no safe file');

$sheet = bakery_driver_reports_onepager_stop([
    'customer_id' => 70,
    'customer_name' => 'Capp',
    'route_order' => 3,
    'driven_order' => 1,
    'arrival_at' => '2026-10-21 10:00:00',
    'delivered_at' => null,
    'stay_minutes' => 0,
    'scheduled_delivery_time' => '09:15:00',
    'badge' => 'arrived_open',
    'photos' => [],
], null);
$assert($sheet['arrival_at'] === '2026-10-21 10:00:00', 'recorded arrival is kept');
$assert($sheet['departure_at'] === null && $sheet['minutes_at_stop'] === null, 'missing departure stays blank and is not estimated as zero');
$assert($sheet['minutes_since_previous'] === null, 'the first stop has no minutes since a previous stop');
$assert(!array_key_exists('scheduled_delivery_time', $sheet), 'a scheduled time is not copied onto the sheet');

$second = bakery_driver_reports_onepager_stop([
    'customer_id' => 71,
    'customer_name' => 'Bay',
    'route_order' => 1,
    'driven_order' => 2,
    'arrival_at' => '2026-10-21 10:20:00',
    'delivered_at' => '2026-10-21 10:32:00',
    'badge' => 'pod',
    'photos' => [
        ['id' => 1, 'photo_type' => 'After', 'file_path' => $after],
    ],
], [
    'arrival_at' => '2026-10-21 10:00:00',
    'delivered_at' => '',
]);
$assert($second['minutes_at_stop'] === 12, 'minutes at the stop are departure minus arrival');
$assert($second['minutes_since_previous'] === null, 'minutes since the last stop stay blank when the previous departure is missing');

$third = bakery_driver_reports_onepager_stop([
    'customer_id' => 72,
    'customer_name' => 'Lucky',
    'route_order' => 5,
    'arrival_at' => '2026-10-21 11:00:00',
    'delivered_at' => '2026-10-21 11:06:00',
    'badge' => 'pod',
    'photos' => [],
], [
    'arrival_at' => '2026-10-21 10:20:00',
    'delivered_at' => '2026-10-21 10:32:00',
]);
$assert($third['minutes_since_previous'] === 28, 'minutes since the last stop use the previous departure and this arrival');
$assert($third['thumbnail'] === null, 'a stop with no photo says so by leaving the thumbnail empty');

echo "\n=== Tomorrow plan ===\n";
$today = '2026-10-21';
$assert(bakery_driver_reports_default_date('summary', $today) === $today, 'end of day defaults to today');
$assert(bakery_driver_reports_default_date('sheet', $today) === $today, 'one page defaults to today');
$assert(bakery_driver_reports_default_date('plan', $today) === '2026-10-22', 'the plan tab defaults to tomorrow');
$assert(bakery_driver_reports_tab_date('plan', 'summary', $today, $today) === '2026-10-22', 'opening the plan tab from today lands on tomorrow');
$assert(bakery_driver_reports_tab_date('sheet', 'summary', '2026-10-20', $today) === '2026-10-20', 'the one page keeps the end of day date');
$assert(bakery_driver_reports_tab_date('summary', 'plan', '2026-10-22', $today) === $today, 'leaving the plan tab returns to today');
$assert(bakery_driver_reports_parse_tab('nope') === 'summary', 'an unknown tab is the end of day summary');

$plan = bakery_driver_reports_plan_build([
    [
        'id' => 2,
        'name' => 'Laura',
        'deliveries' => [
            ['customer_id' => 10, 'customer_name' => 'Capp', 'route_order' => 3, 'item_count' => 12, 'total_amount' => 40],
            ['customer_id' => 11, 'customer_name' => 'Bay', 'route_order' => 1, 'item_count' => 4, 'total_amount' => 9],
            ['customer_id' => 12, 'customer_name' => 'Closed', 'route_order' => 2, 'item_count' => 0, 'total_amount' => 0],
        ],
    ],
    [
        'id' => 7,
        'name' => 'Juan',
        'deliveries' => array_map(static function (int $n): array {
            return [
                'customer_id' => 100 + $n,
                'customer_name' => 'Stop ' . $n,
                'route_order' => $n,
                'item_count' => 1,
            ];
        }, range(1, 11)),
    ],
], [
    ['id' => 30, 'name' => 'Falta Cafe', 'pieces' => 8, 'zone' => 'Mission'],
], [
    [
        'driver_id' => 2,
        'driver_name' => 'Laura',
        'stops' => [
            ['customer_id' => 12, 'customer_name' => 'Closed', 'route_order' => 2],
            ['customer_id' => 40, 'customer_name' => 'Off Tomorrow', 'route_order' => 8],
            ['customer_id' => 30, 'customer_name' => 'Falta Cafe', 'route_order' => 9],
            ['customer_id' => 10, 'customer_name' => 'Capp', 'route_order' => 3],
        ],
    ],
]);
$laura = $plan['drivers'][0];
$juan = $plan['drivers'][1];
$assert(array_column($laura['stops'], 'customer_name') === ['Bay', 'Capp'], 'plan stops with a delivery stay in plan order');
$assert(array_column($laura['stops'], 'route_order') === [1, 3], 'plan numbers are not renumbered');
$assert($laura['minutes_min'] === 60 && $laura['minutes_max'] === 90, 'each delivery stop estimates 30 to 45 minutes');
$assert($laura['overloaded'] === false, 'two stops fit in an 8 hour day');
$assert($juan['stop_count'] === 11 && $juan['overloaded'] === true, 'eleven stops at 45 minutes overload an 8 hour day');
$assert(array_column($plan['no_van'], 'customer_name') === ['Closed', 'Off Tomorrow'], 'not going tomorrow excludes deliveries and unassigned orders');
$assert(array_column($plan['faltan'], 'name') === ['Falta Cafe'], 'unassigned orders are the faltan list');
$assert(!isset($plan['faltan'][0]['pieces']) && !isset($laura['stops'][0]['total_amount']), 'the plan drops pieces and amounts');
$money = reports_money_keys($plan);
$assert($money === [], 'plan output has no amount or price keys' . ($money ? (': ' . implode(', ', $money)) : ''));

echo "\n=== Strings ===\n";
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$prefixes = ['driver_reports.', 'page.driver_reports', 'nav.item.driver_reports', 'nav.item_desc.driver_reports', 'manager_phone.driver_reports'];
foreach ($prefixes as $prefix) {
    $enKeys = array_values(array_filter(array_keys($en), static fn(string $key): bool => strpos($key, $prefix) === 0));
    $esKeys = array_values(array_filter(array_keys($es), static fn(string $key): bool => strpos($key, $prefix) === 0));
    sort($enKeys);
    sort($esKeys);
    $assert($enKeys !== [] && $enKeys === $esKeys, $prefix . ' keys match in English and Spanish');
    foreach ($enKeys as $key) {
        $assert($en[$key] !== '' && $es[$key] !== '', $key . ' is not empty');
        $assert(strpos((string)$en[$key], "\u{2013}") === false && strpos((string)$en[$key], "\u{2014}") === false, $key . ' English has no en dash or em dash');
        $assert(strpos((string)$es[$key], "\u{2013}") === false && strpos((string)$es[$key], "\u{2014}") === false, $key . ' Spanish has no en dash or em dash');
    }
}
$assert(strpos((string)$es['driver_reports.mark_with_photo'], 'Con foto') !== false, 'Spanish mark is Con foto');
$assert(strpos((string)$es['driver_reports.mark_delivered_no_photo'], 'Entregadas sin foto') !== false, 'Spanish mark is Entregadas sin foto');
$assert((string)$es['driver_reports.mark_no_info'] === 'Sin info', 'Spanish mark is Sin info');
$assert(strpos((string)$es['driver_reports.stop_line'], 'parada plan') !== false, 'Spanish driven line uses parada plan');
$assert(strpos((string)$es['driver_reports.plan_with_delivery'], 'Con entrega') !== false, 'Spanish plan heading is Con entrega');
$assert((string)$es['driver_reports.plan_no_van'] === 'No van mañana', 'Spanish off-tomorrow heading is No van mañana');
$assert((string)$es['driver_reports.plan_faltan'] === 'Faltan', 'Spanish unassigned heading is Faltan');

foreach ([
    $root . '/includes/driver_reports.php',
    $root . '/driver_reports.php',
    $root . '/css/driver_reports.css',
] as $path) {
    $raw = (string)file_get_contents($path);
    $assert(strpos($raw, "\u{2013}") === false && strpos($raw, "\u{2014}") === false, basename($path) . ' has no en dash or em dash');
}

echo "\n=== Database load on bakerysf_test ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');
$loaded = bakery_driver_reports_load_summary($db, '2037-01-01');
$assert(isset($loaded['drivers']) && is_array($loaded['drivers']), 'summary load returns a driver list');
$loadedPlan = bakery_driver_reports_load_plan($db, '2037-01-02');
$assert(isset($loadedPlan['drivers'], $loadedPlan['no_van'], $loadedPlan['faltan']), 'plan load returns deliveries, off tomorrow, and unassigned');
$assert(reports_money_keys($loaded) === [] && reports_money_keys($loadedPlan) === [], 'loaded reports do not carry amounts');

echo "\n" . ($fail === 0 ? "ALL PASS\n" : ($fail . " FAILED\n"));
exit($fail === 0 ? 0 : 1);

/**
 * @param array<int, array<string, mixed>> $photos
 * @return array<string, mixed>
 */
function reports_stop(
    string $name,
    int $plan,
    string $arrival,
    string $departure,
    string $badge,
    string $status,
    array $photos = []
): array {
    if ($photos === [] && in_array($badge, ['pod', 'arrival', 'arrived_open'], true)) {
        $photos = [[
            'id' => 1,
            'photo_type' => $badge === 'pod' ? 'After' : 'Before',
            'file_path' => '2026/10/driver2_customer' . $plan . '_After_20261021_100000_aaaaaaaa.jpg',
        ]];
    }
    return [
        'customer_id' => 1000 + $plan,
        'customer_name' => $name,
        'route_order' => $plan,
        'arrival_at' => $arrival !== '' ? $arrival : null,
        'delivered_at' => $departure !== '' ? $departure : null,
        'badge' => $badge,
        'delivery_status' => $status,
        'photos' => $photos,
    ];
}

/**
 * @param array<mixed> $node
 * @return list<string>
 */
function reports_money_keys(array $node): array
{
    $found = [];
    $walk = static function ($value, string $path) use (&$walk, &$found): void {
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $name = (string)$key;
            $here = $path === '' ? $name : $path . '.' . $name;
            if (preg_match('/amount|price|sold|total/i', $name)) {
                $found[] = $here;
            }
            $walk($child, $here);
        }
    };
    $walk($node, '');
    return $found;
}
