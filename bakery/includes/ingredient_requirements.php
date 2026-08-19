<?php
/**
 * Integrated ingredient requirements + batch planning helpers.
 *
 * Explodes finished-unit production quantities through dough-type baker's-%
 * formulas (same algebra as production.php). Does not mutate inventory,
 * recipes, production plans, or purchasing records.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/ingredient_units.php';

/**
 * Supported quantity sources for the planner. Never switch silently in the UI.
 *
 * @return array<string, array{label:string, short:string, description:string}>
 */
function bakery_ingredient_requirements_sources(): array
{
    return [
        'plan' => [
            'label' => 'Saved production plan (Production Center)',
            'short' => 'Production plan',
            'description' => 'Uses production_plan_items.planned_quantity saved in Production Center for the delivery date. Primary committed production decision.',
        ],
        'demand' => [
            'label' => 'Demand (Daily Production source)',
            'short' => 'Demand',
            'description' => 'Uses dated daily-order quantities when any exist for the day; otherwise standing-order forecast for that weekday. Matches Daily Production.',
        ],
        'to_produce' => [
            'label' => 'Still to produce (demand − finished-goods stock)',
            'short' => 'Still to produce',
            'description' => 'Demand minus finished-goods available+loaded for the delivery date. Matches Product Distribution dough needs. Requires finished-goods inventory.',
        ],
    ];
}

/**
 * Whether migration 021 batch reference column exists on dough_types.
 */
function bakery_ingredient_batch_reference_ready(PDO $db): bool
{
    if (!table_exists($db, 'dough_types')) {
        return false;
    }
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute(['dough_types', 'standard_batch_dough_grams']);
    $ready = (int)$stmt->fetchColumn() === 1;
    return $ready;
}

/**
 * Normalize planner date (Y-m-d). Defaults to tomorrow (bake for next delivery day).
 */
function bakery_ingredient_requirements_resolve_date(?string $input): string
{
    $default = date('Y-m-d', strtotime('+1 day'));
    if ($input === null || $input === '') {
        return $default;
    }
    $input = trim($input);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        return $default;
    }
    if (function_exists('bakery_inventory_validate_date')) {
        try {
            return bakery_inventory_validate_date($input);
        } catch (Throwable $e) {
            return $default;
        }
    }
    return strtotime($input) !== false ? $input : $default;
}

/**
 * Normalize quantity source key. Default is committed production plan.
 */
function bakery_ingredient_requirements_resolve_source(?string $source): string
{
    $sources = bakery_ingredient_requirements_sources();
    $source = strtolower(trim((string)$source));
    return isset($sources[$source]) ? $source : 'plan';
}

/**
 * Whether any positive daily-order lines exist for the date (committed demand day).
 */
function bakery_ingredient_requirements_has_daily_orders(PDO $db, string $date): bool
{
    if (!table_exists($db, 'daily_orders') || !table_exists($db, 'daily_order_items')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1
         FROM daily_order_items doi
         JOIN daily_orders do ON doi.daily_order_id = do.id
         WHERE do.order_date = ? AND doi.quantity > 0
         LIMIT 1'
    );
    $stmt->execute([$date]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Compute batch metadata for a product row.
 *
 * @return array<string, mixed>
 */
function bakery_ingredient_requirements_product_batches(
    int $quantity,
    int $weightGrams,
    float $doughGrams,
    ?float $standardBatchDoughGrams
): array {
    $info = [
        'standard_batch_dough_grams' => $standardBatchDoughGrams,
        'batch_reference_configured' => $standardBatchDoughGrams !== null && $standardBatchDoughGrams > 0,
        'reference_yield_units' => null,
        'theoretical_dough_batches' => null,
        'suggested_whole_dough_batches' => null,
        'theoretical_product_batches' => null,
        'suggested_whole_product_batches' => null,
    ];

    if (!$info['batch_reference_configured'] || $weightGrams <= 0) {
        return $info;
    }

    $batchRef = (float)$standardBatchDoughGrams;
    $theoreticalDough = $doughGrams / $batchRef;
    $yieldUnits = $batchRef / $weightGrams;
    $theoreticalProduct = $quantity / $yieldUnits;

    $info['reference_yield_units'] = $yieldUnits;
    $info['theoretical_dough_batches'] = $theoreticalDough;
    $info['suggested_whole_dough_batches'] = (int)ceil($theoreticalDough);
    $info['theoretical_product_batches'] = $theoreticalProduct;
    $info['suggested_whole_product_batches'] = (int)ceil($theoreticalProduct);

    return $info;
}

/**
 * Load finished-unit quantities per product for the chosen source.
 *
 * @return array{
 *   products: list<array<string,mixed>>,
 *   demand_mode: string,
 *   source_label: string,
 *   source_detail: string,
 *   notes: list<string>,
 *   error: ?string
 * }
 */
function bakery_ingredient_requirements_load_products(PDO $db, string $date, string $source): array
{
    $sources = bakery_ingredient_requirements_sources();
    $source = bakery_ingredient_requirements_resolve_source($source);
    $weekday = bakery_standing_day_from_date($date);
    $hasDaily = bakery_ingredient_requirements_has_daily_orders($db, $date);
    $inventoryReady = function_exists('bakery_inventory_ready') && bakery_inventory_ready($db);
    $planReady = table_exists($db, 'production_plan_items');
    $batchReady = bakery_ingredient_batch_reference_ready($db);
    $notes = [];
    $error = null;

    $demandMode = $hasDaily ? 'daily_orders' : 'standing_orders';
    $sourceMeta = $sources[$source];
    $sourceDetail = $sourceMeta['description'];

    if ($source === 'demand') {
        if ($hasDaily) {
            $sourceDetail = 'Committed demand from daily_order_items for ' . $date . '.';
        } else {
            $sourceDetail = 'No daily orders for ' . $date . ' — standing_orders forecast for weekday ' . $weekday . '.';
            $notes[] = 'Standing forecast is in use because no dated daily-order lines exist for this day.';
        }
    } elseif ($source === 'to_produce') {
        if (!$inventoryReady) {
            return [
                'products' => [],
                'demand_mode' => $demandMode,
                'source_label' => $sourceMeta['label'],
                'source_detail' => $sourceDetail,
                'notes' => [],
                'error' => 'Finished-goods inventory is not available, so “Still to produce” cannot be calculated. Choose Demand or Saved plan, or install finished-goods inventory migrations.',
            ];
        }
        $sourceDetail = ($hasDaily
                ? 'Demand from daily orders'
                : 'Demand from standing forecast')
            . ' minus finished-goods available+loaded on ' . $date . '.';
        if (!$hasDaily) {
            $notes[] = 'Standing forecast is the demand base because no dated daily-order lines exist for this day.';
        }
    } elseif ($source === 'plan') {
        if (!$planReady) {
            return [
                'products' => [],
                'demand_mode' => $demandMode,
                'source_label' => $sourceMeta['label'],
                'source_detail' => $sourceDetail,
                'notes' => [],
                'error' => 'Saved production plans are not available (production_plan_items missing). Choose Demand or Still to produce.',
            ];
        }
        $sourceDetail = 'Saved planned_quantity from production_plan_items for ' . $date . '.';
    }

    $batchSelect = $batchReady
        ? 'dt.standard_batch_dough_grams'
        : 'NULL AS standard_batch_dough_grams';
    $inventorySelect = $inventoryReady
        ? 'COALESCE(inv.available_quantity, 0) AS available_quantity,
           COALESCE(inv.loaded_quantity, 0) AS loaded_quantity,
           COALESCE(inv.produced_quantity, 0) AS produced_quantity'
        : '0 AS available_quantity, 0 AS loaded_quantity, 0 AS produced_quantity';
    $inventoryJoin = $inventoryReady
        ? 'LEFT JOIN product_inventory_days inv
               ON inv.product_id = p.id AND inv.delivery_date = ?'
        : '';
    $planSelect = $planReady
        ? 'COALESCE(plan.planned_quantity, 0) AS planned_quantity,
           CASE WHEN plan.planned_quantity IS NULL THEN 0 ELSE 1 END AS has_saved_plan'
        : '0 AS planned_quantity, 0 AS has_saved_plan';
    $planJoin = $planReady
        ? 'LEFT JOIN production_plan_items plan
               ON plan.product_id = p.id AND plan.delivery_date = ?'
        : '';

    $sql = "
        SELECT p.id AS product_id,
               p.name AS product_name,
               p.weight_grams,
               p.dough_type_id,
               dt.name AS dough_type_name,
               {$batchSelect},
               pl.name AS product_line_name,
               COALESCE(standing.quantity, 0) AS standing_quantity,
               COALESCE(actual.quantity, 0) AS actual_quantity,
               {$planSelect},
               {$inventorySelect}
        FROM products p
        LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
        LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
        LEFT JOIN (
            SELECT product_id, SUM(quantity) AS quantity
            FROM standing_orders
            WHERE CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END = ?
            GROUP BY product_id
        ) standing ON standing.product_id = p.id
        LEFT JOIN (
            SELECT doi.product_id, SUM(doi.quantity) AS quantity
            FROM daily_order_items doi
            JOIN daily_orders do ON do.id = doi.daily_order_id
            WHERE do.order_date = ?
            GROUP BY doi.product_id
        ) actual ON actual.product_id = p.id
        {$planJoin}
        {$inventoryJoin}
        ORDER BY COALESCE(pl.sort_order, 999), pl.name, dt.name, p.name
    ";

    $params = [$weekday, $date];
    if ($planReady) {
        $params[] = $date;
    }
    if ($inventoryReady) {
        $params[] = $date;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $operatingDemand = ['by_product' => []];
    if (function_exists('bakery_operating_demand_by_product')) {
        require_once __DIR__ . '/demand_review.php';
        $operatingDemand = bakery_operating_demand_by_product($db, $date);
        $hasDaily = $operatingDemand['has_daily'];
    }

    $products = [];
    $demandWithoutPlan = 0;
    $planWithoutRows = 0;

    foreach ($rows as $row) {
        $pid = (int)$row['product_id'];
        $demandQty = isset($operatingDemand['by_product'][$pid])
            ? (int)$operatingDemand['by_product'][$pid]
            : (int)($hasDaily ? $row['actual_quantity'] : $row['standing_quantity']);
        $stock = (int)$row['available_quantity'] + (int)$row['loaded_quantity'];
        $planned = (int)$row['planned_quantity'];
        $hasSavedPlan = (int)($row['has_saved_plan'] ?? 0) === 1;
        $toProduce = max(0, $demandQty - $stock);
        $standardBatch = $row['standard_batch_dough_grams'] !== null
            ? (float)$row['standard_batch_dough_grams']
            : null;

        if ($source === 'demand') {
            $qty = $demandQty;
            $qtyLabel = $hasDaily ? 'daily_orders' : 'standing_orders';
        } elseif ($source === 'to_produce') {
            $qty = $toProduce;
            $qtyLabel = 'to_produce';
            if ($qty <= 0 && $demandQty <= 0 && $stock <= 0 && (int)$row['produced_quantity'] <= 0) {
                continue;
            }
        } else {
            $qty = $planned;
            $qtyLabel = 'production_plan';
            if ($demandQty > 0 && !$hasSavedPlan) {
                $demandWithoutPlan++;
            }
            if ($qty <= 0) {
                continue;
            }
        }

        if ($source !== 'to_produce' && $qty <= 0) {
            continue;
        }
        if ($source === 'to_produce' && $qty <= 0) {
            continue;
        }

        $weight = $row['weight_grams'] !== null ? (int)$row['weight_grams'] : null;
        $doughGrams = ($weight !== null && $weight > 0) ? ($qty * $weight) : 0.0;
        $batchInfo = bakery_ingredient_requirements_product_batches(
            $qty,
            $weight ?? 0,
            $doughGrams,
            $standardBatch
        );

        $products[] = [
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)$row['product_name'],
            'weight_grams' => $weight,
            'dough_type_id' => $row['dough_type_id'] !== null ? (int)$row['dough_type_id'] : null,
            'dough_type_name' => $row['dough_type_name'] !== null ? (string)$row['dough_type_name'] : null,
            'standard_batch_dough_grams' => $standardBatch,
            'product_line_name' => $row['product_line_name'] !== null ? (string)$row['product_line_name'] : null,
            'quantity' => (int)$qty,
            'quantity_basis' => $qtyLabel,
            'demand_quantity' => $demandQty,
            'planned_quantity' => $planned,
            'has_saved_plan' => $hasSavedPlan,
            'stock_quantity' => $stock,
            'to_produce_quantity' => $toProduce,
            'plan_vs_demand_delta' => $planned - $demandQty,
            'dough_grams' => $doughGrams,
            'batches' => $batchInfo,
        ];
    }

    if ($source === 'plan') {
        if ($demandWithoutPlan > 0) {
            $notes[] = $demandWithoutPlan . ' product'
                . ($demandWithoutPlan === 1 ? '' : 's')
                . ' have demand for this day but no saved production plan — they are excluded from plan-based requirements.';
        }
        if (empty($products)) {
            $planWithoutRows = 1;
            $notes[] = 'No saved production plan quantities for this date. Save targets in Production Center, or switch to Demand to preview from orders/forecast.';
        }
    }

    if ($source === 'to_produce' && empty($products)) {
        $notes[] = 'Nothing left to produce for this date (demand covered by finished-goods stock, or no demand).';
    }

    if ($source === 'plan' && $hasDaily) {
        $notes[] = 'Demand for this date comes from committed daily orders — ingredient requirements follow the saved production plan, not standing forecast.';
    }

    return [
        'products' => $products,
        'demand_mode' => $demandMode,
        'source_label' => $sourceMeta['label'],
        'source_detail' => $sourceDetail,
        'notes' => $notes,
        'error' => $error,
        'plan_empty' => $source === 'plan' && empty($products),
    ];
}

/**
 * Load formula ingredient rows for the given dough type ids.
 *
 * @param list<int> $doughTypeIds
 * @return array<int, list<array{ingredient_id:int, ingredient_name:string, unit:?string, percentage:float}>>
 */
function bakery_ingredient_requirements_load_formulas(PDO $db, array $doughTypeIds): array
{
    $doughTypeIds = array_values(array_unique(array_filter(array_map('intval', $doughTypeIds))));
    if ($doughTypeIds === [] || !table_exists($db, 'formula_ingredients')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($doughTypeIds), '?'));
    $stmt = $db->prepare(
        "SELECT fi.dough_type_id,
                fi.ingredient_id,
                i.name AS ingredient_name,
                i.unit,
                fi.percentage
         FROM formula_ingredients fi
         JOIN ingredients i ON i.id = fi.ingredient_id
         WHERE fi.dough_type_id IN ({$placeholders})
         ORDER BY fi.dough_type_id, fi.percentage DESC, i.name"
    );
    $stmt->execute($doughTypeIds);

    $byDough = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dt = (int)$row['dough_type_id'];
        if (!isset($byDough[$dt])) {
            $byDough[$dt] = [];
        }
        $byDough[$dt][] = [
            'ingredient_id' => (int)$row['ingredient_id'],
            'ingredient_name' => (string)$row['ingredient_name'],
            'unit' => $row['unit'] !== null ? trim((string)$row['unit']) : null,
            'percentage' => (float)$row['percentage'],
        ];
    }
    return $byDough;
}

/**
 * Load inventory/purchasing catalogue fields for ingredient ids.
 *
 * @param list<int> $ingredientIds
 * @return array<int, array<string,mixed>>
 */
function bakery_ingredient_requirements_load_inventory(PDO $db, array $ingredientIds): array
{
    $ingredientIds = array_values(array_unique(array_filter(array_map('intval', $ingredientIds))));
    if ($ingredientIds === [] || !table_exists($db, 'ingredients')) {
        return [];
    }

    $inventoryReady = function_exists('bakery_ingredients_inventory_ready') && bakery_ingredients_inventory_ready($db);
    $purchasingReady = function_exists('bakery_ingredients_purchasing_ready') && bakery_ingredients_purchasing_ready($db);

    $cols = ['i.id', 'i.name', 'i.unit'];
    if ($inventoryReady) {
        $cols = array_merge($cols, ['i.quantity_on_hand', 'i.reorder_level', 'i.supplier_name']);
    }
    if ($purchasingReady) {
        $cols = array_merge($cols, ['i.package_size', 'i.unit_cost']);
    }

    $placeholders = implode(',', array_fill(0, count($ingredientIds), '?'));
    $stmt = $db->prepare(
        'SELECT ' . implode(', ', $cols) . "
         FROM ingredients i
         WHERE i.id IN ({$placeholders})"
    );
    $stmt->execute($ingredientIds);

    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $unit = $row['unit'] !== null ? trim((string)$row['unit']) : null;
        $onHand = null;
        if ($inventoryReady) {
            $onHand = ($row['quantity_on_hand'] === null || $row['quantity_on_hand'] === '')
                ? null
                : (float)$row['quantity_on_hand'];
        }
        $onHandGrams = ($onHand !== null) ? bakery_ingredient_unit_to_grams($onHand, $unit) : null;
        $byId[$id] = [
            'ingredient_id' => $id,
            'ingredient_name' => (string)$row['name'],
            'catalogue_unit' => $unit,
            'quantity_on_hand' => $onHand,
            'on_hand_grams' => $onHandGrams,
            'stock_unit_comparable' => bakery_ingredient_stock_unit_comparable($unit),
            'reorder_level' => $inventoryReady && isset($row['reorder_level']) && $row['reorder_level'] !== null && $row['reorder_level'] !== ''
                ? (float)$row['reorder_level']
                : null,
            'supplier_name' => $inventoryReady ? ($row['supplier_name'] ?? null) : null,
            'package_size' => $purchasingReady && isset($row['package_size']) && $row['package_size'] !== null && $row['package_size'] !== ''
                ? (float)$row['package_size']
                : null,
            'unit_cost' => $purchasingReady && isset($row['unit_cost']) && $row['unit_cost'] !== null && $row['unit_cost'] !== ''
                ? (float)$row['unit_cost']
                : null,
        ];
    }
    return $byId;
}

/**
 * Format grams for display (whole grams under 10 kg, one decimal kg otherwise companion).
 */
function bakery_ingredient_requirements_format_grams(float $grams): string
{
    if (abs($grams) >= 1000) {
        return number_format($grams / 1000, 3) . ' kg';
    }
    return number_format($grams, 1) . ' g';
}

/**
 * Enrich exploded ingredient totals with inventory comparison and purchase hints.
 *
 * @param list<array<string,mixed>> $ingredients
 * @param array<int, array<string,mixed>> $inventoryById
 * @return list<array<string,mixed>>
 */
function bakery_ingredient_requirements_enrich_stock(array $ingredients, array $inventoryById): array
{
    $inventoryReady = function_exists('bakery_ingredients_inventory_ready') && !empty($inventoryById);

    foreach ($ingredients as &$row) {
        $id = (int)$row['ingredient_id'];
        $inv = $inventoryById[$id] ?? null;
        $required = (float)$row['required_grams'];

        $row['quantity_on_hand'] = $inv['quantity_on_hand'] ?? null;
        $row['catalogue_unit'] = $inv['catalogue_unit'] ?? ($row['catalogue_unit'] ?? null);
        $row['on_hand_grams'] = $inv['on_hand_grams'] ?? null;
        $row['stock_unit_comparable'] = $inv['stock_unit_comparable'] ?? false;
        $row['stock_trustworthy'] = $inventoryReady
            && ($inv !== null)
            && ($row['stock_unit_comparable'] ?? false)
            && ($row['quantity_on_hand'] !== null);
        $row['shortage_grams'] = $row['stock_trustworthy']
            ? max(0.0, $required - (float)$row['on_hand_grams'])
            : null;
        $row['surplus_grams'] = $row['stock_trustworthy']
            ? max(0.0, (float)$row['on_hand_grams'] - $required)
            : null;
        $row['on_hand_display'] = ($inv !== null && $row['quantity_on_hand'] !== null)
            ? bakery_ingredient_format_quantity((float)$row['quantity_on_hand'], $row['catalogue_unit'])
            : null;
        $row['shortage_display'] = null;
        $row['suggested_purchase'] = null;
        $row['suggested_purchase_note'] = null;

        if ($row['stock_trustworthy'] && $row['shortage_grams'] > 0) {
            $shortageInUnit = bakery_ingredient_grams_to_unit((float)$row['shortage_grams'], $row['catalogue_unit']);
            if ($shortageInUnit !== null) {
                $row['shortage_display'] = bakery_ingredient_format_quantity($shortageInUnit, $row['catalogue_unit']);
            }
            $packageSize = $inv['package_size'] ?? null;
            if ($packageSize !== null && $packageSize > 0 && $shortageInUnit !== null) {
                $packages = (int)ceil($shortageInUnit / $packageSize);
                $row['suggested_purchase'] = $packages . ' × '
                    . bakery_ingredient_format_quantity($packageSize, $row['catalogue_unit']);
                $row['suggested_purchase_note'] = 'Recommendation only — does not create a purchase order.';
            } elseif ($shortageInUnit !== null) {
                $row['suggested_purchase'] = bakery_ingredient_format_quantity($shortageInUnit, $row['catalogue_unit']);
                $row['suggested_purchase_note'] = 'Recommendation only — does not create a purchase order.';
            }
        } elseif (!$row['stock_trustworthy'] && $inventoryReady && $inv !== null) {
            if (!$row['stock_unit_comparable']) {
                $row['stock_note'] = 'Reported on hand uses unit “'
                    . ($row['catalogue_unit'] ?? 'unknown')
                    . '” which cannot be compared to formula grams.';
            } elseif ($row['quantity_on_hand'] === null) {
                $row['stock_note'] = 'No reported on-hand quantity.';
            }
        }

        if (!isset($row['stock_note']) && !$row['stock_trustworthy']) {
            $row['stock_note'] = 'Shortage not calculated — ingredient stock is manual and not consumption-linked.';
        }
    }
    unset($row);

    return $ingredients;
}

/**
 * Explode product finished units through baker's-% formulas into ingredient grams.
 *
 * Yield model (matches production.php):
 *   dough_grams = finished_units × products.weight_grams
 *   flour_base  = dough_grams / (SUM(formula percentages) / 100)
 *   ingredient  = flour_base × (ingredient percentage / 100)
 *
 * Batch model (when dough_types.standard_batch_dough_grams is set):
 *   theoretical_dough_batches = dough_grams / standard_batch_dough_grams
 *   reference_yield_units = standard_batch_dough_grams / weight_grams
 *   theoretical_product_batches = finished_units / reference_yield_units
 *
 * @param list<array<string,mixed>> $products
 * @param array<int, list<array{ingredient_id:int, ingredient_name:string, unit:?string, percentage:float}>> $formulasByDough
 * @return array{
 *   ingredients: list<array<string,mixed>>,
 *   contributions: list<array<string,mixed>>,
 *   dough_types: list<array<string,mixed>>,
 *   production_rows: list<array<string,mixed>>,
 *   exceptions: list<array{code:string, message:string, product_id:?int, product_name:?string, dough_type_id:?int, dough_type_name:?string, severity:string}>,
 *   totals: array{products:int, units:int, dough_grams:float, ingredients:int, exceptions:int}
 * }
 */
function bakery_ingredient_requirements_explode(array $products, array $formulasByDough): array
{
    $exceptions = [];
    $ingredientTotals = [];
    $contributions = [];
    $doughTypeTotals = [];
    $productionRows = [];
    $batchWarnings = [];

    $totalUnits = 0;
    $totalDough = 0.0;
    $productsIncluded = 0;

    foreach ($products as $product) {
        $productId = (int)$product['product_id'];
        $productName = (string)$product['product_name'];
        $qty = (int)$product['quantity'];
        $doughTypeId = $product['dough_type_id'];
        $doughTypeName = $product['dough_type_name'];
        $weight = $product['weight_grams'];
        $standardBatch = $product['standard_batch_dough_grams'] ?? null;

        if ($qty <= 0) {
            continue;
        }

        $rowBase = [
            'product_id' => $productId,
            'product_name' => $productName,
            'dough_type_id' => $doughTypeId,
            'dough_type_name' => $doughTypeName,
            'planned_quantity' => $qty,
            'demand_quantity' => (int)($product['demand_quantity'] ?? 0),
            'planned_quantity_basis' => (string)($product['quantity_basis'] ?? ''),
            'plan_vs_demand_delta' => (int)($product['plan_vs_demand_delta'] ?? 0),
            'weight_grams' => $weight,
            'dough_grams' => null,
            'flour_base_grams' => null,
            'formula_total_percentage' => null,
            'ingredients' => [],
            'batches' => $product['batches'] ?? bakery_ingredient_requirements_product_batches($qty, $weight ?? 0, 0.0, $standardBatch),
            'explodable' => false,
        ];

        if ($doughTypeId === null || $doughTypeId <= 0) {
            $exceptions[] = [
                'code' => 'no_formula_mapping',
                'message' => $productName . ': no dough type assigned (no formula mapping).',
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_id' => null,
                'dough_type_name' => null,
                'severity' => 'error',
            ];
            $productionRows[] = $rowBase;
            continue;
        }

        if ($weight === null || $weight <= 0) {
            $exceptions[] = [
                'code' => 'missing_yield_weight',
                'message' => $productName . ': missing or zero weight_grams — finished units cannot be converted to dough weight.',
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName,
                'severity' => 'error',
            ];
            $productionRows[] = $rowBase;
            continue;
        }

        $formula = $formulasByDough[$doughTypeId] ?? [];
        if ($formula === []) {
            $exceptions[] = [
                'code' => 'no_formula',
                'message' => $productName . ': dough type “'
                    . ($doughTypeName ?? ('#' . $doughTypeId))
                    . '” has no formula ingredients.',
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName,
                'severity' => 'error',
            ];
            $productionRows[] = $rowBase;
            continue;
        }

        $totalPct = 0.0;
        $badPct = false;
        foreach ($formula as $line) {
            if (!is_finite($line['percentage']) || $line['percentage'] < 0) {
                $badPct = true;
                break;
            }
            $totalPct += $line['percentage'];
        }

        if ($badPct) {
            $exceptions[] = [
                'code' => 'invalid_percentage',
                'message' => $productName . ': formula for “'
                    . ($doughTypeName ?? ('#' . $doughTypeId))
                    . '” has an invalid ingredient percentage.',
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName,
                'severity' => 'error',
            ];
            $productionRows[] = $rowBase;
            continue;
        }

        if ($totalPct <= 0) {
            $exceptions[] = [
                'code' => 'zero_yield_percentage',
                'message' => $productName . ': formula for “'
                    . ($doughTypeName ?? ('#' . $doughTypeId))
                    . '” has zero total baker’s percentage (cannot invert to flour base).',
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName,
                'severity' => 'error',
            ];
            $productionRows[] = $rowBase;
            continue;
        }

        $doughGrams = $qty * $weight;
        $flourBase = $doughGrams / ($totalPct / 100.0);
        $productsIncluded++;
        $totalUnits += $qty;
        $totalDough += $doughGrams;

        $batchInfo = bakery_ingredient_requirements_product_batches($qty, $weight, $doughGrams, $standardBatch);
        if (!$batchInfo['batch_reference_configured'] && !isset($batchWarnings[$doughTypeId])) {
            $batchWarnings[$doughTypeId] = true;
            $exceptions[] = [
                'code' => 'no_batch_reference',
                'message' => ($doughTypeName ?? ('Dough #' . $doughTypeId))
                    . ': no standard_batch_dough_grams configured — dough batches shown as continuous grams only.',
                'product_id' => null,
                'product_name' => null,
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName,
                'severity' => 'info',
            ];
        }

        if (!isset($doughTypeTotals[$doughTypeId])) {
            $doughTypeTotals[$doughTypeId] = [
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName ?? ('Dough #' . $doughTypeId),
                'total_percentage' => $totalPct,
                'standard_batch_dough_grams' => $standardBatch,
                'dough_grams' => 0.0,
                'flour_base_grams' => 0.0,
                'units' => 0,
                'theoretical_dough_batches' => null,
                'suggested_whole_dough_batches' => null,
                'products' => [],
            ];
        }
        $doughTypeTotals[$doughTypeId]['dough_grams'] += $doughGrams;
        $doughTypeTotals[$doughTypeId]['flour_base_grams'] += $flourBase;
        $doughTypeTotals[$doughTypeId]['units'] += $qty;

        $productIngredients = [];
        foreach ($formula as $line) {
            $need = $flourBase * ($line['percentage'] / 100.0);
            $ingredientId = $line['ingredient_id'];
            $unit = $line['unit'];
            if ($unit === null || trim($unit) === '') {
                $exceptions[] = [
                    'code' => 'missing_ingredient_unit',
                    'message' => $line['ingredient_name'] . ': ingredient has no catalogue unit (formula still calculated in grams).',
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'dough_type_id' => $doughTypeId,
                    'dough_type_name' => $doughTypeName,
                    'severity' => 'warn',
                ];
            }

            if (!isset($ingredientTotals[$ingredientId])) {
                $ingredientTotals[$ingredientId] = [
                    'ingredient_id' => $ingredientId,
                    'ingredient_name' => $line['ingredient_name'],
                    'required_grams' => 0.0,
                    'unit_note' => 'g',
                    'catalogue_unit' => $unit,
                    'contributors' => [],
                ];
            }
            $ingredientTotals[$ingredientId]['required_grams'] += $need;

            $contribKey = $ingredientId . ':' . $productId . ':' . $doughTypeId;
            $contributions[$contribKey] = [
                'ingredient_id' => $ingredientId,
                'ingredient_name' => $line['ingredient_name'],
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughTypeName ?? ('Dough #' . $doughTypeId),
                'finished_units' => $qty,
                'weight_grams' => $weight,
                'dough_grams' => $doughGrams,
                'formula_percentage' => $line['percentage'],
                'total_percentage' => $totalPct,
                'flour_base_grams' => $flourBase,
                'required_grams' => $need,
            ];

            $ingredientTotals[$ingredientId]['contributors'][] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type_name' => $doughTypeName ?? ('Dough #' . $doughTypeId),
                'finished_units' => $qty,
                'formula_percentage' => $line['percentage'],
                'required_grams' => $need,
            ];

            $productIngredients[] = [
                'ingredient_id' => $ingredientId,
                'ingredient_name' => $line['ingredient_name'],
                'required_grams' => $need,
                'formula_percentage' => $line['percentage'],
            ];
        }

        $rowBase['dough_grams'] = $doughGrams;
        $rowBase['flour_base_grams'] = $flourBase;
        $rowBase['formula_total_percentage'] = $totalPct;
        $rowBase['ingredients'] = $productIngredients;
        $rowBase['batches'] = $batchInfo;
        $rowBase['explodable'] = true;
        $productionRows[] = $rowBase;

        $doughTypeTotals[$doughTypeId]['products'][] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $qty,
            'demand_quantity' => (int)($product['demand_quantity'] ?? 0),
            'weight_grams' => $weight,
            'dough_grams' => $doughGrams,
            'batches' => $batchInfo,
        ];
    }

    foreach ($doughTypeTotals as $dtId => &$dough) {
        $batchRef = $dough['standard_batch_dough_grams'];
        if ($batchRef !== null && (float)$batchRef > 0) {
            $theoretical = (float)$dough['dough_grams'] / (float)$batchRef;
            $dough['theoretical_dough_batches'] = $theoretical;
            $dough['suggested_whole_dough_batches'] = (int)ceil($theoretical);
        }
    }
    unset($dough);

    $ingredients = array_values($ingredientTotals);
    usort($ingredients, static function ($a, $b) {
        return strcasecmp($a['ingredient_name'], $b['ingredient_name']);
    });

    $doughTypes = array_values($doughTypeTotals);
    usort($doughTypes, static function ($a, $b) {
        return strcasecmp($a['dough_type_name'], $b['dough_type_name']);
    });

    $contribList = array_values($contributions);
    usort($contribList, static function ($a, $b) {
        $cmp = strcasecmp($a['ingredient_name'], $b['ingredient_name']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcasecmp($a['product_name'], $b['product_name']);
    });

    $errorExceptions = array_filter($exceptions, static fn($ex) => ($ex['severity'] ?? 'error') === 'error');

    return [
        'ingredients' => $ingredients,
        'contributions' => $contribList,
        'dough_types' => $doughTypes,
        'production_rows' => $productionRows,
        'exceptions' => $exceptions,
        'totals' => [
            'products' => $productsIncluded,
            'units' => $totalUnits,
            'dough_grams' => $totalDough,
            'ingredients' => count($ingredients),
            'exceptions' => count($errorExceptions),
        ],
    ];
}

/**
 * Build demand-side comparison totals for the same product set shape.
 *
 * @return array{ingredients:list<array<string,mixed>>, totals:array<string,mixed>}|null
 */
function bakery_ingredient_requirements_demand_comparison(PDO $db, string $date, string $activeSource): array
{
    if ($activeSource === 'demand') {
        return [
            'ingredients' => [],
            'totals' => ['units' => 0, 'dough_grams' => 0.0],
            'skipped' => true,
        ];
    }

    $demandLoad = bakery_ingredient_requirements_load_products($db, $date, 'demand');
    if ($demandLoad['error'] !== null || empty($demandLoad['products'])) {
        return [
            'ingredients' => [],
            'totals' => ['units' => 0, 'dough_grams' => 0.0],
            'skipped' => true,
        ];
    }

    $doughTypeIds = [];
    foreach ($demandLoad['products'] as $product) {
        if (!empty($product['dough_type_id'])) {
            $doughTypeIds[] = (int)$product['dough_type_id'];
        }
    }
    $formulas = bakery_ingredient_requirements_load_formulas($db, $doughTypeIds);
    $exploded = bakery_ingredient_requirements_explode($demandLoad['products'], $formulas);

    $byIngredient = [];
    foreach ($exploded['ingredients'] as $row) {
        $byIngredient[(int)$row['ingredient_id']] = (float)$row['required_grams'];
    }

    return [
        'ingredients' => $byIngredient,
        'totals' => [
            'units' => (int)$exploded['totals']['units'],
            'dough_grams' => (float)$exploded['totals']['dough_grams'],
        ],
        'skipped' => false,
    ];
}

/**
 * Build the full planner result for a date + quantity source.
 *
 * @return array<string, mixed>
 */
function bakery_ingredient_requirements_build(PDO $db, string $date, string $source = 'plan'): array
{
    $date = bakery_ingredient_requirements_resolve_date($date);
    $source = bakery_ingredient_requirements_resolve_source($source);
    $loaded = bakery_ingredient_requirements_load_products($db, $date, $source);
    $inventoryReady = function_exists('bakery_ingredients_inventory_ready') && bakery_ingredients_inventory_ready($db);
    $purchasingReady = function_exists('bakery_ingredients_purchasing_ready') && bakery_ingredients_purchasing_ready($db);
    $batchReady = bakery_ingredient_batch_reference_ready($db);

    $result = [
        'date' => $date,
        'weekday' => bakery_standing_day_from_date($date),
        'source' => $source,
        'source_label' => $loaded['source_label'],
        'source_detail' => $loaded['source_detail'],
        'demand_mode' => $loaded['demand_mode'],
        'notes' => $loaded['notes'],
        'error' => $loaded['error'],
        'plan_empty' => (bool)($loaded['plan_empty'] ?? false),
        'products' => $loaded['products'],
        'ingredients' => [],
        'contributions' => [],
        'dough_types' => [],
        'production_rows' => [],
        'exceptions' => [],
        'totals' => [
            'products' => 0,
            'units' => 0,
            'dough_grams' => 0.0,
            'ingredients' => 0,
            'exceptions' => 0,
        ],
        'comparison' => [
            'demand_units' => 0,
            'plan_units' => 0,
            'delta_units' => 0,
            'demand_dough_grams' => 0.0,
            'plan_dough_grams' => 0.0,
        ],
        'demand_ingredient_map' => [],
        'purchase_suggestions' => [],
        'inventory_ready' => $inventoryReady,
        'purchasing_ready' => $purchasingReady,
        'batch_reference_ready' => $batchReady,
        'on_hand_trustworthy' => false,
        'on_hand_note' => $inventoryReady
            ? 'On Hand is shown only when the ingredient catalogue unit converts to grams (g, kg, lb, oz). Stock is manual and not updated by production.'
            : 'Ingredient inventory columns are not installed — requirements only, no on-hand comparison.',
        'yield_note' => 'Yield uses finished units × products.weight_grams as total dough grams, then baker’s-% invert: flour_base = dough_grams ÷ (Σ% ÷ 100); each ingredient = flour_base × (% ÷ 100).',
        'batch_note' => $batchReady
            ? 'When standard_batch_dough_grams is set on a dough type, theoretical batches = dough_grams ÷ batch reference. Suggested whole batches = ceil(theoretical) and do not change the production plan.'
            : 'Batch reference column is not installed — only continuous dough grams are shown. Run migration 023 to enable batch planning.',
        'unit_note' => 'Formula requirements are always grams. Mass catalogue units (g, kg, lb, oz) convert for shortage; volume/count units are not mixed into totals.',
        'stock_formula_note' => 'When comparable: shortage_grams = max(0, required_grams − on_hand_grams). Suggested purchase rounds shortage up to package_size when configured.',
    ];

    if ($loaded['error'] !== null) {
        return $result;
    }

    $doughTypeIds = [];
    foreach ($loaded['products'] as $product) {
        if (!empty($product['dough_type_id'])) {
            $doughTypeIds[] = (int)$product['dough_type_id'];
        }
        if ($source === 'plan') {
            $result['comparison']['plan_units'] += (int)$product['quantity'];
            $result['comparison']['plan_dough_grams'] += (float)$product['dough_grams'];
            $result['comparison']['demand_units'] += (int)$product['demand_quantity'];
        }
    }

    $formulas = bakery_ingredient_requirements_load_formulas($db, $doughTypeIds);
    $exploded = bakery_ingredient_requirements_explode($loaded['products'], $formulas);

    $ingredientIds = array_map(static fn($row) => (int)$row['ingredient_id'], $exploded['ingredients']);
    $inventoryById = bakery_ingredient_requirements_load_inventory($db, $ingredientIds);
    $ingredients = bakery_ingredient_requirements_enrich_stock($exploded['ingredients'], $inventoryById);

    $demandComparison = bakery_ingredient_requirements_demand_comparison($db, $date, $source);
    $result['demand_ingredient_map'] = $demandComparison['ingredients'];
    if (!$demandComparison['skipped']) {
        $result['comparison']['demand_units'] = (int)$demandComparison['totals']['units'];
        $result['comparison']['demand_dough_grams'] = (float)$demandComparison['totals']['dough_grams'];
    }
    $result['comparison']['delta_units'] = $result['comparison']['plan_units'] - $result['comparison']['demand_units'];

    foreach ($ingredients as &$ingredient) {
        $id = (int)$ingredient['ingredient_id'];
        $demandGrams = isset($demandComparison['ingredients'][$id])
            ? (float)$demandComparison['ingredients'][$id]
            : null;
        $ingredient['demand_grams'] = $demandGrams;
        $ingredient['plan_vs_demand_grams'] = ($demandGrams !== null)
            ? (float)$ingredient['required_grams'] - $demandGrams
            : null;
    }
    unset($ingredient);

    $purchaseSuggestions = [];
    foreach ($ingredients as $row) {
        if ($row['shortage_grams'] !== null && $row['shortage_grams'] > 0) {
            $purchaseSuggestions[] = [
                'ingredient_id' => (int)$row['ingredient_id'],
                'ingredient_name' => $row['ingredient_name'],
                'required_grams' => (float)$row['required_grams'],
                'on_hand_display' => $row['on_hand_display'],
                'shortage_display' => $row['shortage_display'],
                'shortage_grams' => (float)$row['shortage_grams'],
                'suggested_purchase' => $row['suggested_purchase'],
                'suggested_purchase_note' => $row['suggested_purchase_note'],
            ];
        }
    }

    $trustworthyCount = 0;
    foreach ($ingredients as $row) {
        if (!empty($row['stock_trustworthy'])) {
            $trustworthyCount++;
        }
    }

    $result['ingredients'] = $ingredients;
    $result['contributions'] = $exploded['contributions'];
    $result['dough_types'] = $exploded['dough_types'];
    $result['production_rows'] = $exploded['production_rows'];
    $result['exceptions'] = $exploded['exceptions'];
    $result['totals'] = $exploded['totals'];
    $result['purchase_suggestions'] = $purchaseSuggestions;
    $result['on_hand_trustworthy'] = $inventoryReady && $trustworthyCount > 0;

    return $result;
}
