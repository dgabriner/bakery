<?php
/**
 * Delivery review: two weeks of drivers, photos, and standing versus actual.
 *
 * Usage: php tests/run_delivery_manager_review_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$pageFile = $root . '/delivery_manager_review.php';
$includeFile = $root . '/includes/delivery_manager_review.php';
if (!is_file($pageFile) || !is_file($includeFile)) {
    echo "FAIL  delivery review page and include exist\n";
    echo "1 FAILED\n";
    exit(1);
}

require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $includeFile;
require_once $root . '/includes/navigation_catalog.php';
require_once $root . '/includes/admin_workspace_mode.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== Standing versus what happened ===\n";
$quiet = ['expected' => 4, 'routed' => 0, 'delivered' => 0, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$quietFlags = bakery_delivery_manager_review_customer_flags($quiet);
$assert(!empty($quietFlags['quiet']) && !empty($quietFlags['short']) && empty($quietFlags['missed']), 'a standing store with no route is quiet and short');
$assert(bakery_delivery_manager_review_customer_reading($quiet)['key'] === 'delivery_review.reading_quiet', 'a quiet store says nothing was put on a driver');

$missed = ['expected' => 4, 'routed' => 4, 'delivered' => 1, 'open' => 2, 'failed' => 1, 'cancelled' => 0, 'off_count' => 0];
$missedFlags = bakery_delivery_manager_review_customer_flags($missed);
$assert(!empty($missedFlags['missed']) && !empty($missedFlags['short']), 'open and failed stops stay visible when the store is also short');
$assert(bakery_delivery_manager_review_customer_reading($missed)['key'] === 'delivery_review.reading_missed', 'a missed store leads with the stops that did not get delivered');

$short = ['expected' => 4, 'routed' => 2, 'delivered' => 2, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$assert(bakery_delivery_manager_review_customer_reading($short)['key'] === 'delivery_review.reading_short', 'fewer deliveries than standing is short when nothing is still open');

$cancelled = ['expected' => 2, 'routed' => 2, 'delivered' => 0, 'open' => 0, 'failed' => 0, 'cancelled' => 2, 'off_count' => 0];
$cancelledFlags = bakery_delivery_manager_review_customer_flags($cancelled);
$assert(empty($cancelledFlags['missed']) && !empty($cancelledFlags['short']), 'a cancelled stop is not a miss and is not a delivery');
$assert(bakery_delivery_manager_review_customer_reading($cancelled)['key'] === 'delivery_review.reading_short_cancelled', 'cancelled visits stay in the reading');

$onTrack = ['expected' => 4, 'routed' => 4, 'delivered' => 4, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 1];
$onFlags = bakery_delivery_manager_review_customer_flags($onTrack);
$assert(!empty($onFlags['on_track']) && !empty($onFlags['off_driver']), 'a full delivery by another driver is both on track and a different driver');
$assert(bakery_delivery_manager_review_customer_reading($onTrack)['key'] === 'delivery_review.reading_on_track', 'the delivery count stays the main reading when the driver changed');

$extra = ['expected' => 2, 'routed' => 3, 'delivered' => 3, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$assert(bakery_delivery_manager_review_customer_reading($extra)['key'] === 'delivery_review.reading_extra', 'more deliveries than standing is extra');
$assert(bakery_delivery_manager_review_customer_matches($extra, 'extra') === true, 'the extra filter matches that store');
$assert(bakery_delivery_manager_review_customer_matches($extra, 'short') === false, 'extra is not fewer than standing');

$noStanding = ['expected' => 0, 'routed' => 2, 'delivered' => 1, 'open' => 1, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$assert(bakery_delivery_manager_review_customer_reading($noStanding)['key'] === 'delivery_review.reading_no_standing_missed', 'deliveries without a standing route still show the open stop');

$pattern = bakery_delivery_manager_review_pattern_label([
    ['dow' => 1, 'drivers' => ['Marcos', 'Marcos']],
    ['dow' => 3, 'drivers' => ['Sergio']],
]);
$dayNames = function_exists('bakery_day_names') ? bakery_day_names(true) : [1 => '1', 3 => '3'];
$assert($pattern === ($dayNames[1] . ' Marcos, ' . $dayNames[3] . ' Sergio'), 'the standing pattern names each day and driver once');
$assert(bakery_delivery_manager_review_pattern_label([]) === bakery_t('delivery_review.no_standing_pattern'), 'no standing rows say there is no standing route');

$range = bakery_delivery_manager_review_parse_range('2099-11-14', '', '');
$assert($range['from'] === '2099-11-01' && $range['to'] === '2099-11-14' && $range['clamped'] === false, 'an empty range is the last 14 days');
$assert(bakery_delivery_manager_review_parse_view('customers') === 'customers', 'stores is a view');
$assert(bakery_delivery_manager_review_parse_view('nope') === 'drivers', 'an unknown view opens on drivers');
$assert(bakery_delivery_manager_review_parse_filter('off_driver') === 'off_driver', 'different driver is a filter');
$assert(bakery_delivery_manager_review_parse_filter('billing') === 'all', 'an unknown filter is all stores');

$href = bakery_delivery_manager_review_href([
    'view' => 'customers',
    'from' => '2099-11-01',
    'to' => '2099-11-14',
    'filter' => 'all',
    'driver' => 4,
    'q' => 'Mission',
]);
$assert(strpos($href, 'view=customers') !== false && strpos($href, 'driver=') === false && strpos($href, 'filter=') === false, 'the store link keeps the search and drops the driver and the all filter');

echo "\n=== Finding your way around ===\n";
$assert(bakery_delivery_manager_review_parse_filter('unassigned') === 'unassigned', 'order with no driver is a filter');
$assert(bakery_delivery_manager_review_parse_filter('on_track') === 'on_track', 'on track is a filter');
$assert(bakery_delivery_manager_review_parse_sort('gap') === 'gap', 'biggest shortfall is a sort');
$assert(bakery_delivery_manager_review_parse_sort('price') === 'attention', 'an unknown sort puts what needs a look first');
$assert(bakery_delivery_manager_review_parse_day('2099-11-03') === '2099-11-03' && bakery_delivery_manager_review_parse_day('tuesday') === '', 'a day link only takes a real date');
$dayHref = bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => '2099-11-01', 'to' => '2099-11-14', 'driver' => 4, 'day' => '2099-11-03', 'sort' => 'name']);
$assert(strpos($dayHref, 'driver=4') !== false && strpos($dayHref, 'day=2099-11-03') !== false && strpos($dayHref, 'sort=') === false, 'a driver link can open one day and drops the store sort');
$noDriverDay = bakery_delivery_manager_review_href(['view' => 'drivers', 'from' => '2099-11-01', 'to' => '2099-11-14', 'day' => '2099-11-03']);
$assert(strpos($noDriverDay, 'day=') === false, 'a day without a driver is dropped');
$sortHref = bakery_delivery_manager_review_href(['view' => 'customers', 'from' => '2099-11-01', 'to' => '2099-11-14', 'sort' => 'last', 'filter' => 'unassigned']);
$assert(strpos($sortHref, 'sort=last') !== false && strpos($sortHref, 'filter=unassigned') !== false, 'a store link keeps its sort and filter');

$presets = bakery_delivery_manager_review_presets('2099-11-14');
$assert(array_column($presets, 'days') === [7, 14, 28], 'quick ranges are 7, 14, and 28 days');
$assert($presets[0]['from'] === '2099-11-08' && $presets[0]['to'] === '2099-11-14', 'the 7 day range ends today and counts today');
$assert($presets[1]['from'] === '2099-11-01', 'the 14 day range matches the default range');
$before = bakery_delivery_manager_review_previous_range('2099-11-01', '2099-11-14');
$assert($before === ['from' => '2099-10-18', 'to' => '2099-10-31', 'days' => 14], 'the period before is the same length and ends the day before');
$assert(bakery_delivery_manager_review_previous_range('nope', '2099-11-14')['days'] === 0, 'a bad range has no period before');

echo "\n=== Numbers and day states ===\n";
$assert(bakery_delivery_manager_review_percent(150, 169) === 89, 'a rate rounds to a whole percent');
$assert(bakery_delivery_manager_review_percent(0, 0) === null, 'a rate with nothing to count is blank, not zero');
$assert(bakery_delivery_manager_review_usual_clock(['2099-11-02 09:00:00', '2099-11-03 10:00:00', '2099-11-04 14:00:00']) === '10:00', 'the usual time is the middle one, so one late day does not move it');
$assert(bakery_delivery_manager_review_usual_clock(['2099-11-02 09:00:00', '2099-11-03 10:04:00']) === '09:32', 'two days meet in the middle');
$assert(bakery_delivery_manager_review_usual_clock([null, '', 'nope']) === null, 'no times means no usual time');

$dayBase = ['standing_stops' => 0, 'paused_stops' => 0, 'unrouted_stops' => 0, 'covered_stops' => 0, 'stops' => 0, 'delivered' => 0, 'delivered_with_after' => 0, 'open' => 0, 'failed' => 0];
$assert(bakery_delivery_manager_review_day_state(['stops' => 5, 'delivered' => 5, 'delivered_with_after' => 5] + $dayBase) === 'clean', 'every stop delivered with an After photo is a clean day');
$assert(bakery_delivery_manager_review_day_state(['stops' => 5, 'delivered' => 5, 'delivered_with_after' => 4] + $dayBase) === 'photo_gap', 'a delivered stop with no After photo marks the day');
$assert(bakery_delivery_manager_review_day_state(['stops' => 5, 'delivered' => 4, 'delivered_with_after' => 3, 'open' => 1] + $dayBase) === 'problem', 'an open stop outranks a missing photo');
$assert(bakery_delivery_manager_review_day_state(['stops' => 2, 'delivered' => 1, 'delivered_with_after' => 1, 'failed' => 1] + $dayBase) === 'problem', 'a failed stop is a problem day');
$assert(bakery_delivery_manager_review_day_state(['standing_stops' => 6, 'unrouted_stops' => 6] + $dayBase) === 'not_out', 'standing stops nobody ran is a day not put on a route');
$assert(bakery_delivery_manager_review_day_state(['standing_stops' => 6, 'covered_stops' => 6] + $dayBase) === 'covered', 'standing stops another driver ran is a covered day');
$assert(bakery_delivery_manager_review_day_state(['standing_stops' => 2, 'paused_stops' => 2] + $dayBase) === 'idle', 'a day where every standing stop was paused calls for nothing');
$assert(bakery_delivery_manager_review_day_state($dayBase) === 'idle', 'a day with nothing is idle');

$assert(bakery_delivery_manager_review_visit_state(['status' => 'delivered', 'has_after' => true]) === 'clean', 'a delivered visit with an After photo is clean');
$assert(bakery_delivery_manager_review_visit_state(['status' => 'delivered', 'has_after' => false]) === 'photo_gap', 'a delivered visit with no After photo is a photo gap');
$assert(bakery_delivery_manager_review_visit_state(['status' => 'unassigned']) === 'problem', 'an order with no driver is a problem');
$assert(bakery_delivery_manager_review_visit_state(['status' => 'cancelled']) === 'cancelled', 'a cancelled visit is neither clean nor a problem');
$assert(bakery_delivery_manager_review_visit_state(['status' => 'paused']) === 'paused', 'a paused visit is explained');
$assert(bakery_delivery_manager_review_visit_state(['status' => 'not_routed']) === 'not_out', 'a standing visit nobody routed is not out');
$strip = bakery_delivery_manager_review_customer_strip([
    'visits' => [
        ['date' => '2099-11-02', 'status' => 'delivered', 'has_after' => true],
        ['date' => '2099-11-02', 'status' => 'open', 'has_after' => false],
        ['date' => '2099-11-04', 'status' => 'paused'],
    ],
], ['2099-11-02', '2099-11-03', '2099-11-04']);
$assert(array_column($strip, 'state') === ['problem', 'idle', 'paused'], 'the store strip shows the worst thing each day and leaves empty days empty');
$manyDates = [];
for ($i = 0; $i < 40; $i++) {
    $manyDates[] = (new DateTimeImmutable('2099-10-01'))->modify('+' . $i . ' days')->format('Y-m-d');
}
$stripDates = bakery_delivery_manager_review_strip_dates($manyDates);
$assert(count($stripDates) === BAKERY_DELIVERY_MANAGER_REVIEW_STRIP_DAYS && end($stripDates) === end($manyDates), 'a long range keeps the most recent days in the strip');

echo "\n=== One label per store ===\n";
$label = static fn(array $row): string => bakery_delivery_manager_review_customer_status($row)['key'];
$assert($label(['expected' => 2, 'routed' => 2, 'delivered' => 2, 'unassigned' => 1]) === 'delivery_review.state_unassigned', 'an order with no driver is the first thing a store is labeled with');
$assert(empty(bakery_delivery_manager_review_customer_flags(['expected' => 2, 'routed' => 2, 'delivered' => 2, 'unassigned' => 1])['on_track']), 'a store with an order that got no driver is not on track');
$assert($label($missed) === 'delivery_review.state_missed', 'open or failed comes before fewer than standing');
$assert($label($quiet) === 'delivery_review.state_quiet', 'a store never routed says so');
$assert($label($short) === 'delivery_review.state_short', 'a short store says fewer than standing');
$assert($label(['expected' => 2, 'routed' => 2, 'delivered' => 2, 'missing_after' => 1]) === 'delivery_review.state_missing_after', 'a full delivery with a missing photo is labeled for the photo');
$assert($label($onTrack) === 'delivery_review.state_off_driver', 'a full delivery by another driver is labeled different driver');
$assert($label(['expected' => 2, 'routed' => 2, 'delivered' => 2]) === 'delivery_review.state_on_track', 'a full delivery is on track');
$allPaused = ['expected' => 0, 'routed' => 0, 'delivered' => 0, 'paused' => 2];
$assert($label($allPaused) === 'delivery_review.state_paused', 'a store paused all window is paused, not short');
$assert(bakery_delivery_manager_review_customer_reading($allPaused)['key'] === 'delivery_review.reading_paused', 'the paused store reads as paused');
$assert(bakery_delivery_manager_review_customer_reading(['expected' => 0, 'routed' => 0, 'unassigned' => 1])['key'] === 'delivery_review.reading_unassigned', 'an order with no driver and nothing else reads as never given to a driver');

$sortRows = [
    ['name' => 'Bravo', 'expected' => 4, 'routed' => 4, 'delivered' => 4, 'last_delivered' => '2099-11-10'],
    ['name' => 'Alpha', 'expected' => 4, 'routed' => 1, 'delivered' => 1, 'last_delivered' => '2099-11-03'],
    ['name' => 'Charlie', 'expected' => 2, 'routed' => 2, 'delivered' => 1, 'open' => 1, 'last_delivered' => '2099-11-12'],
    ['name' => 'Delta', 'expected' => 2, 'routed' => 0, 'delivered' => 0, 'last_delivered' => null],
];
$names = static fn(string $sort): array => array_column(bakery_delivery_manager_review_sort_customers($sortRows, $sort), 'name');
$assert($names('attention') === ['Charlie', 'Delta', 'Alpha', 'Bravo'], 'the default order is open stops, then never routed, then short, then on track');
$assert($names('name') === ['Alpha', 'Bravo', 'Charlie', 'Delta'], 'stores sort by name');
$assert($names('gap') === ['Alpha', 'Delta', 'Charlie', 'Bravo'], 'biggest shortfall comes first');
$assert($names('last') === ['Delta', 'Alpha', 'Bravo', 'Charlie'], 'a store with no delivery comes before the oldest delivery');

echo "\n=== What stands out ===\n";
$clearReport = bakery_delivery_manager_review_blank('2099-11-01', '2099-11-14');
$clear = bakery_delivery_manager_review_insights($clearReport, '2099-11-14');
$assert(count($clear) === 1 && $clear[0]['key'] === 'delivery_review.insight_clear' && $clear[0]['tone'] === 'success', 'a clean window says nothing needs a look');
$busy = $clearReport;
$busy['clock_ready'] = true;
$busy['close_ready'] = true;
$busy['totals']['unassigned'] = 2;
$busy['totals']['unrouted'] = 5;
$busy['totals']['unrouted_customers'] = 3;
$busy['totals']['expected'] = 20;
$busy['needs'] = [
    ['driver_id' => 1, 'driver_name' => 'Ada', 'date' => '2099-11-03', 'reason' => 'open'],
    ['driver_id' => 1, 'driver_name' => 'Ada', 'date' => '2099-11-05', 'reason' => 'open'],
    ['driver_id' => 1, 'driver_name' => 'Ada', 'date' => '2099-11-14', 'reason' => 'open'],
    ['driver_id' => 2, 'driver_name' => 'Bo', 'date' => '2099-11-06', 'reason' => 'failed'],
    ['driver_id' => 2, 'driver_name' => 'Bo', 'date' => '2099-11-06', 'reason' => 'missing_after'],
    ['driver_id' => 2, 'driver_name' => 'Bo', 'date' => '2099-11-07', 'reason' => 'missing_after'],
    ['driver_id' => 1, 'driver_name' => 'Ada', 'date' => '2099-11-07', 'reason' => 'missing_after'],
];
$busy['drivers'] = [
    ['driver_id' => 1, 'driver_name' => 'Ada', 'bulk_days' => 0, 'clocked_days' => 3, 'unclocked_days' => 0, 'closed_days' => 0, 'unclosed_days' => 3, 'repeat' => []],
    ['driver_id' => 2, 'driver_name' => 'Bo', 'bulk_days' => 2, 'clocked_days' => 0, 'unclocked_days' => 4, 'closed_days' => 0, 'unclosed_days' => 4, 'repeat' => [['customer_name' => 'Corner Cafe', 'times' => 3]]],
];
$found = [];
foreach (bakery_delivery_manager_review_insights($busy, '2099-11-14') as $row) {
    $found[$row['key']] = $row;
}
$stale = $found['delivery_review.insight_stale_open'] ?? null;
$assert(is_array($stale) && (int)$stale['params']['count'] === 2 && $stale['tone'] === 'danger', 'open stops from earlier days are counted apart from today');
$assert(is_array($stale) && strpos((string)$stale['params']['date'], 'Nov 3') !== false, 'the oldest open day is named');
$assert((int)($found['delivery_review.insight_open_today']['params']['count'] ?? 0) === 1, 'a stop open today is its own calmer line');
$assert((int)($found['delivery_review.insight_failed']['params']['count'] ?? 0) === 1, 'failed stops are counted');
$assert((int)($found['delivery_review.insight_unassigned']['params']['count'] ?? 0) === 2 && strpos((string)$found['delivery_review.insight_unassigned']['href'], 'filter=unassigned') !== false, 'orders with no driver link to those stores');
$photo = $found['delivery_review.insight_missing_after'] ?? null;
$assert(is_array($photo) && (int)$photo['params']['count'] === 3 && $photo['params']['driver'] === 'Bo' && (int)$photo['params']['most'] === 2, 'missing After photos name the driver with the most');
$assert(is_array($photo) && strpos((string)$photo['href'], 'driver=2') !== false, 'that line opens that driver');
$assert(strpos((string)($found['delivery_review.insight_repeat']['params']['stores'] ?? ''), 'Corner Cafe') !== false, 'a store left open again and again is named');
$assert(($found['delivery_review.insight_bulk']['params']['drivers'] ?? '') === 'Bo 2', 'batch closes name the driver and the days');
$assert(($found['delivery_review.insight_unclocked']['params']['drivers'] ?? '') === 'Bo 4', 'days driven with no clock-in name the driver');
$assert(!isset($found['delivery_review.insight_unclosed']), 'route close is not raised when nobody closed a route in the window');
$gap = $found['delivery_review.insight_standing_gap'] ?? null;
$assert(is_array($gap) && (int)$gap['params']['count'] === 5 && (int)$gap['params']['expected'] === 20 && (int)$gap['params']['stores'] === 3, 'standing visits not routed are counted with the stores they touch');
$assert(!isset($found['delivery_review.insight_clear']), 'the all clear line is gone when something stands out');
$tones = array_column(bakery_delivery_manager_review_insights($busy, '2099-11-14'), 'tone');
$assert($tones[0] === 'danger' && end($tones) !== 'danger', 'the most urgent findings come first');
$quietClock = $busy;
$quietClock['drivers'][0]['clocked_days'] = 0;
$quietKeys = array_column(bakery_delivery_manager_review_insights($quietClock, '2099-11-14'), 'key');
$assert(!in_array('delivery_review.insight_unclocked', $quietKeys, true), 'clock-in is not raised when no driver clocked in at all');

$sum = bakery_delivery_manager_review_summary([
    'totals' => ['routed' => 20, 'cancelled' => 2, 'delivered' => 15, 'with_after' => 12, 'open' => 2, 'failed' => 1, 'unassigned' => 1, 'expected' => 30, 'standing_routed' => 18],
    'needs' => [
        ['reason' => 'open', 'date' => '2099-11-03'],
        ['reason' => 'open', 'date' => '2099-11-14'],
        ['reason' => 'failed', 'date' => '2099-11-03'],
    ],
], '2099-11-14');
$assert($sum['due'] === 18 && $sum['delivered_rate'] === 83, 'the delivered rate leaves cancelled stops out');
$assert($sum['after_rate'] === 80, 'the After photo rate is out of delivered stops');
$assert($sum['problem'] === 3 && $sum['stale_open'] === 1, 'open and failed are counted together, and old open stops apart');
$assert($sum['standing_rate'] === 60, 'standing visits routed is out of visits called for');

echo "\n=== Morning brief ===\n";
$clearBrief = bakery_delivery_manager_review_brief($clearReport, '2099-11-14');
$assert($clearBrief['tone'] === 'good' && $clearBrief['lead']['key'] === 'delivery_review.insight_clear', 'a clean window opens on the all-clear sentence');
$assert($clearBrief['cards'] === [] && $clearBrief['more'] === 0, 'a clean window has no cards');

$briefReport = bakery_delivery_manager_review_blank('2099-11-01', '2099-11-14');
$briefReport['dates'] = ['2099-11-13', '2099-11-14'];
$briefReport['drivers'] = [
    [
        'driver_id' => 4,
        'driver_name' => 'Rosa',
        'open' => 1,
        'failed' => 0,
        'missing_after' => 0,
        'day_rows' => [
            ['date' => '2099-11-13', 'state' => 'problem', 'stops' => 4, 'delivered' => 3, 'open' => 1, 'failed' => 0],
            ['date' => '2099-11-14', 'state' => 'clean', 'stops' => 2, 'delivered' => 2, 'open' => 0, 'failed' => 0],
        ],
    ],
    [
        'driver_id' => 5,
        'driver_name' => 'Luis',
        'open' => 0,
        'failed' => 0,
        'missing_after' => 0,
        'day_rows' => [
            ['date' => '2099-11-13', 'state' => 'clean', 'stops' => 2, 'delivered' => 2, 'open' => 0, 'failed' => 0],
        ],
    ],
];
$briefReport['needs'] = [
    ['reason' => 'open', 'date' => '2099-11-13', 'driver_id' => 4, 'driver_name' => 'Rosa', 'customer_id' => 9, 'customer_name' => 'Bi-Rite', 'photo_url' => ''],
    ['reason' => 'missing_after', 'date' => '2099-11-12', 'driver_id' => 4, 'driver_name' => 'Rosa', 'customer_id' => 8, 'customer_name' => 'Tartine', 'photo_url' => ''],
    ['reason' => 'missing_after', 'date' => '2099-11-13', 'driver_id' => 4, 'driver_name' => 'Rosa', 'customer_id' => 7, 'customer_name' => 'Jane', 'photo_url' => ''],
    ['reason' => 'missing_after', 'date' => '2099-11-14', 'driver_id' => 5, 'driver_name' => 'Luis', 'customer_id' => 6, 'customer_name' => 'Hayes', 'photo_url' => ''],
];
$quietStore = bakery_delivery_manager_review_blank_customer(3, 'Arizmendi', '', true);
$quietStore['expected'] = 4;
$briefReport['customers'] = [$quietStore];
$morning = bakery_delivery_manager_review_brief($briefReport, '2099-11-14');
$assert($morning['lead']['key'] === 'delivery_review.insight_stale_open', 'the sentence leads with the stop that is still open');
$assert($morning['tone'] === 'bad', 'an open stop from an earlier day colors the sentence');
$cardTitles = array_column($morning['cards'], 'title');
$assert($cardTitles[0] === 'Bi-Rite', 'the first card is the store that is still open');
$assert(($morning['cards'][0]['action'] ?? '') === 'sheet', 'that card opens the sheet');
$photoCards = array_values(array_filter($morning['cards'], static function (array $card): bool {
    return ($card['eyebrow_key'] ?? '') === 'delivery_review.eyebrow_photo';
}));
$assert(count($photoCards) === 1 && (int)$photoCards[0]['sentence_params']['count'] === 3 && $photoCards[0]['title'] === 'Rosa', 'missing After photos collapse to the driver with the most');
$assert(in_array('Arizmendi', $cardTitles, true), 'a store that never went out gets a card');
$wallNames = array_column($morning['wall']['rows'], 'name');
$calmNames = array_column($morning['wall']['calm_rows'], 'name');
$assert($wallNames === ['Rosa'] && $calmNames === ['Luis'], 'the day wall keeps the hot driver up and the clean driver folded');
$focused = bakery_delivery_manager_review_brief($briefReport, '2099-11-14', 4, 0, 'drivers');
$assert($focused['cards'] === [] && $focused['lead']['key'] === 'delivery_review.attention_open', 'opening one driver replaces the cards with that driver');
$assert(array_column($focused['wall']['rows'], 'name') === ['Rosa'] && $focused['wall']['calm_rows'] === [], 'that driver is the only row on the wall');

$pile = bakery_delivery_manager_review_blank('2099-11-01', '2099-11-14');
for ($i = 1; $i <= 5; $i++) {
    $pile['needs'][] = [
        'reason' => 'failed',
        'date' => '2099-11-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT),
        'driver_id' => 1,
        'driver_name' => 'Ada',
        'customer_id' => $i,
        'customer_name' => 'Store ' . $i,
        'photo_url' => '',
    ];
}
$piled = bakery_delivery_manager_review_cards($pile, '2099-11-14');
$assert(count($piled['cards']) === 4 && $piled['more'] === 1, 'the front door keeps four stops and counts the rest');
$assert(($piled['cards'][0]['title'] ?? '') === 'Store 5', 'the newest failed stop is the first card');

echo "\n=== Page is read only and gated ===\n";
$pageAccess = [
    'logged out' => [null, 302],
    'driver' => [['role_slug' => 'driver'], 403],
    'cashier' => [['role_slug' => 'cashier'], 403],
    'manager' => [['role_slug' => 'manager'], 200],
    'administrator' => [['role_slug' => 'administrator'], 200],
];
foreach ($pageAccess as $label => $pair) {
    $status = bakery_delivery_manager_review_access_status($pair[0]);
    $assert($status === $pair[1], 'delivery review access for ' . $label . ' is ' . $pair[1]);
}
$roles = bakery_navigation_roles_for_script('delivery_manager_review.php');
$assert(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'catalog allows administrator and manager');
$assert(!in_array('driver', $roles, true) && !in_array('cashier', $roles, true), 'catalog does not allow driver or cashier');
$assert(bakery_admin_workspace_mode_allows('driver', 'delivery_manager_review.php'), 'driver manager mode keeps the review');
$assert(!bakery_admin_workspace_mode_allows('production', 'delivery_manager_review.php'), 'production manager mode leaves the review with routes');

$nav = (string)file_get_contents($root . '/includes/nav.php');
$catalog = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$manager = (string)file_get_contents($root . '/manager.php');
$phone = (string)file_get_contents($root . '/includes/manager_phone.php');
$pageSrc = (string)file_get_contents($pageFile);
$includeSrc = (string)file_get_contents($includeFile);
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$navHealth = strpos($nav, 'route_health.php');
$navReview = strpos($nav, 'delivery_manager_review.php');
$navJourney = strpos($nav, 'route_photo_journey.php');
$assert($navHealth !== false && $navReview !== false && $navJourney !== false && $navHealth < $navReview && $navReview < $navJourney, 'manager nav lists the review with the other route checks');
$assert(strpos($catalog, "'href' => 'delivery_manager_review.php'") !== false, 'catalog lists the review');
$assert(strpos($manager, 'delivery_manager_review.php') !== false, 'manager page links the review');
$assert(strpos($phone, 'delivery_manager_review.php') !== false, 'phone manager page links the review');
$assert(strpos($pageSrc, 'bakery_delivery_manager_review_access_status') !== false, 'page checks the role gate');
$assert(strpos($pageSrc, 'bakery_delivery_manager_review_brief') !== false && strpos($pageSrc, 'delivery-review__hero') !== false, 'the page opens with the morning sentence');
$assert(strpos($pageSrc, 'delivery-review__record') !== false, 'the spreadsheet stays in the full record');
$assert(stripos($pageSrc, 'method="post"') === false && stripos($includeSrc, 'method="post"') === false, 'the page does not post');
foreach ([$pageSrc, $includeSrc] as $src) {
    $assert(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $src) !== 1, 'delivery review source has no write SQL');
    $assert(stripos($src, 'mail(') === false && stripos($src, 'twilio') === false, 'delivery review source does not send mail or text');
    $assert(strpos($src, "\u{2013}") === false && strpos($src, "\u{2014}") === false, 'delivery review source has no en dash or em dash');
}
$assert(strpos($manifest, "'delivery_manager_review.php'") !== false, 'deploy root list includes the page');
$assert(strpos($pageSrc, 'standing_routes.php') !== false && strpos($pageSrc, 'customer_record.php') !== false, 'a store links to the standing route and the customer record');
$assert(strpos($pageSrc, 'driver_photo.php') === false || strpos($includeSrc, 'driver_photo.php') !== false, 'photo links are built in the include');

echo "\n=== Strings ===\n";
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$prefixes = ['delivery_review.', 'page.delivery_manager_review', 'nav.item.delivery_manager_review', 'nav.item_desc.delivery_manager_review', 'manager_phone.delivery_review'];
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
        preg_match_all('/:[a-z_]+/', (string)$en[$key], $enTokens);
        preg_match_all('/:[a-z_]+/', (string)$es[$key], $esTokens);
        $left = $enTokens[0];
        $right = $esTokens[0];
        sort($left);
        sort($right);
        $assert($left === $right, $key . ' placeholders match');
    }
}

echo "\n=== Database load on bakerysf_test ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$d1 = '2099-11-02';
$d2 = '2099-11-09';
$weekday = bakery_standing_day_from_date($d1);
$repeatDates = [];
$cursor = new DateTimeImmutable($d1);
while (count($repeatDates) < 3) {
    $cursor = $cursor->modify('+1 day');
    $day = $cursor->format('Y-m-d');
    if ($day > $d2) {
        break;
    }
    if ((int)bakery_standing_day_from_date($day) === (int)$weekday) {
        continue;
    }
    $repeatDates[] = $day;
}
$assert(count($repeatDates) === 3, 'three non-standing dates exist inside the sample window');

$prefix = 'Review ' . bin2hex(random_bytes(3));
$created = [
    'customers' => [],
    'drivers' => [],
    'orders' => [],
    'photos' => [],
    'users' => [],
    'loads' => [],
];

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, zone, sfb_origin) VALUES (?, '1 Test St', ?, ?, 'human')"
    );
    $makeCustomer = static function (string $name, int $active, string $zone) use ($db, $insertCustomer, $prefix, &$created): int {
        $insertCustomer->execute([$prefix . ' ' . $name, $active, $zone]);
        $id = (int)$db->lastInsertId();
        $created['customers'][] = $id;
        return $id;
    };
    $ids = [
        'Quiet' => $makeCustomer('Quiet', 1, ''),
        'OnTrack' => $makeCustomer('OnTrack', 1, 'Mission'),
        'Short' => $makeCustomer('Short', 1, ''),
        'Missed' => $makeCustomer('Missed', 1, ''),
        'Failed' => $makeCustomer('Failed', 1, ''),
        'Cancel' => $makeCustomer('Cancel', 1, ''),
        'Off' => $makeCustomer('Off', 1, ''),
        'Extra' => $makeCustomer('Extra', 1, ''),
        'Before' => $makeCustomer('Before', 1, ''),
        'Repeat' => $makeCustomer('Repeat', 1, ''),
        'BulkA' => $makeCustomer('BulkA', 1, ''),
        'BulkB' => $makeCustomer('BulkB', 1, ''),
        'BulkC' => $makeCustomer('BulkC', 1, ''),
        'QuietBo' => $makeCustomer('QuietBo', 1, ''),
        'Inactive' => $makeCustomer('Inactive', 0, ''),
        'NoDriver' => $makeCustomer('NoDriver', 1, ''),
        'Paused' => $makeCustomer('Paused', 1, ''),
    ];

    $insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
    $insertDriver->execute([$prefix . ' Ada']);
    $adaId = (int)$db->lastInsertId();
    $insertDriver->execute([$prefix . ' Bo']);
    $boId = (int)$db->lastInsertId();
    $insertDriver->execute([$prefix . ' Cal']);
    $calId = (int)$db->lastInsertId();
    $insertDriver->execute([$prefix . ' Dee']);
    $deeId = (int)$db->lastInsertId();
    $created['drivers'] = [$adaId, $boId, $calId, $deeId];

    $insertOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at) VALUES (?, ?, ?, 0, ?)'
    );
    $insertAssignment = $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $routeOrders = [];
    $addStop = static function (int $customerId, int $driverId, string $date, string $status, ?string $time, string $note) use ($db, $insertOrder, $insertAssignment, &$created, &$routeOrders): void {
        $key = $driverId . '|' . $date;
        $routeOrders[$key] = ($routeOrders[$key] ?? 0) + 1;
        $confirmed = ($status === 'delivered' && $time !== null) ? $date . ' ' . $time : null;
        $insertOrder->execute([$customerId, $date, $status === 'delivered' ? 'delivered' : 'pending', $confirmed]);
        $orderId = (int)$db->lastInsertId();
        $created['orders'][] = $orderId;
        $insertAssignment->execute([$orderId, $driverId, $date, $routeOrders[$key], $status, $time, $note]);
    };

    $addStop($ids['OnTrack'], $adaId, $d1, 'delivered', '09:00:00', '');
    $addStop($ids['Short'], $adaId, $d1, 'delivered', '09:20:00', '');
    $addStop($ids['Missed'], $adaId, $d1, 'pending', null, '');
    $addStop($ids['Failed'], $adaId, $d1, 'failed', null, 'Door locked');
    $addStop($ids['Cancel'], $adaId, $d1, 'cancelled', null, '');
    $addStop($ids['Extra'], $adaId, $d1, 'delivered', '09:40:00', '');
    $addStop($ids['Before'], $adaId, $d1, 'delivered', '09:50:00', '');
    $addStop($ids['BulkA'], $adaId, $d1, 'delivered', '10:00:00', '');
    $addStop($ids['BulkB'], $adaId, $d1, 'delivered', '10:02:00', '');
    $addStop($ids['BulkC'], $adaId, $d1, 'delivered', '10:04:00', '');
    $addStop($ids['OnTrack'], $adaId, $d2, 'delivered', '09:00:00', '');
    $addStop($ids['Missed'], $adaId, $d2, 'pending', null, '');
    $addStop($ids['Cancel'], $adaId, $d2, 'cancelled', null, '');
    foreach ($repeatDates as $repeatDate) {
        $addStop($ids['Repeat'], $adaId, $repeatDate, 'pending', null, '');
    }
    $addStop($ids['Off'], $calId, $d1, 'delivered', '11:00:00', '');
    $addStop($ids['Off'], $calId, $d2, 'delivered', '11:10:00', '');

    // Orders that never got a driver. Only one of the three is waiting for one.
    $productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    $assert($productId > 0, 'a product exists on bakerysf_test');
    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 6, 1, 6)'
    );
    $addLooseOrder = static function (int $customerId, string $date, string $status, bool $withProduct) use ($db, $insertOrder, $insertItem, $productId, &$created): void {
        $insertOrder->execute([$customerId, $date, $status, null]);
        $orderId = (int)$db->lastInsertId();
        $created['orders'][] = $orderId;
        if ($withProduct) {
            $insertItem->execute([$orderId, $productId]);
        }
    };
    $addLooseOrder($ids['NoDriver'], $d1, 'pending', true);
    $addLooseOrder($ids['Quiet'], $d1, 'pending', false);
    $addLooseOrder($ids['Extra'], $d2, 'delivered', true);

    $insertPhoto = $db->prepare(
        'INSERT INTO driver_photos (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
         VALUES (?, ?, ?, ?, ?, ?, 20, \'image/jpeg\', ?)'
    );
    $addPhoto = static function (int $driverId, int $customerId, string $date, string $kind) use ($db, $insertPhoto, &$created): void {
        $file = 'driver' . $driverId . '_customer' . $customerId . '_' . $kind . '_' . str_replace('-', '', $date) . '_aaaaaaaa.jpg';
        $insertPhoto->execute([$driverId, $customerId, $date, $file, $file, $file, $kind]);
        $created['photos'][] = (int)$db->lastInsertId();
    };
    $addPhoto($adaId, $ids['OnTrack'], $d1, 'After');
    $addPhoto($adaId, $ids['OnTrack'], $d2, 'After');
    $addPhoto($adaId, $ids['Short'], $d1, 'After');
    $addPhoto($adaId, $ids['Extra'], $d1, 'After');
    $addPhoto($adaId, $ids['Before'], $d1, 'Before');
    $addPhoto($adaId, $ids['BulkA'], $d1, 'After');
    $addPhoto($adaId, $ids['BulkB'], $d1, 'After');
    $addPhoto($adaId, $ids['BulkC'], $d1, 'After');
    $addPhoto($calId, $ids['Off'], $d1, 'After');
    $addPhoto($calId, $ids['Off'], $d2, 'After');

    $insertStanding = $db->prepare(
        'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
    );
    $standingOrder = 1;
    foreach (['Quiet', 'OnTrack', 'Short', 'Missed', 'Failed', 'Cancel', 'Off'] as $name) {
        $insertStanding->execute([$weekday, $adaId, $ids[$name], $standingOrder]);
        $standingOrder++;
    }
    $insertStanding->execute([$weekday, $adaId, $ids['Inactive'], $standingOrder]);
    $insertStanding->execute([$weekday, $boId, $ids['QuietBo'], 1]);
    $insertStanding->execute([$weekday, $deeId, $ids['NoDriver'], 1]);
    $insertStanding->execute([$weekday, $deeId, $ids['Paused'], 2]);

    // One standing day paused for the week, the other skipped for the date.
    $pauseReady = table_exists($db, 'standing_order_pauses') && table_exists($db, 'customer_delivery_skips');
    if ($pauseReady) {
        $d1Day = new DateTimeImmutable($d1);
        $weekStart = $d1Day->modify('-' . ((int)$d1Day->format('N') - 1) . ' days')->format('Y-m-d');
        $db->prepare('INSERT INTO standing_order_pauses (customer_id, week_start, note) VALUES (?, ?, ?)')
            ->execute([$ids['Paused'], $weekStart, 'delivery review test']);
        $db->prepare('INSERT INTO customer_delivery_skips (customer_id, skip_date, note) VALUES (?, ?, ?)')
            ->execute([$ids['Paused'], $d2, 'delivery review test']);
    }

    $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'driver' LIMIT 1")->fetchColumn();
    $assert($roleId > 0, 'driver role exists on bakerysf_test');
    $email = 'delivery-review-' . bin2hex(random_bytes(3)) . '@example.test';
    $db->prepare('INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)')
        ->execute([$email, 'x', '7' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT), $prefix . ' Ada', $roleId, $adaId]);
    $userId = (int)$db->lastInsertId();
    $created['users'][] = $userId;
    if (table_exists($db, 'time_clock_punches')) {
        $db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at, clock_out_at) VALUES (?, ?, ?)')
            ->execute([$userId, $d1 . ' 07:05:00', $d1 . ' 15:00:00']);
    }
    if (table_exists($db, 'driver_loads')) {
        $db->prepare('INSERT INTO driver_loads (driver_id, delivery_date, status) VALUES (?, ?, ?)')
            ->execute([$adaId, $d1, 'reconciled']);
        $created['loads'][] = (int)$db->lastInsertId();
    }

    $loaded = bakery_delivery_manager_review_load($db, $d1, $d2);
    $assert($loaded['from'] === $d1 && $loaded['to'] === $d2, 'load keeps the requested range');

    $customers = [];
    foreach ($loaded['customers'] as $row) {
        $customers[(int)$row['customer_id']] = $row;
    }
    $drivers = [];
    foreach ($loaded['drivers'] as $row) {
        $drivers[(int)$row['driver_id']] = $row;
    }
    $assert(!isset($customers[$ids['Inactive']]), 'an inactive standing store is not part of the review');

    $quietRow = $customers[$ids['Quiet']];
    $assert((int)$quietRow['expected'] === 2 && (int)$quietRow['routed'] === 0, 'a standing store with no dated stops was called for twice and routed never');
    $assert(!empty($quietRow['flags']['quiet']) && $quietRow['reading']['key'] === 'delivery_review.reading_quiet', 'that store reads as never routed');

    $onRow = $customers[$ids['OnTrack']];
    $assert((string)$onRow['zone'] === 'Mission', 'the store keeps its zone');
    $assert((int)$onRow['expected'] === 2 && (int)$onRow['delivered'] === 2 && (int)$onRow['with_after'] === 2, 'a store delivered on both standing days with After photos is complete');
    $assert(!empty($onRow['flags']['on_track']) && empty($onRow['flags']['short']), 'complete standing deliveries are on track');
    $assert(strpos(bakery_delivery_manager_review_pattern_label($onRow['pattern']), $prefix . ' Ada') !== false, 'the standing pattern names the standing driver');
    $assert(bakery_delivery_manager_review_customer_search_match($onRow, 'mission') === true, 'search matches the zone');
    $assert(bakery_delivery_manager_review_customer_search_match($onRow, 'zzzz-no-store') === false, 'search misses a store that does not match');

    $shortRow = $customers[$ids['Short']];
    $assert((int)$shortRow['expected'] === 2 && (int)$shortRow['routed'] === 1 && (int)$shortRow['delivered'] === 1, 'one delivery against two standing visits is short');
    $assert($shortRow['reading']['key'] === 'delivery_review.reading_short', 'the short store says how many were delivered');
    $assert(empty($shortRow['flags']['quiet']) && empty($shortRow['flags']['missed']), 'a delivered short store is not quiet and not missed');

    $missedRow = $customers[$ids['Missed']];
    $assert((int)$missedRow['open'] === 2 && (int)$missedRow['delivered'] === 0, 'both standing days left open stay open');
    $assert($missedRow['reading']['key'] === 'delivery_review.reading_missed', 'the open store leads with the missed stops');

    $failedRow = $customers[$ids['Failed']];
    $assert((int)$failedRow['failed'] === 1 && (int)$failedRow['expected'] === 2 && (int)$failedRow['routed'] === 1, 'one failed visit does not hide the standing day that was never routed');
    $failedNote = '';
    foreach ($failedRow['visits'] as $visit) {
        if ((string)$visit['status'] === 'failed') {
            $failedNote = (string)$visit['note'];
        }
    }
    $assert($failedNote === 'Door locked', 'the failed stop keeps its note');

    $cancelRow = $customers[$ids['Cancel']];
    $assert((int)$cancelRow['cancelled'] === 2 && (int)$cancelRow['delivered'] === 0 && (int)$cancelRow['open'] === 0, 'cancelled stops are counted on their own');
    $assert(empty($cancelRow['flags']['missed']) && $cancelRow['reading']['key'] === 'delivery_review.reading_short_cancelled', 'cancelled is not treated as a missed delivery');

    $offRow = $customers[$ids['Off']];
    $assert((int)$offRow['delivered'] === 2 && (int)$offRow['off_count'] === 2 && !empty($offRow['flags']['on_track']) && !empty($offRow['flags']['off_driver']), 'deliveries by the other driver still count and stay marked');
    $offVisit = $offRow['visits'][0];
    $assert((int)$offVisit['actual_driver_id'] === $calId && !empty($offVisit['off_driver']), 'the visit names who went and that it was not the standing driver');

    $extraRow = $customers[$ids['Extra']];
    $assert((int)$extraRow['expected'] === 0 && (int)$extraRow['delivered'] === 1 && $extraRow['reading']['key'] === 'delivery_review.reading_no_standing', 'a delivery with no standing route says so');

    $beforeRow = $customers[$ids['Before']];
    $assert((int)$beforeRow['missing_after'] === 0 && (int)$beforeRow['with_after'] === 1, 'a Before photo is delivery proof');
    $beforeUrl = '';
    foreach ($beforeRow['visits'] as $visit) {
        if ((string)$visit['photo_url'] !== '') {
            $beforeUrl = (string)$visit['photo_url'];
        }
    }
    $assert(strpos($beforeUrl, 'driver_photo.php') !== false && strpos($beforeUrl, 'uploads/driver_photos') === false, 'the photo opens through driver_photo.php');

    $repeatRow = $customers[$ids['Repeat']];
    $assert((int)$repeatRow['open'] === 3 && $repeatRow['reading']['key'] === 'delivery_review.reading_no_standing_missed', 'three open visits with no standing route stay visible');

    $ada = $drivers[$adaId];
    $adaDays = [];
    foreach ($ada['day_rows'] as $day) {
        $adaDays[(string)$day['date']] = $day;
    }
    $assert((int)$adaDays[$d1]['standing_stops'] === 7, 'Ada standing count ignores the inactive store');
    $assert((int)$adaDays[$d1]['stops'] === 10 && (int)$adaDays[$d1]['delivered'] === 7 && (int)$adaDays[$d1]['open'] === 1 && (int)$adaDays[$d1]['failed'] === 1 && (int)$adaDays[$d1]['cancelled'] === 1, 'Ada day one counts each kind of stop');
    $assert((int)$adaDays[$d1]['delivered_with_after'] === 7, 'the Before photo counts as proof for its delivered stop');
    $assert((string)$adaDays[$d1]['last_close'] === $d1 . ' 10:04:00', 'the day closes at the last delivered stop');
    $assert($adaDays[$d1]['clocked_in'] === true && $adaDays[$d1]['route_closed'] === true, 'clock-in and a reconciled load show on that day');
    $assert((int)$adaDays[$d2]['standing_stops'] === 7 && (int)$adaDays[$d2]['stops'] === 3 && (int)$adaDays[$d2]['delivered'] === 1, 'the second standing day shows the stops that were actually routed');
    $assert($adaDays[$d2]['clocked_in'] === false && $adaDays[$d2]['route_closed'] === false, 'a day without a punch or a reconciled load is not closed');
    $bulk = $adaDays[$d1]['bulk_stores'];
    sort($bulk);
    $expectedBulk = [$prefix . ' BulkA', $prefix . ' BulkB', $prefix . ' BulkC'];
    sort($expectedBulk);
    $assert($bulk === $expectedBulk, 'three closes inside a few minutes are named and the earlier stops are not');
    $repeatNames = array_map(static fn(array $row): string => (string)$row['customer_name'], $ada['repeat']);
    $assert($repeatNames === [$prefix . ' Repeat'], 'a store left open three times is a repeat for that driver');
    $assert((int)$ada['missing_after'] === 0 && (int)$ada['bulk_days'] === 1, 'Ada has no missing proof photo and one bulk-stamp day');

    $bo = $drivers[$boId];
    $assert((int)$bo['stops'] === 0 && (int)$bo['standing_stops'] === 2 && (int)$bo['quiet_days'] === 2, 'a standing driver with no routed stops is listed at zero');
    $cal = $drivers[$calId];
    $assert((int)$cal['standing_stops'] === 0 && (int)$cal['delivered'] === 2 && (int)$cal['delivered_with_after'] === 2, 'the driver who covered the other route is credited for those deliveries');

    $adaNeeds = array_values(array_filter($loaded['needs'], static fn(array $row): bool => (int)$row['driver_id'] === $adaId));
    $needByCustomer = [];
    foreach ($adaNeeds as $need) {
        $needByCustomer[(int)$need['customer_id']][] = (string)$need['reason'];
    }
    $assert(!in_array('missing_after', $needByCustomer[$ids['Before']] ?? [], true), 'a delivered Before photo does not need a look');
    $assert(in_array('failed', $needByCustomer[$ids['Failed']] ?? [], true), 'a failed stop needs a look');
    $assert(!isset($needByCustomer[$ids['OnTrack']]), 'a delivered After photo does not need a look');
    $assert(!isset($needByCustomer[$ids['Cancel']]), 'a cancelled stop is not listed as open or failed');

    echo "\n=== Orders with no driver, pauses, and coverage ===\n";
    $noDriverRow = $customers[$ids['NoDriver']];
    $noDriverStatuses = [];
    foreach ($noDriverRow['visits'] as $visit) {
        $noDriverStatuses[(string)$visit['date']] = (string)$visit['status'];
    }
    $assert((int)$noDriverRow['unassigned'] === 1 && (int)$noDriverRow['routed'] === 0, 'an order with product and no driver is counted for the store');
    $assert($noDriverStatuses === [$d1 => 'unassigned', $d2 => 'not_routed'], 'the day with an order reads as no driver and the day without one reads as not routed');
    $assert(bakery_delivery_manager_review_customer_status($noDriverRow)['key'] === 'delivery_review.state_unassigned', 'that store is labeled order with no driver');
    $assert(bakery_delivery_manager_review_customer_matches($noDriverRow, 'unassigned') === true, 'the no driver filter finds that store');
    $assert((int)$quietRow['unassigned'] === 0, 'an order with nothing on it is not waiting for a driver');
    $assert((int)$extraRow['unassigned'] === 0 && (int)$extraRow['delivered'] === 1, 'an order already delivered without a route is not waiting for a driver');
    $assert((int)$loaded['totals']['unassigned'] === 1, 'the window has one order with no driver');

    if ($pauseReady) {
        $pausedRow = $customers[$ids['Paused']];
        $assert((int)$pausedRow['paused'] === 2 && (int)$pausedRow['expected'] === 0 && (int)$pausedRow['not_routed'] === 0, 'a paused week and a skipped date are not counted as called for');
        $assert(empty($pausedRow['flags']['short']) && empty($pausedRow['flags']['quiet']), 'a paused store is not short and not never routed');
        $assert($pausedRow['reading']['key'] === 'delivery_review.reading_paused', 'the paused store reads as paused');
        $assert(array_column(bakery_delivery_manager_review_customer_strip($pausedRow, [$d1, $d2]), 'state') === ['paused', 'paused'], 'both paused days show as paused in the strip');
    } else {
        echo "NOTE  pause tables are not on this database, pause checks skipped\n";
    }

    $dee = $drivers[$deeId];
    $deeDays = [];
    foreach ($dee['day_rows'] as $day) {
        $deeDays[(string)$day['date']] = $day;
    }
    $assert((int)$deeDays[$d1]['unrouted_stops'] === 1 && (string)$deeDays[$d1]['state'] === 'not_out', 'a standing stop whose order got no driver leaves that driver day not out');
    if ($pauseReady) {
        $assert((int)$deeDays[$d1]['paused_stops'] === 1 && (int)$deeDays[$d2]['paused_stops'] === 1, 'the paused standing stop is set apart on each day');
    }

    $assert((int)$adaDays[$d1]['kept_stops'] === 5 && (int)$adaDays[$d1]['covered_stops'] === 1 && (int)$adaDays[$d1]['unrouted_stops'] === 1, 'Ada ran five standing stops, another driver ran one, and one was not routed');
    $assert((string)$adaDays[$d1]['state'] === 'problem', 'a day with an open stop is a problem day');
    $assert((int)$cal['covering_stops'] === 2 && (int)$ada['covered_stops'] === 2, 'covering for another route is credited to the driver who went');
    $assert((int)$ada['due'] === 14 && $ada['delivered_rate'] === 57, 'the delivered rate leaves the cancelled stops out');
    $assert($ada['after_rate'] === 100, 'the proof photo rate is out of delivered stops');
    $assert((int)$ada['unclocked_days'] === 4, 'days driven without a punch are counted');
    $assert($ada['usual_finish'] === '09:32', 'the usual finish is the middle of the last closes');
    $adaStrip = bakery_delivery_manager_review_driver_strip($ada, [$d1, $d2]);
    $assert(array_column($adaStrip, 'state') === ['problem', 'problem'] && array_column($adaStrip, 'stops') === [10, 3], 'the driver strip carries each day state and stop count');

    $assert((int)($onRow['driver_mix'][0]['driver_id'] ?? 0) === $adaId && (int)($onRow['driver_mix'][0]['stops'] ?? 0) === 2, 'the store names who went and how often');
    $assert($onRow['actual_days'] === [(int)$weekday => 2] && $onRow['usual_close'] === '09:00', 'the store shows which weekday it was delivered and its usual close time');
    $assert(array_column(bakery_delivery_manager_review_customer_strip($onRow, [$d1, $d2]), 'state') === ['clean', 'clean'], 'two proven deliveries are two clean days');
    $assert(array_column(bakery_delivery_manager_review_customer_strip($noDriverRow, [$d1, $d2]), 'state') === ['problem', 'not_out'], 'the no driver day stands out in the strip');

    $dayTotals = [];
    foreach ($loaded['days'] as $row) {
        $dayTotals[(string)$row['date']] = $row;
    }
    $assert(count($loaded['days']) === count($loaded['dates']) && isset($dayTotals[$d1], $dayTotals[$d2]), 'every date in the range has a row');
    $first = $dayTotals[$d1];
    $assert((int)$first['stops'] === 11 && (int)$first['delivered'] === 8 && (int)$first['with_after'] === 8, 'the day row adds up every driver');
    $assert((int)$first['open'] === 1 && (int)$first['failed'] === 1 && (int)$first['cancelled'] === 1, 'the day row keeps open, failed, and cancelled apart');
    $assert((int)$first['drivers_out'] === 2 && (int)$first['unassigned'] === 1, 'the day row counts drivers out and orders with no driver');

    $loadedSummary = bakery_delivery_manager_review_summary($loaded, '2099-12-01');
    $assert($loadedSummary['problem'] === 6 && $loadedSummary['stale_open'] === 5 && $loadedSummary['unassigned'] === 1, 'the headline counts open, failed, and no driver');
    $loadedKeys = array_column(bakery_delivery_manager_review_insights($loaded, '2099-12-01'), 'key');
    $assert(!in_array('delivery_review.insight_missing_after', $loadedKeys, true), 'what stands out has no missing-photo line when every delivered stop has a photo');
    foreach (['insight_stale_open', 'insight_failed', 'insight_unassigned', 'insight_repeat', 'insight_bulk'] as $expectedKey) {
        $assert(in_array('delivery_review.' . $expectedKey, $loadedKeys, true), 'what stands out includes ' . $expectedKey);
    }
} finally {
    if ($created['customers'] && table_exists($db, 'standing_order_pauses')) {
        $ph = implode(',', array_fill(0, count($created['customers']), '?'));
        $db->prepare("DELETE FROM standing_order_pauses WHERE customer_id IN ($ph)")->execute($created['customers']);
    }
    if ($created['customers'] && table_exists($db, 'customer_delivery_skips')) {
        $ph = implode(',', array_fill(0, count($created['customers']), '?'));
        $db->prepare("DELETE FROM customer_delivery_skips WHERE customer_id IN ($ph)")->execute($created['customers']);
    }
    if ($created['orders'] && table_exists($db, 'daily_order_items')) {
        $ph = implode(',', array_fill(0, count($created['orders']), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($ph)")->execute($created['orders']);
    }
    if ($created['loads'] && table_exists($db, 'driver_loads')) {
        $ph = implode(',', array_fill(0, count($created['loads']), '?'));
        $db->prepare("DELETE FROM driver_loads WHERE id IN ($ph)")->execute($created['loads']);
    }
    if ($created['users'] && table_exists($db, 'time_clock_punches')) {
        $ph = implode(',', array_fill(0, count($created['users']), '?'));
        $db->prepare("DELETE FROM time_clock_punches WHERE user_id IN ($ph)")->execute($created['users']);
    }
    if ($created['users']) {
        $ph = implode(',', array_fill(0, count($created['users']), '?'));
        $db->prepare("DELETE FROM users WHERE id IN ($ph)")->execute($created['users']);
    }
    if ($created['photos']) {
        $ph = implode(',', array_fill(0, count($created['photos']), '?'));
        $db->prepare("DELETE FROM driver_photos WHERE id IN ($ph)")->execute($created['photos']);
    }
    if ($created['orders']) {
        $ph = implode(',', array_fill(0, count($created['orders']), '?'));
        $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($ph)")->execute($created['orders']);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($ph)")->execute($created['orders']);
    }
    if ($created['customers']) {
        $ph = implode(',', array_fill(0, count($created['customers']), '?'));
        $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($created['customers']);
        $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($created['customers']);
    }
    if ($created['drivers']) {
        $ph = implode(',', array_fill(0, count($created['drivers']), '?'));
        $db->prepare("DELETE FROM drivers WHERE id IN ($ph)")->execute($created['drivers']);
    }
}

echo "\n" . ($fail === 0 ? "ALL PASS\n" : ($fail . " FAILED\n"));
exit($fail === 0 ? 0 : 1);
