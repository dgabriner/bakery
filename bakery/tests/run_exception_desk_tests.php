<?php
/**
 * Mobile exception desk contracts.
 * Usage: php tests/run_exception_desk_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_email'] = 'desk@test';
$_SESSION['user_display_name'] = 'Desk';
$_SESSION['user_role_slug'] = 'manager';

require_once $root . '/includes/config.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/exception_desk.php';

$failed = 0;
$passed = 0;
function desk_assert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS  {$message}\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  {$message}\n");
    $failed++;
}

$managerPage = (string)file_get_contents($root . '/manager.php');
desk_assert(
    strpos($managerPage, 'bakery_exception_desk_render') !== false,
    'Manager Mode inserts the shared desk renderer'
);
desk_assert(
    strpos($managerPage, 'manager-desktop-only') !== false,
    'dense Manager Mode forms are marked desktop-only'
);
desk_assert(
    strpos($managerPage, 'Manager attention queue') !== false,
    'desktop attention queue remains in Manager Mode'
);
desk_assert(
    strpos($managerPage, 'Failed-stop recovery') !== false,
    'desktop failed-stop recovery remains in Manager Mode'
);
desk_assert(
    is_file($root . '/css/exception_desk.css'),
    'desk stylesheet exists'
);
$css = (string)file_get_contents($root . '/css/exception_desk.css');
desk_assert(
    strpos($css, '@media (max-width: 720px)') !== false,
    'desk CSS uses the existing 720px breakpoint'
);

$exception = bakery_ops_exception([
    'type' => 'demand_missing_daily',
    'severity' => 'critical',
    'category' => 'demand',
    'title' => 'Missing dated orders',
    'detail' => 'Two standing customers have no dated order.',
    'href' => '/daily_orders.php?date=2099-08-17&review=missing',
    'action' => 'Review orders',
    'work_key' => 'desk-test-key',
    'work' => null,
]);
$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'manager';

$html = bakery_exception_desk_manager_markup(null, '2099-08-17', [$exception], [
    'recovery_cases' => [],
    'untriaged' => [],
    'drivers' => [],
]);
desk_assert(strpos($html, 'exception-desk--manager') !== false, 'manager desk markup renders');
desk_assert(strpos($html, 'due_at') === false, 'desk HTML omits due_at');
desk_assert(stripos($html, 'due-at') === false, 'desk HTML omits due-at');
desk_assert(stripos($html, 'bulk') === false, 'desk HTML omits bulk controls');
desk_assert(
    strpos($html, 'name="acknowledge"') !== false && strpos($html, 'assigned_to_user_id') !== false,
    'desk offers Mine'
);
desk_assert(strpos($html, 'datetime-local') === false, 'default exception cards omit datetime pickers');

$driverHtml = bakery_exception_desk_driver_fail_form([
    'assignment_id' => 9,
    'daily_order_id' => 12,
    'customer_name' => 'Cafe Luna',
    'delivery_status' => 'pending',
]);
desk_assert(strpos($driverHtml, 'reason_code') !== false, 'driver fail form uses recovery reason chips');
desk_assert(strpos($driverHtml, 'billing_handoff') === false, 'driver form omits billing handoff');
desk_assert(strpos($driverHtml, 'to_driver_id') === false, 'driver form omits reassign');
desk_assert(strpos($driverHtml, 'recovery_action') === false, 'driver form omits manager recovery actions');
desk_assert(strpos($driverHtml, 'retry_at') === false, 'driver form omits retry');

$sorted = bakery_exception_desk_sort([
    ['severity' => 'warning', 'title' => 'B'],
    ['severity' => 'critical', 'title' => 'A'],
    ['severity' => 'info', 'title' => 'C'],
]);
desk_assert(($sorted[0]['severity'] ?? '') === 'critical', 'desk sorts critical before warning');

echo "\n=== Isolated write-path checks ===\n";
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/product_inventory.php';
/** @var PDO $db */
$db = check_mysql_connection();
bakery_assert_local_test_target($db);
if (function_exists('bakery_ensure_baker_user')) {
    bakery_ensure_baker_user($db);
}

$manager = $db->query(
    "SELECT u.id, u.email, u.display_name, r.slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE r.slug IN ('administrator', 'manager') AND u.is_active = 1
     ORDER BY r.slug = 'manager' DESC, u.id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
desk_assert(is_array($manager), 'test database has a manager user');

if (is_array($manager)) {
    $_SESSION['user_id'] = (int)$manager['id'];
    $_SESSION['user_email'] = (string)$manager['email'];
    $_SESSION['user_display_name'] = (string)$manager['display_name'];
    $_SESSION['user_role_slug'] = (string)$manager['slug'];
    $mineException = bakery_ops_exception([
        'type' => 'demand_missing_daily',
        'severity' => 'critical',
        'category' => 'demand',
        'title' => 'Missing dated orders',
        'detail' => 'Test missing demand.',
        'context' => ['customer_id' => 1],
    ]);
    bakery_manager_exception_save($db, $mineException, '2099-08-17', bakery_exception_desk_mine_input($mineException));
    $key = bakery_manager_exception_key($mineException, '2099-08-17');
    $work = $db->prepare('SELECT acknowledged_at, assigned_to_user_id FROM manager_exception_work WHERE exception_key = ?');
    $work->execute([$key]);
    $row = $work->fetch(PDO::FETCH_ASSOC);
    desk_assert(is_array($row) && !empty($row['acknowledged_at']), 'manager Mine acknowledges the work row');
    desk_assert(is_array($row) && (int)$row['assigned_to_user_id'] === (int)$manager['id'], 'manager Mine assigns to the current user');
}

$baker = $db->query(
    "SELECT u.id, u.email, u.display_name
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE r.slug = 'baker' AND u.is_active = 1
     ORDER BY u.id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
$product = $db->query(
    'SELECT p.id, p.name, p.dough_type_id, dt.product_line_id
     FROM products p
     LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
     ORDER BY (dt.product_line_id IS NULL) ASC, p.id ASC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
desk_assert(is_array($baker), 'test database has a baker user');
desk_assert(is_array($product), 'test database has a product');

if (is_array($baker) && is_array($product) && bakery_inventory_ready($db)) {
    $_SESSION['user_id'] = (int)$baker['id'];
    $_SESSION['user_email'] = (string)$baker['email'];
    $_SESSION['user_display_name'] = (string)$baker['display_name'];
    $_SESSION['user_role_slug'] = 'baker';
    $productId = (int)$product['id'];
    $lineId = (int)($product['product_line_id'] ?? 0);
    if (table_exists($db, 'baker_product_lines') && $lineId > 0) {
        $db->prepare('INSERT IGNORE INTO baker_product_lines (baker_user_id, product_line_id) VALUES (?, ?)')
            ->execute([(int)$baker['id'], $lineId]);
    }
    bakery_inventory_ensure_day($db, '2099-08-17', $productId);
    $db->prepare('UPDATE product_inventory_days SET available_quantity = 7 WHERE delivery_date = ? AND product_id = ?')
        ->execute(['2099-08-17', $productId]);
$qtyStmt = $db->prepare('SELECT available_quantity FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?');
    $qtyStmt->execute(['2099-08-17', $productId]);
    $qtyBefore = (int)$qtyStmt->fetchColumn();

    $noteBlocked = false;
    try {
        bakery_exception_desk_flag_shortage($db, '2099-08-17', $productId, '', (string)$product['name']);
    } catch (Throwable $e) {
        $noteBlocked = true;
    }
    desk_assert($noteBlocked, 'baker flag requires a note');

    bakery_exception_desk_flag_shortage($db, '2099-08-17', $productId, 'Short 4 loaves on the morning bake.', (string)$product['name']);
    $qtyStmt->execute(['2099-08-17', $productId]);
    $qtyAfter = (int)$qtyStmt->fetchColumn();
    desk_assert($qtyAfter === $qtyBefore, 'baker flag does not change available_quantity');
}

$_SESSION['user_role_slug'] = 'driver';
$_SESSION['user_driver_id'] = 1;
$driverApplyBlocked = false;
try {
    bakery_delivery_recovery_apply($db, 1, 'resolve', ['manager_note' => 'close it']);
} catch (Throwable $e) {
    $driverApplyBlocked = strpos($e->getMessage(), 'Only managers') !== false;
}
desk_assert($driverApplyBlocked, 'driver cannot complete recovery on the live apply path');
$driverBillBlocked = false;
try {
    bakery_delivery_recovery_apply($db, 1, 'update_handoffs', [
        'manager_note' => 'invoice it',
        'communication_status' => 'not_needed',
        'billing_handoff' => 'credit_issued',
    ]);
} catch (Throwable $e) {
    $driverBillBlocked = strpos($e->getMessage(), 'Only managers') !== false;
}
desk_assert($driverBillBlocked, 'driver cannot mark invoiced via recovery handoff');

echo "\nException desk tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
