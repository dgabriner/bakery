<?php
/**
 * Field-level audit trail: before and after values, masked staff codes,
 * and an uncapped CSV export.
 *
 * Usage: php tests/run_audit_log_tests.php
 * Database: bakerysf_test only. Never Staging or Live.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/includes/auth.php';
require_once $root . '/includes/users_admin.php';
require_once $root . '/includes/customer_order_mutations.php';
require_once $root . '/includes/daily_orders_actions.php';
require_once $root . '/includes/hosted_migration_runtime.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function audit_rows(PDO $db, string $type, int $id): array
{
    $stmt = $db->prepare(
        'SELECT * FROM field_audit WHERE entity_type = ? AND entity_id = ? ORDER BY id'
    );
    $stmt->execute([$type, $id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function audit_free_code(PDO $db): string
{
    $stmt = $db->prepare('SELECT id FROM users WHERE login_code = ? LIMIT 1');
    for ($try = 0; $try < 40; $try++) {
        $code = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->execute([$code]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('No free staff code');
}

function audit_no_staff_code(array $rows, string $message): void
{
    foreach ($rows as $row) {
        $blob = (string)($row['old_value'] ?? '') . ' ' . (string)($row['new_value'] ?? '');
        assert_true(!preg_match('/\d{4}/', $blob), $message . ' (' . $blob . ')');
    }
}

echo "=== Audit migration 098 ===\n";
$migrationPath = $root . '/database/schema/098_audit_log.sql';
assert_true(is_file($migrationPath), '098_audit_log.sql exists');
assert_true(!is_file($root . '/database/schema/093_audit_log.sql'), '093 is not used for the audit table');
[$safe, $safeMessage] = bakery_hosted_migration_sql_safe((string)file_get_contents($migrationPath));
assert_true($safe, '098_audit_log.sql is an additive hosted migration (' . $safeMessage . ')');

if (!table_exists($db, 'field_audit')) {
    $db->exec((string)file_get_contents($migrationPath));
    bakery_forget_table_exists('field_audit');
}
assert_true(table_exists($db, 'field_audit'), 'field_audit table exists on bakerysf_test');
assert_true(table_exists($db, 'audit_log'), 'billing audit_log from migration 022 is still present');

$columns = $db->query('SHOW COLUMNS FROM field_audit')->fetchAll(PDO::FETCH_COLUMN);
assert_eq(
    ['id', 'created_at', 'actor_user_id', 'actor_type', 'entity_type', 'entity_id', 'action', 'field', 'old_value', 'new_value', 'ip', 'user_agent', 'request_path'],
    $columns,
    'field_audit columns match the trail'
);

$productsSrc = (string)file_get_contents($root . '/products.php');
$updateAt = strpos($productsSrc, "case 'update':");
$auditAt = $updateAt === false ? false : strpos($productsSrc, 'bakery_audit_product_fields', $updateAt);
$jsonAt = $updateAt === false ? false : strpos($productsSrc, "json_encode(['success' => true, 'reprice_warning'", $updateAt);
assert_true($auditAt !== false && $jsonAt !== false && $auditAt < $jsonAt, 'product inline autosave writes the audit row before the success JSON');

$usersSrc = (string)file_get_contents($root . '/users.php');
assert_true(strpos($usersSrc, 'bakery_audit_mask_staff_code') !== false, 'users.php masks staff codes');
assert_true(strpos($usersSrc, "htmlspecialchars(\$u['login_code'])") === false, 'users.php does not print the raw staff code');
assert_true(strpos($usersSrc, 'reveal_code') !== false, 'users.php reveals a code only through the audited action');

$pageSrc = (string)file_get_contents($root . '/audit_log.php');
$exportAt = strpos($pageSrc, "\$_GET['export'] === 'csv'");
$headerAt = strpos($pageSrc, 'header.php');
assert_true($exportAt !== false && $headerAt !== false && $exportAt < $headerAt, 'CSV export finishes before the page HTML');
assert_true(strpos($pageSrc, 'bakery_audit_rows($db, $filters, 500, \'DESC\')') !== false, 'the screen list is capped at 500');
assert_true(strpos($pageSrc, 'bakery_audit_csv($db, $filters)') !== false, 'CSV uses the uncapped export');
assert_true(strpos((string)file_get_contents($root . '/includes/navigation_catalog.php'), 'audit_log.php') !== false, 'administrators can open the audit log');
assert_true(strpos((string)file_get_contents($root . '/scripts/deploy_manifest.ps1'), "'audit_log.php'") !== false, 'audit_log.php is in the deploy root list');
assert_true(strpos((string)file_get_contents($root . '/includes/daily_order_generation.php'), 'bakery_audit_') === false, 'generating dated orders from standing does not write an audit row');

$portalSrc = (string)file_get_contents($root . '/includes/customer_portal.php');
$portalStart = strpos($portalSrc, 'function bakery_portal_add_to_standing_order');
$portalEnd = strpos($portalSrc, 'function bakery_portal_tip_client_key');
$portalFn = ($portalStart !== false && $portalEnd !== false) ? substr($portalSrc, $portalStart, $portalEnd - $portalStart) : '';
assert_true(strpos($portalFn, 'bakery_standing_order_upsert') !== false, 'portal standing adds go through the audited upsert');
assert_true(strpos($portalFn, 'DELETE FROM standing_orders') === false, 'portal standing adds do not delete the old row before the upsert');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
foreach (['page.audit_log', 'nav.item.audit_log', 'nav.item_desc.audit_log', 'audit_log.lead', 'audit_log.export', 'users.reveal_code', 'users.code_keep'] as $key) {
    assert_true(isset($en[$key], $es[$key]) && $en[$key] !== '' && $es[$key] !== '' && $en[$key] !== $es[$key], 'translated key ' . $key);
}

assert_eq('•••1', bakery_audit_mask_staff_code('7391'), 'a staff code shows only its last digit');
assert_eq('••••', bakery_audit_mask_staff_code(''), 'an empty staff code is dots');

$stamp = 'aud' . bin2hex(random_bytes(3));
$path = '/audit-test/' . $stamp;
$_SERVER['REQUEST_URI'] = $path;
$_SERVER['REMOTE_ADDR'] = '203.0.113.46';
$_SERVER['HTTP_USER_AGENT'] = 'AuditTest/1.0';
$_SESSION = [];

$customerIds = [];
$productIds = [];
$orderIds = [];
$userIds = [];

$cleanup = static function () use ($db, &$customerIds, &$productIds, &$orderIds, &$userIds, $path): void {
    if (table_exists($db, 'field_audit')) {
        $db->prepare('DELETE FROM field_audit WHERE request_path = ? OR entity_type = ?')->execute([$path, 'audit_probe']);
    }
    if ($orderIds !== []) {
        $in = implode(',', array_map('intval', $orderIds));
        if (table_exists($db, 'operational_events')) {
            $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ($in)");
        }
        $db->exec("DELETE FROM daily_orders WHERE id IN ($in)");
    }
    if ($customerIds !== []) {
        $in = implode(',', array_map('intval', $customerIds));
        $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM customer_product_prices WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM customers WHERE id IN ($in)");
    }
    if ($productIds !== []) {
        $in = implode(',', array_map('intval', $productIds));
        $db->exec("DELETE FROM products WHERE id IN ($in)");
    }
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
};

try {
    $adminRole = (int)$db->query("SELECT id FROM roles WHERE slug = 'administrator' LIMIT 1")->fetchColumn();
    assert_true($adminRole > 0, 'administrator role exists');
    bakery_ensure_cashier_role($db);
    $adminCode = audit_free_code($db);
    $insertUser = $db->prepare(
        'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)'
    );
    $insertUser->execute([$stamp . '-admin@example.test', 'x', $adminCode, $stamp . ' admin', $adminRole]);
    $adminId = (int)$db->lastInsertId();
    $userIds[] = $adminId;
    $_SESSION['user_id'] = $adminId;
    $_SESSION['user_role_slug'] = 'administrator';
    $_SESSION['user_display_name'] = $stamp . ' admin';
    $_SESSION['user_email'] = $stamp . '-admin@example.test';

    $insertCustomer = $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active, pricing_tier)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
    );
    $customerName = '65 Fairmount ' . $stamp;
    $insertCustomer->execute([$customerName, 'North Zone', $stamp . '@example.test', '65 Fairmount', '555-0146', 'signature', 'retail']);
    $customerId = (int)$db->lastInsertId();
    $customerIds[] = $customerId;

    $insertProduct = $db->prepare(
        'INSERT INTO products (name, price, wholesale_price, description) VALUES (?, ?, ?, ?)'
    );
    $productName = 'Sesame Seed Bagel ' . $stamp;
    $insertProduct->execute([$productName, 0.00, null, 'catalog gap fixture']);
    $bagelId = (int)$db->lastInsertId();
    $productIds[] = $bagelId;

    echo "=== 65 Fairmount standing orders ===\n";
    bakery_standing_order_upsert($db, $customerId, $bagelId, 1, 4);
    $standingId = (int)$db->query(
        'SELECT id FROM standing_orders WHERE customer_id = ' . $customerId . ' AND product_id = ' . $bagelId . ' AND day_of_week = 1'
    )->fetchColumn();
    $added = audit_rows($db, 'standing_order', $standingId);
    assert_eq(1, count($added), 'adding a standing line writes one audit row');
    assert_eq('add', $added[0]['action'], 'standing add action');
    assert_eq('quantity', $added[0]['field'], 'standing add field');
    assert_true($added[0]['old_value'] === null, 'standing add has no previous quantity');
    assert_eq('4', $added[0]['new_value'], 'standing add records the new quantity');
    assert_eq('human', $added[0]['actor_type'], 'a signed-in staff change is human');
    assert_eq($adminId, (int)$added[0]['actor_user_id'], 'standing add records the staff user');
    assert_eq('203.0.113.46', $added[0]['ip'], 'standing add records the request IP');
    $labeled = bakery_audit_decorate($db, $added);
    assert_true(strpos($labeled[0]['entity_label'], '65 Fairmount') !== false, 'standing label names 65 Fairmount');
    assert_true(strpos($labeled[0]['entity_label'], 'Sesame Seed Bagel') !== false, 'standing label names the bagel');

    bakery_standing_order_upsert($db, $customerId, $bagelId, 1, 7);
    $updated = audit_rows($db, 'standing_order', $standingId);
    assert_eq(2, count($updated), 'editing the standing quantity writes a second row');
    assert_eq('update', $updated[1]['action'], 'standing edit action');
    assert_eq('4', $updated[1]['old_value'], 'standing edit keeps the old quantity');
    assert_eq('7', $updated[1]['new_value'], 'standing edit keeps the new quantity');

    $db->prepare('UPDATE field_audit SET created_at = ? WHERE id = ?')->execute(['2026-10-13 07:46:12', (int)$added[0]['id']]);
    $db->prepare('UPDATE field_audit SET created_at = ? WHERE id = ?')->execute(['2026-10-13 07:48:40', (int)$updated[1]['id']]);
    $morning = bakery_audit_parse_filters([
        'from' => '2026-10-13',
        'to' => '2026-10-13',
        'user_id' => $adminId,
        'entity_type' => 'standing_order',
        'action' => '',
    ]);
    $morningRows = bakery_audit_rows($db, $morning, null, 'ASC');
    assert_eq(2, count($morningRows), 'the 10/13 morning filter returns both Fairmount standing edits');
    $morningUpdate = bakery_audit_parse_filters([
        'from' => '2026-10-13',
        'to' => '2026-10-13',
        'user_id' => '0',
        'entity_type' => 'standing_order',
        'action' => 'update',
    ]);
    $updateOnly = bakery_audit_rows($db, $morningUpdate, null, 'ASC');
    assert_eq(1, count($updateOnly), 'action filter keeps the quantity edit');
    assert_eq('7', $updateOnly[0]['new_value'], 'filtered edit is 4 to 7');
    $otherDay = bakery_audit_parse_filters(['from' => '2026-10-14', 'to' => '2026-10-14', 'user_id' => $adminId, 'entity_type' => 'standing_order', 'action' => '']);
    assert_eq(0, bakery_audit_count($db, $otherDay), 'the next day does not include the backdated morning rows');

    bakery_standing_order_upsert($db, $customerId, $bagelId, 1, 0);
    $removed = audit_rows($db, 'standing_order', $standingId);
    assert_eq('remove', $removed[count($removed) - 1]['action'], 'removing a standing line is recorded');
    assert_eq('7', $removed[count($removed) - 1]['old_value'], 'standing remove keeps the last quantity');
    assert_true($removed[count($removed) - 1]['new_value'] === null, 'standing remove has no new quantity');

    echo "=== Actor types ===\n";
    unset($_SESSION['user_id']);
    bakery_standing_order_upsert($db, $customerId, $bagelId, 2, 1);
    $tuesdayId = (int)$db->query(
        'SELECT id FROM standing_orders WHERE customer_id = ' . $customerId . ' AND product_id = ' . $bagelId . ' AND day_of_week = 2'
    )->fetchColumn();
    $systemRows = audit_rows($db, 'standing_order', $tuesdayId);
    assert_eq('system', $systemRows[0]['actor_type'], 'a change with no session is system');
    assert_true($systemRows[0]['actor_user_id'] === null, 'system changes have no staff user');

    $_SESSION['portal_customer_id'] = $customerId;
    bakery_standing_order_upsert($db, $customerId, $bagelId, 3, 2);
    $wednesdayId = (int)$db->query(
        'SELECT id FROM standing_orders WHERE customer_id = ' . $customerId . ' AND product_id = ' . $bagelId . ' AND day_of_week = 3'
    )->fetchColumn();
    $customerRows = audit_rows($db, 'standing_order', $wednesdayId);
    assert_eq('customer', $customerRows[0]['actor_type'], 'a portal customer change is customer');

    if (column_exists($db, 'customers', 'sf_baker_enabled')) {
        $db->prepare('UPDATE customers SET sf_baker_enabled = 1 WHERE id = ?')->execute([$customerId]);
        bakery_standing_order_upsert($db, $customerId, $bagelId, 4, 3);
        $thursdayId = (int)$db->query(
            'SELECT id FROM standing_orders WHERE customer_id = ' . $customerId . ' AND product_id = ' . $bagelId . ' AND day_of_week = 4'
        )->fetchColumn();
        $botRows = audit_rows($db, 'standing_order', $thursdayId);
        assert_eq('bot', $botRows[0]['actor_type'], 'an SF Baker portal change is bot');
        $db->prepare('UPDATE customers SET sf_baker_enabled = 0 WHERE id = ?')->execute([$customerId]);
    }
    unset($_SESSION['portal_customer_id']);
    $_SESSION['user_id'] = $adminId;

    echo "=== Sesame Seed Bagel price ===\n";
    $beforeProduct = bakery_audit_product_row($db, $bagelId);
    bakery_audit_product_fields($db, $bagelId, $beforeProduct ?: [], ['price' => '0.75']);
    $db->prepare('UPDATE products SET price = 0.75 WHERE id = ?')->execute([$bagelId]);
    $priceRows = audit_rows($db, 'product', $bagelId);
    assert_eq(1, count($priceRows), 'the catalog price change writes one row');
    assert_eq('price', $priceRows[0]['field'], 'catalog field is price');
    assert_eq('0.00', $priceRows[0]['old_value'], 'bagel price before is 0.00');
    assert_eq('0.75', $priceRows[0]['new_value'], 'bagel price after is 0.75');
    bakery_audit_product_fields($db, $bagelId, bakery_audit_product_row($db, $bagelId) ?: [], ['price' => '0.75', 'name' => $productName]);
    assert_eq(1, count(audit_rows($db, 'product', $bagelId)), 'an unchanged product save writes nothing');

    echo "=== Custom price and pricing tier ===\n";
    bakery_save_customer_custom_prices($db, $customerId, [['product_id' => $bagelId, 'unit_price' => '1.25']]);
    $priceId = (int)$db->query(
        'SELECT id FROM customer_product_prices WHERE customer_id = ' . $customerId . ' AND product_id = ' . $bagelId
    )->fetchColumn();
    $custom = audit_rows($db, 'customer_product_price', $priceId);
    assert_eq('add', $custom[0]['action'], 'custom price add');
    assert_eq('1.25', $custom[0]['new_value'], 'custom price starts at 1.25');
    bakery_save_customer_custom_prices($db, $customerId, [['product_id' => $bagelId, 'unit_price' => '1.50']]);
    $custom = audit_rows($db, 'customer_product_price', $priceId);
    assert_eq('1.25', $custom[1]['old_value'], 'custom price edit keeps 1.25');
    assert_eq('1.50', $custom[1]['new_value'], 'custom price edit records 1.50');
    bakery_save_customer_custom_prices($db, $customerId, [['product_id' => $bagelId, 'unit_price' => '']]);
    $custom = audit_rows($db, 'customer_product_price', $priceId);
    assert_eq('remove', $custom[count($custom) - 1]['action'], 'clearing a custom price is a remove');
    bakery_audit_pricing_tier($db, $customerId, 'retail', 'wholesale');
    $tier = audit_rows($db, 'customer', $customerId);
    assert_eq('pricing_tier', $tier[0]['field'], 'pricing tier field');
    assert_eq('retail', $tier[0]['old_value'], 'tier before is retail');
    assert_eq('wholesale', $tier[0]['new_value'], 'tier after is wholesale');

    echo "=== Dated order line ===\n";
    $insertOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, ?, 0)'
    );
    $insertOrder->execute([$customerId, '2098-10-13', 'pending']);
    $orderId = (int)$db->lastInsertId();
    $orderIds[] = $orderId;
    $addedLine = bakery_daily_orders_action_add_item($db, [
        'order_id' => $orderId,
        'product_id' => $bagelId,
        'quantity' => 2,
    ], ['id' => $adminId, 'role_slug' => 'administrator']);
    assert_true(!empty($addedLine['success']), 'dated line add succeeds (' . (string)($addedLine['error'] ?? '') . ')');
    $line = bakery_audit_daily_line_lookup($db, $orderId, $bagelId);
    assert_true(is_array($line), 'dated line exists');
    $lineId = (int)$line['id'];
    $lineRows = audit_rows($db, 'daily_order_item', $lineId);
    $lineFields = [];
    foreach ($lineRows as $row) {
        $lineFields[$row['field']] = $row;
    }
    assert_eq('add', $lineFields['quantity']['action'] ?? '', 'dated quantity add');
    assert_eq('2', $lineFields['quantity']['new_value'] ?? '', 'dated quantity is 2');
    assert_eq('0.75', $lineFields['unit_price']['new_value'] ?? '', 'dated price is the catalog 0.75');

    bakery_daily_orders_action_update_quantity($db, ['item_id' => $lineId, 'quantity' => 5], ['id' => $adminId, 'role_slug' => 'administrator']);
    $qtyChange = null;
    foreach (audit_rows($db, 'daily_order_item', $lineId) as $row) {
        if ($row['field'] === 'quantity' && $row['action'] === 'update') {
            $qtyChange = $row;
        }
    }
    assert_true(is_array($qtyChange), 'dated quantity edit is recorded');
    assert_eq('2', $qtyChange['old_value'], 'dated quantity before is 2');
    assert_eq('5', $qtyChange['new_value'], 'dated quantity after is 5');

    $repriced = bakery_order_reprice_write_lines($db, [[
        'line_id' => $lineId,
        'order_id' => $orderId,
        'quantity' => 5,
        'old_unit_price' => 0.75,
        'new_unit_price' => 1.10,
        'customer_id' => $customerId,
        'product_id' => $bagelId,
        'order_date' => '2098-10-13',
    ]], ['id' => $adminId, 'role_slug' => 'administrator'], 'test', 'reprice.done');
    assert_eq(1, (int)($repriced['updated'] ?? 0), 'reprice writes the dated line');
    $priceChange = null;
    foreach (audit_rows($db, 'daily_order_item', $lineId) as $row) {
        if ($row['field'] === 'unit_price' && $row['action'] === 'update' && $row['new_value'] === '1.10') {
            $priceChange = $row;
        }
    }
    assert_true(is_array($priceChange), 'dated price edit is recorded');
    assert_eq('0.75', $priceChange['old_value'], 'dated price before is 0.75');

    bakery_daily_orders_action_update_quantity($db, ['item_id' => $lineId, 'quantity' => 0], ['id' => $adminId, 'role_slug' => 'administrator']);
    $lineGone = null;
    foreach (audit_rows($db, 'daily_order_item', $lineId) as $row) {
        if ($row['action'] === 'remove' && $row['field'] === 'quantity') {
            $lineGone = $row;
        }
    }
    assert_true(is_array($lineGone), 'dated line remove is recorded');
    assert_eq('5', $lineGone['old_value'], 'dated remove keeps the last quantity');

    echo "=== Jasmine role, active flag, and code ===\n";
    $jasmineCode = audit_free_code($db);
    $replacementCode = audit_free_code($db);
    $created = bakery_users_save($db, [
        'action' => 'create',
        'display_name' => 'Jasmine ' . $stamp,
        'role_slug' => 'administrator',
        'login_code' => $jasmineCode,
        'email' => $stamp . '-jasmine@example.test',
        'is_active' => '1',
    ]);
    $jasmineId = (int)$created['id'];
    $userIds[] = $jasmineId;
    bakery_users_save($db, [
        'action' => 'update',
        'id' => $jasmineId,
        'display_name' => 'Jasmine ' . $stamp,
        'role_slug' => 'cashier',
        'login_code' => '',
        'email' => $stamp . '-jasmine@example.test',
        'is_active' => '1',
    ]);
    $roleRow = null;
    foreach (audit_rows($db, 'user', $jasmineId) as $row) {
        if ($row['field'] === 'role' && $row['action'] === 'update') {
            $roleRow = $row;
        }
    }
    assert_true(is_array($roleRow), 'Jasmine role change is recorded');
    assert_eq('Administrator', $roleRow['old_value'], 'Jasmine was Administrator');
    assert_eq('Cashier', $roleRow['new_value'], 'Jasmine became Cashier');

    bakery_users_set_active($db, $jasmineId, 0);
    $activeRow = null;
    foreach (audit_rows($db, 'user', $jasmineId) as $row) {
        if ($row['field'] === 'is_active' && $row['action'] === 'update') {
            $activeRow = $row;
        }
    }
    assert_true(is_array($activeRow), 'active flag change is recorded');
    assert_eq('1', $activeRow['old_value'], 'active before is 1');
    assert_eq('0', $activeRow['new_value'], 'active after is 0');

    bakery_users_save($db, [
        'action' => 'update',
        'id' => $jasmineId,
        'display_name' => 'Jasmine ' . $stamp,
        'role_slug' => 'cashier',
        'login_code' => $replacementCode,
        'email' => $stamp . '-jasmine@example.test',
        'is_active' => '1',
    ]);
    bakery_audit_write($db, 'user', $jasmineId, 'update', 'login_code', $jasmineCode, $replacementCode);
    $userRows = audit_rows($db, 'user', $jasmineId);
    audit_no_staff_code($userRows, 'user audit rows never store a staff code');
    $codeRow = null;
    foreach ($userRows as $row) {
        if ($row['field'] === 'login_code' && $row['action'] === 'update') {
            $codeRow = $row;
        }
    }
    assert_true(is_array($codeRow), 'code reset is recorded');
    assert_eq('code changed', $codeRow['old_value'], 'old code text is code changed');
    assert_eq('code changed', $codeRow['new_value'], 'new code text is code changed');

    $_SESSION['user_role_slug'] = 'cashier';
    $beforeReveal = count(audit_rows($db, 'user', $jasmineId));
    $denied = bakery_users_reveal_code($db, $jasmineId);
    assert_true(empty($denied['ok']), 'a cashier cannot reveal a staff code');
    assert_eq($beforeReveal, count(audit_rows($db, 'user', $jasmineId)), 'a refused reveal writes nothing');
    $_SESSION['user_role_slug'] = 'administrator';
    $revealed = bakery_users_reveal_code($db, $jasmineId);
    assert_true(!empty($revealed['ok']), 'an administrator can reveal a staff code');
    assert_eq($replacementCode, (string)($revealed['code'] ?? ''), 'reveal returns the current code');
    $revealRow = null;
    foreach (audit_rows($db, 'user', $jasmineId) as $row) {
        if ($row['action'] === 'reveal') {
            $revealRow = $row;
        }
    }
    assert_true(is_array($revealRow), 'reveal writes an audit row');
    assert_eq('revealed', $revealRow['new_value'], 'reveal stores the word revealed');
    audit_no_staff_code(audit_rows($db, 'user', $jasmineId), 'reveal audit still has no staff code');

    echo "=== CSV has no row cap ===\n";
    $emptyFilters = bakery_audit_parse_filters([]);
    [$uncappedSql] = bakery_audit_select_sql($emptyFilters, null, 'ASC');
    [$cappedSql] = bakery_audit_select_sql($emptyFilters, 500, 'DESC');
    assert_true(strpos($uncappedSql, 'LIMIT') === false, 'full export SQL has no LIMIT');
    assert_true(strpos($cappedSql, 'LIMIT 500') !== false, 'screen SQL caps at 500');
    for ($i = 1; $i <= 501; $i++) {
        bakery_audit_write($db, 'audit_probe', 1, 'add', 'note', null, 'row-' . $i);
    }
    $probeFilters = bakery_audit_parse_filters(['entity_type' => 'audit_probe']);
    assert_eq(501, bakery_audit_count($db, $probeFilters), 'probe count is 501');
    assert_eq(500, count(bakery_audit_rows($db, $probeFilters, 500, 'DESC')), 'screen probe list stops at 500');
    $csv = bakery_audit_csv($db, $probeFilters);
    $csvLines = preg_split("/\r\n|\n|\r/", trim($csv)) ?: [];
    assert_eq(502, count($csvLines), 'CSV export includes the header and all 501 rows');
    assert_true(strpos($csv, 'row-1') !== false && strpos($csv, 'row-501') !== false, 'CSV includes the first and last probe rows');
    bakery_audit_write($db, 'audit_probe', 1, 'add', 'note', '=1+1', 'ok');
    $guarded = bakery_audit_csv($db, $probeFilters);
    assert_true(strpos($guarded, "'=1+1") !== false, 'CSV prefixes a formula-like value');
} finally {
    $cleanup();
}

echo "\n" . (int)$GLOBALS['TEST_PASS'] . " passed, " . (int)$GLOBALS['TEST_FAIL'] . " failed\n";
exit(((int)$GLOBALS['TEST_FAIL']) > 0 ? 1 : 0);
