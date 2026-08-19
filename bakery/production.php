<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/daily_order_generation.php';
require_once 'includes/product_inventory.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/exception_desk.php';
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Days of the week for display
$days = bakery_day_names();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_production') {
    try {
        if (!$inventoryReady) {
            throw new RuntimeException(bakery_t('production.error_inventory_ops'));
        }
        $productionDate = bakery_inventory_validate_date((string)($_POST['production_date'] ?? ''));
        $quantities = $_POST['produced'] ?? [];
        if (!is_array($quantities)) {
            throw new InvalidArgumentException(bakery_t('production.error_enter_units'));
        }
        $savedUnits = 0;
        $savedProducts = 0;
        $db->beginTransaction();
        foreach ($quantities as $productId => $quantity) {
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity <= 0) {
                continue;
            }
            if ((int)$productId <= 0) {
                throw new InvalidArgumentException(bakery_t('production.error_product_id'));
            }
            if (is_array($bakerProductIds) && !in_array((int)$productId, $bakerProductIds, true)) {
                throw new InvalidArgumentException(bakery_t('production.error_not_in_list'));
            }
            bakery_inventory_record_production($db, $productionDate, (int)$productId, (int)$quantity, 'Production confirmed');
            $savedUnits += (int)$quantity;
            $savedProducts++;
        }
        if ($savedUnits === 0) {
            throw new InvalidArgumentException(bakery_t('production.error_enter_quantity'));
        }
        $db->commit();
        header('Location: production.php?date=' . urlencode($productionDate) . '&saved=' . $savedUnits . '&products=' . $savedProducts);
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
try {
    require_once __DIR__ . '/includes/demand_review.php';
    $demand = bakery_operating_demand_by_product($db, $selectedDate);
    $hasDailyOrders = $demand['has_daily'];
    $demandByProduct = $demand['by_product'];

    if (!empty($demandByProduct)) {
        $productIds = array_keys($demandByProduct);
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $bakerClause = $isBaker && !empty($bakerProductIds)
            ? ' AND p.id IN (' . implode(',', array_map('intval', $bakerProductIds)) . ')'
            : '';
        $orders = $db->prepare("
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.weight_grams,
                p.dough_type_id,
                dt.name as dough_type_name,
                dt.id as dt_id
            FROM products p
            LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
            WHERE p.id IN ({$placeholders}) {$bakerClause}
            ORDER BY dt.name, p.name
        ");
        $orders->execute($productIds);
        $productionData = [];
        foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            $qty = (int)($demandByProduct[$pid] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $row['total_quantity'] = $qty;
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
                'dough_type_id' => $doughTypeId
            ];
        }
        
        $plannedQty = (int)$item['total_quantity'];
        $madeQty = (int)($producedByProduct[$item['product_id']] ?? 0);
        $remainingQty = max(0, $plannedQty - $madeQty);
        $item['planned_quantity'] = $plannedQty;
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

// Set page title
$page_title = $isBaker ? bakery_t('page.production_baker') : bakery_t('page.production');
?>

<link rel="stylesheet" href="<?php echo bakery_asset_href('css/exception_desk.css'); ?>">
<div class="bp-screen">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <header class="bp-header">
        <div class="bp-header__top">
            <h1 class="bp-title"><?php echo $isBaker ? bakery_t('production.title_baker') : bakery_t('production.title_ops'); ?></h1>
            <a class="bp-pack-link<?php echo $allProductionComplete ? ' bp-pack-link--ready' : ''; ?>" href="<?php echo htmlspecialchars($packListHref, ENT_QUOTES, 'UTF-8'); ?>">
                <?php bakery_te('nav.pack_list'); ?>
            </a>
        </div>
        <form method="get" action="production.php" class="bp-date-form">
            <label class="bp-date-label" for="date"><?php bakery_te('production.bake_for_delivery'); ?></label>
            <input type="date" name="date" id="date" class="bp-date-input"
                   value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                   onchange="this.form.submit()">
            <?php if ($pageReturnKey): ?><input type="hidden" name="return" value="<?php echo htmlspecialchars((string)$pageReturnKey, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
            <?php if ($attentionShortfall): ?><input type="hidden" name="attention" value="shortfall"><?php endif; ?>
            <p class="bp-date-context">
                <strong><?php echo htmlspecialchars($days[$selectedDay], ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(date('M j, Y', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="bp-date-source"><?php echo htmlspecialchars($orderSourceLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
        </form>
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
        bakery_exception_desk_render_baker($db, $selectedDate, $bakerShortages, 'production.php?date=' . rawurlencode($selectedDate));
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
                        </div>
                    </header>

                    <?php if (!empty($data['formula']) && (float)($data['formula']['total_percentage'] ?? 0) > 0 && !empty($doughTypeId)):
                        $ingredientsStmt = $db->prepare("
                            SELECT i.name, fi.percentage
                            FROM formula_ingredients fi
                            JOIN ingredients i ON fi.ingredient_id = i.id
                            WHERE fi.dough_type_id = ?
                            ORDER BY fi.percentage DESC
                        ");
                        $ingredientsStmt->execute([$doughTypeId]);
                        $ingredients = $ingredientsStmt->fetchAll(PDO::FETCH_ASSOC);
                        $totalFlour = $data['total_weight_grams'] / ($data['formula']['total_percentage'] / 100);
                    ?>
                        <details class="bp-formula">
                            <summary><?php echo htmlspecialchars(bakery_t('production.dough_formula', ['grams' => number_format($data['total_weight_grams'], 0)]), ENT_QUOTES, 'UTF-8'); ?></summary>
                            <ul class="bp-formula__list">
                                <?php foreach ($ingredients as $ingredient):
                                    $amount = $totalFlour * ($ingredient['percentage'] / 100);
                                ?>
                                    <li><span><?php echo htmlspecialchars($ingredient['name'], ENT_QUOTES, 'UTF-8'); ?></span><strong><?php echo number_format($amount, 0); ?>g</strong></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>

                    <div class="bp-products">
                        <?php foreach ($data['products'] as $product):
                            $state = $product['completion_state'];
                            $overPlan = max(0, (int)$product['made_quantity'] - (int)$product['planned_quantity']);
                        ?>
                            <article class="bp-product bp-product--<?php echo htmlspecialchars($state, ENT_QUOTES, 'UTF-8'); ?><?php echo (int)$product['remaining_quantity'] > 0 ? ' ops-attention-row' : ''; ?>" id="product-<?php echo (int)$product['product_id']; ?>" data-product-id="<?php echo (int)$product['product_id']; ?>">
                                <div class="bp-product__main">
                                    <div class="bp-product__name-row">
                                        <h3 class="bp-product__name"><?php echo htmlspecialchars($product['product_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                        <?php
                                        echo bakery_ops_render_row_chips($pageExceptions, [
                                            'product_id' => (int)$product['product_id'],
                                            'flags' => ((int)$product['remaining_quantity'] > 0) ? ['fg_shortfall' => true] : [],
                                        ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey]);
                                        ?>
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
                                    <dl class="bp-qty-grid">
                                        <div><dt><?php bakery_te('production.planned'); ?></dt><dd><?php echo number_format((int)$product['planned_quantity']); ?></dd></div>
                                        <div><dt><?php bakery_te('production.made'); ?></dt><dd class="bp-qty-made"><?php echo number_format((int)$product['made_quantity']); ?></dd></div>
                                        <div><dt><?php bakery_te('production.left'); ?></dt><dd class="bp-qty-left"><?php echo number_format((int)$product['remaining_quantity']); ?></dd></div>
                                    </dl>
                                    <?php if ($overPlan > 0): ?>
                                        <p class="bp-variance"><?php echo htmlspecialchars(bakery_t('production.over_plan', ['count' => number_format($overPlan)]), ENT_QUOTES, 'UTF-8'); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="bp-record">
                                    <label class="bp-record__label" for="produced-<?php echo (int)$product['product_id']; ?>"><?php bakery_te('production.record_now'); ?></label>
                                    <span class="quantity-stepper">
                                        <button type="button" class="quantity-step" data-step="-1" aria-label="<?php echo htmlspecialchars(bakery_t('production.decrease', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">−</button>
                                        <input type="number"
                                               id="produced-<?php echo (int)$product['product_id']; ?>"
                                               class="bp-qty-input"
                                               min="0"
                                               step="1"
                                               inputmode="numeric"
                                               name="produced[<?php echo (int)$product['product_id']; ?>]"
                                               value="<?php echo (int)$product['remaining_quantity']; ?>"
                                               data-planned="<?php echo (int)$product['planned_quantity']; ?>"
                                               data-made="<?php echo (int)$product['made_quantity']; ?>"
                                               data-remaining="<?php echo (int)$product['remaining_quantity']; ?>"
                                               aria-label="<?php echo htmlspecialchars(bakery_t('production.units_for', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="button" class="quantity-step" data-step="1" aria-label="<?php echo htmlspecialchars(bakery_t('production.increase', ['name' => $product['product_name']]), ENT_QUOTES, 'UTF-8'); ?>">+</button>
                                    </span>
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
.bp-pack-link--ready { background: var(--sf-accent, #d88346); border-color: #e69a5e; }
.bp-date-form { display: grid; gap: 8px; }
.bp-date-label { font-size: .9rem; opacity: .9; }
.bp-date-input { width: 100%; max-width: 280px; min-height: 48px; padding: 10px 12px; border: 1px solid rgba(255,255,255,.35); border-radius: 10px; background: #fff; color: #173f3c; font-size: 16px; }
.bp-date-context { margin: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; font-size: .95rem; }
.bp-date-source { background: rgba(255,255,255,.14); border-radius: 999px; padding: 4px 10px; font-size: .82rem; }
.bp-progress { margin-top: 14px; background: rgba(255,255,255,.08); border-radius: 10px; padding: 12px; }
.bp-progress__labels { display: flex; justify-content: space-between; gap: 10px; font-size: .95rem; margin-bottom: 8px; }
.bp-progress__bar { height: 12px; background: rgba(255,255,255,.18); border-radius: 999px; overflow: hidden; }
.bp-progress__fill { display: block; height: 100%; background: linear-gradient(90deg, #7fd3a8, #d88346); border-radius: 999px; transition: width .25s ease; }
.bp-progress__meta { margin: 8px 0 0; font-size: .85rem; opacity: .9; }
.bp-alert { border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; font-size: 1rem; line-height: 1.45; }
.bp-alert--success { background: var(--sf-success-bg, #e8f7ec); border: 1px solid var(--sf-success-border, #8bc99a); color: var(--sf-success, #1f5f32); }
.bp-alert--error { background: var(--sf-danger-bg, #fdecec); border: 1px solid var(--sf-danger-border, #e7a1a1); color: var(--sf-danger, #7a1f1f); }
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
.bp-formula__list { list-style: none; margin: 0; padding: 0 16px 14px; display: grid; gap: 8px; }
.bp-formula__list li { display: flex; justify-content: space-between; gap: 10px; padding: 8px 10px; background: #fff; border: 1px solid #e3ece7; border-radius: 8px; }
.bp-products { display: grid; gap: 12px; padding: 14px; }
.bp-product { display: grid; gap: 14px; padding: 14px; border: 1px solid #e3ece7; border-radius: 12px; background: #fcfefd; }
.bp-product--complete { background: #f3fbf5; border-color: #b8d9c2; }
.bp-product--partial { border-color: #efc98d; background: #fffaf2; }
.bp-product__name-row { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.bp-product__name { margin: 0; font-size: 1.12rem; line-height: 1.25; overflow-wrap: anywhere; }
.bp-product__weight { margin: 4px 0 0; color: #607068; font-size: .88rem; }
.bp-status { border-radius: 999px; padding: 5px 10px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
.bp-status--complete { background: #d7f0df; color: #1f5f32; }
.bp-status--partial { background: #ffe8c2; color: #8a4d00; }
.bp-status--pending { background: #e8eef0; color: #42545a; }
.bp-qty-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 12px 0 0; }
.bp-qty-grid div { background: #fff; border: 1px solid #e3ece7; border-radius: 10px; padding: 10px 8px; text-align: center; }
.bp-qty-grid dt { margin: 0; font-size: .78rem; color: #607068; text-transform: uppercase; letter-spacing: .03em; }
.bp-qty-grid dd { margin: 4px 0 0; font-size: 1.35rem; font-weight: 800; color: #173f3c; }
.bp-variance { margin: 8px 0 0; font-size: .85rem; color: #8a4d00; }
.bp-record__label { display: block; margin-bottom: 8px; font-weight: 700; color: #1f5f32; }
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
        var products = 0;
        form.querySelectorAll('.bp-qty-input').forEach(function (input) {
            var qty = parseQty(input);
            if (qty > 0) {
                total += qty;
                products += 1;
            }
        });
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
