<?php
/**
 * Baker "Mix today" helpers — dough batches + starter feedings for one delivery date.
 * Filtered by baker_product_lines (Juan Carlos → Sour Flour/Traditional, Niko → Pan Dulce).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/production_plan.php';
require_once __DIR__ . '/product_inventory.php';
require_once __DIR__ . '/product_pack_yields.php';
require_once __DIR__ . '/formula_units.php';

/**
 * Build the baker mix sheet for a delivery date.
 *
 * @param list<int>|null $bakerProductIds null = no baker filter (manager/admin)
 * @return array{
 *   date: string,
 *   bake_list: array,
 *   batches: array<string, array>,
 *   starter_feedings: array,
 *   batch_count: int,
 *   unit_count: int
 * }
 */
function bakery_baker_mix_sheet(PDO $db, string $date, ?array $bakerProductIds = null): array
{
    $bakeList = bakery_production_bake_list($db, $date);
    $batches = [];
    $starterFeedings = [];
    $unitCount = 0;

    $bakeByProduct = [];
    foreach ($bakeList['items'] as $bakeItem) {
        $bakeByProduct[(int)$bakeItem['product_id']] = $bakeItem;
    }
    if ($bakeByProduct === []) {
        return [
            'date' => $date,
            'bake_list' => $bakeList,
            'batches' => [],
            'starter_feedings' => [],
            'batch_count' => 0,
            'unit_count' => 0,
        ];
    }

    $productIds = array_keys($bakeByProduct);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $bakerClause = '';
    if (is_array($bakerProductIds)) {
        $bakerClause = empty($bakerProductIds)
            ? ' AND 1 = 0'
            : ' AND p.id IN (' . implode(',', array_map('intval', $bakerProductIds)) . ')';
    }

    $orders = $db->prepare("
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            p.weight_grams,
            p.dough_type_id,
            dt.name AS dough_type_name,
            pl.name AS product_line_name
        FROM products p
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
        WHERE p.id IN ({$placeholders}) {$bakerClause}
        ORDER BY dt.name, p.name
    ");
    $orders->execute($productIds);

    $productionRows = [];
    foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        $bakeItem = $bakeByProduct[$pid] ?? null;
        if ($bakeItem === null) {
            continue;
        }
        $qty = (int)$bakeItem['bake_quantity'];
        $demandQty = (int)$bakeItem['demand_quantity'];
        if ($qty <= 0 && $demandQty <= 0) {
            continue;
        }
        $row['planned_quantity'] = $qty;
        $row['demand_quantity'] = $demandQty;
        $row['total_weight_grams'] = $qty * (int)($row['weight_grams'] ?? 0);
        $productionRows[] = $row;
        $unitCount += $qty;
    }

    $doughTypeFormulas = [];
    $doughTypeIds = array_values(array_unique(array_filter(array_map(
        static fn($r) => (int)($r['dough_type_id'] ?? 0),
        $productionRows
    ))));
    if ($doughTypeIds !== []) {
        $ph = implode(',', array_fill(0, count($doughTypeIds), '?'));
        $percentageStmt = $db->prepare("
            SELECT dough_type_id, SUM(percentage) AS total_percentage
            FROM formula_ingredients
            WHERE dough_type_id IN ($ph)
            GROUP BY dough_type_id
        ");
        $percentageStmt->execute($doughTypeIds);
        $percentages = $percentageStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($doughTypeIds as $dtId) {
            $doughTypeFormulas[$dtId] = [
                'total_percentage' => (float)($percentages[$dtId] ?? 0),
            ];
        }
    }

    foreach ($productionRows as $item) {
        $doughType = $item['dough_type_name'] ?: bakery_t('baker_mix.unclassified');
        $doughTypeId = (int)($item['dough_type_id'] ?? 0);
        if (!isset($batches[$doughType])) {
            $batches[$doughType] = [
                'dough_type_id' => $doughTypeId,
                'dough_type_name' => $doughType,
                'product_line_name' => (string)($item['product_line_name'] ?? ''),
                'planned_units' => 0,
                'total_weight_grams' => 0,
                'formula' => $doughTypeFormulas[$doughTypeId] ?? ['total_percentage' => 0.0],
                'ingredients' => [],
                'products' => [],
                'pan_dulce_hint' => null,
            ];
        }
        $batches[$doughType]['planned_units'] += (int)$item['planned_quantity'];
        $batches[$doughType]['total_weight_grams'] += (int)($item['total_weight_grams'] ?? 0);
        $batches[$doughType]['products'][] = [
            'product_id' => (int)$item['product_id'],
            'product_name' => (string)$item['product_name'],
            'planned_quantity' => (int)$item['planned_quantity'],
            'weight_grams' => $item['weight_grams'] !== null ? (int)$item['weight_grams'] : null,
        ];
    }

    if ($batches !== []) {
        $ingredientLoad = $db->prepare("
            SELECT i.id AS ingredient_id, i.name, i.unit, fi.percentage
            FROM formula_ingredients fi
            JOIN ingredients i ON fi.ingredient_id = i.id
            WHERE fi.dough_type_id = ?
            ORDER BY fi.percentage DESC
        ");
        foreach ($batches as &$batch) {
            $dtId = (int)$batch['dough_type_id'];
            $totalPct = (float)($batch['formula']['total_percentage'] ?? 0);
            if ($dtId > 0 && $totalPct > 0) {
                $ingredientLoad->execute([$dtId]);
                $batch['ingredients'] = $ingredientLoad->fetchAll(PDO::FETCH_ASSOC);
            }
            if (strcasecmp((string)$batch['product_line_name'], 'Pan Dulce') === 0) {
                $batch['pan_dulce_hint'] = bakery_baker_mix_pan_dulce_hint(
                    $db,
                    $dtId,
                    (int)$batch['planned_units']
                );
            }
        }
        unset($batch);
    }

    uasort($batches, static function (array $a, array $b): int {
        $aHas = !empty($a['ingredients']);
        $bHas = !empty($b['ingredients']);
        if ($aHas !== $bHas) {
            return $aHas ? -1 : 1;
        }
        return strcmp((string)$a['dough_type_name'], (string)$b['dough_type_name']);
    });

    $starterFeedings = bakery_baker_mix_starter_feedings($db, $batches);

    return [
        'date' => $date,
        'bake_list' => $bakeList,
        'batches' => $batches,
        'starter_feedings' => $starterFeedings,
        'batch_count' => count($batches),
        'unit_count' => $unitCount,
    ];
}

/**
 * Pan Dulce gallon / tray language for the mix card.
 *
 * @return array{gallons: float, trays: float, pieces: int}|null
 */
function bakery_baker_mix_pan_dulce_hint(PDO $db, int $doughTypeId, int $pieces): ?array
{
    if ($doughTypeId <= 0 || $pieces <= 0 || !function_exists('bakery_pack_dough_yield')) {
        return null;
    }
    $yield = bakery_pack_dough_yield($db, $doughTypeId);
    if (!$yield) {
        return null;
    }
    $traysPerGallon = (float)($yield['trays_per_gallon'] ?? 0);
    $piecesPerTray = (int)($yield['pieces_per_tray'] ?? 0);
    if ($traysPerGallon <= 0 || $piecesPerTray <= 0) {
        return null;
    }
    return [
        'gallons' => $pieces / ($traysPerGallon * $piecesPerTray),
        'trays' => $pieces / $piecesPerTray,
        'pieces' => $pieces,
    ];
}

/**
 * Starter feeding amounts derived from scaled formula ingredients (IDs 6 and 13).
 *
 * @param array<string, array> $batches
 * @return array<string, array>
 */
function bakery_baker_mix_starter_feedings(PDO $db, array $batches): array
{
    $starterNeeds = [
        'starter' => 0.0,
        'starter_liquido' => 0.0,
    ];

    foreach ($batches as $batch) {
        $dtId = (int)($batch['dough_type_id'] ?? 0);
        $totalPct = (float)($batch['formula']['total_percentage'] ?? 0);
        $totalWeight = (float)($batch['total_weight_grams'] ?? 0);
        if ($dtId <= 0 || $totalPct <= 0 || $totalWeight <= 0) {
            continue;
        }
        $totalFlour = $totalWeight / ($totalPct / 100);
        foreach ($batch['ingredients'] as $ingredient) {
            $ingredientId = (int)($ingredient['ingredient_id'] ?? 0);
            $amount = $totalFlour * (((float)($ingredient['percentage'] ?? 0)) / 100);
            if ($ingredientId === 6) {
                $starterNeeds['starter'] += $amount;
            } elseif ($ingredientId === 13) {
                $starterNeeds['starter_liquido'] += $amount;
            }
        }
    }

    $feedings = [];
    if ($starterNeeds['starter'] <= 0 && $starterNeeds['starter_liquido'] <= 0) {
        return $feedings;
    }

    if ($starterNeeds['starter'] > 0) {
        $feedings['starter'] = [
            'total_needed' => $starterNeeds['starter'],
            'seed_starter' => $starterNeeds['starter'] / 12.5,
            'flour' => ($starterNeeds['starter'] / 12.5) * 7,
            'water' => ($starterNeeds['starter'] / 12.5) * 4.5,
        ];
    }
    if ($starterNeeds['starter_liquido'] > 0) {
        $feedings['starter_liquido'] = [
            'total_needed' => $starterNeeds['starter_liquido'],
            'seed_starter' => $starterNeeds['starter_liquido'] / 17.5,
            'flour' => ($starterNeeds['starter_liquido'] / 17.5) * 7,
            'water' => ($starterNeeds['starter_liquido'] / 17.5) * 9.5,
        ];
    }

    $totalSeedStarter = 0.0;
    if (isset($feedings['starter'])) {
        $totalSeedStarter += $feedings['starter']['seed_starter'];
    }
    if (isset($feedings['starter_liquido'])) {
        $totalSeedStarter += $feedings['starter_liquido']['seed_starter'];
    }
    if ($totalSeedStarter > 0) {
        $feedings['seed_starter'] = [
            'total_needed' => $totalSeedStarter,
            'mother_starter' => $totalSeedStarter / 10,
            'flour' => ($totalSeedStarter / 10) * 4,
            'water' => ($totalSeedStarter / 10) * 5,
        ];
    }

    return $feedings;
}

/** Echo a scaled ingredient list for one mix card. */
function bakery_baker_mix_echo_formula(array $ingredients, float $totalFlourGrams, float $totalDoughGrams, bool $isBaker): void
{
    $doughClassification = ['liquid' => false, 'kind' => 'dry', 'density_lb_per_gal' => null];
    echo '<ul class="bm-formula" data-formula-units data-unit-mode="'
        . htmlspecialchars(bakery_formula_default_unit_mode($isBaker), ENT_QUOTES, 'UTF-8')
        . '">';
    foreach ($ingredients as $ingredient) {
        $amount = $totalFlourGrams * (((float)($ingredient['percentage'] ?? 0)) / 100);
        $classification = bakery_formula_classify_ingredient($ingredient['name'] ?? '', $ingredient['unit'] ?? '');
        echo '<li class="' . (!empty($classification['liquid']) ? 'is-liquid' : '') . '"'
            . ' data-grams="' . htmlspecialchars((string)$amount, ENT_QUOTES, 'UTF-8') . '"'
            . ' data-liquid="' . (!empty($classification['liquid']) ? '1' : '0') . '"';
        if (!empty($classification['density_lb_per_gal'])) {
            echo ' data-density="' . htmlspecialchars((string)$classification['density_lb_per_gal'], ENT_QUOTES, 'UTF-8') . '"';
        }
        echo '><span>' . htmlspecialchars((string)$ingredient['name'], ENT_QUOTES, 'UTF-8') . '</span>'
            . '<strong class="ingredient-amount">' . bakery_formula_amount_markup($amount, $classification) . '</strong></li>';
    }
    echo '<li class="bm-formula__total" data-grams="' . htmlspecialchars((string)$totalDoughGrams, ENT_QUOTES, 'UTF-8') . '" data-liquid="0">'
        . '<span>' . htmlspecialchars(bakery_t('formula.total_dough'), ENT_QUOTES, 'UTF-8') . '</span>'
        . '<strong class="ingredient-amount">' . bakery_formula_amount_markup($totalDoughGrams, $doughClassification) . '</strong></li>';
    echo '</ul>';
}
