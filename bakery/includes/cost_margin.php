<?php
/**
 * Read-only cost and margin for active products.
 * Piece grams come from bakery_formula_product_piece_grams.
 * Ingredient prices come from bakery_ingredient_current_cost_per_kg.
 * Dough loss is 50 g per mix, spread across the batch. Nothing here writes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/formula_structure.php';
require_once __DIR__ . '/ingredient_prices.php';

function bakery_cost_margin_loss_grams(): float
{
    return bakery_formula_default_dough_loss_grams();
}

function bakery_cost_margin_method_allowed(string $method): bool
{
    $method = strtoupper(trim($method));
    return $method === 'GET' || $method === 'HEAD';
}

/**
 * @param list<array<string, mixed>> $lines
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $parts
 * @param array<int, float|null> $costsByIngredient
 * @return array{
 *   piece_grams:?float,
 *   ingredient_cost:?float,
 *   dough_loss:?float,
 *   loss_share_grams:?float,
 *   unit_price:?float,
 *   margin_dollars:?float,
 *   margin_percent:?float,
 *   flags:list<string>
 * }
 */
function bakery_cost_margin_quote(array $lines, array $structure, array $parts, $pieceWeight, array $costsByIngredient, $unitPrice): array
{
    $weight = ($pieceWeight === null || $pieceWeight === '') ? null : (float)$pieceWeight;
    $price = ($unitPrice === null || $unitPrice === '') ? null : (float)$unitPrice;
    $blank = [
        'piece_grams' => ($weight !== null && $weight > 0) ? $weight : null,
        'ingredient_cost' => null,
        'dough_loss' => null,
        'loss_share_grams' => null,
        'unit_price' => $price,
        'margin_dollars' => null,
        'margin_percent' => null,
        'flags' => ['empty_formula'],
    ];
    if ($weight === null || $weight <= 0) {
        $blank['piece_grams'] = null;
        return $blank;
    }

    $base = bakery_formula_normalize_structure($structure);
    $without = $base;
    $without['dough_loss_grams'] = null;
    $with = $base;
    $with['dough_loss_grams'] = bakery_cost_margin_loss_grams();

    $plain = bakery_formula_product_piece_grams($lines, $without, $parts, $weight);
    $lossed = bakery_formula_product_piece_grams($lines, $with, $parts, $weight);
    $plainCost = bakery_cost_margin_sum_ingredients($plain['ingredients'] ?? [], $costsByIngredient);
    $lossedCost = bakery_cost_margin_sum_ingredients($lossed['ingredients'] ?? [], $costsByIngredient);

    if ($plainCost['status'] === 'empty' || $lossedCost['status'] === 'empty') {
        return $blank;
    }
    if ($plainCost['status'] === 'missing' || $lossedCost['status'] === 'missing') {
        $blank['flags'] = ['missing_price'];
        return $blank;
    }

    $ingredient = (float)$plainCost['cost'];
    $lossShare = $lossed['loss_share_grams'] ?? null;
    $lossApplied = !empty($lossed['loss_applied']) && $lossShare !== null;
    $doughLoss = $lossApplied ? ((float)$lossedCost['cost'] - $ingredient) : null;
    $marginDollars = null;
    $marginPercent = null;
    if ($lossApplied && $price !== null) {
        $marginDollars = $price - (float)$lossedCost['cost'];
        if ($price > 0) {
            $marginPercent = ($marginDollars / $price) * 100.0;
        }
    }

    return [
        'piece_grams' => $weight,
        'ingredient_cost' => $ingredient,
        'dough_loss' => $doughLoss,
        'loss_share_grams' => $lossApplied ? (float)$lossShare : null,
        'unit_price' => $price,
        'margin_dollars' => $marginDollars,
        'margin_percent' => $marginPercent,
        'flags' => [],
    ];
}

/**
 * @param list<array<string, mixed>> $ingredients
 * @param array<int, float|null> $costsByIngredient
 * @return array{status:string, cost:?float}
 */
function bakery_cost_margin_sum_ingredients(array $ingredients, array $costsByIngredient): array
{
    $sum = 0.0;
    $any = false;
    foreach ($ingredients as $row) {
        if (!is_array($row) || empty($row['counts_in_totals'])) {
            continue;
        }
        $grams = (float)($row['grams'] ?? 0);
        if ($grams <= 0) {
            continue;
        }
        $any = true;
        $id = (int)($row['ingredient_id'] ?? 0);
        if ($id <= 0 || !array_key_exists($id, $costsByIngredient) || $costsByIngredient[$id] === null || $costsByIngredient[$id] === '') {
            return ['status' => 'missing', 'cost' => null];
        }
        $sum += ($grams / 1000.0) * (float)$costsByIngredient[$id];
    }
    if (!$any) {
        return ['status' => 'empty', 'cost' => null];
    }
    return ['status' => 'ok', 'cost' => $sum];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function bakery_cost_margin_csv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }
    fputcsv($handle, [
        'product',
        'piece_grams',
        'ingredient_cost',
        'dough_loss',
        'unit_price',
        'margin_dollars',
        'margin_percent',
        'delivered_90_days',
        'flag',
    ]);
    foreach ($rows as $row) {
        fputcsv($handle, [
            (string)($row['product_name'] ?? ''),
            bakery_cost_margin_csv_number($row['piece_grams'] ?? null, 4),
            bakery_cost_margin_csv_number($row['ingredient_cost'] ?? null, 4),
            bakery_cost_margin_csv_number($row['dough_loss'] ?? null, 4),
            bakery_cost_margin_csv_number($row['unit_price'] ?? null, 4),
            bakery_cost_margin_csv_number($row['margin_dollars'] ?? null, 4),
            bakery_cost_margin_csv_number($row['margin_percent'] ?? null, 4),
            array_key_exists('delivered_90_days', $row) && $row['delivered_90_days'] !== null
                ? (string)(int)$row['delivered_90_days']
                : '',
            implode(';', $row['flags'] ?? []),
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === false ? '' : $csv;
}

function bakery_cost_margin_csv_number($value, int $decimals): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, $decimals, '.', '');
}

/**
 * One row per active product. Costs that cannot be known are null, never zero.
 *
 * @return list<array<string, mixed>>
 */
function bakery_cost_margin_rows(PDO $db, ?string $asOf = null): array
{
    $asOfDate = bakery_cost_margin_as_of($asOf);
    $start = $asOfDate->modify('-90 days')->format('Y-m-d');
    $end = $asOfDate->format('Y-m-d');

    $activeSql = '';
    if (function_exists('column_exists') && column_exists($db, 'products', 'is_active')) {
        $activeSql = ' WHERE COALESCE(p.is_active, 1) = 1';
    }
    $products = $db->query(
        'SELECT p.id, p.name, p.price, p.weight_grams, p.dough_type_id
         FROM products p' . $activeSql . '
         ORDER BY p.name, p.id'
    )->fetchAll(PDO::FETCH_ASSOC);

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
    $delivered = bakery_cost_margin_delivered_map($db, $start, $end);
    $costCache = [];

    $rows = [];
    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $doughId = (int)($product['dough_type_id'] ?? 0);
        $lines = $doughId > 0 ? ($formulas[$doughId] ?? []) : [];
        $structure = $structures[$doughId] ?? bakery_formula_normalize_structure([]);
        $productParts = $parts[$productId] ?? [];
        $costs = bakery_cost_margin_costs_for($db, $lines, $productParts, $costCache);
        $quote = bakery_cost_margin_quote(
            $lines,
            $structure,
            $productParts,
            $product['weight_grams'],
            $costs,
            $product['price']
        );
        $rows[] = [
            'product_id' => $productId,
            'product_name' => (string)$product['name'],
            'piece_grams' => $quote['piece_grams'],
            'ingredient_cost' => $quote['ingredient_cost'],
            'dough_loss' => $quote['dough_loss'],
            'loss_share_grams' => $quote['loss_share_grams'],
            'unit_price' => $quote['unit_price'],
            'margin_dollars' => $quote['margin_dollars'],
            'margin_percent' => $quote['margin_percent'],
            'delivered_90_days' => (int)($delivered[$productId] ?? 0),
            'flags' => $quote['flags'],
        ];
    }
    return $rows;
}

function bakery_cost_margin_as_of(?string $asOf): DateTimeImmutable
{
    $asOf = trim((string)$asOf);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
    $errors = DateTime::getLastErrors();
    if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
        return $date;
    }
    return new DateTimeImmutable('today');
}

/**
 * @param list<int> $doughIds
 * @return array<int, list<array<string, mixed>>>
 */
function bakery_cost_margin_load_formulas(PDO $db, array $doughIds): array
{
    $doughIds = array_values(array_unique(array_filter(array_map('intval', $doughIds))));
    if ($doughIds === [] || !function_exists('table_exists') || !table_exists($db, 'formula_ingredients')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($doughIds), '?'));
    $stmt = $db->prepare(
        "SELECT fi.dough_type_id, fi.ingredient_id, i.name AS ingredient_name, i.unit, fi.percentage
         FROM formula_ingredients fi
         JOIN ingredients i ON i.id = fi.ingredient_id
         WHERE fi.dough_type_id IN ($placeholders)
         ORDER BY fi.dough_type_id, fi.percentage DESC, i.name"
    );
    $stmt->execute($doughIds);
    $byDough = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDough[(int)$row['dough_type_id']][] = [
            'ingredient_id' => (int)$row['ingredient_id'],
            'ingredient_name' => (string)$row['ingredient_name'],
            'unit' => (string)($row['unit'] ?? ''),
            'percentage' => (float)$row['percentage'],
        ];
    }
    return $byDough;
}

/**
 * @param list<array<string, mixed>> $lines
 * @param list<array<string, mixed>> $parts
 * @param array<int, float|null> $cache
 * @return array<int, float|null>
 */
function bakery_cost_margin_costs_for(PDO $db, array $lines, array $parts, array &$cache): array
{
    $ids = [];
    foreach ($lines as $line) {
        $id = (int)($line['ingredient_id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    foreach ($parts as $part) {
        foreach ($part['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $id = (int)($line['ingredient_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
    }
    $costs = [];
    foreach (array_keys($ids) as $id) {
        if (!array_key_exists($id, $cache)) {
            $cache[$id] = bakery_ingredient_current_cost_per_kg($id, $db);
        }
        $costs[$id] = $cache[$id];
    }
    return $costs;
}

/**
 * @return array<int, int>
 */
function bakery_cost_margin_delivered_map(PDO $db, string $start, string $end): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'daily_order_items') || !table_exists($db, 'daily_orders')) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT doi.product_id,
                SUM(COALESCE(doi.delivered_quantity, doi.quantity)) AS units
         FROM daily_order_items doi
         INNER JOIN daily_orders o ON o.id = doi.daily_order_id
         WHERE o.status IN ('delivered', 'invoiced')
           AND o.order_date >= ?
           AND o.order_date <= ?
         GROUP BY doi.product_id"
    );
    $stmt->execute([$start, $end]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['product_id']] = (int)$row['units'];
    }
    return $map;
}

function bakery_cost_margin_format_money($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return '$' . number_format((float)$value, 4);
}

function bakery_cost_margin_format_grams($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $number = (float)$value;
    if ($number == 0.0) {
        return '0';
    }
    $text = rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    if ($text === '' || $text === '0') {
        $text = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }
    return $text;
}

function bakery_cost_margin_format_percent($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, 1) . '%';
}
