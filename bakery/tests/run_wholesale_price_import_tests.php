<?php
/**
 * Wholesale price import: preview writes nothing, Apply writes only
 * products.wholesale_price, and the $0 guard is Wholesale tier only.
 *
 * Usage: php tests/run_wholesale_price_import_tests.php
 * Database: bakerysf_test only. Never Staging or Live.
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
require_once $root . '/includes/auth.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/wholesale_price_import.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
$GLOBALS['db'] = $db;

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $fail++;
    }
};

echo "=== Roles ===\n";
$roles = bakery_navigation_roles_for_script('wholesale_price_import.php');
sort($roles);
$assert($roles === ['administrator', 'manager'], 'page roles are administrator and manager');
$assert(!in_array('wholesale_price_import.php', bakery_driver_scripts(), true), 'drivers cannot reach wholesale price import');
$assert(!in_array('wholesale_price_import.php', bakery_sales_page_scripts(), true), 'sales reps cannot reach wholesale price import');
$assert(in_array('wholesale_price_import.php', bakery_navigation_scripts_for_role('manager'), true), 'managers can reach wholesale price import');
$assert(in_array('wholesale_price_import.php', bakery_navigation_scripts_for_role('administrator'), true), 'administrators can reach wholesale price import');

$page = (string)file_get_contents($root . '/wholesale_price_import.php');
$helper = (string)file_get_contents($root . '/includes/wholesale_price_import.php');
$assert(strpos($page, 'bakery_require_role(bakery_wholesale_price_import_roles())') !== false, 'page checks manager and administrator on the server');
$csrfAt = strpos($page, 'bakery_require_csrf()');
$applyAt = strpos($page, 'bakery_wholesale_price_import_apply');
$assert($csrfAt !== false && $applyAt !== false && $csrfAt < $applyAt, 'Apply is behind the CSRF check');
$assert(strpos($helper, 'UPDATE products SET wholesale_price = ? WHERE id = ?') !== false, 'the only product update sets wholesale_price');
$assert(preg_match('/UPDATE\s+products\s+SET\s+(?!wholesale_price\b)/i', $helper) !== 1, 'helper has no other products update');
$assert(stripos($helper, 'daily_order') === false, 'helper never mentions order tables');
$assert(strpos((string)file_get_contents($root . '/products.php'), 'wholesale_price_import') === false, 'products.php autosave is untouched');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$assert(strpos($manifest, "'wholesale_price_import.php'") !== false, 'wholesale_price_import.php is in the deploy root list');
$nav = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$pricingAt = strpos($nav, "'href' => 'customer_pricing.php'");
$importAt = strpos($nav, "'href' => 'wholesale_price_import.php'");
$assert($pricingAt !== false && $importAt !== false && $importAt > $pricingAt, 'nav item sits with Customer Custom Pricing');

echo "\n=== Languages ===\n";
$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$keys = [
    'page.wholesale_price_import',
    'nav.item.wholesale_price_import',
    'nav.item_desc.wholesale_price_import',
    'wholesale_import.heading',
    'wholesale_import.intro',
    'wholesale_import.col_product',
    'wholesale_import.col_catalog',
    'wholesale_import.col_current',
    'wholesale_import.col_new',
    'wholesale_import.col_change',
    'wholesale_import.col_warnings',
    'wholesale_import.warn_unknown',
    'wholesale_import.warn_duplicate',
    'wholesale_import.warn_non_numeric',
    'wholesale_import.warn_negative',
    'wholesale_import.warn_zero',
    'wholesale_import.warn_big_change',
    'wholesale_import.zero_guard',
    'wholesale_import.preview_note',
    'wholesale_import.apply',
];
foreach ($keys as $key) {
    $assert(isset($en[$key], $es[$key]) && $en[$key] !== '' && $es[$key] !== '', 'key present in English and Spanish: ' . $key);
}
$assert($en['wholesale_import.heading'] !== $es['wholesale_import.heading'], 'heading is translated');
$assert($en['wholesale_import.zero_guard'] !== $es['wholesale_import.zero_guard'], 'zero guard is translated');
$enKeys = array_keys($en);
$esKeys = array_keys($es);
$contiguous = static function (array $catalogKeys, string $prefix): bool {
    $matched = array_values(array_filter($catalogKeys, static fn(string $key): bool => strpos($key, $prefix) === 0));
    if ($matched === []) {
        return false;
    }
    $first = array_search($matched[0], $catalogKeys, true);
    $last = array_search($matched[count($matched) - 1], $catalogKeys, true);
    if ($first === false || $last === false) {
        return false;
    }
    for ($i = (int)$first; $i <= (int)$last; $i++) {
        if (strpos((string)$catalogKeys[$i], $prefix) !== 0) {
            return false;
        }
    }
    return true;
};
$assert($contiguous($enKeys, 'wholesale_import.') && $contiguous($esKeys, 'wholesale_import.'), 'wholesale import keys are one contiguous block');
$enImport = array_values(array_filter($enKeys, static fn(string $key): bool => strpos($key, 'wholesale_import.') === 0));
$esImport = array_values(array_filter($esKeys, static fn(string $key): bool => strpos($key, 'wholesale_import.') === 0));
$assert($enImport === $esImport && $enImport !== [], 'wholesale import keys match in English and Spanish');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
$sample = bakery_wholesale_price_import_preview($db, "name,wholesale_price\n");
$html = bakery_wholesale_price_import_render([
    'csv' => "product_id,wholesale_price\n",
    'preview' => [
        'rows' => [[
            'line' => 2,
            'product_id' => 1,
            'product_name' => 'Sample Bun',
            'catalog_price' => '4.00',
            'current_wholesale' => null,
            'new_wholesale' => '2.00',
            'change' => '+2.00',
            'warnings' => ['big_change'],
            'apply' => true,
        ]],
        'applicable' => 1,
        'skipped' => 0,
    ],
    'error' => null,
    'flash' => null,
]);
foreach ([
    'wholesale_import.col_product',
    'wholesale_import.col_catalog',
    'wholesale_import.col_current',
    'wholesale_import.col_new',
    'wholesale_import.col_change',
    'wholesale_import.col_warnings',
] as $col) {
    $assert(strpos($html, bakery_t($col)) !== false, 'preview table shows ' . $col);
}
$assert(strpos($html, 'Sample Bun') !== false, 'preview table shows the product');
$assert(strpos($html, bakery_t('wholesale_import.preview_note')) !== false, 'preview says it writes nothing');
unset($sample);

echo "\n=== Preview writes nothing, Apply writes only wholesale_price ===\n";
$stamp = 'wpi' . bin2hex(random_bytes(3));
$created = [
    'customers' => [],
    'products' => [],
    'orders' => [],
];
$userId = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
if ($userId <= 0) {
    $userId = 1;
}
$_SESSION['user_id'] = $userId;
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_display_name'] = 'Wholesale Import Test';

$insertCustomer = $db->prepare(
    'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active, pricing_tier)
     VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
);
$insertProduct = $db->prepare(
    'INSERT INTO products (name, price, wholesale_price, weight_grams, description) VALUES (?, ?, ?, ?, ?)'
);

try {
    $insertCustomer->execute([$stamp . ' wholesale', 'North Zone', $stamp . '-w@example.test', '1 Import Street', '555-0191', 'signature', 'wholesale']);
    $wholesaleId = (int)$db->lastInsertId();
    $created['customers'][] = $wholesaleId;
    $insertCustomer->execute([$stamp . ' retail', 'North Zone', $stamp . '-r@example.test', '2 Import Street', '555-0192', 'signature', 'retail']);
    $retailId = (int)$db->lastInsertId();
    $created['customers'][] = $retailId;
    $insertCustomer->execute([$stamp . ' custom', 'North Zone', $stamp . '-c@example.test', '3 Import Street', '555-0193', 'signature', 'custom']);
    $customId = (int)$db->lastInsertId();
    $created['customers'][] = $customId;

    $insertProduct->execute([$stamp . ' Bun', 10.00, 6.00, 80, 'priced bun']);
    $bunId = (int)$db->lastInsertId();
    $created['products'][] = $bunId;
    $insertProduct->execute([$stamp . ' Roll', 4.00, null, 40, 'untouched roll']);
    $rollId = (int)$db->lastInsertId();
    $created['products'][] = $rollId;
    $insertProduct->execute([$stamp . ' Zero', 0.00, null, 30, 'zero catalog']);
    $zeroId = (int)$db->lastInsertId();
    $created['products'][] = $zeroId;
    $insertProduct->execute([$stamp . ' Half', 10.00, null, 50, 'exactly half']);
    $halfId = (int)$db->lastInsertId();
    $created['products'][] = $halfId;
    $insertProduct->execute([$stamp . ' Named', 8.00, 5.00, 60, 'named match']);
    $namedId = (int)$db->lastInsertId();
    $created['products'][] = $namedId;

    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, 1, 2)')
        ->execute([$wholesaleId, $zeroId]);
    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, 1, 2)')
        ->execute([$retailId, $zeroId]);
    $db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, 1, 2)')
        ->execute([$customId, $zeroId]);

    $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, ?, ?)')
        ->execute([$wholesaleId, '2031-04-02', 'pending', 12.00]);
    $orderId = (int)$db->lastInsertId();
    $created['orders'][] = $orderId;
    $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 2, 6.00, 12.00)')
        ->execute([$orderId, $bunId]);

    $readProduct = $db->prepare('SELECT price, wholesale_price, weight_grams FROM products WHERE id = ?');
    $readProduct->execute([$bunId]);
    $before = $readProduct->fetch(PDO::FETCH_ASSOC);
    $auditBefore = (int)$db->query('SELECT COUNT(*) FROM field_audit')->fetchColumn();

    $csv = "product_id,wholesale_price\n{$bunId},3.50\n999999,1.25\n";
    $preview = bakery_wholesale_price_import_preview($db, $csv);
    $readProduct->execute([$bunId]);
    $afterPreview = $readProduct->fetch(PDO::FETCH_ASSOC);
    $auditAfterPreview = (int)$db->query('SELECT COUNT(*) FROM field_audit')->fetchColumn();
    $assert($afterPreview == $before, 'preview does not change the product row');
    $assert($auditAfterPreview === $auditBefore, 'preview does not write an audit row');

    $byLine = [];
    foreach ($preview['rows'] as $row) {
        $byLine[(int)$row['product_id']] = $row;
    }
    $assert(isset($byLine[$bunId]) && $byLine[$bunId]['apply'] === true, 'known product is applicable');
    $assert($byLine[$bunId]['catalog_price'] === '10.00', 'preview shows the current catalog price');
    $assert($byLine[$bunId]['current_wholesale'] === '6.00', 'preview shows the current wholesale price');
    $assert($byLine[$bunId]['new_wholesale'] === '3.50', 'preview shows the new wholesale price');
    $assert($byLine[$bunId]['change'] === '-2.50', 'preview shows the wholesale change');
    $assert(in_array('big_change', $byLine[$bunId]['warnings'], true), 'more than a 50% move from catalog price is a warning');
    $unknown = null;
    foreach ($preview['rows'] as $row) {
        if (in_array('unknown', $row['warnings'], true)) {
            $unknown = $row;
        }
    }
    $assert($unknown !== null && $unknown['apply'] === false, 'unknown product is rejected');

    $result = bakery_wholesale_price_import_apply($db, $csv);
    $assert((int)$result['applied'] === 1, 'Apply writes the known row and rejects the unknown product');
    $readProduct->execute([$bunId]);
    $afterApply = $readProduct->fetch(PDO::FETCH_ASSOC);
    $assert($afterApply['wholesale_price'] === '3.50', 'Apply writes wholesale_price');
    $assert($afterApply['price'] === $before['price'], 'products.price is unchanged');
    $assert((string)$afterApply['weight_grams'] === (string)$before['weight_grams'], 'catalog weight is unchanged');
    $readProduct->execute([$rollId]);
    $roll = $readProduct->fetch(PDO::FETCH_ASSOC);
    $assert($roll['price'] === '4.00' && $roll['wholesale_price'] === null, 'a product left out of the CSV is unchanged');
    $line = $db->prepare('SELECT unit_price, line_total FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?');
    $line->execute([$orderId, $bunId]);
    $lineRow = $line->fetch(PDO::FETCH_ASSOC);
    $assert($lineRow['unit_price'] === '6.00' && $lineRow['line_total'] === '12.00', 'Apply does not reprice orders');

    $audit = $db->prepare(
        'SELECT actor_user_id, field, old_value, new_value, created_at, action
         FROM field_audit
         WHERE entity_type = ? AND entity_id = ? AND field = ?
         ORDER BY id DESC LIMIT 1'
    );
    $audit->execute(['product', $bunId, 'wholesale_price']);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    $assert(is_array($auditRow), 'Apply writes an audit row');
    if (is_array($auditRow)) {
        $assert((int)$auditRow['actor_user_id'] === $userId, 'audit row records the user');
        $assert($auditRow['old_value'] === '6.00' && $auditRow['new_value'] === '3.50', 'audit row records old and new wholesale values');
        $assert($auditRow['field'] === 'wholesale_price', 'audit field is wholesale_price');
        $createdAt = strtotime((string)$auditRow['created_at']);
        $dbNow = strtotime((string)$db->query('SELECT NOW()')->fetchColumn());
        $assert($createdAt !== false && $dbNow !== false && abs($createdAt - $dbNow) < 180, 'audit time is the time of this Apply');
    }
    $priceAudit = $db->prepare(
        'SELECT COUNT(*) FROM field_audit WHERE entity_type = ? AND entity_id = ? AND field = ?'
    );
    $priceAudit->execute(['product', $bunId, 'price']);
    $assert((int)$priceAudit->fetchColumn() === 0, 'catalog price is not audited because it was not written');

    $bad = bakery_wholesale_price_import_preview($db, "product_id,wholesale_price\n{$bunId},nope\n{$bunId},-2\n{$bunId},0\n{$bunId},3.50\n");
    $flags = ['non_numeric' => false, 'negative' => false, 'zero' => false, 'duplicate' => false];
    foreach ($bad['rows'] as $row) {
        foreach (array_keys($flags) as $flag) {
            if (in_array($flag, $row['warnings'], true)) {
                $flags[$flag] = true;
            }
        }
        $assert($row['apply'] === false, 'duplicate, non-numeric, negative, and unchanged rows are not written');
    }
    $assert($flags['non_numeric'] && $flags['negative'] && $flags['zero'] && $flags['duplicate'], 'preview warns for non-numeric, negative, $0, and duplicate rows');
    $readProduct->execute([$bunId]);
    $still = $readProduct->fetch(PDO::FETCH_ASSOC);
    bakery_wholesale_price_import_apply($db, "product_id,wholesale_price\n{$bunId},nope\n{$bunId},-2\n");
    $readProduct->execute([$bunId]);
    $afterBad = $readProduct->fetch(PDO::FETCH_ASSOC);
    $assert($afterBad == $still, 'rejected rows do not change wholesale_price');

    $exact = bakery_wholesale_price_import_preview($db, "name,wholesale_price\n{$stamp} Named,4.00\n{$stamp} named,1.00\n");
    $matched = 0;
    $missed = 0;
    foreach ($exact['rows'] as $row) {
        if ((int)$row['product_id'] === $namedId && $row['apply'] === true) {
            $matched++;
        }
        if (in_array('unknown', $row['warnings'], true)) {
            $missed++;
        }
    }
    $assert($matched === 1, 'exact product name matches');
    $assert($missed === 1, 'a different case is an unknown product');

    $half = bakery_wholesale_price_import_preview($db, "product_id,wholesale_price\n{$halfId},5.00\n");
    $assert($half['rows'][0]['apply'] === true && $half['rows'][0]['warnings'] === [], 'a change of exactly 50% from catalog price is not a big-change warning');
    $over = bakery_wholesale_price_import_preview($db, "product_id,wholesale_price\n{$halfId},4.99\n");
    $assert(in_array('big_change', $over['rows'][0]['warnings'], true) && $over['rows'][0]['apply'] === true, 'more than 50% warns and can still be applied');

    echo "\n=== Wholesale \$0 guard ===\n";
    $wholesaleNames = bakery_wholesale_zero_price_product_names($db, $wholesaleId);
    $assert($wholesaleNames === [$stamp . ' Zero'], 'Wholesale tier lists the standing product with no wholesale price and a $0 catalog price');
    $assert(bakery_wholesale_zero_price_product_names($db, $retailId) === [], '$0 guard does not fire for Retail');
    $assert(bakery_wholesale_zero_price_product_names($db, $customId) === [], '$0 guard does not fire for Custom');
    $db->prepare('UPDATE products SET wholesale_price = 2.00 WHERE id = ?')->execute([$zeroId]);
    $assert(bakery_wholesale_zero_price_product_names($db, $wholesaleId) === [], 'a wholesale price clears the guard');
    $db->prepare('UPDATE products SET wholesale_price = NULL, price = 3.00 WHERE id = ?')->execute([$zeroId]);
    $assert(bakery_wholesale_zero_price_product_names($db, $wholesaleId) === [], 'a catalog price above $0 clears the guard');
    $guardText = bakery_wholesale_zero_price_warning_text([$stamp . ' Zero']);
    $assert(strpos($guardText, $stamp . ' Zero') !== false && strlen($guardText) < 180, 'the warning is one short line that names the product');
    $customersPage = (string)file_get_contents($root . '/customers.php');
    $recordPage = (string)file_get_contents($root . '/customer_record.php');
    $assert(strpos($customersPage, 'bakery_wholesale_zero_price_standing_map') !== false, 'Customers page can show the guard');
    $assert(strpos($customersPage, '$salesReadOnly ? [] : bakery_wholesale_zero_price_standing_map') !== false, 'sales lookup does not load the price guard');
    $assert(strpos($recordPage, 'bakery_wholesale_zero_price_product_names') !== false, 'Customer record shows the guard where the tier is shown');
} finally {
    if ($created['orders'] !== []) {
        $marks = implode(',', array_fill(0, count($created['orders']), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($marks)")->execute($created['orders']);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($marks)")->execute($created['orders']);
    }
    if ($created['products'] !== []) {
        $marks = implode(',', array_fill(0, count($created['products']), '?'));
        $db->prepare("DELETE FROM standing_orders WHERE product_id IN ($marks)")->execute($created['products']);
        $db->prepare("DELETE FROM field_audit WHERE entity_type = 'product' AND entity_id IN ($marks)")->execute($created['products']);
        $db->prepare("DELETE FROM products WHERE id IN ($marks)")->execute($created['products']);
    }
    if ($created['customers'] !== []) {
        $marks = implode(',', array_fill(0, count($created['customers']), '?'));
        $db->prepare("DELETE FROM customers WHERE id IN ($marks)")->execute($created['customers']);
    }
}

echo $fail === 0 ? "\nAll wholesale price import checks passed\n" : "\n$fail failed\n";
exit($fail > 0 ? 1 : 0);
