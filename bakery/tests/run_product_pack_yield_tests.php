<?php
/**
 * Product pack yields: gallon / tray / barra → pieces.
 *
 * CLI / local bakerysf_test only. Applies migration 052 if needed.
 * Does not rewrite daily orders.
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
require_once $root . '/includes/product_pack_yields.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
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
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
};

// Ensure 052 is present on bakerysf_test (idempotent).
$schema = $root . '/database/schema/052_product_pack_yields.sql';
if (!bakery_pack_yields_ready($db)) {
    echo "Applying 052_product_pack_yields to bakerysf_test...\n";
    bakery_run_sql_file($db, $schema);
}
$assert(bakery_pack_yields_ready($db), 'pack yield tables ready');

$productId = static function (PDO $db, string $name): int {
    $stmt = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('Missing product: ' . $name);
    }
    return $id;
};

$cortadillos = $productId($db, 'Cortadillos');
$colchon = $productId($db, 'Colchón');
$budin = $productId($db, 'Budín');
$barras = $productId($db, 'Barras');
$conchas = $productId($db, 'Conchas');

$assert(bakery_pack_to_pieces($db, $cortadillos, 1.0, 'tray') === 33, '1 tray Cortadillos → 33');
$assert(bakery_pack_to_pieces($db, $colchon, 1.0, 'tray') === 32, '1 tray Colchón → 32');
$assert(bakery_pack_to_pieces($db, $budin, 1.0, 'tray') === 40, '1 tray Budín → 40');
$assert(bakery_pack_to_pieces($db, $barras, 1.0, 'barra') === 1, '1 Barras → 1 piece');
$assert(bakery_pack_to_pieces($db, $barras, 120.0, 'barra') === 120, '120 Barras → 120 pieces');
$assert(bakery_pack_barra_to_rebanada($db, 2.0) === 12, '2 Barras → 12 Rebanada');
$assert(bakery_pack_to_pieces($db, $conchas, 3.0, 'gallon') === 880, '3 gal Conchas → 880');
$assert(bakery_pack_to_pieces($db, $conchas, 1.5, 'gallon') === 440, '1.5 gal Conchas → 440');

$fino = bakery_pack_fino_split($db, 3.0);
$finoTotal = array_sum($fino);
$assert($finoTotal === 880, '3 gal Fino total pieces → 880');
$assert(count($fino) === 5, 'Fino splits across 5 SKUs');
$assert(min($fino) === 176 && max($fino) === 176, 'Fino even split 176 each');

$budinResolved = bakery_pack_resolve_product($db, 'pudin');
$assert($budinResolved === $budin, 'alias pudin → Budín');
$assert(bakery_pack_resolve_product($db, 'Pudín') === $budin, 'alias Pudín (accent) → Budín');
$assert(bakery_pack_resolve_product($db, 'gragea') === $productId($db, 'Grajea'), 'alias gragea → Grajea');
$assert(bakery_pack_resolve_product($db, 'queiquitos') === $productId($db, 'Quequitos'), 'alias queiquitos → Quequitos');
$assert(bakery_pack_normalize_alias('  Pingüino  ') === 'pinguino', 'normalize strips accent and trim');

// Default unit from product row
$assert(bakery_pack_to_pieces($db, $cortadillos, 2.0) === 66, 'Cortadillos default unit tray: 2 → 66');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
