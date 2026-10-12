<?php
/**
 * Catalog gaps for active products and ingredients.
 * Piece formulas use bakery_formula_product_piece_grams.
 * Ingredient cost uses bakery_ingredient_current_cost_per_kg.
 * Weight and price follow the product editor: empty stays unset,
 * zero weight is stored as null, and a negative number is rejected.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/cost_margin.php';
require_once __DIR__ . '/operational_timeline.php';

/**
 * Same rules as products.php price (blank stays unset) and the editor's min=0 check.
 *
 * @return array{apply:bool, value:?float, error:?string}
 */
function bakery_catalog_gap_normalize_price($raw): array
{
    if ($raw === null || (is_string($raw) && trim($raw) === '')) {
        return ['apply' => false, 'value' => null, 'error' => null];
    }
    if (is_string($raw)) {
        $raw = trim($raw);
    }
    if (!is_numeric($raw)) {
        return ['apply' => false, 'value' => null, 'error' => 'catalog_gaps.error_price'];
    }
    $number = (float)$raw;
    if (!is_finite($number) || $number < 0) {
        return ['apply' => false, 'value' => null, 'error' => 'catalog_gaps.error_price'];
    }
    return ['apply' => true, 'value' => round($number, 2), 'error' => null];
}

/**
 * Same rules as products.php weight_grams ?: null and the editor's min=0 check.
 *
 * @return array{apply:bool, value:?float, error:?string}
 */
function bakery_catalog_gap_normalize_weight($raw): array
{
    if ($raw === null || $raw === false || (is_string($raw) && trim($raw) === '')) {
        return ['apply' => false, 'value' => null, 'error' => null];
    }
    if (is_string($raw)) {
        $raw = trim($raw);
    }
    if (!is_numeric($raw)) {
        return ['apply' => false, 'value' => null, 'error' => 'catalog_gaps.error_weight'];
    }
    $number = (float)$raw;
    if (!is_finite($number) || $number < 0) {
        return ['apply' => false, 'value' => null, 'error' => 'catalog_gaps.error_weight'];
    }
    if ($number <= 0) {
        return ['apply' => false, 'value' => null, 'error' => null];
    }
    return ['apply' => true, 'value' => $number, 'error' => null];
}

function bakery_catalog_gap_missing_weight($weight): bool
{
    if ($weight === null || $weight === '') {
        return true;
    }
    return is_numeric($weight) && (float)$weight <= 0;
}

function bakery_catalog_gap_missing_price($price): bool
{
    return $price === null || $price === '';
}

/**
 * A formula exists when the piece-grams helper can name at least one countable gram.
 * Missing weight uses a probe weight so it does not look like a missing formula.
 *
 * @param list<array<string, mixed>> $lines
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $parts
 */
function bakery_catalog_gap_has_formula(array $lines, array $structure, array $parts, $weight): bool
{
    $probe = (is_numeric($weight) && (float)$weight > 0) ? (float)$weight : 100.0;
    $piece = bakery_formula_product_piece_grams($lines, $structure, $parts, $probe);
    foreach ($piece['ingredients'] ?? [] as $ingredient) {
        if (!is_array($ingredient) || empty($ingredient['counts_in_totals'])) {
            continue;
        }
        if ((float)($ingredient['grams'] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{missing_weight:int, missing_price:int, no_formula:int, no_cost_or_supplier:int}
 */
function bakery_catalog_gap_counts(array $rows): array
{
    $counts = [
        'missing_weight' => 0,
        'missing_price' => 0,
        'no_formula' => 0,
        'no_cost_or_supplier' => 0,
    ];
    foreach ($rows as $row) {
        $gap = (string)($row['gap'] ?? '');
        if (isset($counts[$gap])) {
            $counts[$gap]++;
        }
    }
    return $counts;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function bakery_catalog_gap_count_rows(array $rows): int
{
    return count($rows);
}

function bakery_catalog_gap_count(PDO $db): int
{
    return count(bakery_catalog_gap_rows($db));
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_catalog_gap_rows(PDO $db): array
{
    $products = bakery_catalog_gap_products($db);
    $doughIds = [];
    $productIds = [];
    foreach ($products as $product) {
        $productIds[] = (int)$product['id'];
        if (!empty($product['dough_type_id'])) {
            $doughIds[] = (int)$product['dough_type_id'];
        }
    }
    $formulas = bakery_cost_margin_load_formulas($db, $doughIds);
    $structures = bakery_formula_structure_load_map($db, $doughIds);
    $parts = bakery_formula_parts_load_map($db, $productIds);

    $weights = [];
    $prices = [];
    $formulasMissing = [];
    foreach ($products as $product) {
        $id = (int)$product['id'];
        $doughId = (int)($product['dough_type_id'] ?? 0);
        $lines = $doughId > 0 ? ($formulas[$doughId] ?? []) : [];
        $structure = $structures[$doughId] ?? bakery_formula_normalize_structure([]);
        $productParts = $parts[$id] ?? [];
        $base = [
            'kind' => 'product',
            'id' => $id,
            'name' => (string)$product['name'],
            'weight_grams' => $product['weight_grams'],
            'price' => $product['price'],
            'dough_type_id' => $doughId > 0 ? $doughId : null,
            'href' => '',
        ];
        if (bakery_catalog_gap_missing_weight($product['weight_grams'])) {
            $row = $base;
            $row['gap'] = 'missing_weight';
            $weights[] = $row;
        }
        if (bakery_catalog_gap_missing_price($product['price'])) {
            $row = $base;
            $row['gap'] = 'missing_price';
            $prices[] = $row;
        }
        if (!bakery_catalog_gap_has_formula($lines, $structure, $productParts, $product['weight_grams'])) {
            $row = $base;
            $row['gap'] = 'no_formula';
            $row['href'] = $doughId > 0
                ? 'formulas.php?dough_type=' . $doughId . '#formula-' . $doughId
                : 'formulas.php';
            $formulasMissing[] = $row;
        }
    }

    return array_merge($weights, $prices, $formulasMissing, bakery_catalog_gap_ingredient_rows($db));
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_catalog_gap_products(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'products')) {
        return [];
    }
    $activeSql = '';
    if (function_exists('column_exists') && column_exists($db, 'products', 'is_active')) {
        $activeSql = ' WHERE COALESCE(p.is_active, 1) = 1';
    }
    return $db->query(
        'SELECT p.id, p.name, p.price, p.weight_grams, p.dough_type_id
         FROM products p' . $activeSql . '
         ORDER BY p.name, p.id'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_catalog_gap_ingredient_rows(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'ingredients')) {
        return [];
    }
    $supplierSql = (function_exists('column_exists') && column_exists($db, 'ingredients', 'supplier_name'))
        ? 'i.supplier_name'
        : 'NULL AS supplier_name';
    $activeSql = (function_exists('column_exists') && column_exists($db, 'ingredients', 'is_active'))
        ? ' WHERE COALESCE(i.is_active, 1) = 1'
        : '';
    $ingredients = $db->query(
        'SELECT i.id, i.name, ' . $supplierSql . '
         FROM ingredients i' . $activeSql . '
         ORDER BY i.name, i.id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($ingredients as $ingredient) {
        $id = (int)$ingredient['id'];
        if ($id <= 0) {
            continue;
        }
        $cost = bakery_ingredient_current_cost_per_kg($id, $db);
        $supplier = trim((string)($ingredient['supplier_name'] ?? ''));
        $vendor = '';
        if ($cost !== null && function_exists('bakery_ingredient_current_price')) {
            $current = bakery_ingredient_current_price($db, $id);
            $vendor = trim((string)($current['vendor'] ?? ''));
        }
        $hasSupplier = $supplier !== '' || $vendor !== '';
        if ($cost !== null && $hasSupplier) {
            continue;
        }
        $rows[] = [
            'gap' => 'no_cost_or_supplier',
            'kind' => 'ingredient',
            'id' => $id,
            'name' => (string)$ingredient['name'],
            'weight_grams' => null,
            'price' => null,
            'dough_type_id' => null,
            'href' => 'ingredients.php?view=prices&id=' . $id,
        ];
    }
    return $rows;
}

/**
 * @param array<int|string, mixed> $weights
 * @param array<int|string, mixed> $prices
 * @return array{ok:bool, updated:int, errors:list<string>}
 */
function bakery_catalog_gap_apply(PDO $db, array $weights, array $prices): array
{
    $plans = [];
    $errors = [];
    foreach ($weights as $id => $raw) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $norm = bakery_catalog_gap_normalize_weight($raw);
        if ($norm['error'] !== null) {
            $errors[] = $norm['error'];
            continue;
        }
        if ($norm['apply']) {
            $grams = (int)round((float)$norm['value']);
            if ($grams > 0) {
                $plans[$id]['weight_grams'] = $grams;
            }
        }
    }
    foreach ($prices as $id => $raw) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $norm = bakery_catalog_gap_normalize_price($raw);
        if ($norm['error'] !== null) {
            $errors[] = $norm['error'];
            continue;
        }
        if ($norm['apply']) {
            $plans[$id]['price'] = round((float)$norm['value'], 2);
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'updated' => 0, 'errors' => array_values(array_unique($errors))];
    }
    if ($plans === []) {
        return ['ok' => true, 'updated' => 0, 'errors' => []];
    }

    $ids = array_keys($plans);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare('SELECT id, name, price, weight_grams FROM products WHERE id IN (' . $marks . ')');
    $stmt->execute($ids);
    $existing = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[(int)$row['id']] = $row;
    }

    $updated = 0;
    $db->beginTransaction();
    try {
        foreach ($plans as $id => $plan) {
            if (!isset($existing[$id])) {
                continue;
            }
            $row = $existing[$id];
            $sets = [];
            $params = [];
            $changes = [];
            if (array_key_exists('weight_grams', $plan)) {
                $old = ($row['weight_grams'] === null || $row['weight_grams'] === '') ? null : (int)$row['weight_grams'];
                $new = (int)$plan['weight_grams'];
                if ($old !== $new) {
                    $sets[] = 'weight_grams = ?';
                    $params[] = $new;
                    $changes[] = ['field' => 'weight_grams', 'from' => $old, 'to' => $new];
                }
            }
            if (array_key_exists('price', $plan)) {
                $old = bakery_catalog_gap_missing_price($row['price']) ? null : round((float)$row['price'], 2);
                $new = round((float)$plan['price'], 2);
                if ($old === null || abs($old - $new) > 0.001) {
                    $sets[] = 'price = ?';
                    $params[] = $new;
                    $changes[] = ['field' => 'price', 'from' => $old, 'to' => $new];
                }
            }
            if ($sets === []) {
                continue;
            }
            $params[] = $id;
            $db->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
            foreach ($changes as $change) {
                $updated++;
                bakery_catalog_gap_record($db, $id, (string)$row['name'], $change);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'updated' => 0, 'errors' => ['catalog_gaps.error_save']];
    }

    return ['ok' => true, 'updated' => $updated, 'errors' => []];
}

/**
 * @param array{field:string, from:mixed, to:mixed} $change
 */
function bakery_catalog_gap_record(PDO $db, int $productId, string $name, array $change): void
{
    $field = (string)$change['field'];
    $summary = $field === 'price'
        ? 'Catalog price updated for ' . $name
        : 'Catalog weight updated for ' . $name;
    bakery_record_operational_event($db, 'catalog_gap_updated', $summary, [
        'product_id' => $productId,
        'metadata' => [
            'field' => $field,
            'from' => $change['from'],
            'to' => $change['to'],
            'product_name' => $name,
        ],
    ]);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function bakery_catalog_gap_csv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }
    fputcsv($handle, ['gap', 'kind', 'id', 'name', 'weight_grams', 'price', 'link']);
    foreach ($rows as $row) {
        fputcsv($handle, [
            (string)($row['gap'] ?? ''),
            (string)($row['kind'] ?? ''),
            (string)($row['id'] ?? ''),
            (string)($row['name'] ?? ''),
            bakery_catalog_gap_csv_number($row['weight_grams'] ?? null),
            bakery_catalog_gap_csv_number($row['price'] ?? null),
            (string)($row['href'] ?? ''),
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === false ? '' : $csv;
}

function bakery_catalog_gap_csv_number($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    if (!is_numeric($value)) {
        return '';
    }
    $formatted = number_format((float)$value, 2, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}
