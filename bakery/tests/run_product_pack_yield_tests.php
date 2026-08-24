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
$schema059 = $root . '/database/schema/059_bolillo_and_gallon_estimates.sql';
bakery_run_sql_file_safe($db, $schema059);
$schema060 = $root . '/database/schema/060_mantecada_batch_and_piece_weights.sql';
bakery_run_sql_file_safe($db, $schema060);
$schema065 = $root . '/database/schema/065_product_pack_boxes.sql';
bakery_run_sql_file_safe($db, $schema065);
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
$assert(bakery_pack_to_pieces($db, $barras, 4.5, 'gallon') === 50, '4.5 gal Mantecada all-barras → 50');
$quequitos = $productId($db, 'Quequitos');
$assert(bakery_pack_to_pieces($db, $quequitos, 1.0, 'tray') === 20, '1 tray Quequitos → 20');
$assert(bakery_pack_to_pieces($db, $quequitos, 4.5, 'gallon') === 400, '4.5 gal all-quequitos → 400');
$assert(bakery_pack_to_pieces($db, $cortadillos, 4.5, 'gallon') === 660, '4.5 gal all-cortadillos → 660');
$weightOf = static function (PDO $db, string $name): int {
    $stmt = $db->prepare('SELECT weight_grams FROM products WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    return (int)$stmt->fetchColumn();
};
$assert($weightOf($db, 'Barras') === 662, 'Barras piece weight 662 g (1.5 batch / 50)');
$assert($weightOf($db, 'Quequitos') === 83, 'Quequitos piece weight 83 g (1.5 batch / 400)');
$assert($weightOf($db, 'Cortadillos') === 50, 'Cortadillos piece weight 50 g (1.5 batch / 660)');
$assert($weightOf($db, 'Colchón') === 52, 'Colchón piece weight 52 g (1.5 batch / 640)');
$assert(bakery_pack_barra_to_rebanada($db, 2.0) === 12, '2 Barras → 12 Rebanada');
$nuezBare = $productId($db, 'Nuez');
$db->prepare('DELETE FROM product_pack_yields WHERE product_id = ?')->execute([$nuezBare]);
$nuezBarePcs = bakery_pack_to_pieces($db, $nuezBare, 1.0, 'gallon');
$assert($nuezBarePcs > 0, '1 gal Nuez without pack-yield row does not throw');
bakery_pack_ensure_defaults($db);

$assert(bakery_pack_to_pieces($db, $conchas, 3.0, 'gallon') === 880, '3 gal Conchas → 880');
$assert(bakery_pack_to_pieces($db, $conchas, 1.5, 'gallon') === 440, '1.5 gal Conchas → 440');

$fino = bakery_pack_fino_split($db, 3.0);
$finoTotal = array_sum($fino);
$assert($finoTotal === 880, '3 gal Fino total pieces → 880');
$assert(count($fino) === 5, 'Fino splits across 5 SKUs');
$elotes = $productId($db, 'Elotes');
$cuerno = $productId($db, 'Cuerno Azucar');
$tostado = $productId($db, 'Tostado');
$assert(($fino[$elotes] ?? 0) === 352, 'Fino 3 gal Elotes 40% → 352');
$assert(($fino[$cuerno] ?? 0) === 352, 'Fino 3 gal Cuerno 40% → 352');
$assert(($fino[$tostado] ?? 0) === 70, 'Fino 3 gal Tostado 8% → 70');
$assert(($fino[$elotes] ?? 0) > ($fino[$tostado] ?? 0), 'Fino primary SKUs exceed the small three');

$budinResolved = bakery_pack_resolve_product($db, 'pudin');
$assert($budinResolved === $budin, 'alias pudin → Budín');
$assert(bakery_pack_resolve_product($db, 'Pudín') === $budin, 'alias Pudín (accent) → Budín');
$assert(bakery_pack_resolve_product($db, 'gragea') === $productId($db, 'Grajea'), 'alias gragea → Grajea');
$assert(bakery_pack_resolve_product($db, 'queiquitos') === $productId($db, 'Quequitos'), 'alias queiquitos → Quequitos');
$assert(bakery_pack_normalize_alias('  Pingüino  ') === 'pinguino', 'normalize strips accent and trim');

// Default unit from product row
$assert(bakery_pack_to_pieces($db, $cortadillos, 2.0) === 66, 'Cortadillos default unit tray: 2 → 66');

$kitchen = <<<'TXT'
Buenos días ☀️
3.0 de concha
3.0 de fino

1 de picón

120 barras
25 cortadillos
15 colchones
25 queiquitos
10 pudin
1.nuez
1 de guayaba
2 taco / gragea 1 y 1
1.0 puerco
2 de amarilla
2 de rosada
2 de chocolate

1 bolillo
TXT;
$parsed = bakery_pack_parse_kitchen_note($db, $kitchen);
$assert($parsed['by_product'][$conchas] === 880, 'kitchen note: 3 gal concha → 880');
$assert(array_sum(array_intersect_key($parsed['by_product'], bakery_pack_fino_split($db, 3.0))) === 880, 'kitchen note: fino 880 in split SKUs');
$rebanada = $productId($db, 'Barra (Rebanada)');
$assert(($parsed['by_product'][$barras] ?? 0) === 24, 'kitchen note: 20% of 120 barras kept whole');
$assert(($parsed['by_product'][$rebanada] ?? 0) === 576, 'kitchen note: 96 barras → 576 rebanadas');
$assert($parsed['by_product'][$cortadillos] === 825, 'kitchen note: 25 trays cortadillos');
$assert($parsed['by_product'][$colchon] === 480, 'kitchen note: 15 trays colchón');
$assert($parsed['by_product'][$budin] === 400, 'kitchen note: 10 trays pudin');
$assert(bakery_pack_batch_label($db, $budin, 400) === '10 trays · 400 pcs', '400 Budín reverses to 10 trays');
$assert(!in_array('1 bolillo', $parsed['unknown'], true), 'kitchen note: bolillo is in catalog');
$bolillo = $productId($db, 'Bolillo');
$assert(($parsed['by_product'][$bolillo] ?? 0) === 80, 'kitchen note: 1 bolillo batch → 80');
$nuezId = $productId($db, 'Nuez');
$assert(($parsed['by_product'][$nuezId] ?? 0) === 80, 'kitchen note: 1 gal nuez estimate 80');
$tacoId = $productId($db, 'Taco');
$grajeaId = $productId($db, 'Grajea');
$assert(($parsed['by_product'][$tacoId] ?? 0) === 72, 'kitchen note: 1 gal taco estimate 72');
$assert(($parsed['by_product'][$grajeaId] ?? 0) === 80, 'kitchen note: 1 gal grajea estimate 80');
$amarilla = $productId($db, 'Polvoron Amarilla');
$assert(($parsed['by_product'][$amarilla] ?? 0) === 200, 'kitchen note: 2 gal amarilla estimate 200');
$liso = $productId($db, 'Liso');
$gusano = $productId($db, 'Gusano');
$picon = bakery_pack_picon_split($db, 1.0);
$assert(($picon[$liso] ?? 0) > ($picon[$gusano] ?? 0), 'picon Liso exceeds handful SKUs');
$piconPieces = 0;
foreach (bakery_pack_picon_split($db, 1.0) as $pid => $pcs) {
    $piconPieces += (int)($parsed['by_product'][$pid] ?? 0);
}
$assert($piconPieces === array_sum(bakery_pack_picon_split($db, 1.0)), 'kitchen note: 1 gal picon matches picon split');

$todayKitchen = <<<'TXT'
Buenos días ☀️

3.0 de concha
3.0 de fino

130 barras
25 cortadillos
15 colchones
20 queiquitos
10 pudin
1 de nuez
2 taco / gragea
1. puerco
2 de amarilla
2 de rosada
2 de chocolate

1 bolillo
2 de bolillo
TXT;
$today = bakery_pack_parse_kitchen_note($db, $todayKitchen);
$assert(($today['unknown'] ?? []) === [], 'today kitchen note has no unknown lines');
$assert(($today['by_product'][$tacoId] ?? 0) === 72, '2 taco / gragea without 1 y 1 → 1 gal taco');
$assert(($today['by_product'][$grajeaId] ?? 0) === 80, '2 taco / gragea without 1 y 1 → 1 gal grajea');
$assert(($today['by_product'][$bolillo] ?? 0) === 240, '1 bolillo + 2 de bolillo → 3 batches');
$assert(($today['by_product'][$nuezId] ?? 0) === 80, '1 de nuez → 80');
$puercoId = $productId($db, 'Puerco');
$assert(($today['by_product'][$puercoId] ?? 0) === 48, '1. puerco → 1 gal');
$assert(($today['by_product'][$barras] ?? 0) === 26, '20% of 130 barras kept whole');
$assert(($today['by_product'][$rebanada] ?? 0) === 624, '104 barras → 624 rebanadas');
$quequitos = $productId($db, 'Quequitos');
$assert(($today['by_product'][$quequitos] ?? 0) === 400, '20 trays queiquitos → 400');
$todayPlan = bakery_pack_kitchen_plan_with_zeros($db, $today['by_product']);
$guayabaId = $productId($db, 'Guayaba');
$assert(($todayPlan[$guayabaId] ?? -1) === 0, 'omitted guayaba is zeroed');
$assert(($todayPlan[$liso] ?? -1) === 0, 'omitted picón Liso is zeroed');
$assert(($todayPlan[$conchas] ?? 0) === 880, 'zeros helper keeps 3 gal concha');

$nuezScale = bakery_pack_input_scale($db, $nuezId);
$assert($nuezScale['unit'] === 'gallon' && (int)$nuezScale['pcs_per'] === 80, 'Nuez scale is 80 pcs/gal');
$budinScale = bakery_pack_input_scale($db, $budin);
$assert($budinScale['unit'] === 'tray' && (int)$budinScale['pcs_per'] === 40, 'Budín scale is 40 pcs/tray');
$sheet = bakery_pack_formula_sheet($db, $conchas, 880);
$assert($sheet['product'] === 'Conchas', 'formula sheet names Conchas');
$assert($sheet['lines'] !== [] || $sheet['note'] === 'No formula', 'formula sheet returns lines or no-formula note');
if ($sheet['lines'] !== []) {
    $assert($sheet['dough_grams'] > 0, 'formula sheet scales dough grams from pieces');
}

$conchaBreak = bakery_pack_count_breakdown($db, $conchas, 440);
$assert($conchaBreak['pieces'] === 440, 'concha breakdown keeps piece count');
$assert(($conchaBreak['pieces_per_tray'] ?? 0) === 20, 'conchas convert at 20 pcs/tray');
$assert($conchaBreak['trays'] === 22 && $conchaBreak['tray_remainder'] === 0, '440 conchas → 22 trays');
$assert(strpos($conchaBreak['label'], '22 tray') !== false, 'concha label names trays');

$colHasBox = (bool)$db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_pack_yields' AND COLUMN_NAME = 'pieces_per_box'"
)->fetchColumn();
if ($colHasBox) {
    $db->prepare('UPDATE product_pack_yields SET pieces_per_box = 40 WHERE product_id = ?')->execute([$conchas]);
    $boxBreak = bakery_pack_count_breakdown($db, $conchas, 440);
    $assert($boxBreak['boxes'] === 11 && $boxBreak['box_remainder'] === 0, '440 conchas → 11 boxes of 40');
    $savedBox = bakery_pack_save_count_units($db, $conchas, 20, 40);
    $assert((int)$savedBox['pieces_per_box'] === 40, 'pack unit save writes pieces_per_box');
    $db->prepare('UPDATE product_pack_yields SET pieces_per_box = NULL WHERE product_id = ?')->execute([$conchas]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
