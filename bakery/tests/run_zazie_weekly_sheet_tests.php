<?php
/**
 * Zazie weekly sheet upload.
 *
 * Usage: php tests/run_zazie_weekly_sheet_tests.php
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
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/hosted_migration_runtime.php';
require_once $root . '/includes/zazie_weekly_sheet.php';
require_once $root . '/includes/navigation_catalog.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

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

echo "=== Target ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$migrationPath = $root . '/database/schema/090_zazie_sheet_product_map.sql';
$migrationSql = (string)file_get_contents($migrationPath);
[$migrationSafe, $migrationMessage] = bakery_hosted_migration_sql_safe($migrationSql);
$assert($migrationSafe, 'migration 090 is additive (' . $migrationMessage . ')');
if (!bakery_zazie_weekly_ready($db)) {
    foreach (bakery_parse_sql_file($migrationPath) as $statement) {
        bakery_hosted_migration_exec_statement($db, $statement);
    }
    if (table_exists($db, 'schema_migrations')) {
        $db->prepare('INSERT IGNORE INTO schema_migrations (id) VALUES (?)')->execute(['090_zazie_sheet_product_map']);
    }
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('zazie_sheet_product_map');
    }
}
$assert(bakery_zazie_weekly_ready($db), 'mapping table exists on bakerysf_test');
$reran = true;
try {
    foreach (bakery_parse_sql_file($migrationPath) as $statement) {
        bakery_hosted_migration_exec_statement($db, $statement);
    }
} catch (Throwable $e) {
    $reran = false;
    echo 'migration re-run error: ' . $e->getMessage() . PHP_EOL;
}
$assert($reran && bakery_zazie_weekly_ready($db), 'migration 090 can be applied again when the table already exists');

echo "=== Parse ===\n";
$csvPath = $root . '/tests/fixtures/zazie_weekly_order.csv';
$esPath = $root . '/tests/fixtures/zazie_weekly_order_es.csv';
$xlsxPath = $root . '/tests/fixtures/zazie_weekly_order.xlsx';
$csvRows = bakery_zazie_weekly_read_file($csvPath, 'zazie_weekly_order.csv');
$parsed = bakery_zazie_weekly_parse_rows($csvRows);
$assert($parsed['dates'] === [
    '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18',
], 'weekday columns become the week of 2026-10-12');
$byName = [];
foreach ($parsed['rows'] as $row) {
    $byName[$row['sheet_name']] = $row['quantities'];
}
$assert(($byName['ZW Country Loaf']['2026-10-12'] ?? null) === 4, 'country loaf Monday is 4');
$assert(($byName['ZW Country Loaf']['2026-10-13'] ?? null) === 0, 'country loaf Tuesday is 0');
$assert(($byName['ZW Baguette']['2026-10-17'] ?? null) === 4, 'baguette Saturday is 4');
$assert(($byName['ZW House Roll']['2026-10-12'] ?? null) === 1, 'house roll Monday is 1');
$assert($parsed['errors'] === [], 'fixture csv has no quantity errors');

$es = bakery_zazie_weekly_parse_rows(bakery_zazie_weekly_read_file($esPath, 'zazie_weekly_order_es.csv'));
$assert($es['dates'][0] === '2026-10-12' && $es['dates'][6] === '2026-10-18', 'Spanish weekday headings use the same week');
$assert(($es['rows'][0]['quantities']['2026-10-12'] ?? null) === 3, 'Spanish Monday quantity is 3');
$assert(($es['rows'][0]['quantities']['2026-10-17'] ?? null) === 1, 'Spanish Saturday quantity is 1');

if (!is_file($xlsxPath)) {
    zazie_test_write_xlsx($xlsxPath, $csvRows, false);
}
$xlsxParsed = bakery_zazie_weekly_parse_rows(bakery_zazie_weekly_read_file($xlsxPath, 'zazie_weekly_order.xlsx'));
$assert($xlsxParsed['dates'] === $parsed['dates'], 'xlsx dates match the csv');
$xlsxByName = [];
foreach ($xlsxParsed['rows'] as $row) {
    $xlsxByName[$row['sheet_name']] = $row['quantities'];
}
$assert($xlsxByName === $byName, 'xlsx quantities match the csv');

$tempXlsx = tempnam(sys_get_temp_dir(), 'zazie');
if ($tempXlsx === false) {
    fwrite(STDERR, "FAIL  temp xlsx path\n");
    exit(1);
}
$tempXlsxFile = $tempXlsx . '.xlsx';
@unlink($tempXlsx);
zazie_test_write_xlsx($tempXlsxFile, $csvRows, true);
$numericParsed = bakery_zazie_weekly_parse_rows(bakery_zazie_weekly_read_file($tempXlsxFile, 'numeric.xlsx'));
@unlink($tempXlsxFile);
$numericByName = [];
foreach ($numericParsed['rows'] as $row) {
    $numericByName[$row['sheet_name']] = $row['quantities'];
}
$assert($numericByName === $byName, 'numeric xlsx cells match the csv');

$base = new DateTimeImmutable('1899-12-30');
$serial = (int)round(((new DateTimeImmutable('2026-10-12'))->getTimestamp() - $base->getTimestamp()) / 86400);
$assert(bakery_zazie_excel_serial_date($serial) === '2026-10-12', 'excel serial for 2026-10-12 round trips');
$serialParsed = bakery_zazie_weekly_parse_rows([
    ['Product', (string)$serial, (string)($serial + 1)],
    ['ZW Country Loaf', '2', '5'],
]);
$assert($serialParsed['dates'] === ['2026-10-12', '2026-10-13'], 'numeric date headings become dated columns');
$assert($serialParsed['rows'][0]['quantities']['2026-10-13'] === 5, 'quantity beside a serial date is kept');

$anchored = bakery_zazie_weekly_parse_rows([
    ['Week of 2026-10-12'],
    ['Product', 'Mon', 'Tue'],
    ['ZW Country Loaf', '1', ''],
], '2030-01-07');
$assert($anchored['dates'] === ['2026-10-12', '2026-10-13'], 'a date in the sheet wins over the Monday field');

$fallback = bakery_zazie_weekly_parse_rows([
    ['Product', 'Monday', 'Tuesday'],
    ['ZW Country Loaf', '6', '7'],
], '2026-10-14');
$assert($fallback['dates'] === ['2026-10-12', '2026-10-13'], 'a Wednesday Monday-field snaps to that Monday');

$missingWeek = false;
try {
    bakery_zazie_weekly_parse_rows([
        ['Product', 'Monday', 'Tuesday'],
        ['ZW Country Loaf', '1', '2'],
    ]);
} catch (BakeryZazieWeeklyException $e) {
    $missingWeek = $e->messageKey === 'zazie_weekly.missing_week';
}
$assert($missingWeek, 'weekday columns without a date ask for the Monday');

$badQty = bakery_zazie_weekly_parse_rows([
    ['Week of', '2026-10-12'],
    ['Product', 'Monday', 'Tuesday'],
    ['ZW Country Loaf', '4.5', '1'],
]);
$assert($badQty['errors'] !== [] && $badQty['errors'][0]['key'] === 'zazie_weekly.bad_quantity', 'fractional quantities are errors');

$badFile = false;
try {
    bakery_zazie_weekly_read_file($csvPath, 'orders.xls');
} catch (BakeryZazieWeeklyException $e) {
    $badFile = $e->messageKey === 'zazie_weekly.bad_file';
}
$assert($badFile, 'only xlsx and csv are accepted');

$notXlsx = tempnam(sys_get_temp_dir(), 'zazie-txt-');
$zipAsCsv = tempnam(sys_get_temp_dir(), 'zazie-zip-');
$bombPath = tempnam(sys_get_temp_dir(), 'zazie-bomb-');
$textRejected = false;
$zipCsvRejected = false;
$bombRejected = false;
$wideRejected = false;
try {
    file_put_contents((string)$notXlsx, "Product,Monday\nLoaf,1\n");
    try {
        bakery_zazie_weekly_read_file((string)$notXlsx, 'week.xlsx');
    } catch (BakeryZazieWeeklyException $e) {
        $textRejected = $e->messageKey === 'zazie_weekly.bad_file';
    }
    copy($xlsxPath, (string)$zipAsCsv);
    try {
        bakery_zazie_weekly_read_file((string)$zipAsCsv, 'week.csv');
    } catch (BakeryZazieWeeklyException $e) {
        $zipCsvRejected = $e->messageKey === 'zazie_weekly.bad_file';
    }
    $bomb = new ZipArchive();
    if ($bomb->open((string)$bombPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $bomb->addFromString('xl/worksheets/sheet1.xml', str_repeat('A', (3 * 1024 * 1024)));
        $bomb->close();
        try {
            bakery_zazie_weekly_read_file((string)$bombPath, 'week.xlsx');
        } catch (BakeryZazieWeeklyException $e) {
            $bombRejected = $e->messageKey === 'zazie_weekly.file_too_large';
        }
    }
    try {
        bakery_zazie_xlsx_rows(
            '<?xml version="1.0"?><worksheet><sheetData><row><c r="ZZZZ1"><v>1</v></c></row></sheetData></worksheet>',
            []
        );
    } catch (BakeryZazieWeeklyException $e) {
        $wideRejected = $e->messageKey === 'zazie_weekly.bad_file';
    }
} finally {
    @unlink((string)$notXlsx);
    @unlink((string)$zipAsCsv);
    @unlink((string)$bombPath);
}
$assert($textRejected, 'a text file renamed to xlsx is rejected');
$assert($zipCsvRejected, 'a zip renamed to csv is rejected');
$assert($bombRejected, 'an xlsx entry larger than 2 MB uncompressed is rejected');
$assert($wideRejected, 'a sheet cell past column 64 is rejected');

$page = (string)file_get_contents($root . '/zazie_weekly_sheet.php');
$csrfAt = strpos($page, 'bakery_require_csrf()');
$previewAt = strpos($page, 'bakery_zazie_weekly_begin_preview');
$mapAt = strpos($page, 'bakery_zazie_weekly_save_mapping');
$confirmAt = strpos($page, 'bakery_zazie_weekly_confirm');
$assert(
    $csrfAt !== false && $previewAt !== false && $mapAt !== false && $confirmAt !== false
        && $csrfAt < $previewAt && $csrfAt < $mapAt && $csrfAt < $confirmAt,
    'preview, mapping, and confirm POSTs are behind the CSRF check'
);
$assert(strpos($page, "htmlspecialchars((string)\$line['sheet_name'])") !== false, 'uploaded sheet names are escaped');
$assert(strpos($page, "in_array(\$status, ['new', 'changed', 'unchanged', 'unmapped']") !== false, 'cell status is an allowlist before it reaches the class attribute');

echo "=== Preview, mapping, apply ===\n";
$assert(column_exists($db, 'customers', 'pricing_tier'), 'customer pricing tier is installed');
$assert(column_exists($db, 'products', 'wholesale_price'), 'product wholesale price is installed');
$assert(table_exists($db, 'operational_events'), 'operational events are installed');

$productNames = ['ZW Country Loaf', 'ZW Baguette', 'ZW Dinner Roll', 'ZW House Roll'];
$sheetKeys = ['zw country loaf', 'zw baguette', 'zw house roll', 'zw dinner roll'];
$dates = $parsed['dates'];
$createdCustomer = false;
$previousTier = null;

$cleanup = static function () use ($db, $productNames, $sheetKeys, $dates, &$createdCustomer): void {
    $ids = $db->query(
        "SELECT id FROM products WHERE name IN ('ZW Country Loaf', 'ZW Baguette', 'ZW Dinner Roll', 'ZW House Roll')"
    )->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_map('intval', $ids);
    $customerId = (int)$db->query("SELECT id FROM customers WHERE name = 'Zazie' LIMIT 1")->fetchColumn();
    if ($customerId > 0 && $dates !== []) {
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $orderStmt = $db->prepare("SELECT id FROM daily_orders WHERE customer_id = ? AND order_date IN ($placeholders)");
        $orderStmt->execute(array_merge([$customerId], $dates));
        $orderIds = array_map('intval', $orderStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($orderIds !== []) {
            $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($orderPlaceholders)")->execute($orderIds);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($orderPlaceholders)")->execute($orderIds);
        }
        $db->prepare("DELETE FROM operational_events WHERE customer_id = ? AND summary LIKE 'Zazie weekly sheet%'")->execute([$customerId]);
    }
    if ($sheetKeys !== []) {
        $keyPlaceholders = implode(',', array_fill(0, count($sheetKeys), '?'));
        $db->prepare("DELETE FROM zazie_sheet_product_map WHERE sheet_key IN ($keyPlaceholders)")->execute($sheetKeys);
    }
    if ($ids !== []) {
        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE product_id IN ($idPlaceholders)")->execute($ids);
        $db->prepare("DELETE FROM products WHERE id IN ($idPlaceholders)")->execute($ids);
    }
    if ($createdCustomer && $customerId > 0) {
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
    }
};
$cleanup();

try {
    $existingCustomer = $db->query("SELECT id, pricing_tier FROM customers WHERE name = 'Zazie' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($existingCustomer) {
        $customerId = (int)$existingCustomer['id'];
        $previousTier = (string)$existingCustomer['pricing_tier'];
        $db->prepare("UPDATE customers SET pricing_tier = 'wholesale', is_active = 1 WHERE id = ?")->execute([$customerId]);
        if (column_exists($db, 'customers', 'sfb_origin')) {
            $db->prepare("UPDATE customers SET sfb_origin = 'human' WHERE id = ? AND (sfb_origin IS NULL OR sfb_origin = '')")->execute([$customerId]);
        }
    } else {
        $columns = 'name, address, is_active, pricing_tier';
        $values = '?, ?, 1, ?';
        $params = ['Zazie', '941 Cole St', 'wholesale'];
        if (column_exists($db, 'customers', 'sfb_origin')) {
            $columns .= ', sfb_origin';
            $values .= ', ?';
            $params[] = 'human';
        }
        $db->prepare("INSERT INTO customers ($columns) VALUES ($values)")->execute($params);
        $customerId = (int)$db->lastInsertId();
        $createdCustomer = true;
    }

    $insertProduct = $db->prepare('INSERT INTO products (name, price, wholesale_price) VALUES (?, ?, ?)');
    $insertProduct->execute(['ZW Country Loaf', 9.00, 4.50]);
    $countryId = (int)$db->lastInsertId();
    $insertProduct->execute(['ZW Baguette', 8.00, 3.25]);
    $baguetteId = (int)$db->lastInsertId();
    $insertProduct->execute(['ZW Dinner Roll', 2.00, 0.50]);
    $dinnerId = (int)$db->lastInsertId();

    $customer = bakery_zazie_weekly_customer($db);
    $assert($customer !== null && (int)$customer['id'] === $customerId, 'the active customer named Zazie is the upload target');

    $preview = bakery_zazie_weekly_build_preview($db, $customer, $parsed);
    $house = null;
    $country = null;
    foreach ($preview['lines'] as $line) {
        if ($line['sheet_name'] === 'ZW House Roll') {
            $house = $line;
        }
        if ($line['sheet_name'] === 'ZW Country Loaf') {
            $country = $line;
        }
    }
    $assert($house !== null && $house['unmapped'] === true, 'an unknown sheet name starts unmapped');
    $assert($country !== null && (int)$country['product_id'] === $countryId, 'a matching product name maps without a saved row');
    $assert($preview['counts']['unmapped'] === 1, 'one unmapped product row');
    $assert($country['cells']['2026-10-12']['status'] === 'new', 'a missing dated line is new');

    $folded = bakery_zazie_weekly_parse_rows([
        ['Week of', '2026-10-12'],
        ['Product', 'Monday', 'Tuesday'],
        ['zw  country loaf', '4', '0'],
    ]);
    $foldedPreview = bakery_zazie_weekly_build_preview($db, $customer, $folded);
    $assert((int)$foldedPreview['lines'][0]['product_id'] === $countryId, 'extra spaces and case still match the product');

    bakery_zazie_weekly_save_mapping($db, 'ZW House Roll', $dinnerId);
    $insertProduct->execute(['ZW House Roll', 1.00, 1.00]);
    $houseProductId = (int)$db->lastInsertId();
    $mapped = bakery_zazie_weekly_build_preview($db, $customer, $parsed);
    $houseMapped = null;
    foreach ($mapped['lines'] as $line) {
        if ($line['sheet_name'] === 'ZW House Roll') {
            $houseMapped = $line;
        }
    }
    $assert($houseMapped !== null && (int)$houseMapped['product_id'] === $dinnerId, 'a saved mapping wins over a product with the same name');
    $remembered = bakery_zazie_weekly_mapping_rows($db);
    $rememberedKeys = array_column($remembered, 'sheet_key');
    $assert(in_array('zw house roll', $rememberedKeys, true), 'the mapping is remembered');

    $db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, '2026-10-12', 'pending', 0)")->execute([$customerId]);
    $mondayOrder = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 4, 1.00, 4.00)')->execute([$mondayOrder, $countryId]);
    $db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, '2026-10-13', 'pending', 0)")->execute([$customerId]);
    $tuesdayOrder = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 1, 1.00, 1.00)')->execute([$tuesdayOrder, $baguetteId]);

    $diff = bakery_zazie_weekly_build_preview($db, $customer, $parsed);
    $diffCountry = null;
    $diffBaguette = null;
    foreach ($diff['lines'] as $line) {
        if ((int)$line['product_id'] === $countryId) {
            $diffCountry = $line;
        }
        if ((int)$line['product_id'] === $baguetteId) {
            $diffBaguette = $line;
        }
    }
    $assert($diffCountry['cells']['2026-10-12']['status'] === 'unchanged', 'the same Monday quantity is unchanged');
    $assert($diffBaguette['cells']['2026-10-13']['status'] === 'changed', 'a different Tuesday quantity is changed');
    $assert($diff['counts']['unmapped'] === 0, 'the saved name is no longer unmapped');

    $refused = false;
    try {
        bakery_zazie_weekly_apply($db, $customer, bakery_zazie_weekly_build_preview($db, $customer, $badQty));
    } catch (BakeryZazieWeeklyException $e) {
        $refused = $e->messageKey === 'zazie_weekly.has_errors';
    }
    $assert($refused, 'confirm refuses a sheet with a bad quantity');

    $eventBefore = (int)$db->query("SELECT COUNT(*) FROM operational_events WHERE customer_id = " . $customerId . " AND summary LIKE 'Zazie weekly sheet%'")->fetchColumn();
    $session = [];
    bakery_zazie_weekly_begin_preview($db, $csvPath, 'zazie_weekly_order.csv', null, $session);
    $sessionPreview = bakery_zazie_weekly_session_preview($db, $session);
    $assert(is_array($sessionPreview) && $sessionPreview['token'] !== '', 'upload stores a preview token');
    $expired = false;
    try {
        bakery_zazie_weekly_confirm($db, 'not-the-token', $session);
    } catch (BakeryZazieWeeklyException $e) {
        $expired = $e->messageKey === 'zazie_weekly.preview_expired';
    }
    $assert($expired, 'confirm rejects a token that is not the preview');
    $assert(isset($session['zazie_weekly_preview']), 'a rejected confirm leaves the preview in place');

    $first = bakery_zazie_weekly_confirm($db, (string)$sessionPreview['token'], $session);
    $assert($first['applied'] > 0, 'confirm writes the new and changed lines');
    $assert(!isset($session['zazie_weekly_preview']), 'confirm consumes the preview');

    $mondayQty = zazie_test_qty($db, $customerId, '2026-10-12', $countryId);
    $tuesdayQty = zazie_test_qty($db, $customerId, '2026-10-13', $baguetteId);
    $saturdayQty = zazie_test_qty($db, $customerId, '2026-10-17', $baguetteId);
    $dinnerMonday = zazie_test_qty($db, $customerId, '2026-10-12', $dinnerId);
    $houseProductMonday = zazie_test_qty($db, $customerId, '2026-10-12', $houseProductId);
    $assert($mondayQty === 4, 'unchanged Monday country loaf stays 4');
    $assert($tuesdayQty === 2, 'Tuesday baguette changes from 1 to 2');
    $assert($saturdayQty === 4, 'Saturday baguette is created at 4');
    $assert($dinnerMonday === 1, 'the mapped house roll writes the dinner roll');
    $assert($houseProductMonday === 0, 'the same-named product is not what got the quantity');

    $customer = bakery_zazie_weekly_customer($db);
    $countryProduct = bakery_customer_product_row($db, $countryId);
    $expectedPrice = round((float)bakery_resolve_customer_price($db, $customer, $countryProduct), 2);
    $assert($expectedPrice === 4.50, 'resolver uses the wholesale price, not the catalog price');
    $storedPrice = zazie_test_price($db, $customerId, '2026-10-17', $baguetteId);
    $baguetteProduct = bakery_customer_product_row($db, $baguetteId);
    $baguetteExpected = round((float)bakery_resolve_customer_price($db, $customer, $baguetteProduct), 2);
    $assert($storedPrice === $baguetteExpected, 'new lines store bakery_resolve_customer_price');

    $eventAfter = (int)$db->query("SELECT COUNT(*) FROM operational_events WHERE customer_id = " . $customerId . " AND summary LIKE 'Zazie weekly sheet%'")->fetchColumn();
    $assert($eventAfter === $eventBefore + (int)$first['applied'], 'each applied change logs one operational event');
    $meta = $db->query(
        "SELECT metadata FROM operational_events
         WHERE customer_id = " . $customerId . " AND summary LIKE 'Zazie weekly sheet%'
         ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    $decoded = json_decode((string)$meta, true);
    $assert(is_array($decoded) && ($decoded['source'] ?? '') === 'zazie_weekly_sheet', 'the event records the weekly sheet as its source');

    $db->prepare(
        'UPDATE daily_order_items doi
         INNER JOIN daily_orders do ON do.id = doi.daily_order_id
         SET doi.unit_price = 9.99, doi.line_total = 9.99 * doi.quantity
         WHERE do.customer_id = ? AND do.order_date = ? AND doi.product_id = ?'
    )->execute([$customerId, '2026-10-13', $baguetteId]);
    $second = bakery_zazie_weekly_apply($db, $customer, $diff);
    $assert($second['applied'] === 0, 'applying the same sheet again changes nothing');
    $assert(zazie_test_price($db, $customerId, '2026-10-13', $baguetteId) === 9.99, 'a second apply does not rewrite the price');
    $assert(zazie_test_qty($db, $customerId, '2026-10-13', $baguetteId) === 2, 'a second apply keeps the quantity');
    $eventFinal = (int)$db->query("SELECT COUNT(*) FROM operational_events WHERE customer_id = " . $customerId . " AND summary LIKE 'Zazie weekly sheet%'")->fetchColumn();
    $assert($eventFinal === $eventAfter, 'a second apply logs no operational event');

    $session = [];
    bakery_zazie_weekly_begin_preview($db, $csvPath, 'order.csv', '2031-01-06', $session);
    $again = bakery_zazie_weekly_session_preview($db, $session);
    $assert(is_array($again) && (int)$again['counts']['new'] === 0 && (int)$again['counts']['changed'] === 0, 're-uploading the same file previews no changes');
    $third = bakery_zazie_weekly_confirm($db, (string)$again['token'], $session);
    $assert($third['applied'] === 0, 'confirming that re-upload writes nothing');
} finally {
    if ($previousTier !== null && !$createdCustomer) {
        $restoreId = (int)$db->query("SELECT id FROM customers WHERE name = 'Zazie' LIMIT 1")->fetchColumn();
        if ($restoreId > 0) {
            $db->prepare('UPDATE customers SET pricing_tier = ? WHERE id = ?')->execute([$previousTier, $restoreId]);
        }
    }
    $cleanup();
}

echo "=== Page contracts ===\n";
$page = (string)file_get_contents($root . '/zazie_weekly_sheet.php');
$helper = (string)file_get_contents($root . '/includes/zazie_weekly_sheet.php');
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$assert(strpos($page, "bakery_require_role(['administrator', 'manager'])") !== false, 'the page allows administrator and manager');
$assert(strpos($page, 'includes/database.php') !== false, 'the page boots through the auth gate');
$assert(strpos($page, "=== 'confirm'") !== false, 'apply is behind the confirm action');
$assert(strpos($page, 'bakery_zazie_weekly_confirm') !== false, 'the page confirms through the helper');
$assert(strpos($page, 'bakery_demand_set_dated_quantity') === false, 'the page does not write dated orders itself');
$assert(strpos($page, 'bakery_resolve_customer_price') === false, 'the page does not price lines itself');
$assert(strpos($helper, 'bakery_demand_set_dated_quantity') !== false, 'apply uses the dated-order writer');
$assert(strpos($helper, 'wholesale_price') === false, 'the helper does not reimplement pricing');
$assert(strpos($manifest, 'zazie_weekly_sheet.php') !== false, 'the page is on the deploy root list');
$roles = bakery_navigation_roles_for_script('zazie_weekly_sheet.php');
sort($roles);
$assert($roles === ['administrator', 'manager'], 'navigation allows only administrator and manager');

$dashFiles = [
    $root . '/zazie_weekly_sheet.php',
    $root . '/includes/zazie_weekly_sheet.php',
    $root . '/database/schema/090_zazie_sheet_product_map.sql',
    $csvPath,
    $esPath,
];
foreach ($dashFiles as $path) {
    $text = (string)file_get_contents($path);
    $assert(strpos($text, "\u{2014}") === false && strpos($text, "\u{2013}") === false, basename($path) . ' has no en dash or em dash');
}

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$enKeys = array_keys($en);
$esKeys = array_keys($es);
$start = array_search('nav.item.zazie_weekly_sheet', $enKeys, true);
$ux = array_search('ux.stage.demand', $enKeys, true);
$block = array_slice($enKeys, (int)$start, (int)$ux - (int)$start);
$blockOk = $start !== false && $ux !== false && $block !== [];
foreach ($block as $key) {
    if (!str_starts_with((string)$key, 'zazie_weekly.') && !str_starts_with((string)$key, 'nav.item.zazie_weekly_sheet') && !str_starts_with((string)$key, 'nav.item_desc.zazie_weekly_sheet')) {
        $blockOk = false;
    }
    if (!isset($es[$key]) || $es[$key] === '' || $es[$key] === $en[$key]) {
        $blockOk = false;
    }
    if (strpos((string)$en[$key], "\u{2014}") !== false || strpos((string)$en[$key], "\u{2013}") !== false) {
        $blockOk = false;
    }
    if (strpos((string)$es[$key], "\u{2014}") !== false || strpos((string)$es[$key], "\u{2013}") !== false) {
        $blockOk = false;
    }
}
$esStart = array_search('nav.item.zazie_weekly_sheet', $esKeys, true);
$esUx = array_search('ux.stage.demand', $esKeys, true);
$esBlock = array_slice($esKeys, (int)$esStart, (int)$esUx - (int)$esStart);
$assert($blockOk && $block === $esBlock, 'Zazie strings are one translated block immediately before ux');

echo "\n" . ($fail === 0 ? 'ALL PASS' : ($fail . ' FAILED')) . "\n";
exit($fail === 0 ? 0 : 1);

function zazie_test_qty(PDO $db, int $customerId, string $date, int $productId): int
{
    $stmt = $db->prepare(
        'SELECT doi.quantity
         FROM daily_order_items doi
         INNER JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doi.product_id = ?
         LIMIT 1'
    );
    $stmt->execute([$customerId, $date, $productId]);
    $qty = $stmt->fetchColumn();
    return $qty === false ? 0 : (int)$qty;
}

function zazie_test_price(PDO $db, int $customerId, string $date, int $productId): float
{
    $stmt = $db->prepare(
        'SELECT doi.unit_price
         FROM daily_order_items doi
         INNER JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doi.product_id = ?
         LIMIT 1'
    );
    $stmt->execute([$customerId, $date, $productId]);
    return round((float)$stmt->fetchColumn(), 2);
}

/**
 * @param list<list<string>> $rows
 */
function zazie_test_write_xlsx(string $path, array $rows, bool $numericQuantities): void
{
    $strings = [];
    $index = [];
    $intern = static function (string $value) use (&$strings, &$index): int {
        if (!isset($index[$value])) {
            $index[$value] = count($strings);
            $strings[] = $value;
        }
        return $index[$value];
    };
    $sheetRows = '';
    foreach ($rows as $r => $row) {
        $cells = '';
        foreach ($row as $c => $value) {
            $column = zazie_test_column((int)$c);
            $ref = $column . ($r + 1);
            $text = (string)$value;
            $asNumber = $numericQuantities && $r > 2 && $c > 0 && preg_match('/^\d+(\.0+)?$/', $text);
            if ($asNumber) {
                $cells .= '<c r="' . $ref . '"><v>' . $text . '</v></c>';
            } else {
                $cells .= '<c r="' . $ref . '" t="s"><v>' . $intern($text) . '</v></c>';
            }
        }
        $sheetRows .= '<row r="' . ($r + 1) . '">' . $cells . '</row>';
    }
    $shared = '';
    foreach ($strings as $value) {
        $shared .= '<si><t xml:space="preserve">' . htmlspecialchars($value, ENT_XML1) . '</t></si>';
    }
    $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($strings) . '" uniqueCount="' . count($strings) . '">'
        . $shared . '</sst>';
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        . $sheetRows . '</sheetData></worksheet>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Week" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>';
    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '</Types>';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not write xlsx fixture');
    }
    $zip->addFromString('[Content_Types].xml', $types);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
}

function zazie_test_column(int $index): string
{
    $index++;
    $letters = '';
    while ($index > 0) {
        $index--;
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26);
    }
    return $letters;
}
