<?php
/**
 * Driver Manager stop-reliability flags.
 *
 * Usage: php tests/run_stop_reliability_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/stop_reliability.php';
require_once $root . '/includes/schema_migration_numbers.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== Pure rules ===\n";
$assert(BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER >= 3 && BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER <= 5, 'collapse threshold is 3 to 5');
$assert(BAKERY_STOP_RELIABILITY_MISS_MIN === 2, 'a flag needs 2 consecutive misses');
$assert(bakery_stop_reliability_can_view(['role_slug' => 'manager']) === true, 'managers see the panel');
$assert(bakery_stop_reliability_can_view(['role_slug' => 'administrator']) === true, 'administrators see the panel');
$assert(bakery_stop_reliability_can_view(['role_slug' => 'driver']) === false, 'drivers do not see the panel');
$assert(bakery_stop_reliability_can_view(['role_slug' => 'driver_assistant']) === false, 'driver assistants do not see the panel');

$pending = static function (string $date, array $extra = []): array {
    return array_merge([
        'date' => $date,
        'on_route' => true,
        'delivery_status' => 'pending',
        'order_status' => 'pending',
        'delivery_confirmed_at' => null,
        'actual_delivery_time' => null,
        'notes' => '',
        'skipped' => false,
        'photos' => [],
    ], $extra);
};
$afterPhoto = static function (string $date): array {
    return [[
        'photo_type' => 'Before',
        'filename' => 'driver1_customer1_After_' . str_replace('-', '', $date) . '_111000_bbbbbbbb.jpg',
        'file_path' => 'driver1_customer1_After_' . str_replace('-', '', $date) . '_111000_bbbbbbbb.jpg',
    ]];
};

$today = '2026-11-22';
$one = bakery_stop_reliability_open_streak([$pending('2026-11-17')], $today);
$assert($one['count'] === 1, 'a single miss does not reach the flag count');

$skip = bakery_stop_reliability_open_streak([
    $pending('2026-11-17'),
    $pending('2026-11-18', ['delivery_status' => 'cancelled', 'notes' => 'Skipped: closed']),
    $pending('2026-11-19'),
], $today);
$assert($skip['count'] === 1, 'a skipped stop breaks the miss streak');

$note = bakery_stop_reliability_open_streak([
    $pending('2026-11-17'),
    $pending('2026-11-18', ['notes' => 'Driver called the store']),
    $pending('2026-11-19'),
], $today);
$assert($note['count'] === 1, 'a driver note breaks the miss streak');

$cleared = bakery_stop_reliability_open_streak([
    $pending('2026-10-20'),
    $pending('2026-10-22'),
    $pending('2026-10-24'),
    $pending('2026-10-26', ['photos' => $afterPhoto('2026-10-26'), 'delivery_status' => 'delivered']),
    $pending('2026-11-17'),
], $today);
$assert($cleared['count'] === 1, 'an After-photo delivery clears the earlier streak');
$assert($cleared['last_real'] === '2026-10-26', 'the last real delivery is the After photo');

$yellow = bakery_stop_reliability_open_streak([
    $pending('2026-10-20'),
    $pending('2026-10-22'),
    $pending('2026-10-24'),
    $pending('2026-10-26', ['photos' => $afterPhoto('2026-10-26')]),
    $pending('2026-11-17'),
    $pending('2026-11-18'),
    $pending('2026-11-19'),
    $pending('2026-11-20'),
    $pending('2026-11-21'),
    $pending('2026-11-24'),
    $pending('2026-11-26'),
], $today);
$assert($yellow['dates'] === ['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21'], 'Yellowstone open misses are Nov 17 through 21');
$assert($yellow['last_real'] === '2026-10-26', 'Yellowstone last real delivery is Oct 26');
$assert(!in_array('2026-11-24', $yellow['dates'], true), 'a future pending stop is not a miss yet');

$off = bakery_stop_reliability_off_standing_dates([
    ['date' => '2026-11-17', 'pieces' => 187],
    ['date' => '2026-11-18', 'pieces' => 187],
    ['date' => '2026-11-19', 'pieces' => 187],
    ['date' => '2026-11-20', 'pieces' => 187],
    ['date' => '2026-11-24', 'pieces' => 187],
    ['date' => '2026-11-26', 'pieces' => 187],
], [2, 4, 6]);
$offDates = array_column($off, 'date');
$assert($offDates === ['2026-11-18', '2026-11-20'], 'Wed and Fri flag for a Tue/Thu/Sat standing order');
$standingDay = bakery_stop_reliability_off_standing_dates([
    ['date' => '2026-11-18', 'pieces' => 187],
], [3]);
$assert($standingDay === [], 'a customer with a standing order that day does not flag');

$rows = [
    ['store' => 'Older', 'consecutive' => 2, 'recency' => '2026-10-08'],
    ['store' => 'Yellowstone Market', 'consecutive' => 5, 'recency' => '2026-11-21'],
    ['store' => 'Middle', 'consecutive' => 3, 'recency' => '2026-11-01'],
    ['store' => 'Recent pair', 'consecutive' => 2, 'recency' => '2026-11-10'],
    ['store' => 'Early pair', 'consecutive' => 2, 'recency' => '2026-10-03'],
    ['store' => 'Four', 'consecutive' => 4, 'recency' => '2026-11-12'],
];
$sorted = bakery_stop_reliability_sort($rows);
$assert(array_column($sorted, 'store') === [
    'Yellowstone Market',
    'Four',
    'Middle',
    'Recent pair',
    'Older',
    'Early pair',
], 'severity sorts by consecutive misses, then recency');
$collapsed = bakery_stop_reliability_collapse($sorted);
$assert(count($collapsed['visible']) === BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER, 'the top rows stay visible');
$assert(count($collapsed['hidden']) === count($sorted) - BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER, 'the rest collapse behind show more');

$sample = ['fingerprint' => 'abc', 'customer_id' => 1];
$assert(bakery_stop_reliability_action_hides($sample, [
    'action' => 'snooze',
    'snooze_until' => '2026-11-25',
    'flag_fingerprint' => 'abc',
], '2026-11-22') === true, 'snooze hides before the until date');
$assert(bakery_stop_reliability_action_hides($sample, [
    'action' => 'snooze',
    'snooze_until' => '2026-11-25',
    'flag_fingerprint' => 'abc',
], '2026-11-25') === false, 'snooze returns on the until date');
$assert(bakery_stop_reliability_action_hides($sample, [
    'action' => 'acknowledge',
    'flag_fingerprint' => 'abc',
], $today) === true, 'acknowledge hides the same flag');
$assert(bakery_stop_reliability_action_hides($sample, [
    'action' => 'acknowledge',
    'flag_fingerprint' => 'changed',
], $today) === false, 'a new streak shows again after acknowledge');

echo "\n=== Pages and migration ===\n";
foreach (['route_manager.php', 'route_health.php', 'driver_assignment.php', 'manager.php'] as $page) {
    $src = (string)file_get_contents($root . '/' . $page);
    $assert(strpos($src, 'bakery_stop_reliability_handle_post') !== false, $page . ' accepts acknowledge and snooze');
}
$routes = (string)file_get_contents($root . '/includes/manager_phone.php');
$assert(strpos($routes, 'bakery_stop_reliability_render') !== false, 'phone routes view shows the panel');
$schema = (string)file_get_contents($root . '/database/schema/118_stop_reliability_actions.sql');
$assert(strpos($schema, 'CREATE TABLE IF NOT EXISTS stop_reliability_actions') !== false, 'migration 118 creates the log table');
$assert(bakery_schema_next_migration_number() === 119, '118 is used, so the next free number is 119');
$gitIds = include $root . '/includes/schema_git_ids.php';
$assert(in_array('118_stop_reliability_actions', $gitIds, true), '118 is in the shipped schema id list');
$assert(!is_file($root . '/database/schema/116_route_closeout_source.sql'), 'this branch does not take migration 116');
$assert(!is_file($root . '/database/schema/117_driver_photo_integrity.sql'), 'this branch does not take migration 117');

echo "\n=== Database load on bakerysf_test ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');
$sql = (string)file_get_contents($root . '/database/schema/118_stop_reliability_actions.sql');
$db->exec($sql);
$assert(table_exists($db, 'stop_reliability_actions'), 'log table is installed on bakerysf_test');

$prefix = 'StopFlag ' . bin2hex(random_bytes(3));
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$assert($productId > 0, 'a product exists for the fixture');
$created = ['customers' => [], 'drivers' => [], 'orders' => [], 'photos' => [], 'users' => []];
$manager = ['id' => 1, 'role_slug' => 'manager'];

$makeCustomer = static function (string $name, string $address) use ($db, &$created, $prefix): int {
    $stmt = $db->prepare("INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')");
    $stmt->execute([$prefix . ' ' . $name, $address]);
    $id = (int)$db->lastInsertId();
    $created['customers'][] = $id;
    return $id;
};

try {
    $yellowId = $makeCustomer('Yellowstone Market', "716 O'Farrell");
    $singleId = $makeCustomer('Single Miss', '1 Test St');
    $skipId = $makeCustomer('Skip Break', '2 Test St');
    $noteId = $makeCustomer('Note Break', '3 Test St');
    $clearId = $makeCustomer('After Clear', '4 Test St');
    $stoodId = $makeCustomer('Stood Tuesday', '5 Test St');
    $wedId = $makeCustomer('Stood Wednesday', '6 Test St');
    $extraIds = [];
    for ($i = 1; $i <= 5; $i++) {
        $extraIds[] = $makeCustomer('Extra ' . $i, $i . ' Extra St');
    }

    $insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
    $insertDriver->execute([$prefix . ' Marcos']);
    $marcos = (int)$db->lastInsertId();
    $created['drivers'][] = $marcos;

    $insertOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at) VALUES (?, ?, ?, 0, ?)'
    );
    $insertAssignment = $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, notes, actual_delivery_time)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, ?)'
    );
    $insertPhoto = $db->prepare(
        'INSERT INTO driver_photos (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
         VALUES (?, ?, ?, ?, ?, ?, 20, \'image/jpeg\', ?)'
    );
    $insertStanding = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    );
    foreach ([2, 4, 6] as $day) {
        $insertStanding->execute([$yellowId, $productId, $day, 187]);
    }
    $insertStanding->execute([$stoodId, $productId, 2, 40]);
    $insertStanding->execute([$wedId, $productId, 3, 40]);

    $routeSeq = [];
    $addStop = static function (
        int $customerId,
        string $date,
        string $status = 'pending',
        string $notes = '',
        int $pieces = 0,
        bool $after = false
    ) use (
        $db,
        $marcos,
        $productId,
        $insertOrder,
        $insertAssignment,
        $insertItem,
        $insertPhoto,
        &$created,
        &$routeSeq
    ): void {
        $confirmed = $after ? $date . ' 11:10:00' : null;
        $insertOrder->execute([$customerId, $date, $after ? 'delivered' : 'pending', $confirmed]);
        $orderId = (int)$db->lastInsertId();
        $created['orders'][] = $orderId;
        $routeSeq[$date] = ($routeSeq[$date] ?? 0) + 1;
        $insertAssignment->execute([
            $orderId,
            $marcos,
            $date,
            $routeSeq[$date],
            $status,
            $notes,
            $after ? '11:10:00' : null,
        ]);
        if ($pieces > 0) {
            $insertItem->execute([$orderId, $productId, $pieces, $pieces]);
        }
        if ($after) {
            $file = 'driver' . $marcos . '_customer' . $customerId . '_After_' . str_replace('-', '', $date) . '_111000_bbbbbbbb.jpg';
            $insertPhoto->execute([$marcos, $customerId, $date, $file, $file, $file, 'After']);
            $created['photos'][] = (int)$db->lastInsertId();
        }
    };

    foreach (['2026-10-20', '2026-10-22', '2026-10-24', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-24', '2026-11-26'] as $date) {
        $addStop($yellowId, $date, 'pending', '', 187, false);
    }
    $addStop($yellowId, '2026-10-26', 'delivered', '', 187, true);
    $addStop($singleId, '2026-10-06');
    $addStop($skipId, '2026-10-07');
    $addStop($skipId, '2026-10-08', 'cancelled', 'Skipped: closed');
    $addStop($skipId, '2026-10-09');
    $addStop($noteId, '2026-10-13');
    $addStop($noteId, '2026-10-14', 'pending', 'Driver called the store');
    $addStop($noteId, '2026-10-15');
    $addStop($clearId, '2026-10-27');
    $addStop($clearId, '2026-10-28');
    $addStop($clearId, '2026-10-29', 'delivered', '', 0, true);
    $addStop($stoodId, '2026-11-17', 'pending', '', 40);
    $addStop($wedId, '2026-11-11', 'pending', '', 40);
    $extraDates = [
        ['2026-10-01', '2026-10-02'],
        ['2026-10-05', '2026-10-06'],
        ['2026-10-08', '2026-10-09'],
        ['2026-10-12', '2026-10-13'],
        ['2026-10-15', '2026-10-16'],
    ];
    foreach ($extraIds as $index => $extraId) {
        $addStop($extraId, $extraDates[$index][0]);
        $addStop($extraId, $extraDates[$index][1]);
    }

    $names = [];
    $loaded = bakery_stop_reliability_load($db, $today);
    foreach ($loaded as $row) {
        $names[(int)$row['customer_id']] = $row;
    }
    $flag = $names[$yellowId] ?? null;
    $assert(is_array($flag), 'Yellowstone flags with 2 or more consecutive misses');
    if (is_array($flag)) {
        $assert($flag['miss_dates'] === ['2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21'], 'loaded miss dates are the open streak');
        $assert($flag['last_real'] === '2026-10-26', 'loaded last real delivery is the After photo');
        $assert($flag['driver'] === $prefix . ' Marcos', 'loaded driver is Marcos');
        $assert(strpos((string)$flag['address'], "716 O'Farrell") !== false, 'loaded address is 716 O\'Farrell');
        $loadedOff = array_column($flag['off_dates'], 'date');
        $assert(in_array('2026-11-18', $loadedOff, true) && in_array('2026-11-20', $loadedOff, true), 'Wed 11/18 and Fri 11/20 flag as off standing');
        $assert(!in_array('2026-11-17', $loadedOff, true), 'Tuesday standing does not flag as off standing');
        $assert(!in_array('2026-11-24', $loadedOff, true) && !in_array('2026-11-26', $loadedOff, true), 'queued Tue/Thu orders are standing days');
    }
    $assert(!isset($names[$singleId]), 'a single miss does not flag');
    $assert(!isset($names[$skipId]), 'a skipped stop keeps the store off the flag list');
    $assert(!isset($names[$noteId]), 'a driver note keeps the store off the flag list');
    $assert(!isset($names[$clearId]), 'a later After-photo delivery auto-clears the flag');
    $assert(!isset($names[$stoodId]), 'a standing-day order does not flag');
    $assert(!isset($names[$wedId]), 'Wednesday standing does not flag a Wednesday order');
    $fixtureIds = array_fill_keys(array_merge(
        [$yellowId, $singleId, $skipId, $noteId, $clearId, $stoodId, $wedId],
        $extraIds
    ), true);
    $fixtureRows = [];
    foreach ($loaded as $row) {
        if (isset($fixtureIds[(int)$row['customer_id']])) {
            $fixtureRows[] = $row;
        }
    }
    $assert(count($fixtureRows) === 6, 'Yellowstone plus five shorter streaks are the flag list');

    $sortedFixture = bakery_stop_reliability_sort($fixtureRows);
    $collapsed = bakery_stop_reliability_collapse($sortedFixture);
    $board = [
        'visible' => $collapsed['visible'],
        'hidden' => $collapsed['hidden'],
        'snoozed' => 0,
        'schema_ready' => true,
        'allowed' => true,
        'today' => $today,
    ];
    $assert(count($board['visible']) === BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER, 'the board collapses after the threshold');
    $assert(count($board['hidden']) === 2, 'two rows wait behind show more');
    $assert((string)($board['visible'][0]['store'] ?? '') === $prefix . ' Yellowstone Market', 'the longest streak sorts first');
    if (function_exists('bakery_set_locale')) {
        bakery_set_locale('en', false);
    }
    $html = bakery_stop_reliability_render_board($board, 'route_manager.php');
    $assert(strpos($html, 'Show 2 more') !== false, 'the panel offers show N more');
    $assert(strpos($html, 'data-collapse="' . BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER . '"') !== false, 'the panel records the collapse threshold');
    $assert(strpos($html, 'minlength="' . BAKERY_STOP_RELIABILITY_NOTE_MIN . '"') !== false, 'acknowledge and snooze require a note');
    $driverBoard = bakery_stop_reliability_board($db, $today, ['id' => 9, 'role_slug' => 'driver']);
    $assert($driverBoard['allowed'] === false && $driverBoard['visible'] === [], 'a driver board is empty');
    $assert(bakery_stop_reliability_render($db, 'route_manager.php', ['id' => 9, 'role_slug' => 'driver'], $today) === '', 'render hides the panel from a driver');

    $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'manager' LIMIT 1")->fetchColumn();
    $assert($roleId > 0, 'manager role exists on bakerysf_test');
    $email = 'stop-flag-' . bin2hex(random_bytes(3)) . '@example.test';
    $db->prepare('INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$email, 'x', '8' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT), $prefix . ' Manager', $roleId]);
    $userId = (int)$db->lastInsertId();
    $created['users'][] = $userId;

    $acked = bakery_stop_reliability_acknowledge($db, $yellowId, $userId, 'Saw the missed week', $today);
    $assert((int)$acked['user_id'] === $userId, 'acknowledge logs the user id');
    $assert(trim((string)$acked['created_at']) !== '' && (string)$acked['created_at'] !== '0000-00-00 00:00:00', 'acknowledge logs a timestamp');
    $assert((string)$acked['note'] === 'Saw the missed week', 'acknowledge stores the note');
    $afterAck = bakery_stop_reliability_board($db, $today, $manager);
    $ackIds = array_map(static function (array $row): int {
        return (int)$row['customer_id'];
    }, array_merge($afterAck['visible'], $afterAck['hidden']));
    $assert(!in_array($yellowId, $ackIds, true), 'acknowledge hides the current flag');

    $snoozed = bakery_stop_reliability_snooze($db, $yellowId, $userId, 'Check after the holiday', '2026-11-25', $today);
    $assert(substr((string)$snoozed['snooze_until'], 0, 10) === '2026-11-25', 'snooze stores the until date');
    $whileSnoozed = bakery_stop_reliability_board($db, '2026-11-22', $manager);
    $snoozeIds = array_map(static function (array $row): int {
        return (int)$row['customer_id'];
    }, array_merge($whileSnoozed['visible'], $whileSnoozed['hidden']));
    $assert(!in_array($yellowId, $snoozeIds, true), 'snooze hides the store before the until date');
    $returned = bakery_stop_reliability_board($db, '2026-11-25', $manager);
    $returnIds = array_map(static function (array $row): int {
        return (int)$row['customer_id'];
    }, array_merge($returned['visible'], $returned['hidden']));
    $assert(in_array($yellowId, $returnIds, true), 'the flag returns on the snooze date');

    $threw = false;
    try {
        bakery_stop_reliability_acknowledge($db, $yellowId, $userId, 'no', $today);
    } catch (InvalidArgumentException $e) {
        $threw = $e->getMessage() === 'note';
    }
    $assert($threw, 'a too-short note is refused');
} catch (Throwable $e) {
    echo 'FAIL  fixture: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    if ($created['photos'] !== []) {
        $db->exec('DELETE FROM driver_photos WHERE id IN (' . implode(',', array_map('intval', $created['photos'])) . ')');
    }
    if ($created['orders'] !== []) {
        $ids = implode(',', array_map('intval', $created['orders']));
        $db->exec('DELETE FROM daily_order_items WHERE daily_order_id IN (' . $ids . ')');
        $db->exec('DELETE FROM daily_order_assignments WHERE daily_order_id IN (' . $ids . ')');
        $db->exec('DELETE FROM daily_orders WHERE id IN (' . $ids . ')');
    }
    if ($created['customers'] !== []) {
        $ids = implode(',', array_map('intval', $created['customers']));
        if (table_exists($db, 'stop_reliability_actions')) {
            $db->exec('DELETE FROM stop_reliability_actions WHERE customer_id IN (' . $ids . ')');
        }
        $db->exec('DELETE FROM standing_orders WHERE customer_id IN (' . $ids . ')');
        $db->exec('DELETE FROM customers WHERE id IN (' . $ids . ')');
    }
    if ($created['drivers'] !== []) {
        $db->exec('DELETE FROM drivers WHERE id IN (' . implode(',', array_map('intval', $created['drivers'])) . ')');
    }
    if ($created['users'] !== []) {
        $db->exec('DELETE FROM users WHERE id IN (' . implode(',', array_map('intval', $created['users'])) . ')');
    }
}

echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED\n";
exit($fail > 0 ? 1 : 0);
