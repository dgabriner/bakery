<?php
/**
 * Danny's baker day. Reads the same mix sheet, demand, and plan Juan uses.
 * Batch edits save a draft. Publish commits that draft so Juan's screens update.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/baker_mix.php';
require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/formula_structure.php';
require_once __DIR__ . '/staff_driver_profile.php';
require_once __DIR__ . '/pack_list.php';

/**
 * @param array<int,int> $quantities product_id => pieces
 * @return array<int,int>
 */
function bakery_baker_day_scale_quantities(array $quantities, float $newBatches, float $oldBatches, ?float $piecesPerBatch): array
{
    $clean = [];
    foreach ($quantities as $productId => $quantity) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            continue;
        }
        $clean[$productId] = max(0, (int)$quantity);
    }
    if ($clean === []) {
        return [];
    }
    if ($newBatches <= 0) {
        foreach ($clean as $productId => $quantity) {
            $clean[$productId] = 0;
        }
        return $clean;
    }

    $oldTotal = array_sum($clean);
    if ($oldBatches <= 0 && $oldTotal > 0) {
        $oldBatches = 1.0;
    }
    if ($piecesPerBatch !== null && $piecesPerBatch > 0) {
        $target = (int)round($newBatches * $piecesPerBatch);
    } elseif ($oldBatches > 0 && $oldTotal > 0) {
        $target = (int)round($oldTotal * ($newBatches / $oldBatches));
    } else {
        $target = 0;
    }
    if ($target < 0) {
        $target = 0;
    }
    if ($oldTotal <= 0) {
        return bakery_baker_day_distribute(array_keys($clean), $target);
    }

    $floors = [];
    $assigned = 0;
    $largestId = null;
    $largestQty = -1;
    foreach ($clean as $productId => $quantity) {
        $share = ($quantity / $oldTotal) * $target;
        $floor = (int)floor($share + 1e-9);
        $floors[$productId] = $floor;
        $assigned += $floor;
        if ($quantity > $largestQty || ($quantity === $largestQty && ($largestId === null || $productId < $largestId))) {
            $largestQty = $quantity;
            $largestId = $productId;
        }
    }
    $remainder = $target - $assigned;
    if ($largestId !== null && $remainder > 0) {
        $floors[$largestId] += $remainder;
    }
    return $floors;
}

/**
 * @param list<int> $productIds
 * @return array<int,int>
 */
function bakery_baker_day_distribute(array $productIds, int $total): array
{
    $ids = [];
    foreach ($productIds as $productId) {
        $productId = (int)$productId;
        if ($productId > 0 && !in_array($productId, $ids, true)) {
            $ids[] = $productId;
        }
    }
    sort($ids);
    $count = count($ids);
    $out = [];
    if ($count === 0) {
        return $out;
    }
    $total = max(0, $total);
    $base = intdiv($total, $count);
    $remainder = $total - ($base * $count);
    foreach ($ids as $index => $productId) {
        $out[$productId] = $base + ($index === 0 ? $remainder : 0);
    }
    return $out;
}

function bakery_baker_day_normalize_batches(float $batches): float
{
    if ($batches <= 0) {
        return 0.0;
    }
    $steps = round($batches * 2) / 2;
    if ($steps < 0) {
        $steps = 0.0;
    }
    if ($steps > 40) {
        $steps = 40.0;
    }
    return (float)$steps;
}

function bakery_baker_day_step_batches(float $current, float $step): float
{
    $grid = bakery_baker_day_normalize_batches($current);
    return bakery_baker_day_normalize_batches($grid + $step);
}

function bakery_baker_day_format_batches(float $batches): string
{
    $text = number_format($batches, 1, '.', '');
    return rtrim(rtrim($text, '0'), '.');
}

/**
 * @param array<string,mixed> $batch
 */
function bakery_baker_day_displayed_batches(array $batch): float
{
    $math = is_array($batch['batch_math'] ?? null) ? $batch['batch_math'] : [];
    $fromPieces = $math['batches_from_pieces'] ?? null;
    if ($fromPieces !== null && (float)$fromPieces > 0) {
        return (float)$fromPieces;
    }
    $theoretical = $math['theoretical_batches'] ?? null;
    if ($theoretical !== null && (float)$theoretical > 0) {
        return (float)$theoretical;
    }
    return (int)($batch['planned_units'] ?? 0) > 0 ? 1.0 : 0.0;
}

/**
 * @return array<int,int>
 */
function bakery_baker_day_working_quantities(PDO $db, string $date): array
{
    $quantities = [];
    $bakeList = bakery_production_bake_list($db, $date);
    foreach ($bakeList['items'] as $item) {
        $quantities[(int)$item['product_id']] = (int)$item['bake_quantity'];
    }
    foreach (bakery_production_plan_draft_quantities($db, $date) as $productId => $quantity) {
        $quantities[(int)$productId] = max(0, (int)$quantity);
    }
    return $quantities;
}

/**
 * @param array<int,int> $quantities
 */
function bakery_baker_day_save_products(PDO $db, string $date, array $quantities, ?int $userId): void
{
    $planned = [];
    $allowed = [];
    foreach ($quantities as $productId => $quantity) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            continue;
        }
        $planned[$productId] = max(0, (int)$quantity);
        $allowed[$productId] = true;
    }
    if ($planned === []) {
        return;
    }
    bakery_production_plan_save_targets($db, [$date => $planned], $allowed, $userId);
}

/**
 * @param array<string,mixed> $batch
 * @return list<array{name:string,unit:string,grams:float}>
 */
function bakery_baker_day_batch_amounts(array $batch): array
{
    $rows = [];
    $totalPct = (float)($batch['formula']['total_percentage'] ?? 0);
    $dough = (float)($batch['formula_dough_grams'] ?? $batch['total_weight_grams'] ?? 0);
    $ingredients = is_array($batch['ingredients'] ?? null) ? $batch['ingredients'] : [];
    if ($totalPct > 0 && $dough > 0 && $ingredients !== []) {
        $structure = is_array($batch['formula_structure'] ?? null) ? $batch['formula_structure'] : null;
        $normalized = $structure !== null ? bakery_formula_normalize_structure($structure) : null;
        $foldable = false;
        if ($normalized !== null) {
            foreach ($normalized['starters'] as $starter) {
                if (is_array($starter) && bakery_formula_starter_parts($starter) !== null) {
                    $foldable = true;
                    break;
                }
            }
        }
        foreach ($ingredients as $ingredient) {
            if (!empty($ingredient['subformula']['lines'])) {
                $foldable = true;
                break;
            }
        }
        if ($foldable && $normalized !== null) {
            $lines = [];
            foreach ($ingredients as $ingredient) {
                $line = [
                    'ingredient_id' => (int)($ingredient['ingredient_id'] ?? 0),
                    'ingredient_name' => (string)($ingredient['name'] ?? $ingredient['ingredient_name'] ?? ''),
                    'unit' => (string)($ingredient['unit'] ?? ''),
                    'percentage' => (float)($ingredient['percentage'] ?? 0),
                ];
                if (!empty($ingredient['subformula']) && is_array($ingredient['subformula'])) {
                    $line['subformula'] = $ingredient['subformula'];
                }
                $lines[] = $line;
            }
            $folded = bakery_formula_fold_formula($lines, $normalized['starters'], $dough);
            foreach ($folded['ingredients'] as $ingredient) {
                $rows[] = [
                    'name' => (string)($ingredient['ingredient_name'] ?? ''),
                    'unit' => (string)($ingredient['unit'] ?? ''),
                    'grams' => (float)($ingredient['grams'] ?? 0),
                ];
            }
        } else {
            $flour = $dough / ($totalPct / 100);
            foreach ($ingredients as $ingredient) {
                $rows[] = [
                    'name' => (string)($ingredient['name'] ?? ''),
                    'unit' => (string)($ingredient['unit'] ?? ''),
                    'grams' => $flour * (((float)($ingredient['percentage'] ?? 0)) / 100),
                ];
            }
        }
    }
    foreach ($batch['add_in_rows'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'name' => (string)($row['ingredient_name'] ?? ''),
            'unit' => (string)($row['unit'] ?? ''),
            'grams' => (float)($row['grams'] ?? 0),
        ];
    }
    return $rows;
}

/**
 * @param list<array<string,mixed>> $batches
 * @return list<array{name:string,unit:string,grams:float}>
 */
function bakery_baker_day_pull_list(array $batches): array
{
    $sum = [];
    foreach ($batches as $batch) {
        foreach (bakery_baker_day_batch_amounts($batch) as $row) {
            $name = trim($row['name']);
            if ($name === '' || $row['grams'] <= 0) {
                continue;
            }
            $key = function_exists('bakery_staff_name_key')
                ? bakery_staff_name_key($name)
                : strtolower($name);
            if (!isset($sum[$key])) {
                $sum[$key] = ['name' => $name, 'unit' => $row['unit'], 'grams' => 0.0];
            }
            $sum[$key]['grams'] += $row['grams'];
        }
    }
    $rows = array_values($sum);
    usort($rows, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });
    return $rows;
}

function bakery_baker_day_pile_label(array $pile): string
{
    $kind = (string)($pile['kind'] ?? '');
    if ($kind === 'driver') {
        $name = trim((string)($pile['driver_name'] ?? ''));
        return $name !== '' ? $name : bakery_t('baker_day.unassigned');
    }
    if ($kind === 'civic_center') {
        return bakery_t('baker_day.pile_civic');
    }
    if ($kind === 'noe_valley') {
        return bakery_t('baker_day.pile_noe');
    }
    if ($kind === 'capp') {
        return bakery_t('baker_day.pile_capp');
    }
    return bakery_t('baker_day.unassigned');
}

function bakery_baker_day_customer_pile(string $customerName): string
{
    if (!function_exists('bakery_staff_name_key')) {
        require_once __DIR__ . '/staff_driver_profile.php';
    }
    $key = bakery_staff_name_key($customerName);
    if ($key === '') {
        return 'route';
    }
    if (strpos($key, 'civic center') !== false) {
        return 'civic_center';
    }
    if (strpos($key, 'noe valley') !== false) {
        return 'noe_valley';
    }
    if ($key === 'capp') {
        return 'capp';
    }
    return 'route';
}

/**
 * Dated assignments win. Standing routes fill customers not assigned today.
 * Same rule as Pack List.
 *
 * @return array<int,array{driver_id:int,driver_name:string,route_order:int}>
 */
function bakery_baker_day_customer_routes(PDO $db, string $date): array
{
    $routes = [];
    if (!function_exists('bakery_standing_day_from_date')) {
        require_once __DIR__ . '/common_functions.php';
    }
    if (table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        $assignStmt = $db->prepare(
            'SELECT do.customer_id, doa.driver_id, d.name AS driver_name,
                    COALESCE(doa.route_order, 2147483647) AS route_order
             FROM daily_orders do
             JOIN daily_order_assignments doa
               ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
             JOIN drivers d ON d.id = doa.driver_id
             WHERE do.order_date = ?
             ORDER BY doa.driver_id, COALESCE(doa.route_order, 2147483647), doa.id'
        );
        $assignStmt->execute([$date]);
        foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $assign) {
            $customerId = (int)$assign['customer_id'];
            if (isset($routes[$customerId])) {
                continue;
            }
            $routes[$customerId] = [
                'driver_id' => (int)$assign['driver_id'],
                'driver_name' => (string)$assign['driver_name'],
                'route_order' => (int)$assign['route_order'],
            ];
        }
    }
    if (table_exists($db, 'standing_routes') && function_exists('bakery_standing_day_in_clause')) {
        if (!function_exists('bakery_sfb_ops_origin_clause')) {
            require_once __DIR__ . '/sfb_origin.php';
        }
        $day = bakery_standing_day_from_date($date);
        $dayClause = bakery_standing_day_in_clause($day);
        $standingStmt = $db->prepare(
            'SELECT sr.customer_id, sr.driver_id, d.name AS driver_name,
                    COALESCE(sr.route_order, 2147483647) AS route_order
             FROM standing_routes sr
             JOIN drivers d ON d.id = sr.driver_id
             JOIN customers c ON c.id = sr.customer_id AND COALESCE(c.is_active, 1) = 1
                 ' . bakery_sfb_ops_origin_clause('c', $db) . '
             WHERE sr.day_of_week ' . $dayClause['sql'] . '
             ORDER BY sr.driver_id, COALESCE(sr.route_order, 2147483647), sr.id'
        );
        $standingStmt->execute($dayClause['values']);
        foreach ($standingStmt->fetchAll(PDO::FETCH_ASSOC) as $route) {
            $customerId = (int)$route['customer_id'];
            if (isset($routes[$customerId])) {
                continue;
            }
            $routes[$customerId] = [
                'driver_id' => (int)$route['driver_id'],
                'driver_name' => (string)$route['driver_name'],
                'route_order' => (int)$route['route_order'],
            ];
        }
    }
    return $routes;
}

/**
 * @param list<array<string,mixed>> $lines
 * @param array<int,array{driver_id:int,driver_name:string,route_order?:int}> $routesByCustomer
 * @return list<array{product_id:int,product_name:string,ordered:int,piles:list<array<string,mixed>>}>
 */
function bakery_baker_day_pack_products(array $lines, array $routesByCustomer): array
{
    $products = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $quantity = (int)($line['quantity'] ?? 0);
        $productId = (int)($line['product_id'] ?? 0);
        if ($quantity <= 0 || $productId <= 0) {
            continue;
        }
        $customerId = (int)($line['customer_id'] ?? 0);
        $customerName = (string)($line['customer_name'] ?? '');
        $productName = (string)($line['product_name'] ?? '');
        $route = $routesByCustomer[$customerId] ?? null;
        $driverName = is_array($route) ? (string)($route['driver_name'] ?? '') : '';
        $driverId = is_array($route) ? (int)($route['driver_id'] ?? 0) : 0;
        $pileKind = bakery_baker_day_customer_pile($customerName);
        if ($pileKind === 'route') {
            $pileKey = $driverId > 0 ? 'driver:' . $driverId : 'unassigned';
            $kind = $driverId > 0 ? 'driver' : 'unassigned';
        } else {
            $pileKey = $pileKind;
            $kind = $pileKind;
        }
        if (!isset($products[$productId])) {
            $products[$productId] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'ordered' => 0,
                'piles' => [],
            ];
        }
        if ($products[$productId]['product_name'] === '' && $productName !== '') {
            $products[$productId]['product_name'] = $productName;
        }
        $products[$productId]['ordered'] += $quantity;
        if (!isset($products[$productId]['piles'][$pileKey])) {
            $products[$productId]['piles'][$pileKey] = [
                'key' => $pileKey,
                'kind' => $kind,
                'driver_id' => $kind === 'driver' ? $driverId : 0,
                'driver_name' => $kind === 'driver' ? $driverName : '',
                'quantity' => 0,
                'stops' => [],
            ];
        }
        $products[$productId]['piles'][$pileKey]['quantity'] += $quantity;
        $products[$productId]['piles'][$pileKey]['stops'][] = [
            'customer_name' => $customerName,
            'quantity' => $quantity,
            'driver_name' => $driverName,
        ];
    }

    $kindOrder = [
        'driver' => 0,
        'civic_center' => 1,
        'noe_valley' => 2,
        'capp' => 3,
        'unassigned' => 4,
    ];
    $out = [];
    foreach ($products as $product) {
        $piles = array_values($product['piles']);
        usort($piles, static function (array $a, array $b) use ($kindOrder): int {
            $aOrder = $kindOrder[$a['kind']] ?? 9;
            $bOrder = $kindOrder[$b['kind']] ?? 9;
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }
            return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
        });
        $product['piles'] = $piles;
        $out[] = $product;
    }
    usort($out, static function (array $a, array $b): int {
        return strcasecmp($a['product_name'], $b['product_name']);
    });
    return $out;
}

/**
 * Same pieces as the product sheet, grouped by driver and by Civic Center,
 * Noe Valley, and Capp. A market loaf stays out of the driver total.
 *
 * @param list<array<string,mixed>> $products
 * @return list<array<string,mixed>>
 */
function bakery_baker_day_pack_destinations(array $products): array
{
    $groups = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        foreach ($product['piles'] ?? [] as $pile) {
            if (!is_array($pile)) {
                continue;
            }
            $key = (string)($pile['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'kind' => (string)($pile['kind'] ?? 'unassigned'),
                    'driver_id' => (int)($pile['driver_id'] ?? 0),
                    'driver_name' => (string)($pile['driver_name'] ?? ''),
                    'quantity' => 0,
                    'products' => [],
                ];
            }
            $quantity = (int)($pile['quantity'] ?? 0);
            $groups[$key]['quantity'] += $quantity;
            $groups[$key]['products'][] = [
                'product_id' => (int)($product['product_id'] ?? 0),
                'product_name' => (string)($product['product_name'] ?? ''),
                'quantity' => $quantity,
                'stops' => is_array($pile['stops'] ?? null) ? $pile['stops'] : [],
            ];
        }
    }
    $kindOrder = [
        'driver' => 0,
        'civic_center' => 1,
        'noe_valley' => 2,
        'capp' => 3,
        'unassigned' => 4,
    ];
    $out = array_values($groups);
    usort($out, static function (array $a, array $b) use ($kindOrder): int {
        $aOrder = $kindOrder[$a['kind']] ?? 9;
        $bOrder = $kindOrder[$b['kind']] ?? 9;
        if ($aOrder !== $bOrder) {
            return $aOrder <=> $bOrder;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });
    foreach ($out as &$group) {
        usort($group['products'], static function (array $a, array $b): int {
            return strcasecmp((string)$a['product_name'], (string)$b['product_name']);
        });
    }
    unset($group);
    return $out;
}

/**
 * @return array<int,int>
 */
function bakery_baker_day_produced(PDO $db, string $date): array
{
    if (!table_exists($db, 'product_inventory_days')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT product_id, produced_quantity
         FROM product_inventory_days
         WHERE delivery_date = ?'
    );
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['product_id']] = (int)$row['produced_quantity'];
    }
    return $out;
}

/**
 * @return list<array{id:int,order_text:string,customer_name:string,status:string}>
 */
function bakery_baker_day_capp_counter(PDO $db, string $date): array
{
    if (!table_exists($db, 'counter_orders')) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT id, order_text, customer_name, status
         FROM counter_orders
         WHERE pickup_place = 'capp' AND pickup_date = ?
         ORDER BY id"
    );
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $text = trim((string)($row['order_text'] ?? ''));
        if (strlen($text) > 160) {
            $text = substr($text, 0, 157) . '...';
        }
        $out[] = [
            'id' => (int)$row['id'],
            'order_text' => $text,
            'customer_name' => trim((string)($row['customer_name'] ?? '')),
            'status' => (string)($row['status'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $batches
 * @return list<array{id:int,name:string}>
 */
function bakery_baker_day_addable_doughs(PDO $db, array $batches): array
{
    $onDay = [];
    foreach ($batches as $batch) {
        $doughId = (int)($batch['dough_type_id'] ?? 0);
        if ($doughId > 0) {
            $onDay[$doughId] = true;
        }
    }
    $stmt = $db->query(
        'SELECT dt.id, dt.name
         FROM dough_types dt
         WHERE EXISTS (
             SELECT 1 FROM formula_ingredients fi WHERE fi.dough_type_id = dt.id
         )
         ORDER BY dt.name'
    );
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        if ($id <= 0 || isset($onDay[$id])) {
            continue;
        }
        $out[] = ['id' => $id, 'name' => (string)$row['name']];
    }
    return $out;
}

/**
 * @return array<int,int>
 */
function bakery_baker_day_standard_batch_quantities(PDO $db, int $doughTypeId): array
{
    if ($doughTypeId <= 0) {
        return [];
    }
    $active = '';
    if (function_exists('column_exists') && column_exists($db, 'products', 'is_active')) {
        $active = ' AND COALESCE(p.is_active, 1) = 1';
    }
    $stmt = $db->prepare(
        "SELECT p.id, p.weight_grams
         FROM products p
         WHERE p.dough_type_id = ? {$active}
         ORDER BY p.id"
    );
    $stmt->execute([$doughTypeId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($products === []) {
        return [];
    }
    $structures = bakery_formula_structure_load_map($db, [$doughTypeId]);
    $structure = $structures[$doughTypeId] ?? bakery_formula_normalize_structure([]);
    $pieces = $structure['pieces_per_batch'] ?? null;
    $grams = $structure['standard_batch_dough_grams'] ?? null;
    $ids = [];
    $weights = [];
    foreach ($products as $product) {
        $id = (int)$product['id'];
        $ids[] = $id;
        $weight = (int)($product['weight_grams'] ?? 0);
        if ($weight > 0) {
            $weights[$id] = $weight;
        }
    }
    if (($pieces === null || (float)$pieces <= 0) && $grams !== null && (float)$grams > 0 && $weights !== []) {
        $average = array_sum($weights) / count($weights);
        $pieces = $average > 0 ? (int)round((float)$grams / $average) : null;
    }
    $total = ($pieces !== null && (float)$pieces > 0) ? (int)round((float)$pieces) : count($ids);
    if ($total < 1) {
        $total = count($ids);
    }
    return bakery_baker_day_distribute($ids, $total);
}

/**
 * @param array<string,mixed> $bakeList
 * @param array<int,int> $working
 * @return array{matches:bool,committed:bool,juan_pieces:int,draft_pieces:int,delta:int}
 */
function bakery_baker_day_status(array $bakeList, array $working): array
{
    $juan = [];
    foreach ($bakeList['items'] ?? [] as $item) {
        $juan[(int)$item['product_id']] = (int)$item['bake_quantity'];
    }
    $ids = array_unique(array_merge(array_keys($juan), array_keys($working)));
    $matches = true;
    foreach ($ids as $id) {
        if ((int)($juan[$id] ?? 0) !== (int)($working[$id] ?? 0)) {
            $matches = false;
            break;
        }
    }
    $juanPieces = 0;
    foreach ($juan as $quantity) {
        $juanPieces += max(0, (int)$quantity);
    }
    $draftPieces = 0;
    foreach ($working as $quantity) {
        $draftPieces += max(0, (int)$quantity);
    }
    return [
        'matches' => $matches,
        'committed' => !empty($bakeList['committed']),
        'juan_pieces' => $juanPieces,
        'draft_pieces' => $draftPieces,
        'delta' => $draftPieces - $juanPieces,
    ];
}

/**
 * @return array{
 *   date:string,
 *   batches:list<array<string,mixed>>,
 *   starter_feedings:array,
 *   pull:list<array{name:string,unit:string,grams:float}>,
 *   addable:list<array{id:int,name:string}>,
 *   pack:list<array<string,mixed>>,
 *   produced:array<int,int>,
 *   counter:list<array<string,mixed>>,
 *   status:array{matches:bool,committed:bool,juan_pieces:int,draft_pieces:int,delta:int},
 *   working:array<int,int>
 * }
 */
function bakery_baker_day_view(PDO $db, string $date, ?array $bakerProductIds = null): array
{
    $juan = bakery_production_bake_list($db, $date);
    $working = bakery_baker_day_working_quantities($db, $date);
    if (is_array($bakerProductIds)) {
        $working = array_intersect_key($working, array_flip($bakerProductIds));
    }
    $sheet = bakery_baker_mix_sheet($db, $date, $bakerProductIds, $working);
    $batches = [];
    foreach ($sheet['batches'] as $batch) {
        if ((int)($batch['planned_units'] ?? 0) <= 0) {
            continue;
        }
        $batch['displayed_batches'] = bakery_baker_day_displayed_batches($batch);
        $batches[] = $batch;
    }
    $lines = bakery_operating_demand_lines($db, $date);
    if (is_array($bakerProductIds)) {
        $allowed = array_flip($bakerProductIds);
        $lines = array_values(array_filter($lines, static function ($row) use ($allowed): bool {
            return is_array($row) && isset($allowed[(int)($row['product_id'] ?? 0)]);
        }));
    }
    $routes = bakery_baker_day_customer_routes($db, $date);
    $pack = bakery_baker_day_pack_products($lines, $routes);
    $addable = bakery_baker_day_addable_doughs($db, $batches);
    if (is_array($bakerProductIds)) {
        $doughIds = [];
        if ($bakerProductIds !== []) {
            $placeholders = implode(',', array_fill(0, count($bakerProductIds), '?'));
            $doughStmt = $db->prepare("SELECT DISTINCT dough_type_id FROM products WHERE id IN ($placeholders)");
            $doughStmt->execute(array_values($bakerProductIds));
            foreach ($doughStmt->fetchAll(PDO::FETCH_COLUMN) as $doughId) {
                $doughIds[(int)$doughId] = true;
            }
        }
        $addable = array_values(array_filter($addable, static function (array $dough) use ($doughIds): bool {
            return isset($doughIds[(int)$dough['id']]);
        }));
    }
    return [
        'date' => $date,
        'batches' => $batches,
        'starter_feedings' => is_array($sheet['starter_feedings'] ?? null) ? $sheet['starter_feedings'] : [],
        'pull' => bakery_baker_day_pull_list($batches),
        'addable' => $addable,
        'pack' => $pack,
        'customers' => bakery_pack_customer_cards($lines, $routes),
        'produced' => bakery_baker_day_produced($db, $date),
        'counter' => bakery_baker_day_capp_counter($db, $date),
        'status' => bakery_baker_day_status($juan, $working),
        'working' => $working,
    ];
}

function bakery_baker_day_guard_products(array $productIds, ?array $allowedProductIds): void
{
    if ($allowedProductIds === null) {
        return;
    }
    $allowed = array_flip($allowedProductIds);
    foreach ($productIds as $productId) {
        if (!isset($allowed[(int)$productId])) {
            throw new InvalidArgumentException('That dough is not on your sheet.');
        }
    }
}

function bakery_baker_day_apply_batches(PDO $db, string $date, int $doughTypeId, float $newBatches, ?int $userId, ?array $allowedProductIds = null): void
{
    if ($doughTypeId <= 0) {
        throw new InvalidArgumentException('Choose a dough.');
    }
    $newBatches = bakery_baker_day_normalize_batches($newBatches);
    $working = bakery_baker_day_working_quantities($db, $date);
    $sheet = bakery_baker_mix_sheet($db, $date, null, $working);
    $batch = null;
    foreach ($sheet['batches'] as $candidate) {
        if ((int)($candidate['dough_type_id'] ?? 0) === $doughTypeId) {
            $batch = $candidate;
            break;
        }
    }
    if ($batch === null) {
        throw new InvalidArgumentException('That dough is not on this day.');
    }
    $current = [];
    foreach ($batch['products'] as $product) {
        $current[(int)$product['product_id']] = (int)$product['planned_quantity'];
    }
    bakery_baker_day_guard_products(array_keys($current), $allowedProductIds);
    $pieces = $batch['batch_math']['pieces_per_batch'] ?? null;
    $scaled = bakery_baker_day_scale_quantities(
        $current,
        $newBatches,
        bakery_baker_day_displayed_batches($batch),
        ($pieces !== null && (float)$pieces > 0) ? (float)$pieces : null
    );
    bakery_baker_day_save_products($db, $date, $scaled, $userId);
}

function bakery_baker_day_apply_shape(PDO $db, string $date, int $productId, int $quantity, ?int $userId, ?array $allowedProductIds = null): void
{
    if ($productId <= 0 || $quantity < 0) {
        throw new InvalidArgumentException('Piece counts must be zero or more.');
    }
    bakery_baker_day_guard_products([$productId], $allowedProductIds);
    bakery_baker_day_save_products($db, $date, [$productId => $quantity], $userId);
}

function bakery_baker_day_add_dough(PDO $db, string $date, int $doughTypeId, ?int $userId, ?array $allowedProductIds = null): void
{
    $quantities = bakery_baker_day_standard_batch_quantities($db, $doughTypeId);
    if ($quantities === []) {
        throw new InvalidArgumentException('That dough has no products to bake.');
    }
    bakery_baker_day_guard_products(array_keys($quantities), $allowedProductIds);
    bakery_baker_day_save_products($db, $date, $quantities, $userId);
}

/**
 * Write the full screen (draft over the bake Juan sees) and commit it.
 *
 * @return array{products_count:int,units_count:int,delivery_date:string}
 */
function bakery_baker_day_publish(PDO $db, string $date, ?int $userId): array
{
    $working = bakery_baker_day_working_quantities($db, $date);
    $planned = [];
    foreach ($working as $productId => $quantity) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            continue;
        }
        $planned[$productId] = max(0, (int)$quantity);
    }
    if ($planned === []) {
        throw new RuntimeException('Nothing to publish for this day.');
    }
    if (table_exists($db, 'production_plan_items')) {
        $existing = bakery_production_plan_draft_quantities($db, $date);
        $drop = [];
        foreach ($existing as $productId => $quantity) {
            if (!array_key_exists((int)$productId, $planned)) {
                $drop[] = (int)$productId;
            }
        }
        if ($drop !== []) {
            $placeholders = implode(',', array_fill(0, count($drop), '?'));
            $delete = $db->prepare(
                "DELETE FROM production_plan_items WHERE delivery_date = ? AND product_id IN ($placeholders)"
            );
            $delete->execute(array_merge([$date], $drop));
        }
    }
    bakery_baker_day_save_products($db, $date, $planned, $userId);
    return bakery_production_plan_commit($db, $date, $userId);
}
