<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/daily_order_generation.php';
require_once 'includes/product_inventory.php';
require_once 'includes/production_plan.php';
require_once 'includes/production_workflow_strip.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/exception_desk.php';
require_once 'includes/formula_units.php';
require_once 'includes/product_pack_yields.php';
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Days of the week for display
$days = bakery_day_names();

if (!function_exists('bakery_production_pan_dulce_batch_hint')) {
    /** Translate a Pan Dulce dough total back into the gallon/tray language used at the bench. */
    function bakery_production_pan_dulce_batch_hint(PDO $db, int $doughTypeId, int $pieces): ?array {
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
}

if (!function_exists('bakery_production_pan_dulce_product_hint')) {
    /** Translate remaining pieces into whole trays plus loose pieces when configured. */
    function bakery_production_pan_dulce_product_hint(PDO $db, int $productId, int $pieces): ?array {
        if ($productId <= 0 || $pieces <= 0 || !function_exists('bakery_pack_product_yield')) {
            return null;
        }
        $yield = bakery_pack_product_yield($db, $productId);
        if (!$yield || strtolower((string)($yield['input_unit'] ?? '')) !== 'tray') {
            return null;
        }
        $piecesPerTray = (int)round((float)($yield['pieces_per_input'] ?? $yield['pieces_per_tray'] ?? 0));
        if ($piecesPerTray <= 0) {
            return null;
        }
        return [
            'trays' => intdiv($pieces, $piecesPerTray),
            'loose' => $pieces % $piecesPerTray,
            'pieces_per_tray' => $piecesPerTray,
        ];
    }
}

if (!function_exists('bakery_production_echo_formula_items')) {
    /** Shared baker mix list: ingredient name + scaled amount. */
    function bakery_production_echo_formula_items(array $ingredients, float $totalFlourGrams, float $totalDoughGrams, bool $isBaker): void {
        $doughClassification = ['liquid' => false, 'kind' => 'dry', 'density_lb_per_gal' => null];
        echo '<ul class="bp-formula__list" data-formula-units data-unit-mode="'
            . htmlspecialchars(bakery_formula_default_unit_mode($isBaker), ENT_QUOTES, 'UTF-8')
            . '">';
        foreach ($ingredients as $ingredient) {
            $amount = $totalFlourGrams * (((float)($ingredient['percentage'] ?? 0)) / 100);
            $classification = bakery_formula_classify_ingredient($ingredient['name'] ?? '', $ingredient['unit'] ?? '');
            echo '<li class="' . (!empty($classification['liquid']) ? 'is-liquid' : '') . '"'
                . ' data-grams="' . htmlspecialchars((string) $amount, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-liquid="' . (!empty($classification['liquid']) ? '1' : '0') . '"';
            if (!empty($classification['density_lb_per_gal'])) {
                echo ' data-density="' . htmlspecialchars((string) $classification['density_lb_per_gal'], ENT_QUOTES, 'UTF-8') . '"';
            }
            echo '><span>' . htmlspecialchars((string)$ingredient['name'], ENT_QUOTES, 'UTF-8') . '</span>'
                . '<strong class="ingredient-amount">' . bakery_formula_amount_markup($amount, $classification) . '</strong></li>';
        }
        echo '<li class="bp-formula-total" data-grams="' . htmlspecialchars((string) $totalDoughGrams, ENT_QUOTES, 'UTF-8') . '" data-liquid="0">'
            . '<span>' . htmlspecialchars(bakery_t('formula.total_dough'), ENT_QUOTES, 'UTF-8') . '</span>'
            . '<strong class="ingredient-amount">' . bakery_formula_amount_markup($totalDoughGrams, $doughClassification) . '</strong></li>';
        echo '</ul>';
    }
}

// Date picker (default: tomorrow — bake for the next day)
$defaultProductionDate = date('Y-m-d', strtotime('+1 day'));
$selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : $defaultProductionDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || strtotime($selectedDate) === false) {
    $selectedDate = $defaultProductionDate;
}
$selectedDay = bakery_standing_day_from_date($selectedDate);
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
try {
    bakery_fill_demand_horizon($db, $selectedDate, ['record_event' => false]);
} catch (Throwable $e) {
    error_log('production demand horizon: ' . $e->getMessage());
}
$pageReturnKey = $returnTarget['key'] ?? null;
$attentionShortfall = (string)($_GET['attention'] ?? '') === 'shortfall';
$attentionLabel = $attentionShortfall
    ? (function_exists('bakery_t') ? bakery_t('ops.attention.shortfall') : 'Showing products with a finished-goods shortfall')
    : '';
$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$bakerProductClause = '';
if (is_array($bakerProductIds)) {
    $bakerProductClause = empty($bakerProductIds)
        ? ' AND 1 = 0'
        : ' AND p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
}
$inventoryNotice = '';
$inventoryError = '';
$productionDeskNotice = !empty($_GET['notice']) ? substr(trim((string)$_GET['notice']), 0, 160) : '';
$isBaker = function_exists('bakery_user_has_role') && bakery_user_has_role(['baker']);
$inventoryReady = bakery_inventory_ready($db);

if (!function_exists('bakery_production_user_message')) {
    function bakery_production_user_message(Throwable $e, bool $forBaker): string {
        $msg = $e->getMessage();
        if (stripos($msg, 'inventory is not installed') !== false || stripos($msg, 'migrations') !== false) {
            return $forBaker
                ? bakery_t('production.error_inventory_baker')
                : bakery_t('production.error_inventory_ops');
        }
        if (stripos($msg, 'stale_production_count') !== false) {
            return bakery_t('production.error_stale_made');
        }
        if (stripos($msg, 'at least one produced') !== false) {
            return bakery_t('production.error_enter_units');
        }
        if ($e instanceof InvalidArgumentException) {
            return $msg;
        }
        return $forBaker
            ? bakery_t('production.error_not_saved_baker')
            : bakery_t('production.error_not_saved_ops');
    }
}

if (isset($_GET['saved'])) {
    $savedUnits = filter_var($_GET['saved'], FILTER_VALIDATE_INT);
    if ($savedUnits !== false && $savedUnits > 0) {
        $savedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? (string)$_GET['date'] : $selectedDate;
        $inventoryNotice = $savedUnits === 1
            ? bakery_t('production.saved_notice', [
                'count' => number_format($savedUnits),
                'date' => date('l, M j', strtotime($savedDate)),
            ])
            : bakery_t('production.saved_notice_plural', [
                'count' => number_format($savedUnits),
                'date' => date('l, M j', strtotime($savedDate)),
            ]);
    }
}
$savedWasteNotice = filter_var($_GET['waste'] ?? 0, FILTER_VALIDATE_INT);
if ($savedWasteNotice !== false && $savedWasteNotice > 0) {
    $wasteNotice = bakery_t('production.waste_saved_notice', ['count' => number_format($savedWasteNotice)]);
    $inventoryNotice = trim(($inventoryNotice ? $inventoryNotice . ' ' : '') . $wasteNotice);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_production') {
    try {
        if (!$inventoryReady) {
            throw new RuntimeException(bakery_t('production.error_inventory_ops'));
        }
        $productionDate = bakery_inventory_validate_date((string)($_POST['production_date'] ?? ''));
        $quantities = $_POST['produced'] ?? [];
        $wasteQuantities = $_POST['waste'] ?? [];
        if (!is_array($quantities)) {
            throw new InvalidArgumentException(bakery_t('production.error_enter_units'));
        }
        if (!is_array($wasteQuantities)) $wasteQuantities = [];
        $expectedMade = $_POST['produced_was'] ?? [];
        if (!is_array($expectedMade)) {
            $expectedMade = [];
        }
        $savedUnits = 0;
        $savedWaste = 0;
        $savedProducts = 0;
        $db->beginTransaction();
        $postedProductIds = array_unique(array_merge(array_keys($quantities), array_keys($wasteQuantities)));
        foreach ($postedProductIds as $productId) {
            $quantity = $quantities[$productId] ?? 0;
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            $waste = filter_var($wasteQuantities[$productId] ?? 0, FILTER_VALIDATE_INT);
            if ($quantity === false || $waste === false || $quantity < 0 || $waste < 0) {
                throw new InvalidArgumentException(bakery_t('production.error_enter_units'));
            }
            if ($quantity === 0 && $waste === 0) {
                continue;
            }
            if ((int)$productId <= 0) {
                throw new InvalidArgumentException(bakery_t('production.error_product_id'));
            }
            if (is_array($bakerProductIds) && !in_array((int)$productId, $bakerProductIds, true)) {
                throw new InvalidArgumentException(bakery_t('production.error_not_in_list'));
            }
            $expected = filter_var($expectedMade[$productId] ?? 0, FILTER_VALIDATE_INT);
            bakery_inventory_record_production(
                $db,
                $productionDate,
                (int)$productId,
                (int)$quantity,
                'Production confirmed',
                $expected === false ? 0 : (int)$expected,
                (int)$waste
            );
            $savedUnits += (int)$quantity;
            $savedWaste += (int)$waste;
            $savedProducts++;
        }
        if (($savedUnits + $savedWaste) === 0) {
            throw new InvalidArgumentException(bakery_t('production.error_enter_quantity'));
        }
        $db->commit();
        header('Location: production.php?date=' . urlencode($productionDate) . '&saved=' . $savedUnits . '&waste=' . $savedWaste . '&products=' . $savedProducts);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $inventoryError = bakery_production_user_message($e, $isBaker);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['exception_desk_mutation'] ?? '') === 'flag_shortage') {
    bakery_require_role(['baker']);
    try {
        $notice = bakery_exception_desk_handle_baker_post($db);
        $flagDate = trim((string)($_POST['date'] ?? $selectedDate));
        header('Location: production.php?date=' . rawurlencode($flagDate) . '&notice=' . rawurlencode((string)$notice));
        exit;
    } catch (Throwable $e) {
        $inventoryError = $e->getMessage();
    }
}

// Fetch production data for the selected date
$productionData = [];
$groupedData = [];
$hasDailyOrders = false;
$starterFeedings = [];
$progressPlanned = 0;
$progressMade = 0;
$progressRemaining = 0;
$progressCompleteProducts = 0;
$progressProductCount = 0;
$bakeList = [
    'available' => false,
    'committed' => false,
    'commit' => null,
    'changed_since' => ['count' => 0, 'latest' => null, 'examples' => []],
    'has_daily' => false,
    'items' => [],
];
try {
    $bakeList = bakery_production_bake_list($db, $selectedDate);
    $hasDailyOrders = !empty($bakeList['has_daily']);
    $bakeByProduct = [];
    foreach ($bakeList['items'] as $bakeItem) {
        $bakeByProduct[(int)$bakeItem['product_id']] = $bakeItem;
    }

    if (!empty($bakeByProduct)) {
        $productIds = array_keys($bakeByProduct);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $bakerClause = '';
        if ($isBaker && is_array($bakerProductIds)) {
            $bakerClause = empty($bakerProductIds)
                ? ' AND 1 = 0'
                : ' AND p.id IN (' . implode(',', array_map('intval', $bakerProductIds)) . ')';
        }
        $orders = $db->prepare("
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.weight_grams,
                p.dough_type_id,
                dt.name as dough_type_name,
                dt.id as dt_id,
                pl.name as product_line_name
            FROM products p
            LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
            LEFT JOIN product_lines pl ON dt.product_line_id = pl.id
            WHERE p.id IN ({$placeholders}) {$bakerClause}
            ORDER BY dt.name, p.name
        ");
        $orders->execute($productIds);
        $productionData = [];
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
            $row['total_quantity'] = $qty;
            $row['demand_quantity'] = $demandQty;
            $row['bake_source'] = (string)$bakeItem['source'];
            $row['total_weight_grams'] = $qty * (int)($row['weight_grams'] ?? 0);
            $productionData[] = $row;
        }
    }

    $progressPlanned = 0;
    $progressMade = 0;
    $progressRemaining = 0;
    $progressCompleteProducts = 0;
    $progressProductCount = count($productionData);

    $producedByProduct = [];
    if (bakery_inventory_ready($db)) {
        $producedStmt = $db->prepare('SELECT product_id, produced_quantity FROM product_inventory_days WHERE delivery_date = ?');
        $producedStmt->execute([$selectedDate]);
        $producedByProduct = $producedStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Get formulas for dough types with total percentage
    $doughTypeFormulas = [];
    if (!empty($productionData)) {
        $doughTypeIds = array_unique(array_column($productionData, 'dough_type_id'));
        $doughTypeIds = array_filter($doughTypeIds); // Remove null values
        
        if (!empty($doughTypeIds)) {
            $placeholders = str_repeat('?,', count($doughTypeIds) - 1) . '?';
            
            // First, get the total percentage for each dough type
            $percentageStmt = $db->prepare("
                SELECT 
                    dough_type_id,
                    SUM(percentage) as total_percentage
                FROM formula_ingredients
                WHERE dough_type_id IN ($placeholders)
                GROUP BY dough_type_id
            ");
            $percentageStmt->execute(array_values($doughTypeIds));
            $percentages = $percentageStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Then get the ingredients list
            $formulaStmt = $db->prepare("
                SELECT 
                    fi.dough_type_id,
                    dt.name as dough_type_name,
                    GROUP_CONCAT(
                        CONCAT(i.name, ' (', fi.percentage, '%)')
                        ORDER BY fi.percentage DESC 
                        SEPARATOR ', '
                    ) as ingredients_list
                FROM formula_ingredients fi
                JOIN dough_types dt ON fi.dough_type_id = dt.id
                JOIN ingredients i ON fi.ingredient_id = i.id
                WHERE fi.dough_type_id IN ($placeholders)
                GROUP BY fi.dough_type_id, dt.name
            ");
            $formulaStmt->execute(array_values($doughTypeIds));
            $formulas = $formulaStmt->fetchAll();
            
            foreach ($formulas as $formula) {
                $doughTypeId = $formula['dough_type_id'];
                $doughTypeFormulas[$doughTypeId] = [
                    'id' => $doughTypeId,
                    'name' => $formula['dough_type_name'],
                    'ingredients' => $formula['ingredients_list'],
                    'total_percentage' => $percentages[$doughTypeId] ?? 0
                ];
            }
        }
    }
    
    // Group by dough type
    $groupedData = [];
    foreach ($productionData as $item) {
        $doughType = $item['dough_type_name'] ?: 'Unclassified';
        $doughTypeId = $item['dough_type_id'];
        if (!isset($groupedData[$doughType])) {
            $formula = null;
            $totalWeight = 0;
            if (!empty($doughTypeId) && isset($doughTypeFormulas[$doughTypeId])) {
                $formula = $doughTypeFormulas[$doughTypeId];
            }
            
            $groupedData[$doughType] = [
                'total_items' => 0,
                'total_weight_grams' => 0,
                'products' => [],
                'formula' => $formula,
                'dough_type_id' => $doughTypeId,
                'product_line_name' => (string)($item['product_line_name'] ?? '')
            ];
        }
        
        $plannedQty = (int)$item['total_quantity'];
        $madeQty = (int)($producedByProduct[$item['product_id']] ?? 0);
        $remainingQty = max(0, $plannedQty - $madeQty);
        $item['planned_quantity'] = $plannedQty;
        $item['demand_quantity'] = (int)($item['demand_quantity'] ?? $plannedQty);
        $item['bake_source'] = (string)($item['bake_source'] ?? 'demand');
        $item['made_quantity'] = $madeQty;
        $item['remaining_quantity'] = $remainingQty;
        if ($remainingQty === 0) {
            $item['completion_state'] = 'complete';
            $progressCompleteProducts++;
        } elseif ($madeQty > 0) {
            $item['completion_state'] = 'partial';
        } else {
            $item['completion_state'] = 'pending';
        }
        $progressPlanned += $plannedQty;
        $progressMade += min($madeQty, $plannedQty);
        $progressRemaining += $remainingQty;

        $groupedData[$doughType]['total_items'] += $plannedQty;
        $groupedData[$doughType]['total_weight_grams'] += $item['total_weight_grams'] ?? 0;
        $groupedData[$doughType]['products'][] = $item;
    }

    // Sort grouped data to show dough types with formulas first
    uasort($groupedData, function($a, $b) {
        // If one has a formula and the other doesn't, prioritize the one with formula
        if (!empty($a['formula']) && empty($b['formula'])) {
            return -1;
        }
        if (empty($a['formula']) && !empty($b['formula'])) {
            return 1;
        }
        // If both have formulas or both don't have formulas, sort alphabetically by dough type name
        $aName = !empty($a['formula']) ? $a['formula']['name'] : 'Unclassified';
        $bName = !empty($b['formula']) ? $b['formula']['name'] : 'Unclassified';
        return strcmp($aName, $bName);
    });
    
    // Calculate starter feeding requirements
    $starterNeeds = [
        'starter' => 0,       // ingredient_id = 6
        'starter_liquido' => 0 // ingredient_id = 13
    ];
    
    // Collect all starter amounts needed across all dough types
    if (!empty($groupedData)) {
        foreach ($groupedData as $doughType => $data) {
            if (!empty($data['formula']) && !empty($data['dough_type_id'])) {
                $totalPct = (float)($data['formula']['total_percentage'] ?? 0);
                if ($totalPct <= 0) {
                    continue;
                }
                // Get ingredients for this dough type with ingredient IDs
                $starterStmt = $db->prepare("
                    SELECT 
                        fi.ingredient_id,
                        i.name,
                        fi.percentage
                    FROM formula_ingredients fi
                    JOIN ingredients i ON fi.ingredient_id = i.id
                    WHERE fi.dough_type_id = ? AND fi.ingredient_id IN (6, 13)
                ");
                $starterStmt->execute([$data['dough_type_id']]);
                $starterIngredients = $starterStmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate total flour weight for this dough type
                $totalFlour = $data['total_weight_grams'] / ($data['formula']['total_percentage'] / 100);
                
                foreach ($starterIngredients as $ingredient) {
                    $amount = $totalFlour * ($ingredient['percentage'] / 100);
                    if ($ingredient['ingredient_id'] == 6) {
                        $starterNeeds['starter'] += $amount;
                    } elseif ($ingredient['ingredient_id'] == 13) {
                        $starterNeeds['starter_liquido'] += $amount;
                    }
                }
            }
        }
    }
    
    // Calculate feeding ratios
    $starterFeedings = [];
    if ($starterNeeds['starter'] > 0 || $starterNeeds['starter_liquido'] > 0) {
        // Starter feeding: 1:7:4.5 (Seed:Flour:Water)
        if ($starterNeeds['starter'] > 0) {
            $starterFeedings['starter'] = [
                'total_needed' => $starterNeeds['starter'],
                'seed_starter' => $starterNeeds['starter'] / 12.5, // 1 part out of 12.5 total
                'flour' => ($starterNeeds['starter'] / 12.5) * 7,
                'water' => ($starterNeeds['starter'] / 12.5) * 4.5
            ];
        }
        
        // Starter Liquido feeding: 1:7:9.5 (Seed:Flour:Water)
        if ($starterNeeds['starter_liquido'] > 0) {
            $starterFeedings['starter_liquido'] = [
                'total_needed' => $starterNeeds['starter_liquido'],
                'seed_starter' => $starterNeeds['starter_liquido'] / 17.5, // 1 part out of 17.5 total
                'flour' => ($starterNeeds['starter_liquido'] / 17.5) * 7,
                'water' => ($starterNeeds['starter_liquido'] / 17.5) * 9.5
            ];
        }
        
        // Calculate total seed starter needed
        $totalSeedStarter = 0;
        if (isset($starterFeedings['starter'])) {
            $totalSeedStarter += $starterFeedings['starter']['seed_starter'];
        }
        if (isset($starterFeedings['starter_liquido'])) {
            $totalSeedStarter += $starterFeedings['starter_liquido']['seed_starter'];
        }
        
        // Seed starter feeding: 1:4:5 (Seed:Flour:Water)
        if ($totalSeedStarter > 0) {
            $starterFeedings['seed_starter'] = [
                'total_needed' => $totalSeedStarter,
                'mother_starter' => $totalSeedStarter / 10, // 1 part out of 10 total
                'flour' => ($totalSeedStarter / 10) * 4,
                'water' => ($totalSeedStarter / 10) * 5
            ];
        }
    }
    
} catch (Exception $e) {
    $error = $isBaker
        ? bakery_t('production.error_load_baker')
        : bakery_t('production.error_load_ops', ['message' => $e->getMessage()]);
}

$packListHref = 'pack_list.php?' . http_build_query(bakery_ops_workflow_query(['date' => $selectedDate]));
$progressPercent = $progressPlanned > 0 ? (int)round(($progressMade / $progressPlanned) * 100) : 0;
$allProductionComplete = !empty($productionData) && $progressRemaining === 0;
$orderSourceLabel = !empty($hasDailyOrders) ? bakery_t('production.from_daily_orders') : bakery_t('production.from_standing');
$planCommitted = !empty($bakeList['committed']);
$planDriftCount = (int)($bakeList['changed_since']['count'] ?? 0);
$planAvailable = !empty($bakeList['available']);
$bakerCommitStamp = '';
if ($isBaker && $planCommitted && !empty($bakeList['commit']['committed_at'])) {
    $commitTs = strtotime((string)$bakeList['commit']['committed_at']);
    if ($commitTs) {
        $bakerCommitStamp = bakery_t('production.baker_committed_stamp', ['time' => date('g:i a', $commitTs)]);
    }
}
$commitDiff = [];
if ($planCommitted && function_exists('bakery_production_commit_diff')) {
    try {
        $commitDiff = bakery_production_commit_diff($db, $selectedDate);
    } catch (Throwable $e) {
        error_log('production commit diff: ' . $e->getMessage());
    }
}
$canOpenProductionCenter = !$isBaker
    && function_exists('bakery_user_has_role')
    && bakery_user_has_role(['administrator', 'manager']);
$productionCenterHref = function_exists('bakery_ops_link_production_center')
    ? bakery_ops_link_production_center(
        date('Y-m-d', strtotime('monday this week', strtotime($selectedDate))),
        ['date' => $selectedDate],
        $pageReturnKey ?: 'production'
    )
    : ('production_center.php?date=' . rawurlencode($selectedDate));
$workflowStages = [];
try {
    $workflowStages = bakery_production_workflow_kitchen_stages($db, $selectedDate);
} catch (Throwable $e) {
    error_log('production workflow strip: ' . $e->getMessage());
}
$bakerShortages = [];
if ($isBaker && function_exists('bakery_exception_desk_product_shortages')) {
    $bakerShortages = bakery_exception_desk_product_shortages($db, $selectedDate, is_array($bakerProductIds) ? $bakerProductIds : null);
}
$pageExceptions = [];
try {
    $pageExceptions = bakery_ops_exceptions_for_date($db, $selectedDate, $pageReturnKey);
} catch (Throwable $e) {
    error_log('production exceptions: ' . $e->getMessage());
}

if ($attentionShortfall && !empty($groupedData) && is_array($groupedData)) {
    foreach ($groupedData as &$groupData) {
        usort($groupData['products'], static function ($a, $b) {
            return ((int)($b['remaining_quantity'] ?? 0)) <=> ((int)($a['remaining_quantity'] ?? 0));
        });
    }
    unset($groupData);
    uasort($groupedData, static function ($a, $b) {
        $aRem = 0;
        $bRem = 0;
        foreach ($a['products'] as $p) {
            $aRem += (int)($p['remaining_quantity'] ?? 0);
        }
        foreach ($b['products'] as $p) {
            $bRem += (int)($p['remaining_quantity'] ?? 0);
        }
        return $bRem <=> $aRem;
    });
}

if (!empty($groupedData) && is_array($groupedData)) {
    $ingredientLoad = $db->prepare("
        SELECT i.name, i.unit, fi.percentage
        FROM formula_ingredients fi
        JOIN ingredients i ON fi.ingredient_id = i.id
        WHERE fi.dough_type_id = ?
        ORDER BY fi.percentage DESC
    ");
    foreach ($groupedData as &$groupRow) {
        $groupRow['ingredients'] = [];
        $dtId = (int)($groupRow['dough_type_id'] ?? 0);
        $totalPct = (float)($groupRow['formula']['total_percentage'] ?? 0);
        if ($dtId <= 0 || $totalPct <= 0) {
            continue;
        }
        $ingredientLoad->execute([$dtId]);
        $groupRow['ingredients'] = $ingredientLoad->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($groupRow);
}

// Set page title
$page_title = $isBaker ? bakery_t('page.production_baker') : bakery_t('page.production');
?>

<link rel="stylesheet" href="<?php echo bakery_asset_href('css/exception_desk.css'); ?>">
<div class="bp-screen">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <header class="bp-header">
        <div class="bp-header__top">
            <h1 class="bp-title"><?php echo $isBaker ? bakery_t('production.title_baker') : bakery_t('production.title_ops'); ?></h1>
            <div class="bp-header__links">
                <?php if ($canOpenProductionCenter): ?>
                    <a class="bp-pack-link" href="<?php echo htmlspecialchars($productionCenterHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production.open_production_center'); ?></a>
                <?php endif; ?>
                <a class="bp-pack-link<?php echo $allProductionComplete ? ' bp-pack-link--ready' : ''; ?>" href="<?php echo htmlspecialchars($packListHref, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php bakery_te('nav.pack_list'); ?>
                </a>
                <?php if (!empty($groupedData)): ?>
                    <a class="bp-pack-link" href="#bp-mix-overview"><?php bakery_te('production.mix_overview_link'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$isBaker): ?>
            <?php
            echo bakery_production_workflow_strip_css();
            echo bakery_production_workflow_strip_html($workflowStages, [
                'current' => 'produce',
                'compact' => true,
                'title' => bakery_t('production_workflow.title'),
                'lead' => bakery_t('production_workflow.lead_manager'),
            ]);
            ?>
        <?php endif; ?>
        <form method="get" action="production.php" class="bp-date-form">
            <label class="bp-date-label" for="date"><?php bakery_te('production.bake_for_delivery'); ?></label>
            <input type="date" name="date" id="date" class="bp-date-input"
                   value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                   onchange="this.form.submit()">
            <?php if ($pageReturnKey): ?><input type="hidden" name="return" value="<?php echo htmlspecialchars((string)$pageReturnKey, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($attentionShortfall): ?><input type="hidden" name="attention" value="shortfall"><?php endif; ?>
            <p class="bp-date-context">
                <strong><?php echo htmlspecialchars($days[$selectedDay], ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(date('M j, Y', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8'); ?></strong>
                <?php if (!$isBaker): ?>
                    <span class="bp-date-source"><?php echo htmlspecialchars($orderSourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
                <?php if (!$isBaker && $planAvailable): ?>
                    <?php if ($planCommitted): ?>
                        <span class="bp-plan-chip bp-plan-chip--committed"><?php bakery_te('production.plan_committed'); ?></span>
                        <?php if ($planDriftCount > 0): ?>
                            <span class="bp-plan-chip bp-plan-chip--drift"><?php echo htmlspecialchars(bakery_t('production.plan_drift', ['count' => $planDriftCount]), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="bp-plan-chip bp-plan-chip--uncommitted"><?php bakery_te('production.plan_not_committed'); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        </form>
        <?php if (!$isBaker && $planAvailable && !$planCommitted): ?>
            <div class="bp-alert bp-alert--warn" role="status">
                <p><strong><?php bakery_te('production.uncommitted_banner_title'); ?></strong>
                    <?php echo htmlspecialchars($isBaker ? bakery_t('production.uncommitted_banner_baker') : bakery_t('production.uncommitted_banner_ops'), ENT_QUOTES, 'UTF-8'); ?>
                </p>
                <?php if ($canOpenProductionCenter): ?>
                    <a class="bp-btn bp-btn--primary" href="<?php echo htmlspecialchars($productionCenterHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production.commit_from_center'); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($isBaker): ?>
            <div class="bp-baker-focus" role="status">
                <strong><?php bakery_te('production.baker_focus_title'); ?></strong>
                <span><?php bakery_te('production.baker_focus_lead'); ?></span>
                <?php if ($bakerCommitStamp !== ''): ?>
                    <span class="bp-baker-focus__stamp"><?php echo htmlspecialchars($bakerCommitStamp, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </div>
            <?php if ($planAvailable && !$planCommitted): ?>
                <details class="bp-plan-note">
                    <summary><?php bakery_te('production.baker_uncommitted_summary'); ?></summary>
                    <p><?php bakery_te('production.uncommitted_banner_baker'); ?></p>
                </details>
            <?php elseif ($planCommitted && $planDriftCount > 0): ?>
                <details class="bp-plan-note bp-plan-note--drift">
                    <summary><?php bakery_te('production.baker_drift_note'); ?></summary>
                    <p><?php echo htmlspecialchars(bakery_t('production.baker_drift_detail', ['count' => $planDriftCount]), ENT_QUOTES, 'UTF-8'); ?></p>
                </details>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($planCommitted && !empty($commitDiff)): ?>
            <details class="bp-plan-note bp-plan-note--drift">
                <summary><?php bakery_te('production_sheet.commit_diff_title'); ?></summary>
                <div class="bp-commit-diff">
                    <?php foreach ($commitDiff as $diffRow): ?>
                        <span class="bp-commit-diff__chip"><?php echo htmlspecialchars(
                            $diffRow['product_name'] . ' ' . bakery_t('production_sheet.commit_diff_chip', [
                                'from' => number_format((int)$diffRow['previous_quantity']),
                                'to' => number_format((int)$diffRow['new_quantity']),
                            ]), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
        <?php if (!isset($error) && !empty($productionData)): ?>
            <div class="bp-progress" aria-live="polite">
                <div class="bp-progress__labels">
                    <span><?php echo htmlspecialchars(bakery_t('production.units_done', ['made' => number_format($progressMade), 'planned' => number_format($progressPlanned)]), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span id="bp-progress-remaining"><?php echo htmlspecialchars(bakery_t('production.units_left', ['count' => number_format($progressRemaining)]), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="bp-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int)$progressPlanned; ?>" aria-valuenow="<?php echo (int)$progressMade; ?>">
                    <span class="bp-progress__fill" style="width: <?php echo (int)$progressPercent; ?>%;"></span>
                </div>
                <p class="bp-progress__meta"><?php echo htmlspecialchars(bakery_t('production.products_complete', ['complete' => (int)$progressCompleteProducts, 'total' => (int)$progressProductCount]), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($inventoryNotice): ?>
        <div class="bp-alert bp-alert--success" role="status"><?php echo htmlspecialchars($inventoryNotice); ?></div>
    <?php endif; ?>
    <?php if ($inventoryError): ?>
        <div class="bp-alert bp-alert--error" role="alert"><?php echo htmlspecialchars($inventoryError); ?></div>
    <?php endif; ?>
    <?php if (!empty($productionDeskNotice)): ?>
        <div class="bp-alert bp-alert--success" role="status"><?php echo htmlspecialchars($productionDeskNotice); ?></div>
    <?php endif; ?>
    <?php
    if ($isBaker && !empty($bakerShortages) && function_exists('bakery_exception_desk_render_baker')) {
        echo '<details class="bp-baker-help"><summary>' . htmlspecialchars(bakery_t('production.baker_help'), ENT_QUOTES, 'UTF-8') . '</summary>';
        bakery_exception_desk_render_baker($db, $selectedDate, $bakerShortages, 'production.php?date=' . rawurlencode($selectedDate));
        echo '</details>';
    }
    ?>
    <?php if ($allProductionComplete): ?>
        <div class="bp-handoff" role="status">
            <p><strong><?php bakery_te('production.all_complete'); ?></strong> <?php echo htmlspecialchars(bakery_t('production.next_step_pack', ['day' => $days[$selectedDay]]), ENT_QUOTES, 'UTF-8'); ?></p>
            <a class="bp-btn bp-btn--primary" href="<?php echo htmlspecialchars($packListHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production.open_pack_list'); ?></a>
        </div>
    <?php endif; ?>

    <?php if (!empty($starterFeedings)): ?>
        <details class="bp-starter">
            <summary class="bp-starter__summary"><?php bakery_te('production.starter_feedings'); ?></summary>
            <div class="bp-starter__body">
            
            <!-- Step 1: Seed Starter Feeding (Full Width) -->
            <?php if (isset($starterFeedings['seed_starter'])): ?>
                <div class="feeding-card priority full-width">
                    <h3><?php bakery_te('production.feed_seed'); ?></h3>
                    <div class="feeding-grid">
                        <div class="feeding-item">
                            <span class="ingredient-name"><?php bakery_te('production.mother_starter'); ?></span>
                            <span class="ingredient-amount"><?php echo number_format($starterFeedings['seed_starter']['mother_starter'], 0); ?>g</span>
                        </div>
                        <div class="feeding-item">
                            <span class="ingredient-name"><?php bakery_te('production.flour'); ?></span>
                            <span class="ingredient-amount"><?php echo number_format($starterFeedings['seed_starter']['flour'], 0); ?>g</span>
                        </div>
                        <div class="feeding-item">
                            <span class="ingredient-name"><?php bakery_te('production.water'); ?></span>
                            <span class="ingredient-amount"><?php echo number_format($starterFeedings['seed_starter']['water'], 0); ?>g</span>
                        </div>
                        <div class="feeding-item total">
                            <span class="ingredient-name"><strong><?php bakery_te('production.total_seed'); ?></strong></span>
                            <span class="ingredient-amount"><strong><?php echo number_format($starterFeedings['seed_starter']['total_needed'], 0); ?>g</strong></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Steps 2 & 3: Regular Starter and Starter Liquido (Side by Side) -->
            <?php if (isset($starterFeedings['starter']) || isset($starterFeedings['starter_liquido'])): ?>
                <div class="feeding-row">
                    <!-- Step 2: Regular Starter Feeding -->
                    <?php if (isset($starterFeedings['starter'])): ?>
                        <div class="feeding-card">
                            <h3><?php bakery_te('production.feed_regular'); ?></h3>
                            <div class="feeding-grid">
                                <div class="feeding-item">
                                    <span class="ingredient-name"><?php bakery_te('production.seed_starter'); ?></span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter']['seed_starter'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name"><?php bakery_te('production.flour'); ?></span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter']['flour'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name"><?php bakery_te('production.water'); ?></span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter']['water'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item total">
                                    <span class="ingredient-name"><strong><?php bakery_te('production.total_starter'); ?></strong></span>
                                    <span class="ingredient-amount"><strong><?php echo number_format($starterFeedings['starter']['total_needed'], 0); ?>g</strong></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Step 3: Starter Liquido Feeding -->
                    <?php if (isset($starterFeedings['starter_liquido'])): ?>
                        <div class="feeding-card">
                            <h3><?php bakery_te('production.feed_liquido'); ?></h3>
                            <div class="feeding-grid">
                                <div class="feeding-item">
                                    <span class="ingredient-name"><?php bakery_te('production.seed_starter'); ?></span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter_liquido']['seed_starter'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name"><?php bakery_te('production.flour'); ?></span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter_liquido']['flour'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name"><?php bakery_te('production.water'); ?></span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter_liquido']['water'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item total">
                                    <span class="ingredient-name"><strong><?php bakery_te('production.total_liquido'); ?></strong></span>
                                    <span class="ingredient-amount"><strong><?php echo number_format($starterFeedings['starter_liquido']['total_needed'], 0); ?>g</strong></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </div>
        </details>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="bp-alert bp-alert--error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php elseif (empty($groupedData)): ?>
        <div class="bp-empty">
            <p><?php bakery_te('production.no_scheduled'); ?></p>
            <a class="bp-btn bp-btn--outline" href="<?php echo htmlspecialchars($packListHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production.open_pack_anyway'); ?></a>
        </div>
    <?php else: ?>
        <form method="post" class="bp-work-form" id="bp-work-form" novalidate>
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="confirm_production">
            <input type="hidden" name="production_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">

            <p class="bp-form-intro"><?php bakery_te('production.form_intro'); ?></p>
            <?php if (!$inventoryReady): ?>
                <div class="bp-alert bp-alert--error" role="alert"><?php bakery_te('production.inventory_unavailable'); ?></div>
            <?php endif; ?>
            <div class="bp-form-error" id="bp-form-error" role="alert" hidden></div>

            <?php
            $hasFormulaPanel = false;
            foreach ($groupedData as $formulaGroup) {
                if (!empty($formulaGroup['formula']) && (float)($formulaGroup['formula']['total_percentage'] ?? 0) > 0 && !empty($formulaGroup['dough_type_id'])) {
                    $hasFormulaPanel = true;
                    break;
                }
            }
            ?>
            <?php if ($hasFormulaPanel): ?>
                <?php
                $formulaDefaultUnit = bakery_formula_default_unit_mode($isBaker);
                $formulaUnitModes = bakery_formula_unit_modes($isBaker);
                ?>
                <div class="formula-unit-bar<?php echo $isBaker ? ' formula-unit-bar--baker' : ''; ?>"
                     data-formula-units
                     data-unit-mode="<?php echo htmlspecialchars($formulaDefaultUnit, ENT_QUOTES, 'UTF-8'); ?>"
                     <?php echo $isBaker ? 'data-baker-units="1"' : ''; ?>>
                    <div class="formula-unit-bar-row">
                        <span class="formula-unit-label<?php echo $isBaker ? ' sf-sr-only' : ''; ?>" id="formula-unit-label"><?php echo htmlspecialchars(bakery_t('formula.show_mix_as'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <div class="formula-unit-switch" role="radiogroup" aria-labelledby="formula-unit-label">
                            <?php foreach ($formulaUnitModes as $unitMode): ?>
                                <button type="button"
                                        role="radio"
                                        class="formula-unit-btn<?php echo $unitMode === $formulaDefaultUnit ? ' is-active' : ''; ?>"
                                        data-unit="<?php echo htmlspecialchars($unitMode, ENT_QUOTES, 'UTF-8'); ?>"
                                        aria-checked="<?php echo $unitMode === $formulaDefaultUnit ? 'true' : 'false'; ?>"
                                        aria-label="<?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode . '_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode), ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <?php if (!$isBaker): ?>
                        <nav class="formula-lang-switch" aria-label="<?php echo htmlspecialchars(bakery_t('formula.lang_label'), ENT_QUOTES, 'UTF-8'); ?>">
                            <a href="<?php echo htmlspecialchars(bakery_locale_switch_url('en'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo bakery_locale() === 'en' ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars(bakery_t('formula.lang.en'), ENT_QUOTES, 'UTF-8'); ?></a>
                            <a href="<?php echo htmlspecialchars(bakery_locale_switch_url('es'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo bakery_locale() === 'es' ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars(bakery_t('formula.lang.es'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </nav>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isBaker): ?>
                    <details class="formula-unit-help">
                        <summary><?php echo htmlspecialchars(bakery_t('formula.help_title'), ENT_QUOTES, 'UTF-8'); ?></summary>
                        <p><?php echo htmlspecialchars(bakery_t('formula.help_lead'), ENT_QUOTES, 'UTF-8'); ?></p>
                        <ul>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.water'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.milk'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.cream'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.oil'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.eggs'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.honey'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.starter_liquido'), ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><?php echo htmlspecialchars(bakery_t('formula.density.other'), ENT_QUOTES, 'UTF-8'); ?></li>
                        </ul>
                    </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <details class="bp-mix-overview" id="bp-mix-overview">
                <summary class="bp-mix-overview__summary"><?php bakery_te('production.mix_overview_title'); ?></summary>
                <p class="bp-mix-overview__lead"><?php bakery_te('production.mix_overview_lead'); ?></p>
                <div class="bp-mix-grid">
                    <?php foreach ($groupedData as $doughType => $overviewData):
                        $overviewId = (int)($overviewData['dough_type_id'] ?? 0);
                        $overviewKey = $overviewId > 0 ? ('batch-' . $overviewId) : ('batch-' . preg_replace('/[^a-z0-9]+/i', '-', (string)$doughType));
                        $overviewPlanned = 0;
                        $overviewMade = 0;
                        $overviewLeft = 0;
                        foreach ($overviewData['products'] as $overviewProduct) {
                            $overviewPlanned += (int)$overviewProduct['planned_quantity'];
                            $overviewMade += (int)$overviewProduct['made_quantity'];
                            $overviewLeft += (int)$overviewProduct['remaining_quantity'];
                        }
                        $overviewHasFormula = !empty($overviewData['ingredients'])
                            && (float)($overviewData['formula']['total_percentage'] ?? 0) > 0
                            && $overviewId > 0;
                    ?>
                        <article class="bp-mix-card">
                            <h3 class="bp-mix-card__title"><?php echo htmlspecialchars((string)$doughType, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p class="bp-mix-card__meta"><?php echo htmlspecialchars(bakery_t('production.line_meta', [
                                'planned' => number_format($overviewPlanned),
                                'made' => number_format($overviewMade),
                                'left' => number_format($overviewLeft),
                            ]), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php if ($overviewHasFormula):
                                $overviewFlour = $overviewData['total_weight_grams'] / ($overviewData['formula']['total_percentage'] / 100);
                                bakery_production_echo_formula_items(
                                    $overviewData['ingredients'],
                                    (float)$overviewFlour,
                                    (float)$overviewData['total_weight_grams'],
                                    $isBaker
                                );
                            else: ?>
                                <p class="bp-mix-card__empty"><?php bakery_te('production.mix_no_formula'); ?></p>
                            <?php endif; ?>
                            <a class="bp-mix-card__jump" href="#<?php echo htmlspecialchars($overviewKey, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production.mix_work_this'); ?></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </details>

            <?php foreach ($groupedData as $doughType => $data):
                $doughTypeId = $data['dough_type_id'] ?? null;
                $linePlanned = 0;
                $lineMade = 0;
                $lineRemaining = 0;
                foreach ($data['products'] as $p) {
                    $linePlanned += (int)$p['planned_quantity'];
                    $lineMade += (int)$p['made_quantity'];
                    $lineRemaining += (int)$p['remaining_quantity'];
                }
                $isPanDulceLine = strcasecmp((string)($data['product_line_name'] ?? ''), 'Pan Dulce') === 0;
                $panDulceBatch = $isBaker && $isPanDulceLine
                    ? bakery_production_pan_dulce_batch_hint($db, (int)$doughTypeId, $lineRemaining)
                    : null;
            ?>
                <section class="bp-line">
                    <header class="bp-line__header">
                        <div>
                            <h2 class="bp-line__title"><?php echo htmlspecialchars($doughType, ENT_QUOTES, 'UTF-8'); ?></h2>
                            <p class="bp-line__meta"><?php echo htmlspecialchars(bakery_t('production.line_meta', [
                                'planned' => number_format($linePlanned),
                                'made' => number_format($lineMade),
                                'left' => number_format($lineRemaining),
                            ]), ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php if ($panDulceBatch): ?>
                                <p class="bp-batch-hint">
                                    <strong><?php bakery_te('production.batch_left'); ?></strong>
                                    <?php echo htmlspecialchars(bakery_t('production.batch_left_values', [
                                        'gallons' => rtrim(rtrim(number_format((float)$panDulceBatch['gallons'], 2), '0'), '.'),
                                        'trays' => rtrim(rtrim(number_format((float)$panDulceBatch['trays'], 1), '0'), '.'),
                                        'pieces' => number_format((int)$panDulceBatch['pieces']),
                                    ]), ENT_QUOTES, 'UTF-8'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </header>

                    <?php if (!empty($data['formula']) && (float)($data['formula']['total_percentage'] ?? 0) > 0 && !empty($doughTypeId)):
                        $ingredientsStmt = $db->prepare("
                            SELECT i.name, i.unit, fi.percentage
                            FROM formula_ingredients fi
                            JOIN ingredients i ON fi.ingredient_id = i.id
                            WHERE fi.dough_type_id = ?
                            ORDER BY fi.percentage DESC
                        ");
                        $ingredientsStmt->execute([$doughTypeId]);
                        $ingredients = $ingredientsStmt->fetchAll(PDO::FETCH_ASSOC);
                        $totalFlour = $data['total_weight_grams'] / ($data['formula']['total_percentage'] / 100);
                        $doughClassification = ['liquid' => false, 'kind' => 'dry', 'density_lb_per_gal' => null];
                    ?>
                        <details class="bp-formula" open>
                            <summary><?php echo htmlspecialchars(bakery_t('production.dough_formula', ['grams' => number_format($data['total_weight_grams'], 0)]), ENT_QUOTES, 'UTF-8'); ?></summary>
                            <ul class="bp-formula__list" data-formula-units data-unit-mode="<?php echo htmlspecialchars(bakery_formula_default_unit_mode($isBaker), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php foreach ($ingredients as $ingredient):
                                    $amount = $totalFlour * ($ingredient['percentage'] / 100);
                                    $classification = bakery_formula_classify_ingredient($ingredient['name'], $ingredient['unit'] ?? '');
                                ?>
                                    <li class="<?php echo !empty($classification['liquid']) ? 'is-liquid' : ''; ?>"
                                        data-grams="<?php echo htmlspecialchars((string) $amount, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-liquid="<?php echo !empty($classification['liquid']) ? '1' : '0'; ?>"
                                        <?php if (!empty($classification['density_lb_per_gal'])): ?>data-density="<?php echo htmlspecialchars((string) $classification['density_lb_per_gal'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                        <span><?php echo htmlspecialchars($ingredient['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        <strong class="ingredient-amount"><?php echo bakery_formula_amount_markup($amount, $classification); ?></strong>
                                    </li>
                                <?php endforeach; ?>
                                <li class="bp-formula-total" data-grams="<?php echo htmlspecialchars((string) $data['total_weight_grams'], ENT_QUOTES, 'UTF-8'); ?>" data-liquid="0">
                                    <span><?php echo htmlspecialchars(bakery_t('formula.total_dough'), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <strong class="ingredient-amount"><?php echo bakery_formula_amount_markup($data['total_weight_grams'], $doughClassification); ?></strong>
                                </li>
                            </ul>
                        </details>
                    <?php endif; ?>

                    <div class="bp-products">
                        <?php foreach ($data['products'] as $product):
                            $state = $product['completion_state'];
                            $overPlan = max(0, (int)$product['made_quantity'] - (int)$product['planned_quantity']);
                            $panDulceProductHint = $isBaker && $isPanDulceLine
                                ? bakery_production_pan_dulce_product_hint($db, (int)$product['product_id'], (int)$product['remaining_quantity'])
                                : null;
                        ?>
                            <article class="bp-product bp-product--<?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?><?php echo !$isBaker && (int)$product['remaining_quantity'] > 0 ? ' ops-attention-row' : ''; ?>" id="product-<?php echo (int)$product['product_id']; ?>" data-product-id="<?php echo (int)$product['product_id']; ?>">
                                <div class="bp-product__main">
                                    <div class="bp-product__name-row">
                                        <h3 class="bp-product__name"><?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                        <?php if (!$isBaker): ?>
                                            <?php
                                            echo bakery_ops_render_row_chips($pageExceptions, [
                                                'product_id' => (int)$product['product_id'],
                                                'flags' => ((int)$product['remaining_quantity'] > 0) ? ['fg_shortfall' => true] : [],
                                            ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey]);
                                            ?>
                                        <?php endif; ?>
                                        <span class="bp-status bp-status--<?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php
                                            if ($state === 'complete') {
                                                echo bakery_t('production.status_done');
                                            } elseif ($state === 'partial') {
                                                echo bakery_t('production.status_in_progress');
                                            } else {
                                                echo bakery_t('production.status_to_make');
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <?php if ($product['weight_grams'] !== null): ?>
                                        <p class="bp-product__weight"><?php echo htmlspecialchars(bakery_t('production.each_weight', ['grams' => number_format((int)$product['weight_grams'])]), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    <?php if ($panDulceProductHint && ((int)$panDulceProductHint['trays'] > 0 || (int)$panDulceProductHint['loose'] > 0)): ?>
                                        <p class="bp-pack-hint"><?php echo htmlspecialchars(bakery_t('production.tray_hint', [
                                            'trays' => number_format((int)$panDulceProductHint['trays']),
                                            'loose' => number_format((int)$panDulceProductHint['loose']),
                                            'per_tray' => number_format((int)$panDulceProductHint['pieces_per_tray']),
                                        ]), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                    <dl class="bp-qty-grid<?php echo $isBaker ? ' bp-qty-grid--baker' : ''; ?><?php echo $isBaker && $planCommitted ? ' bp-qty-grid--baker-committed' : ''; ?>">
                                        <?php if ($isBaker): ?>
                                            <div class="bp-qty-primary"><dt><?php bakery_te('production.left'); ?></dt><dd class="bp-qty-left"><?php echo number_format((int)$product['remaining_quantity']); ?></dd></div>
                                            <?php if ($planCommitted): ?>
                                                <div class="bp-qty-target"><dt><?php bakery_te('production.bake_target'); ?></dt><dd><?php echo number_format((int)$product['planned_quantity']); ?></dd></div>
                                            <?php endif; ?>
                                            <div><dt><?php bakery_te('production.made'); ?></dt><dd class="bp-qty-made"><?php echo number_format((int)$product['made_quantity']); ?></dd></div>
                                        <?php else: ?>
                                            <div><dt><?php bakery_te('production.demand'); ?></dt><dd><?php echo number_format((int)$product['demand_quantity']); ?></dd></div>
                                            <?php if ($planCommitted): ?>
                                                <div><dt><?php bakery_te('production.committed'); ?></dt><dd><?php echo number_format((int)$product['planned_quantity']); ?></dd></div>
                                            <?php endif; ?>
                                            <div><dt><?php bakery_te('production.made'); ?></dt><dd class="bp-qty-made"><?php echo number_format((int)$product['made_quantity']); ?></dd></div>
                                            <div><dt><?php bakery_te('production.left'); ?></dt><dd class="bp-qty-left"><?php echo number_format((int)$product['remaining_quantity']); ?></dd></div>
                                        <?php endif; ?>
                                    </dl>
                                    <?php if ($overPlan > 0): ?>
                                        <p class="bp-variance"><?php echo htmlspecialchars(bakery_t('production.over_plan', ['count' => number_format($overPlan)]), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="bp-record">
                                    <label class="bp-record__label" for="produced-<?php echo (int)$product['product_id']; ?>"><?php bakery_te('production.record_now'); ?></label>
                                    <span class="quantity-stepper">
                                        <button type="button" class="quantity-step" data-step="-1" aria-label="<?php echo htmlspecialchars(bakery_t('production.decrease', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">−</button>
                                        <input type="hidden" name="produced_was[<?php echo (int)$product['product_id']; ?>]" value="<?php echo (int)$product['made_quantity']; ?>">
                                        <input type="number"
                                               id="produced-<?php echo (int)$product['product_id']; ?>"
                                               class="bp-qty-input"
                                               min="0"
                                               step="1"
                                               inputmode="numeric"
                                               name="produced[<?php echo (int)$product['product_id']; ?>]"
                                               value="0"
                                               data-planned="<?php echo (int)$product['planned_quantity']; ?>"
                                               data-made="<?php echo (int)$product['made_quantity']; ?>"
                                               data-remaining="<?php echo (int)$product['remaining_quantity']; ?>"
                                               aria-label="<?php echo htmlspecialchars(bakery_t('production.units_for', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button" class="quantity-step" data-step="1" aria-label="<?php echo htmlspecialchars(bakery_t('production.increase', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">+</button>
                                    </span>
                                    <label class="bp-waste-label" for="waste-<?php echo (int)$product['product_id']; ?>"><?php bakery_te('production.waste_now'); ?></label>
                                    <input type="number" id="waste-<?php echo (int)$product['product_id']; ?>" class="bp-waste-input" min="0" step="1" inputmode="numeric" name="waste[<?php echo (int)$product['product_id']; ?>]" value="0" aria-label="<?php echo htmlspecialchars(bakery_t('production.waste_for', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <footer class="bp-actions">
                <p class="bp-actions__summary" id="bp-submit-summary" aria-live="polite"><?php echo htmlspecialchars(bakery_t('production.ready_to_record', ['count' => '0']), ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="bp-actions__buttons">
                    <button type="button" class="bp-btn bp-btn--outline" id="bp-fill-remaining"><?php bakery_te('production.set_all_remaining'); ?></button>
                    <button type="submit" class="bp-btn bp-btn--primary" id="bp-submit-btn"<?php echo $inventoryReady ? '' : ' disabled'; ?>><?php bakery_te('production.record_production'); ?></button>
                    <a class="bp-btn bp-btn--ghost" href="<?php echo htmlspecialchars($packListHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('nav.pack_list'); ?></a>
                    <?php if (!$isBaker): ?>
                        <a class="bp-btn bp-btn--ghost" href="inventory.php?date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('production.view_inventory'); ?></a>
                    <?php endif; ?>
                </div>
            </footer>
        </form>
    <?php endif; ?>
</div>

<style>
.bp-screen { max-width: 920px; margin: 0 auto; padding: 12px 12px 120px; color: var(--sf-text, #1f2a24); font-family: var(--sf-font-sans); }
.bp-header { background: var(--sf-brand, #173f3c); color: #fff; border-radius: var(--sf-radius-lg, 14px); padding: 16px; margin-bottom: 14px; box-shadow: var(--sf-shadow-md, 0 4px 16px rgba(23, 63, 60, .18)); }
.bp-header__top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.bp-title { margin: 0; font-size: 1.45rem; line-height: 1.2; }
.bp-pack-link { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.28); border-radius: 999px; color: #fff; font-weight: 700; min-height: 44px; padding: 10px 16px; text-decoration: none; white-space: nowrap; }
.bp-header__links { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.bp-pack-link--ready { background: var(--sf-accent, #d88346); border-color: #e69a5e; }
.bp-date-form { display: grid; gap: 8px; }
.bp-date-label { font-size: .9rem; opacity: .9; }
.bp-date-input { width: 100%; max-width: 280px; min-height: 48px; padding: 10px 12px; border: 1px solid rgba(255,255,255,.35); border-radius: 10px; background: #fff; color: #173f3c; font-size: 16px; }
.bp-date-context { margin: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: .95rem; }
.bp-date-source { background: rgba(255,255,255,.14); border-radius: 999px; padding: 4px 10px; font-size: .82rem; }
.bp-plan-chip { border-radius: 999px; padding: 4px 10px; font-size: .82rem; font-weight: 700; }
.bp-plan-chip--uncommitted { background: #fff3d3; color: #735412; }
.bp-plan-chip--committed { background: #d8f0e2; color: #195f35; }
.bp-plan-chip--drift { background: #fdeaea; color: #9f2727; }
.bp-progress { margin-top: 14px; background: rgba(255,255,255,.08); border-radius: 10px; padding: 12px; }
.bp-progress__labels { display: flex; justify-content: space-between; gap: 10px; font-size: .95rem; margin-bottom: 8px; }
.bp-progress__bar { height: 12px; background: rgba(255,255,255,.18); border-radius: 999px; overflow: hidden; }
.bp-progress__fill { display: block; height: 100%; background: linear-gradient(90deg, #7fd3a8, #d88346); border-radius: 999px; transition: width .25s ease; }
.bp-progress__meta { margin: 8px 0 0; font-size: .85rem; opacity: .9; }
.bp-alert { border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; font-size: 1rem; line-height: 1.45; }
.bp-alert--success { background: var(--sf-success-bg, #e8f7ec); border: 1px solid var(--sf-success-border, #8bc99a); color: var(--sf-success, #1f5f32); }
.bp-alert--warn { background: #fff6e8; border: 1px solid #efc98d; color: #735412; display: grid; gap: 10px; }
.bp-alert--error { background: var(--sf-danger-bg, #fdecec); border: 1px solid var(--sf-danger-border, #e7a1a1); color: var(--sf-danger, #7a1f1f); }
.bp-baker-focus { display: grid; gap: 2px; margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: rgba(255,255,255,.12); }
.bp-baker-focus span { font-size: .9rem; opacity: .92; }
.bp-baker-focus__stamp { font-weight: 700; color: #d8f0e2; }
.bp-plan-note { margin-top: 8px; font-size: .85rem; color: #fff3d3; }
.bp-plan-note summary { cursor: pointer; font-weight: 700; }
.bp-plan-note p { margin: 6px 0 0; }
.bp-plan-note--drift > summary { color: #ffd9a0; }
.bp-commit-diff { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
.bp-commit-diff__chip { background: rgba(255,255,255,.12); border-radius: 999px; padding: 4px 10px; font-weight: 700; color: #ffd9a0; }
.bp-baker-help { margin: 0 0 14px; border: 1px solid #dbe7df; border-radius: 10px; background: #f8faf9; }
.bp-baker-help > summary { cursor: pointer; padding: 12px 14px; color: #42545a; font-weight: 700; }
.bp-baker-help .exception-desk { margin: 0 12px 12px; }
.bp-handoff { background: #fff7ea; border: 1px solid #efc98d; border-radius: 12px; padding: 16px; margin-bottom: 14px; display: grid; gap: 12px; }
.bp-starter, .bp-formula { border: 1px solid #cfe8db; border-radius: 12px; background: #f7fbf8; margin-bottom: 14px; overflow: hidden; }
.bp-starter__summary, .bp-formula summary { cursor: pointer; font-weight: 700; padding: 14px 16px; list-style: none; }
.bp-starter__summary::-webkit-details-marker, .bp-formula summary::-webkit-details-marker { display: none; }
.bp-starter__body { padding: 0 14px 14px; display: grid; gap: 10px; }
.feeding-card, .bp-feeding-card { background: #fff; border: 1px solid #d7e8de; border-radius: 10px; padding: 12px; }
.feeding-card h3, .bp-feeding-card h3 { margin: 0 0 10px; font-size: .98rem; color: #0e5a43; }
.feeding-grid, .bp-feeding-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.feeding-item, .bp-feeding-grid > div { display: flex; flex-direction: column; gap: 4px; padding: 8px; background: #f8faf9; border: 1px solid #e3ece7; border-radius: 8px; font-size: .85rem; }
.feeding-item.total, .bp-feeding-total { grid-column: 1 / -1; flex-direction: row; justify-content: space-between; align-items: center; background: #e8f7ec; border-color: #8bc99a; }
.bp-empty { text-align: center; padding: 32px 16px; background: #f8faf9; border: 1px dashed #c8d8d0; border-radius: 12px; }
.bp-form-intro { margin: 0 0 14px; color: #4b6351; line-height: 1.45; }
.bp-form-error { background: #fdecec; border: 1px solid #e7a1a1; color: #7a1f1f; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; }
.bp-line { background: #fff; border: 1px solid #dbe7df; border-radius: 14px; margin-bottom: 16px; overflow: hidden; box-shadow: 0 2px 10px rgba(31,42,36,.06); }
.bp-line__header { padding: 14px 16px; background: linear-gradient(180deg, #f4faf6, #fff); border-bottom: 1px solid #e4eee8; }
.bp-line__title { margin: 0; font-size: 1.15rem; color: #173f3c; }
.bp-line__meta { margin: 6px 0 0; color: #607068; font-size: .92rem; }
.bp-batch-hint { margin: 10px 0 0; padding: 10px 12px; border-radius: 10px; background: #e7f3ec; color: #174f36; font-size: 1rem; }
.bp-batch-hint strong { display: block; margin-bottom: 2px; }
.bp-formula__list { list-style: none; margin: 0; padding: 0 16px 14px; display: grid; gap: 8px; }
.bp-formula__list li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #fff; border: 1px solid #e3ece7; border-radius: 8px; }
.bp-products { display: grid; gap: 12px; padding: 14px; }
.bp-product { display: grid; gap: 14px; padding: 14px; border: 1px solid #e3ece7; border-radius: 12px; background: #fcfefd; }
.bp-product--complete { background: #f3fbf5; border-color: #b8d9c2; }
.bp-product--partial { border-color: #efc98d; background: #fffaf2; }
.bp-product__name-row { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.bp-product__name { margin: 0; font-size: 1.12rem; line-height: 1.25; overflow-wrap: anywhere; }
.bp-product__weight { margin: 4px 0 0; color: #607068; font-size: .88rem; }
.bp-pack-hint { display: inline-block; margin: 9px 0 0; padding: 6px 9px; border-radius: 8px; background: #eef6f1; color: #315e46; font-size: .88rem; font-weight: 700; }
.bp-status { border-radius: 999px; padding: 5px 10px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.bp-status--complete { background: #d7f0df; color: #1f5f32; }
.bp-status--partial { background: #ffe8c2; color: #8a4d00; }
.bp-status--pending { background: #e8eef0; color: #42545a; }
.bp-qty-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(72px, 1fr)); gap: 8px; margin: 12px 0 0; }
.bp-qty-grid div { background: #fff; border: 1px solid #e3ece7; border-radius: 10px; padding: 10px 8px; text-align: center; }
.bp-qty-grid dt { margin: 0; font-size: .78rem; color: #607068; text-transform: uppercase; letter-spacing: .03em; }
.bp-qty-grid dd { margin: 4px 0 0; font-size: 1.35rem; font-weight: 800; color: #173f3c; }
.bp-qty-grid--baker { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.bp-qty-grid--baker.bp-qty-grid--baker-committed { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.bp-qty-grid--baker .bp-qty-primary { background: #e7f3ec; border-color: #8db59a; }
.bp-qty-grid--baker .bp-qty-primary dd { font-size: 1.8rem; color: #155f36; }
.bp-qty-grid--baker .bp-qty-target { background: #f3fbf5; }
.bp-qty-grid--baker .bp-qty-target dd { font-weight: 700; }
.bp-variance { margin: 8px 0 0; font-size: .85rem; color: #8a4d00; }
.bp-record__label { display: block; margin-bottom: 8px; font-weight: 700; color: #1f5f32; }
.bp-waste-label { display: block; margin-top: 10px; font-size: .82rem; font-weight: 700; color: #7a3d21; }
.bp-waste-input { width: 100%; min-height: 42px; margin-top: 5px; border: 1px solid #d6a17f; border-radius: 8px; padding: 7px 10px; font-size: 1rem; }
.quantity-stepper { display: grid; grid-template-columns: 56px minmax(88px, 1fr) 56px; gap: 8px; align-items: stretch; }
.quantity-stepper input { width: 100%; min-width: 0; min-height: 56px; padding: 8px; border: 2px solid #8db59a; border-radius: 10px; text-align: center; font-size: 1.45rem; font-weight: 800; color: #173f3c; background: #fff; }
.quantity-step { min-height: 56px; border: 2px solid #8db59a; border-radius: 10px; background: #fff; color: #1f6637; font-size: 1.8rem; line-height: 1; cursor: pointer; touch-action: manipulation; }
.quantity-step:active { background: #dff1e4; transform: translateY(1px); }
.bp-actions { position: sticky; bottom: 0; z-index: 20; margin-top: 8px; padding: 14px; background: rgba(255,255,255,.96); border: 1px solid #dbe7df; border-radius: 14px 14px 0 0; box-shadow: 0 -8px 24px rgba(31,42,36,.12); backdrop-filter: blur(8px); }
.bp-actions__summary { margin: 0 0 12px; font-size: 1rem; color: #42545a; }
.bp-actions__buttons { display: grid; gap: 10px; }
.bp-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 52px; padding: 12px 18px; border-radius: 12px; border: 2px solid transparent; font-size: 1rem; font-weight: 800; text-decoration: none; cursor: pointer; touch-action: manipulation; }
.bp-btn--primary { background: #1f7a48; border-color: #1f7a48; color: #fff; }
.bp-btn--primary:disabled { opacity: .65; cursor: not-allowed; }
.bp-btn--outline { background: #fff; border-color: #8db59a; color: #1f6637; }
.bp-btn--ghost { background: #f4faf6; border-color: #cfe8db; color: #173f3c; }
@media (min-width: 720px) {
    .bp-actions__buttons { grid-template-columns: 1fr 1.2fr auto auto; }
    .bp-product { grid-template-columns: 1fr minmax(260px, 320px); align-items: center; }
}
@media (max-width: 719px) {
    .bp-screen { padding-left: 10px; padding-right: 10px; }
    .bp-header__top { align-items: flex-start; flex-direction: column; }
    .bp-pack-link { align-self: stretch; text-align: center; }
}
.formula-unit-bar {
    position: sticky;
    top: 0;
    z-index: 8;
    margin: 0 0 16px 0;
    padding: 12px;
    background: #fff;
    border: 1px solid #c5d4cc;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.formula-unit-bar--baker {
    position: static;
    margin: 0 0 10px;
    padding: 0;
    border: 0;
    box-shadow: none;
    background: transparent;
}
.formula-unit-bar-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; }
.formula-unit-bar--baker .formula-unit-bar-row { gap: 0; }
.formula-unit-label { font-weight: 600; color: #2c3e50; font-size: 0.95rem; }
.formula-unit-switch {
    display: flex;
    flex: 1 1 220px;
    min-width: 0;
    border: 1px solid #8db59a;
    border-radius: 10px;
    overflow: hidden;
    background: #f3fbf5;
}
.formula-unit-bar--baker .formula-unit-switch {
    flex: 0 0 auto;
    width: auto;
}
.formula-unit-btn {
    flex: 1 1 0;
    min-height: 44px;
    min-width: 44px;
    border: 0;
    border-right: 1px solid #8db59a;
    background: transparent;
    color: #1f6637;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
}
.formula-unit-bar--baker .formula-unit-btn {
    flex: 0 0 auto;
    min-height: 36px;
    min-width: 40px;
    padding: 0 12px;
    font-size: 0.9rem;
}
.formula-unit-btn:last-child { border-right: 0; }
.formula-unit-btn.is-active,
.formula-unit-btn[aria-checked="true"] { background: #1f6b35; color: #fff; }
.formula-lang-switch { display: flex; gap: 8px; margin-left: auto; font-size: 0.9rem; }
.formula-lang-switch a {
    color: #1f6b35;
    text-decoration: none;
    min-height: 44px;
    display: inline-flex;
    align-items: center;
    padding: 0 8px;
    border-radius: 8px;
}
.formula-lang-switch a[aria-current="true"] { font-weight: 700; background: #e8f5e9; }
.formula-unit-help { margin-top: 10px; font-size: 0.88rem; color: #4b6351; }
.formula-unit-help summary { cursor: pointer; font-weight: 600; min-height: 36px; display: flex; align-items: center; }
.formula-unit-help p { margin: 8px 0; }
.formula-unit-help ul { margin: 0; padding-left: 1.2em; }
.ingredient-amount { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: baseline; gap: 0; text-align: right; }
[data-unit-mode="g"] .qty-lb,
[data-unit-mode="g"] .qty-gal,
[data-unit-mode="g"] .qty-sep { display: none; }
[data-unit-mode="lb"] .qty-g,
[data-unit-mode="lb"] .qty-gal,
[data-unit-mode="lb"] .qty-sep { display: none; }
[data-unit-mode="gal"] .qty-g,
[data-unit-mode="gal"] .qty-sep { display: none; }
[data-unit-mode="gal"] .qty-gal { display: inline; }
[data-unit-mode="gal"] li:not(.is-liquid) .qty-gal { display: none; }
[data-unit-mode="gal"] li:not(.is-liquid) .qty-lb { display: inline; }
[data-unit-mode="all"] .qty-sep { display: inline; }
@media (max-width: 480px) {
    [data-unit-mode="all"] .ingredient-amount { flex-direction: column; align-items: flex-end; }
    [data-unit-mode="all"] .qty-sep { display: none; }
    .formula-unit-bar:not(.formula-unit-bar--baker) .formula-unit-bar-row { flex-direction: column; align-items: stretch; }
    .formula-lang-switch { margin-left: 0; justify-content: flex-start; }
}
</style>

<script>
var __PRODUCTION_I18N__ = <?php echo json_encode([
    'ready_to_record' => bakery_t('production.ready_to_record', ['count' => '__COUNT__']),
    'record_production' => bakery_t('production.record_production'),
    'record_units' => bakery_t('production.record_units', ['count' => '__COUNT__']),
    'record_units_plural' => bakery_t('production.record_units_plural', ['count' => '__COUNT__']),
    'error_enter_before_save' => bakery_t('production.error_enter_before_save'),
    'confirm_record' => bakery_t('production.confirm_record', ['units' => '__UNITS__', 'products' => '__PRODUCTS__']),
    'confirm_record_plural' => bakery_t('production.confirm_record_plural', ['units' => '__UNITS__', 'products' => '__PRODUCTS__']),
], JSON_UNESCAPED_UNICODE); ?>;
document.addEventListener('DOMContentLoaded', function () {
    (function () {
        var storageKey = 'bakery.formulaUnitMode';
        var bakerView = !!document.querySelector('[data-baker-units]');
        var modes = bakerView ? ['g', 'lb', 'gal'] : ['g', 'lb', 'gal', 'all'];
        var fallback = bakerView ? 'g' : 'all';
        var root = document;
        function applyFormulaUnitMode(mode) {
            if (modes.indexOf(mode) === -1) mode = fallback;
            root.querySelectorAll('[data-formula-units]').forEach(function (el) {
                el.setAttribute('data-unit-mode', mode);
            });
            root.querySelectorAll('.formula-unit-btn').forEach(function (btn) {
                var on = btn.getAttribute('data-unit') === mode;
                btn.classList.toggle('is-active', on);
                btn.setAttribute('aria-checked', on ? 'true' : 'false');
            });
            try { localStorage.setItem(storageKey, mode); } catch (err) {}
        }
        var saved = null;
        try { saved = localStorage.getItem(storageKey); } catch (err) {}
        if (saved) applyFormulaUnitMode(saved);
        root.querySelectorAll('.formula-unit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                applyFormulaUnitMode(btn.getAttribute('data-unit'));
            });
        });
    })();
    var i18n = __PRODUCTION_I18N__;
    var form = document.getElementById('bp-work-form');
    var submitBtn = document.getElementById('bp-submit-btn');
    var fillBtn = document.getElementById('bp-fill-remaining');
    var formError = document.getElementById('bp-form-error');
    var submitUnits = document.getElementById('bp-submit-units');
    var submitting = false;

    function parseQty(input) {
        var value = parseInt(input.value, 10);
        return isNaN(value) || value < 0 ? 0 : value;
    }

    function hideFormError() {
        if (!formError) return;
        formError.hidden = true;
        formError.textContent = '';
    }

    function showFormError(message) {
        if (!formError) return;
        formError.hidden = false;
        formError.textContent = message;
    }

    function updateSubmitSummary() {
        if (!form) return;
        var total = 0;
        var productIds = {};
        form.querySelectorAll('.bp-qty-input, .bp-waste-input').forEach(function (input) {
            var qty = parseQty(input);
            if (qty > 0) {
                total += qty;
                var match = input.name.match(/\[(\d+)\]/);
                if (match) productIds[match[1]] = true;
            }
        });
        var products = Object.keys(productIds).length;
        if (submitUnits) {
            submitUnits.textContent = String(total);
            var summaryEl = document.getElementById('bp-submit-summary');
            if (summaryEl) {
                summaryEl.innerHTML = i18n.ready_to_record.replace('__COUNT__', '<strong id="bp-submit-units">' + total + '</strong>');
            }
        }
        if (submitBtn) {
            submitBtn.textContent = total > 0
                ? (total === 1 ? i18n.record_units.replace('__COUNT__', total) : i18n.record_units_plural.replace('__COUNT__', total))
                : i18n.record_production;
        }
        return { total: total, products: products };
    }

    document.querySelectorAll('.quantity-stepper').forEach(function (stepper) {
        var input = stepper.querySelector('input[type="number"]');
        if (!input) return;

        function changeBy(amount) {
            input.value = String(Math.max(0, parseQty(input) + amount));
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        stepper.querySelectorAll('.quantity-step').forEach(function (button) {
            button.addEventListener('click', function () {
                changeBy(parseInt(button.getAttribute('data-step'), 10) || 0);
            });
        });

        input.addEventListener('input', function () {
            if (parseQty(input) !== parseInt(input.value, 10) && input.value !== '') {
                input.value = String(parseQty(input));
            }
            hideFormError();
            updateSubmitSummary();
        });

        input.addEventListener('wheel', function (event) {
            if (document.activeElement !== input || event.deltaY === 0) return;
            event.preventDefault();
            changeBy(event.deltaY < 0 ? 1 : -1);
        }, { passive: false });
    });

    document.querySelectorAll('.bp-waste-input').forEach(function (input) {
        input.addEventListener('input', function () {
            if (parseQty(input) !== parseInt(input.value, 10) && input.value !== '') input.value = String(parseQty(input));
            hideFormError();
            updateSubmitSummary();
        });
    });

    if (fillBtn && form) {
        fillBtn.addEventListener('click', function () {
            form.querySelectorAll('.bp-qty-input').forEach(function (input) {
                input.value = String(Math.max(0, parseInt(input.getAttribute('data-remaining'), 10) || 0));
            });
            hideFormError();
            updateSubmitSummary();
        });
    }

    if (form && submitBtn) {
        form.addEventListener('submit', function (event) {
            if (submitting) {
                event.preventDefault();
                return;
            }
            var summary = updateSubmitSummary();
            if (summary.total <= 0) {
                event.preventDefault();
                showFormError(i18n.error_enter_before_save);
                return;
            }
            var message = (summary.total === 1 && summary.products === 1)
                ? i18n.confirm_record.replace('__UNITS__', summary.total).replace('__PRODUCTS__', summary.products)
                : i18n.confirm_record_plural.replace('__UNITS__', summary.total).replace('__PRODUCTS__', summary.products);
            if (!window.confirm(message)) {
                event.preventDefault();
                return;
            }
            submitting = true;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving…';
            hideFormError();
        });
    }

    updateSubmitSummary();
});
</script>

<?php require_once 'includes/footer.php'; ?>
