<?php
/**
 * Wave 0: synthetic bakers never enter wholesale ops; community exposes origin.
 *
 * Does not reset bakerysf_local. Creates fixture rows and deletes them.
 * A full isolate reset of bakerysf_test needs REFERENCES on that database.
 *
 * Usage: C:\php\php.exe tests/run_sfb_origin_tests.php
 */
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/sf_baker.php';
require_once dirname(__DIR__) . '/includes/sfb_agent.php';
require_once dirname(__DIR__) . '/includes/daily_order_generation.php';

$GLOBALS['db'] = $db;

$finish = function () {
    echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
    exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
};

assert_true(column_exists($db, 'customers', 'sfb_origin'), 'customers.sfb_origin exists');
assert_true(bakery_sfb_ops_origin_clause('c', $db) !== '', 'ops origin clause is active');
assert_true(in_array('failures', bakery_sfb_community_categories(), true), 'failures circle is in the category list');

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
assert_true($productId > 0, 'catalog has a product');

$date = '2099-12-31';
$dayOfWeek = bakery_standing_day_from_date($date);

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$syntheticName = 'SFB Origin Synthetic ' . $suffix;
$humanName = 'SFB Origin Human ' . $suffix;
$createdIds = [];
$topicId = 0;

try {
    bakery_sfb_agent_ensure_admin($db);
    bakery_sfb_agent_login($db);

    $created = bakery_sfb_agent_create_customer($db, $syntheticName, '');
    $synthetic = $created['customer'];
    $syntheticId = (int)$synthetic['id'];
    $createdIds[] = $syntheticId;
    assert_eq('synthetic', (string)$synthetic['sfb_origin'], 'agent-created baker is synthetic');
    assert_eq(false, bakery_sfb_ops_customer_allowed($db, $syntheticId), 'synthetic is blocked from ops');

    standing_save($db, $syntheticId, $productId, $dayOfWeek, 4);
    assert_eq(4, standing_qty($db, $syntheticId, $productId, $dayOfWeek), 'synthetic can still own a standing-order row');

    $driverId = 0;
    if (table_exists($db, 'drivers')) {
        $driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
    }
    if ($driverId > 0 && table_exists($db, 'standing_routes')) {
        $db->prepare(
            'INSERT INTO standing_routes (customer_id, driver_id, day_of_week) VALUES (?, ?, ?)'
        )->execute([$syntheticId, $driverId, $dayOfWeek]);
        $routeStmt = $db->prepare(
            'SELECT COUNT(*) FROM standing_routes sr
             JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
             ' . bakery_sfb_ops_origin_clause('c', $db) . '
             WHERE sr.customer_id = ?'
        );
        $routeStmt->execute([$syntheticId]);
        assert_eq(0, (int)$routeStmt->fetchColumn(), 'synthetic standing route is invisible to ops route queries');
    }

    bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => true,
        'record_event' => false,
        'assign_routes' => false,
    ]);

    $countStmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $countStmt->execute([$syntheticId, $date]);
    assert_eq(0, (int)$countStmt->fetchColumn(), 'synthetic standing order produces zero daily_orders');

    $humanSql = 'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active';
    $humanSql .= column_exists($db, 'customers', 'sfb_origin') ? ', sfb_origin' : '';
    $humanSql .= ') VALUES (?, ?, ?, 1, 0, 1';
    $humanSql .= column_exists($db, 'customers', 'sfb_origin') ? ', ?' : '';
    $humanSql .= ')';
    $humanParams = [$humanName, '555-0199', '2 Test Way'];
    if (column_exists($db, 'customers', 'sfb_origin')) {
        $humanParams[] = 'human';
    }
    $db->prepare($humanSql)->execute($humanParams);
    $humanId = (int)$db->lastInsertId();
    $createdIds[] = $humanId;
    assert_eq(true, bakery_sfb_ops_customer_allowed($db, $humanId), 'human is allowed in ops');

    standing_save($db, $humanId, $productId, $dayOfWeek, 2);
    bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => true,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $countStmt->execute([$humanId, $date]);
    assert_true((int)$countStmt->fetchColumn() > 0, 'human standing order still generates daily_orders');

    $asSynthetic = bakery_sfb_agent_login_as_customer($db, $syntheticName);
    $topicId = bakery_sfb_create_community_topic(
        $db,
        (int)$asSynthetic['id'],
        '78F bulk, no ear — origin test',
        'Bulk at 78F for 4 hours, 74% hydration. What would you change?',
        'fermentation'
    );
    assert_true($topicId > 0, 'synthetic can post in community');
    $topic = bakery_sfb_community_topic($db, $topicId);
    assert_true($topic !== null, 'community topic is visible');
    assert_eq('synthetic', bakery_sfb_normalize_origin($topic['sfb_origin'] ?? ''), 'community topic exposes synthetic origin');
    assert_true(bakery_sfb_is_synthetic($topic), 'badge helper treats topic author as synthetic');
    $badge = bakery_sfb_render_origin_badge($topic);
    assert_true(strpos($badge, 'sfb-origin-badge--synthetic') !== false, 'badge markup is labeled synthetic');

    $again = bakery_sfb_agent_create_customer($db, $humanName, '');
    assert_eq('human', bakery_sfb_normalize_origin($again['customer']['sfb_origin'] ?? ''), 'agent create does not retag an existing human');

    $live = $db->query(
        "SELECT name, sfb_origin FROM customers WHERE name IN ('Customer1','Customer2','65 Fairmount')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (isset($live['Customer1'])) {
        assert_eq('synthetic', (string)$live['Customer1'], 'Customer1 is tagged synthetic on this database');
    }
    if (isset($live['Customer2'])) {
        assert_eq('synthetic', (string)$live['Customer2'], 'Customer2 is tagged synthetic on this database');
    }
    if (isset($live['65 Fairmount'])) {
        assert_eq('human', (string)$live['65 Fairmount'], '65 Fairmount stays human');
    }
} finally {
    $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$date]);
    foreach ($createdIds as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$id]);
            if (table_exists($db, 'standing_routes')) {
                $db->prepare('DELETE FROM standing_routes WHERE customer_id = ?')->execute([$id]);
            }
            $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        }
    }
}

$finish();
