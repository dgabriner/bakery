<?php
/**
 * Account health: lapse counts dated orders and photo-confirmed deliveries,
 * 8-week vs prior 8-week units, CSV auth, and read-only GET.
 *
 * bakerysf_test only. Never Live or Staging.
 * Usage: php tests/run_account_health_tests.php
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
require_once $root . '/includes/billing_aging.php';
require_once $root . '/includes/account_health.php';
require_once $root . '/includes/navigation_catalog.php';
require_once $root . '/includes/agent_work_map.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: account health tests must run with APP_ENV=local\n");
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
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};

$asOf = '2026-10-07';
$thresholds = bakery_account_health_thresholds();
$assert($thresholds['recent_days'] === 56, 'recent window is 8 weeks (56 days)');
$assert($thresholds['prior_days'] === 56, 'prior window is 8 weeks (56 days)');
$assert($thresholds['share_days'] === 28, 'delivered-share window is 4 weeks (28 days)');
$assert($thresholds['lapse_days'] === 56, 'lapse lookback is 56 days');
$assert((float)$thresholds['shrink_percent'] === 25.0, 'shrinking threshold is a 25% drop');
$assert((int)$thresholds['shrink_min_prior_units'] === 8, 'shrinking ignores prior windows under 8 units');
$assert(
    BAKERY_ACCOUNT_HEALTH_RECENT_DAYS === 56
        && BAKERY_ACCOUNT_HEALTH_PRIOR_DAYS === 56
        && BAKERY_ACCOUNT_HEALTH_SHARE_DAYS === 28
        && BAKERY_ACCOUNT_HEALTH_LAPSE_DAYS === 56
        && (float)BAKERY_ACCOUNT_HEALTH_SHRINK_PERCENT === 25.0
        && BAKERY_ACCOUNT_HEALTH_SHRINK_MIN_PRIOR_UNITS === 8,
    'thresholds live in one constant block'
);

$healthSource = (string)file_get_contents($root . '/includes/account_health.php');
$assert(strpos($healthSource, 'Standing quantity is never an input') !== false, 'lapse note documents that standing quantity is ignored');
$assert(stripos($healthSource, 'standing_orders') === false, 'health math does not read standing_orders');
$assert(
    !preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/', $healthSource),
    'account health include is read-only SQL'
);

$windows = bakery_account_health_windows($asOf);
$assert($windows['recent_start'] === '2026-08-13' && $windows['recent_end'] === '2026-10-07', 'recent 8 weeks ends on the as-of date');
$assert($windows['prior_start'] === '2026-06-18' && $windows['prior_end'] === '2026-08-12', 'prior 8 weeks sit immediately before the recent window');
$assert($windows['share_start'] === '2026-09-10' && $windows['share_end'] === '2026-10-07', 'share window is the last 4 weeks');
$assert($windows['lapse_start'] === '2026-08-13', 'lapse window matches the recent 8 weeks');

$assert(bakery_account_health_change_percent(50, 100) === -50.0, '8v8 percent change is (recent-prior)/prior');
$assert(bakery_account_health_change_percent(12, 0) === null, 'no prior units means no percent');
$assert(bakery_account_health_delivered_share(18, 30) === 60.0, '4-week share is delivered/ordered');
$assert(bakery_account_health_delivered_share(0, 0) === null, 'share is empty when nothing was ordered');

$assert(
    bakery_account_health_status([
        'dated_orders_in_lapse' => 1,
        'delivery_evidence_in_lapse' => 1,
        'recent_delivered_units' => 12,
        'prior_delivered_units' => 0,
        'standing_qty' => 0,
    ]) === 'ok',
    'dated order plus photo evidence is not lapsed when standing qty is 0'
);
$assert(
    bakery_account_health_status([
        'dated_orders_in_lapse' => 0,
        'delivery_evidence_in_lapse' => 0,
        'recent_delivered_units' => 0,
        'prior_delivered_units' => 0,
        'standing_qty' => 40,
    ]) === 'lapsed',
    'standing quantity alone does not keep an account out of lapsed'
);
$assert(
    bakery_account_health_status([
        'dated_orders_in_lapse' => 1,
        'delivery_evidence_in_lapse' => 1,
        'recent_delivered_units' => 50,
        'prior_delivered_units' => 100,
    ]) === 'shrinking',
    'a 50% drop versus the prior 8 weeks is shrinking'
);
$assert(
    bakery_account_health_status([
        'dated_orders_in_lapse' => 1,
        'delivery_evidence_in_lapse' => 1,
        'recent_delivered_units' => 76,
        'prior_delivered_units' => 100,
    ]) === 'ok',
    'a drop under 25% stays OK'
);
$assert(
    bakery_account_health_status([
        'dated_orders_in_lapse' => 1,
        'delivery_evidence_in_lapse' => 1,
        'recent_delivered_units' => 75,
        'prior_delivered_units' => 100,
    ]) === 'shrinking',
    'a drop of exactly 25% is shrinking'
);
$assert(
    bakery_account_health_status([
        'dated_orders_in_lapse' => 1,
        'delivery_evidence_in_lapse' => 0,
        'recent_delivered_units' => 0,
        'prior_delivered_units' => 7,
    ]) === 'ok',
    'prior volume under 8 units does not flag shrinking'
);

// ── Page contracts: manager/owner, GET only, no new nav module ───────────────
$page = (string)file_get_contents($root . '/account_health.php');
$rolePos = strpos($page, "bakery_require_role(['administrator', 'manager'])");
$methodPos = strpos($page, '405');
$rowsPos = strpos($page, 'bakery_account_health_rows(');
$assert($rolePos !== false && $methodPos !== false && $rowsPos !== false, 'page gates role, method, then reads rows');
$assert($rolePos < $methodPos && $methodPos < $rowsPos, 'role and GET checks run before any health read');
$assert(strpos($page, '$_POST') === false, 'page does not read POST');
$assert(!preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/', $page), 'page file has no write SQL');
$assert(strpos($page, "export'] ?? '') === 'csv'") !== false || strpos($page, "export'] ?? \"\") === 'csv'") !== false, 'CSV export is a GET query on the same page');

$roles = bakery_navigation_roles_for_script('account_health.php');
$assert(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'owner and manager may open account health');
$assert(!in_array('driver', $roles, true) && !in_array('baker', $roles, true) && !in_array('cashier', $roles, true), 'driver, baker, and cashier are refused');
$catalog = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$assert(strpos($catalog, "'href' => 'account_health.php'") === false, 'account health is not a new navigation module');
$assert(strpos($catalog, "'account_health.php'") !== false, 'script registry allowlists account health for the auth gate');

$customersPage = (string)file_get_contents($root . '/customers.php');
$hubPage = (string)file_get_contents($root . '/customer_record.php');
$assert(strpos($customersPage, 'account_health.php') !== false, 'customer list links to account health');
$assert(substr_count($hubPage, 'account_health.php') === 1, 'Customer Hub landing adds a single link');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$assert(strpos($manifest, "'account_health.php'") !== false, 'deploy manifest lists account_health.php');

$map = bakery_agent_work_map();
$assert(isset($map['account-health']), 'work map registers account-health');
$assert(
    in_array('tests/run_account_health_tests.php', $map['account-health']['tests'], true),
    'work map points at this suite'
);

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$enKeys = array_keys($en);
$esKeys = array_keys($es);
$enHealth = array_values(array_filter($enKeys, static fn(string $key): bool => strpos($key, 'account_health.') === 0));
$esHealth = array_values(array_filter($esKeys, static fn(string $key): bool => strpos($key, 'account_health.') === 0));
$assert($enHealth !== [] && $enHealth === $esHealth, 'account_health keys match in English and Spanish');
$enTail = array_slice($enKeys, (int)array_search($enHealth[0], $enKeys, true));
$esTail = array_slice($esKeys, (int)array_search($esHealth[0], $esKeys, true));
$tailOk = static function (array $tail): bool {
    foreach ($tail as $key) {
        if (strpos((string)$key, 'account_health.') !== 0) {
            return false;
        }
    }
    return $tail !== [];
};
$assert($tailOk($enTail) && $tailOk($esTail), 'account_health keys are one contiguous block at the end of each catalog');

// ── Fixture rows on bakerysf_test ────────────────────────────────────────────
$tag = 'AH' . substr(bin2hex(random_bytes(4)), 0, 8);
$customerIds = [];
$orderIds = [];
$productId = 0;
$driverId = 0;
$customersBefore = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$ordersBefore = (int)$db->query('SELECT COUNT(*) FROM daily_orders')->fetchColumn();

$insertCustomer = static function (PDO $db, string $name, int $active, ?string $origin) use (&$customerIds): int {
    if ($origin !== null && column_exists($db, 'customers', 'sfb_origin')) {
        $db->prepare('INSERT INTO customers (name, zone, is_active, sfb_origin) VALUES (?, ?, ?, ?)')
            ->execute([$name, 'Account Health', $active, $origin]);
    } else {
        $db->prepare('INSERT INTO customers (name, zone, is_active) VALUES (?, ?, ?)')
            ->execute([$name, 'Account Health', $active]);
    }
    $id = (int)$db->lastInsertId();
    $customerIds[] = $id;
    return $id;
};
$insertOrder = static function (PDO $db, int $customerId, string $date, string $status, ?string $confirmedAt, ?float $total) use (&$orderIds): int {
    $cols = 'customer_id, order_date, status, total_amount';
    $marks = '?, ?, ?, ?';
    $params = [$customerId, $date, $status, $total ?? 0];
    if ($confirmedAt !== null && column_exists($db, 'daily_orders', 'delivery_confirmed_at')) {
        $cols .= ', delivery_confirmed_at';
        $marks .= ', ?';
        $params[] = $confirmedAt;
    }
    if ($total !== null && column_exists($db, 'daily_orders', 'delivery_order_total')) {
        $cols .= ', delivery_order_total, delivery_pricing_label';
        $marks .= ', ?, ?';
        $params[] = $total;
        $params[] = 'account health test';
    }
    if ($total !== null && column_exists($db, 'daily_orders', 'amount_collected')) {
        $cols .= ', amount_collected';
        $marks .= ', ?';
        $params[] = 0;
    }
    $db->prepare("INSERT INTO daily_orders ($cols) VALUES ($marks)")->execute($params);
    $id = (int)$db->lastInsertId();
    $orderIds[] = $id;
    return $id;
};
$insertItem = static function (PDO $db, int $orderId, int $productId, int $qty, ?int $delivered): void {
    if ($delivered === null) {
        $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 1, ?)')
            ->execute([$orderId, $productId, $qty, $qty]);
        return;
    }
    $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([$orderId, $productId, $qty, $delivered, $delivered]);
};

try {
    $db->prepare('INSERT INTO products (name, price) VALUES (?, 1.00)')->execute([$tag . ' loaf']);
    $productId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO drivers (name) VALUES (?)')->execute([$tag . ' driver']);
    $driverId = (int)$db->lastInsertId();

    $photoOnly = $insertCustomer($db, $tag . ' photo', 1, 'human');
    $standingOnly = $insertCustomer($db, $tag . ' standing', 1, 'human');
    $compare = $insertCustomer($db, $tag . ' compare', 1, 'human');
    $share = $insertCustomer($db, $tag . ' share', 1, 'human');
    $balanceCustomer = $insertCustomer($db, $tag . ' balance', 1, 'human');
    $inactive = $insertCustomer($db, $tag . ' inactive', 0, 'human');
    $synthetic = 0;
    if (column_exists($db, 'customers', 'sfb_origin')) {
        $synthetic = $insertCustomer($db, $tag . ' synthetic', 1, 'synthetic');
    }

    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, 1, 24)')
        ->execute([$standingOnly, $productId]);

    $incidentToday = $insertOrder($db, $photoOnly, '2026-10-07', 'pending', null, null);
    $insertItem($db, $incidentToday, $productId, 12, null);
    $incidentStale = $insertOrder($db, $photoOnly, '2026-10-06', 'pending', null, null);
    $insertItem($db, $incidentStale, $productId, 4, null);
    $insertOrder($db, $photoOnly, '2026-10-08', 'pending', null, null);
    $db->prepare(
        'INSERT INTO driver_photos (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type)
         VALUES (?, ?, ?, ?, ?, ?, 12, ?)'
    )->execute([
        $driverId,
        $photoOnly,
        '2026-10-07',
        $tag . '.jpg',
        $tag . '.jpg',
        'storage/' . $tag . '.jpg',
        'image/jpeg',
    ]);

    $priorStart = $insertOrder($db, $compare, '2026-06-18', 'delivered', '2026-06-18 09:00:00', 40);
    $insertItem($db, $priorStart, $productId, 40, 40);
    $priorEnd = $insertOrder($db, $compare, '2026-08-12', 'delivered', '2026-08-12 09:00:00', 60);
    $insertItem($db, $priorEnd, $productId, 60, 60);
    $recentStart = $insertOrder($db, $compare, '2026-08-13', 'delivered', '2026-08-13 09:00:00', 30);
    $insertItem($db, $recentStart, $productId, 30, 30);
    $recentEnd = $insertOrder($db, $compare, '2026-10-07', 'invoiced', '2026-10-07 09:00:00', 20);
    $insertItem($db, $recentEnd, $productId, 20, 20);

    $shareOld = $insertOrder($db, $share, '2026-09-10', 'delivered', '2026-09-10 09:00:00', 10);
    $insertItem($db, $shareOld, $productId, 10, 8);
    $shareNew = $insertOrder($db, $share, '2026-10-07', 'delivered', '2026-10-07 09:00:00', 20);
    $insertItem($db, $shareNew, $productId, 20, 10);

    $balanceOrder = $insertOrder($db, $balanceCustomer, '2026-09-27', 'delivered', '2026-09-27 09:00:00', 80);
    $insertItem($db, $balanceOrder, $productId, 8, 8);

    $inactiveOrder = $insertOrder($db, $inactive, '2026-10-07', 'delivered', '2026-10-07 09:00:00', 15);
    $insertItem($db, $inactiveOrder, $productId, 15, 15);
    if ($synthetic > 0) {
        $syntheticOrder = $insertOrder($db, $synthetic, '2026-10-07', 'delivered', '2026-10-07 09:00:00', 9);
        $insertItem($db, $syntheticOrder, $productId, 9, 9);
    }

    $rows = bakery_account_health_rows($db, $asOf);
    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row['customer_id']] = $row;
    }

    $assert(isset($byId[$photoOnly]), 'photo-confirmed dated account is listed');
    $photoRow = $byId[$photoOnly] ?? [];
    $assert(($photoRow['status'] ?? '') === 'ok', '2026-10-07 photo-confirmed pending delivery is not lapsed');
    $assert((int)($photoRow['recent_delivered_units'] ?? -1) === 12, 'photo-confirmed units count even when closeout is still pending');
    $assert((int)($photoRow['prior_delivered_units'] ?? -1) === 0, 'incident account has no prior-window deliveries');
    $assert(($photoRow['last_delivered_date'] ?? '') === '2026-10-07', 'last delivered date follows the photo-confirmed stop');
    $assert((int)($photoRow['stale_pending_count'] ?? -1) === 1, 'only the past-dated pending order is stale');
    $assert((int)($photoRow['dated_orders_in_lapse'] ?? 0) >= 1, 'dated orders in the lapse window are counted');

    $assert(isset($byId[$standingOnly]) && ($byId[$standingOnly]['status'] ?? '') === 'lapsed', 'standing qty with no dated order or delivery is lapsed');
    $assert((int)($byId[$standingOnly]['recent_delivered_units'] ?? -1) === 0, 'lapsed standing account has zero recent units');

    $compareRow = $byId[$compare] ?? [];
    $assert((int)($compareRow['recent_delivered_units'] ?? -1) === 50, 'recent 8 weeks sums 30 on Aug 13 and 20 on Oct 7');
    $assert((int)($compareRow['prior_delivered_units'] ?? -1) === 100, 'prior 8 weeks sums 40 on Jun 18 and 60 on Aug 12');
    $assert(($compareRow['change_percent'] ?? null) === -50.0, 'compare account percent change is -50');
    $assert(($compareRow['status'] ?? '') === 'shrinking', '50% drop is shrinking');

    $shareRow = $byId[$share] ?? [];
    $assert((int)($shareRow['share_ordered_units'] ?? -1) === 30, '4-week ordered units are 10 + 20');
    $assert((int)($shareRow['share_delivered_units'] ?? -1) === 18, '4-week delivered units are 8 + 10');
    $assert(($shareRow['delivered_share_percent'] ?? null) === 60.0, '4-week delivered share is 60%');

    $balanceExpected = bakery_billing_customer_balance($db, $balanceCustomer);
    $balanceRow = $byId[$balanceCustomer] ?? [];
    $assert(abs((float)($balanceRow['balance'] ?? -1) - (float)$balanceExpected['outstanding_total']) < 0.001, 'balance reuses the Customer Hub aging helper');
    $assert((float)$balanceExpected['outstanding_total'] > 0, 'balance fixture has an outstanding confirmed delivery');

    $assert(!isset($byId[$inactive]), 'inactive customers are omitted');
    if ($synthetic > 0) {
        $assert(!isset($byId[$synthetic]), 'synthetic bakers are omitted from wholesale account health');
    }

    $customersAfter = (int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn();
    $ordersAfter = (int)$db->query('SELECT COUNT(*) FROM daily_orders')->fetchColumn();
    $assert($customersAfter === $customersBefore + count($customerIds), 'reading account health does not insert customers');
    $assert($ordersAfter === $ordersBefore + count($orderIds), 'reading account health does not insert orders');

    $lapsed = bakery_account_health_filter($rows, 'lapsed');
    $lapsedIds = array_map(static fn(array $row): int => (int)$row['customer_id'], $lapsed);
    $assert(in_array($standingOnly, $lapsedIds, true) && !in_array($photoOnly, $lapsedIds, true), 'status filter keeps lapsed rows only');

    $sorted = bakery_account_health_sort($byId, 'recent', 'desc');
    $assert((int)$sorted[0]['recent_delivered_units'] >= (int)$sorted[count($sorted) - 1]['recent_delivered_units'], 'recent units sort descending');

    $GLOBALS['bakery_i18n_catalog'] = null;
    bakery_set_locale('en', false);
    $csv = bakery_account_health_csv($lapsed);
    $lines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    $header = str_getcsv((string)array_shift($lines));
    $assert($header === bakery_account_health_csv_headers(), 'CSV headers match the translated column set');
    $sawStanding = false;
    $sawPhoto = false;
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $fields = str_getcsv($line);
        $assert(count($fields) === count($header), 'CSV row width matches the header');
        if ((int)($fields[0] ?? 0) === $standingOnly) {
            $sawStanding = true;
            $assert(($fields[count($fields) - 1] ?? '') === 'lapsed', 'CSV status is the stable code');
        }
        if ((int)($fields[0] ?? 0) === $photoOnly) {
            $sawPhoto = true;
        }
    }
    $assert($sawStanding && !$sawPhoto, 'CSV export is the same filtered rows');
    $ordersAfterCsv = (int)$db->query('SELECT COUNT(*) FROM daily_orders')->fetchColumn();
    $assert($ordersAfterCsv === $ordersAfter, 'building the CSV does not write');

    $html = bakery_account_health_render($rows, $rows, [
        'status' => 'all',
        'sort' => 'status',
        'dir' => 'asc',
    ]);
    $assert(strpos($html, 'account_health.php?') !== false && strpos($html, 'export=csv') !== false, 'view links to the CSV export');
    $assert(strpos($html, 'status=lapsed') !== false, 'view can filter by lapsed');
    $assert(strpos($html, htmlspecialchars(bakery_t('account_health.status_lapsed'), ENT_QUOTES, 'UTF-8')) !== false, 'view shows the lapsed label');
    $assert(strpos($html, htmlspecialchars(bakery_t('account_health.col_recent'), ENT_QUOTES, 'UTF-8')) !== false, 'view shows the 8-week column');
    $assert(strpos($html, 'sort=recent') !== false, 'column headers are sortable links');
    $assert(strpos($html, (string)$photoOnly) !== false, 'rendered table includes the photo-confirmed account');
} finally {
    if ($orderIds) {
        $ph = implode(',', array_fill(0, count($orderIds), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($ph)")->execute($orderIds);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($ph)")->execute($orderIds);
    }
    if ($customerIds) {
        $ph = implode(',', array_fill(0, count($customerIds), '?'));
        $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($customerIds);
        $db->prepare("DELETE FROM driver_photos WHERE customer_id IN ($ph)")->execute($customerIds);
        $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
    }
    if ($productId > 0) {
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    }
    if ($driverId > 0) {
        $db->prepare('DELETE FROM drivers WHERE id = ?')->execute([$driverId]);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
