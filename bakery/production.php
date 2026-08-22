<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/i18n.php';
require_once 'includes/formula_units.php';
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Days of the week for display
$days = [
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
    7 => 'Sunday'
];

// Date picker (default: tomorrow — bake for the next day)
$defaultProductionDate = date('Y-m-d', strtotime('+1 day'));
$selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : $defaultProductionDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || strtotime($selectedDate) === false) {
    $selectedDate = $defaultProductionDate;
}
$selectedDay = bakery_standing_day_from_date($selectedDate);
$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$bakerProductClause = '';
if (is_array($bakerProductIds)) {
    $bakerProductClause = empty($bakerProductIds)
        ? ' AND 1 = 0'
        : ' AND p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
}
$inventoryNotice = '';
$inventoryError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_production') {
    try {
        if (!bakery_inventory_ready($db)) {
            throw new RuntimeException('Finished-goods inventory is not installed. Run the database migrations first.');
        }
        $productionDate = bakery_inventory_validate_date((string)($_POST['production_date'] ?? ''));
        $quantities = $_POST['produced'] ?? [];
        $saved = 0;
        $db->beginTransaction();
        foreach ($quantities as $productId => $quantity) {
            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity <= 0) continue;
            bakery_inventory_record_production($db, $productionDate, (int)$productId, (int)$quantity, 'Production confirmed');
            $saved += (int)$quantity;
        }
        if ($saved === 0) throw new InvalidArgumentException('Enter at least one produced quantity.');
        $db->commit();
        $inventoryNotice = $saved . ' unit' . ($saved === 1 ? '' : 's') . ' added to available inventory for ' . date('M j', strtotime($productionDate)) . '.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $inventoryError = $e->getMessage();
    }
}

// Fetch production data for the selected date
try {
    $dailyCheck = $db->prepare("
        SELECT COUNT(*)
        FROM daily_order_items doi
        JOIN daily_orders do ON doi.daily_order_id = do.id
        WHERE do.order_date = ? AND doi.quantity > 0
    ");
    $dailyCheck->execute([$selectedDate]);
    $hasDailyOrders = (int)$dailyCheck->fetchColumn() > 0;

    if ($hasDailyOrders) {
        $orders = $db->prepare("
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.weight_grams,
                p.dough_type_id,
                dt.name as dough_type_name,
                dt.id as dt_id,
                SUM(doi.quantity) as total_quantity,
                SUM(doi.quantity * p.weight_grams) as total_weight_grams
            FROM daily_order_items doi
            JOIN daily_orders do ON doi.daily_order_id = do.id
            JOIN products p ON doi.product_id = p.id
            LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
            WHERE do.order_date = ? {$bakerProductClause}
            GROUP BY p.id, p.name, p.weight_grams, p.dough_type_id, dt.name, dt.id
            HAVING total_quantity > 0
            ORDER BY dt.name, p.name
        ");
        $orders->execute(array_merge([$selectedDate], $bakerProductIds ?? []));
    } else {
        $dayClause = bakery_standing_day_in_clause($selectedDay);
        $orders = $db->prepare("
            SELECT 
                p.id as product_id,
                p.name as product_name,
                p.weight_grams,
                p.dough_type_id,
                dt.name as dough_type_name,
                dt.id as dt_id,
                SUM(so.quantity) as total_quantity,
                SUM(so.quantity * p.weight_grams) as total_weight_grams
            FROM standing_orders so
            JOIN products p ON so.product_id = p.id
            LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
            WHERE so.day_of_week {$dayClause['sql']} {$bakerProductClause}
            GROUP BY p.id, p.name, p.weight_grams, p.dough_type_id, dt.name, dt.id
            HAVING total_quantity > 0
            ORDER BY dt.name, p.name
        ");
        $orders->execute(array_merge($dayClause['values'], $bakerProductIds ?? []));
    }
    $productionData = $orders->fetchAll();

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
        
        $groupedData[$doughType]['total_items'] += $item['total_quantity'];
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
    
    $hasFormulaPanel = false;
    if (!empty($groupedData)) {
        foreach ($groupedData as $formulaCheck) {
            if (!empty($formulaCheck['formula']) && (float)($formulaCheck['formula']['total_percentage'] ?? 0) > 0) {
                $hasFormulaPanel = true;
                break;
            }
        }
    }

} catch (Exception $e) {
    $error = 'Error loading production data: ' . $e->getMessage();
}

if (!isset($hasFormulaPanel)) {
    $hasFormulaPanel = false;
}

// Set page title
$page_title = 'Production Schedule';
?>

<div class="container">
    <h1>Production Schedule</h1>

    <?php if ($inventoryNotice): ?><div class="success-message"><?php echo htmlspecialchars($inventoryNotice); ?></div><?php endif; ?>
    <?php if ($inventoryError): ?><div class="error-message"><?php echo htmlspecialchars($inventoryError); ?></div><?php endif; ?>
    
    <!-- Date Selector -->
    <div class="day-selector">
        <form method="get" action="production.php" class="form-inline">
            <div class="form-group">
                <label for="date">Production date:</label>
                <input type="date" name="date" id="date" class="form-control"
                       value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>"
                       onchange="this.form.submit()">
            </div>
        </form>
    </div>

    <!-- Starter Feeding Section -->
    <?php if (!empty($starterFeedings)): ?>
        <div class="starter-feeding-section">
            <h2>🧬 Starter Feedings</h2>
            
            <!-- Step 1: Seed Starter Feeding (Full Width) -->
            <?php if (isset($starterFeedings['seed_starter'])): ?>
                <div class="feeding-card priority full-width">
                    <h3>1. Feed Seed Starter</h3>
                    <div class="feeding-grid">
                        <div class="feeding-item">
                            <span class="ingredient-name">Mother Starter</span>
                            <span class="ingredient-amount"><?php echo number_format($starterFeedings['seed_starter']['mother_starter'], 0); ?>g</span>
                        </div>
                        <div class="feeding-item">
                            <span class="ingredient-name">Flour</span>
                            <span class="ingredient-amount"><?php echo number_format($starterFeedings['seed_starter']['flour'], 0); ?>g</span>
                        </div>
                        <div class="feeding-item">
                            <span class="ingredient-name">Water</span>
                            <span class="ingredient-amount"><?php echo number_format($starterFeedings['seed_starter']['water'], 0); ?>g</span>
                        </div>
                        <div class="feeding-item total">
                            <span class="ingredient-name"><strong>Total Seed Starter</strong></span>
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
                            <h3>2. Feed Regular Starter</h3>
                            <div class="feeding-grid">
                                <div class="feeding-item">
                                    <span class="ingredient-name">Seed Starter</span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter']['seed_starter'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name">Flour</span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter']['flour'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name">Water</span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter']['water'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item total">
                                    <span class="ingredient-name"><strong>Total Starter</strong></span>
                                    <span class="ingredient-amount"><strong><?php echo number_format($starterFeedings['starter']['total_needed'], 0); ?>g</strong></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Step 3: Starter Liquido Feeding -->
                    <?php if (isset($starterFeedings['starter_liquido'])): ?>
                        <div class="feeding-card">
                            <h3>3. Feed Starter Liquido</h3>
                            <div class="feeding-grid">
                                <div class="feeding-item">
                                    <span class="ingredient-name">Seed Starter</span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter_liquido']['seed_starter'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name">Flour</span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter_liquido']['flour'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item">
                                    <span class="ingredient-name">Water</span>
                                    <span class="ingredient-amount"><?php echo number_format($starterFeedings['starter_liquido']['water'], 0); ?>g</span>
                                </div>
                                <div class="feeding-item total">
                                    <span class="ingredient-name"><strong>Total Starter Liquido</strong></span>
                                    <span class="ingredient-amount"><strong><?php echo number_format($starterFeedings['starter_liquido']['total_needed'], 0); ?>g</strong></span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
        <div class="production-summary">
            <h2>Production for <?php echo $days[$selectedDay]; ?>, <?php echo htmlspecialchars(date('M j, Y', strtotime($selectedDate)), ENT_QUOTES, 'UTF-8'); ?></h2>

            <?php if (!empty($hasFormulaPanel)): ?>
                <div class="formula-unit-bar" data-formula-units data-unit-mode="all">
                    <div class="formula-unit-bar-row">
                        <span class="formula-unit-label" id="formula-unit-label"><?php echo htmlspecialchars(bakery_t('formula.show_mix_as'), ENT_QUOTES, 'UTF-8'); ?></span>
                        <div class="formula-unit-switch" role="radiogroup" aria-labelledby="formula-unit-label">
                            <?php foreach (bakery_formula_unit_modes() as $unitMode): ?>
                                <button type="button"
                                        role="radio"
                                        class="formula-unit-btn<?php echo $unitMode === 'all' ? ' is-active' : ''; ?>"
                                        data-unit="<?php echo htmlspecialchars($unitMode, ENT_QUOTES, 'UTF-8'); ?>"
                                        aria-checked="<?php echo $unitMode === 'all' ? 'true' : 'false'; ?>"
                                        aria-label="<?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode . '_aria'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(bakery_t('formula.units.' . $unitMode), ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <nav class="formula-lang-switch" aria-label="<?php echo htmlspecialchars(bakery_t('formula.lang_label'), ENT_QUOTES, 'UTF-8'); ?>">
                            <a href="<?php echo htmlspecialchars(bakery_lang_switch_query('en'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo bakery_current_lang() === 'en' ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars(bakery_t('formula.lang.en'), ENT_QUOTES, 'UTF-8'); ?></a>
                            <a href="<?php echo htmlspecialchars(bakery_lang_switch_query('es'), ENT_QUOTES, 'UTF-8'); ?>"<?php echo bakery_current_lang() === 'es' ? ' aria-current="true"' : ''; ?>><?php echo htmlspecialchars(bakery_t('formula.lang.es'), ENT_QUOTES, 'UTF-8'); ?></a>
                        </nav>
                    </div>
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
                </div>
            <?php endif; ?>
            
    <?php if (!empty($productionData)): ?>
        <section class="production-confirmation production-confirmation-legacy">
            <div>
                <h2>Confirm finished product</h2>
                <p>Enter the units actually produced. They are immediately available for this delivery day.</p>
            </div>
            <form method="post" class="production-confirm-form">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="confirm_production">
                <input type="hidden" name="production_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <div class="confirmation-grid">
                    <?php foreach ($productionData as $product): ?>
                        <label>
                            <span><?php echo htmlspecialchars($product['product_name']); ?></span>
                            <small>Planned <?php echo number_format($product['total_quantity']); ?> · confirmed <?php echo number_format((int)($producedByProduct[$product['product_id']] ?? 0)); ?></small>
                            <input type="number" min="0" step="1" name="produced[<?php echo (int)$product['product_id']; ?>]" placeholder="Produced now">
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="btn btn-success" type="submit">Add confirmed production to inventory</button>
                <a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($selectedDate); ?>">View inventory</a>
            </form>
        </section>
    <?php endif; ?>

    <?php if (empty($groupedData)): ?>
                <p>No production scheduled for this day.</p>
            <?php else: ?>
                <?php foreach ($groupedData as $doughType => $data): 
                    // Get the dough type ID from the grouped data
                    $doughTypeId = $data['dough_type_id'] ?? null;
                ?>
                    <div class="dough-type-card">
                        <div class="dough-type-header">
                            <div class="dough-type-title">
                                <h3><?php echo htmlspecialchars($doughType); ?></h3>
                            </div>
                            <?php if (!empty($data['formula']) && (float)($data['formula']['total_percentage'] ?? 0) > 0): 
                                // Get detailed ingredients for this dough type
                                $ingredientsStmt = $db->prepare("
                                    SELECT 
                                        i.name,
                                        i.unit,
                                        fi.percentage
                                    FROM formula_ingredients fi
                                    JOIN ingredients i ON fi.ingredient_id = i.id
                                    WHERE fi.dough_type_id = ?
                                    ORDER BY fi.percentage DESC
                                ");
                                $ingredientsStmt->execute([$doughTypeId]);
                                $ingredients = $ingredientsStmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                // Calculate total flour weight (100%)
                                $totalFlour = $data['total_weight_grams'] / ($data['formula']['total_percentage'] / 100);
                                $doughClassification = ['liquid' => false, 'kind' => 'dry', 'density_lb_per_gal' => null];
                            ?>
                                <div class="formula-info">
                                    <div class="formula-details" data-formula-units data-unit-mode="all">
                                        <h4><?php echo htmlspecialchars(bakery_t('formula.ingredients_needed'), ENT_QUOTES, 'UTF-8'); ?></h4>
                                        <div class="ingredients-grid">
                                            <?php foreach ($ingredients as $ingredient): 
                                                $amount = $totalFlour * ($ingredient['percentage'] / 100);
                                                $classification = bakery_formula_classify_ingredient($ingredient['name'], $ingredient['unit'] ?? '');
                                            ?>
                                                <div class="ingredient-item<?php echo !empty($classification['liquid']) ? ' is-liquid' : ''; ?>"
                                                     data-grams="<?php echo htmlspecialchars((string) $amount, ENT_QUOTES, 'UTF-8'); ?>"
                                                     data-liquid="<?php echo !empty($classification['liquid']) ? '1' : '0'; ?>"
                                                     <?php if (!empty($classification['density_lb_per_gal'])): ?>data-density="<?php echo htmlspecialchars((string) $classification['density_lb_per_gal'], ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                                    <span class="ingredient-name"><?php echo htmlspecialchars($ingredient['name']); ?></span>
                                                    <span class="ingredient-amount"><?php echo bakery_formula_amount_markup($amount, $classification); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="ingredient-item total"
                                                 data-grams="<?php echo htmlspecialchars((string) $data['total_weight_grams'], ENT_QUOTES, 'UTF-8'); ?>"
                                                 data-liquid="0">
                                                <span class="ingredient-name"><strong><?php echo htmlspecialchars(bakery_t('formula.total_dough'), ENT_QUOTES, 'UTF-8'); ?></strong></span>
                                                <span class="ingredient-amount"><strong><?php echo bakery_formula_amount_markup($data['total_weight_grams'], $doughClassification); ?></strong></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="formula-info">
                                    <?php if (!empty($data['formula']) && (float)($data['formula']['total_percentage'] ?? 0) <= 0): ?>
                                        <span class="no-formula">Formula percentages total 0% — cannot calculate ingredients</span>
                                    <?php else: ?>
                                        <span class="no-formula">No formula assigned — set up formula on dough type before baking</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="product-list">
                            <h4>Products to Make:</h4>
                            <div class="products-grid">
                                <?php foreach ($data['products'] as $product): ?>
                                    <div class="product-item">
                                        <div class="product-name">
                                            <?php echo htmlspecialchars($product['product_name']); ?> 
                                            (<?php echo $product['weight_grams'] !== null ? number_format($product['weight_grams']) : '0'; ?>g)
                                        </div>
                                        <div class="product-quantity">
                                            <?php echo number_format($product['total_quantity']); ?> units
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($productionData)): ?>
                <section class="production-confirmation production-confirmation-after">
                    <div>
                        <h2>Confirm finished product</h2>
                        <p>Planned quantities are ready to submit. Tap the number, use − / +, or focus it and roll the wheel to adjust.</p>
                    </div>
                    <form method="post" class="production-confirm-form">
                        <?php echo bakery_csrf_field(); ?>
                        <input type="hidden" name="action" value="confirm_production">
                        <input type="hidden" name="production_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                        <div class="confirmation-grid">
                            <?php foreach ($productionData as $product): ?>
                                <label class="confirmation-item">
                                    <span class="confirmation-product-name"><?php echo htmlspecialchars($product['product_name']); ?></span>
                                    <small>Planned <?php echo number_format($product['total_quantity']); ?> · confirmed <?php echo number_format((int)($producedByProduct[$product['product_id']] ?? 0)); ?></small>
                                    <span class="quantity-stepper">
                                        <button type="button" class="quantity-step" data-step="-1" aria-label="Decrease <?php echo htmlspecialchars($product['product_name']); ?>">−</button>
                                        <input type="number" min="0" step="1" inputmode="numeric" name="produced[<?php echo (int)$product['product_id']; ?>]" value="<?php echo (int)$product['total_quantity']; ?>" aria-label="Produced units for <?php echo htmlspecialchars($product['product_name']); ?>">
                                        <button type="button" class="quantity-step" data-step="1" aria-label="Increase <?php echo htmlspecialchars($product['product_name']); ?>">+</button>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="confirmation-actions">
                            <button class="btn btn-success" type="submit">Add confirmed production to inventory</button>
                            <a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($selectedDate); ?>">View inventory</a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 10px;
    }
    
    .day-selector {
        margin: 15px 0;
        padding: 12px;
        background-color: #f8f9fa;
        border-radius: 8px;
    }
    
    .form-inline {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .form-group {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-control {
        padding: 8px 12px;
        border: 1px solid #ced4da;
        border-radius: 6px;
        font-size: 16px; /* Prevents zoom on iOS */
    }
    
    .production-summary {
        margin-top: 20px;
    }
    .production-confirmation { margin: 20px 0; padding: 20px; border: 1px solid #b8d9c2; border-radius: 10px; background: #f3fbf5; }
    .production-confirmation-legacy { display: none; }
    .production-confirmation-after { margin-top: 28px; scroll-margin-top: 20px; }
    .production-confirmation h2 { margin: 0 0 5px; color: #1f6b35; }
    .production-confirmation p { margin: 0 0 16px; color: #4b6351; }
    .confirmation-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .confirmation-item { display: flex; flex-direction: column; gap: 5px; font-weight: 600; min-width: 0; }
    .confirmation-product-name { overflow-wrap: anywhere; }
    .confirmation-grid small { color: #607068; font-weight: 400; }
    .quantity-stepper { display: grid; grid-template-columns: 48px minmax(70px, 1fr) 48px; gap: 7px; align-items: stretch; }
    .quantity-stepper input { width: 100%; min-width: 0; padding: 8px; border: 1px solid #aab9af; border-radius: 5px; text-align: center; font-size: 1.2rem; font-weight: 700; }
    .quantity-step { min-height: 48px; border: 1px solid #8db59a; border-radius: 7px; background: #fff; color: #1f6637; font-size: 1.6rem; line-height: 1; cursor: pointer; touch-action: manipulation; }
    .quantity-step:active { background: #dff1e4; transform: translateY(1px); }
    .confirmation-actions { display: flex; gap: 10px; flex-wrap: wrap; }
    .confirmation-actions .btn { min-height: 46px; }
    @media (max-width: 560px) {
        .production-confirmation-after { margin-left: -2px; margin-right: -2px; padding: 16px 14px; }
        .confirmation-grid { grid-template-columns: 1fr; gap: 14px; }
        .confirmation-item { padding: 10px; border: 1px solid #d6e6da; border-radius: 8px; background: #fff; }
        .quantity-stepper { grid-template-columns: 52px minmax(78px, 1fr) 52px; }
        .confirmation-actions { flex-direction: column; }
        .confirmation-actions .btn { width: 100%; text-align: center; }
    }
    
    .production-summary h2 {
        font-size: 1.4rem;
        margin-bottom: 20px;
        color: #333;
    }
    
    /* Dough Type Cards */
    .dough-type-card {
        margin-bottom: 20px;
        border: 1px solid #dee2e6;
        border-radius: 12px;
        overflow: hidden;
        background: white;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .dough-type-header {
        background: linear-gradient(135deg, #e9ecef 0%, #f8f9fa 100%);
        padding: 15px;
        border-bottom: 1px solid #dee2e6;
    }
    
    .dough-type-title h3 {
        margin: 0 0 10px 0;
        font-size: 1.2rem;
        color: #2c3e50;
        font-weight: 600;
    }
    
    .formula-info h4 {
        margin: 0 0 12px 0;
        font-size: 1rem;
        color: #495057;
        font-weight: 600;
    }
    
    .no-formula {
        color: #6c757d;
        font-style: italic;
        font-size: 0.9rem;
    }
    
    /* Ingredients Grid */
    .ingredients-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .ingredient-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 12px;
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        font-size: 0.9rem;
    }
    
    .ingredient-item.total {
        background: #e3f2fd;
        border-color: #2196f3;
        margin-top: 4px;
    }
    
    .ingredient-name {
        flex: 1;
        color: #333;
    }
    
    .ingredient-amount {
        font-weight: 600;
        color: #2c3e50;
        font-family: 'SF Mono', Consolas, monospace;
    }
    
    /* Product List */
    .product-list {
        padding: 15px;
    }
    
    .product-list h4 {
        margin: 0 0 12px 0;
        font-size: 1rem;
        color: #495057;
        font-weight: 600;
    }
    
    .products-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 8px;
    }
    
    .product-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 12px;
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 6px;
    }
    
    .product-name {
        flex: 1;
        font-weight: 500;
        color: #333;
        font-size: 0.9rem;
    }
    
    .product-quantity {
        font-weight: 600;
        color: #007bff;
        font-family: 'SF Mono', Consolas, monospace;
        font-size: 0.9rem;
    }
    
    .alert {
        padding: 12px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 6px;
        font-size: 0.9rem;
    }
    
    .alert-danger {
        color: #721c24;
        background-color: #f8d7da;
        border-color: #f5c6cb;
    }

    /* Starter Feeding Styles */
    .starter-feeding-section {
        margin: 15px 0 20px 0;
        padding: 15px;
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
        border: 1px solid #0ea5e9;
        border-radius: 8px;
        box-shadow: 0 2px 6px rgba(14, 165, 233, 0.1);
    }
    
    .starter-feeding-section h2 {
        margin: 0 0 12px 0;
        color: #0c4a6e;
        font-size: 1.2rem;
        font-weight: 600;
    }
    
    .feeding-card {
        background: white;
        border: 1px solid #bae6fd;
        border-radius: 6px;
        margin-bottom: 10px;
        padding: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    
    .feeding-card.priority {
        border-color: #06b6d4;
        background: linear-gradient(135deg, #ffffff 0%, #f0fdfa 100%);
    }
    
    .feeding-card h3 {
        margin: 0 0 8px 0;
        font-size: 1rem;
        color: #0e7490;
        font-weight: 600;
    }
    
    .feeding-card.priority h3 {
        color: #0f766e;
    }
    
    .feeding-card.full-width {
        margin-bottom: 15px;
    }
    
    .feeding-row {
        display: flex;
        gap: 12px;
        margin-bottom: 10px;
    }
    
    .feeding-row .feeding-card {
        flex: 1;
        margin-bottom: 0;
    }
    
    .feeding-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
    }
    
    .feeding-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 8px 6px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 0.85rem;
        text-align: center;
    }
    
    .feeding-item.total {
        background: #dcfce7;
        border-color: #22c55e;
        grid-column: span 4;
        flex-direction: row;
        justify-content: space-between;
        text-align: left;
    }
    
    .feeding-item .ingredient-name {
        color: #475569;
        font-size: 0.8rem;
        margin-bottom: 4px;
        font-weight: 500;
    }
    
    .feeding-item .ingredient-amount {
        font-weight: 700;
        color: #0c4a6e;
        font-family: 'SF Mono', Consolas, monospace;
        font-size: 0.9rem;
    }
    
    .feeding-item.total .ingredient-name {
        margin-bottom: 0;
        font-size: 0.85rem;
    }
    
    .feeding-item.total .ingredient-amount {
        color: #15803d;
    }
    
    /* Mobile Optimizations */
    @media (max-width: 768px) {
        .container {
            padding: 8px;
        }
        
        .dough-type-header {
            padding: 12px;
        }
        
        .dough-type-title h3 {
            font-size: 1.1rem;
        }
        
        .formula-info h4,
        .product-list h4 {
            font-size: 0.95rem;
        }
        
        .ingredient-item,
        .product-item {
            padding: 8px 10px;
            font-size: 0.85rem;
        }
        
        .form-control {
            font-size: 16px; /* Prevents zoom on mobile */
        }
        
        .starter-feeding-section {
            margin: 10px 0 15px 0;
            padding: 12px;
        }
        
        .starter-feeding-section h2 {
            font-size: 1.1rem;
        }
        
        .feeding-card {
            padding: 10px;
            margin-bottom: 8px;
        }
        
        .feeding-row {
            flex-direction: column;
            gap: 8px;
        }
        
        .feeding-row .feeding-card {
            margin-bottom: 8px;
        }
        
        .feeding-card h3 {
            font-size: 0.95rem;
        }
        
        .feeding-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 6px;
        }
    }
    
    @media (max-width: 480px) {
        .production-summary h2 {
            font-size: 1.2rem;
        }
        
        .dough-type-title h3 {
            font-size: 1rem;
        }
        
        .ingredient-item,
        .product-item {
            font-size: 0.8rem;
            padding: 6px 8px;
        }
        
        .ingredient-amount,
        .product-quantity {
            font-size: 0.8rem;
        }
        
        .feeding-card h3 {
            font-size: 0.9rem;
        }
        
        .feeding-item {
            font-size: 0.75rem;
            padding: 5px 3px;
        }
        
        .feeding-item .ingredient-name {
            font-size: 0.7rem;
        }
        
        .feeding-item .ingredient-amount {
            font-size: 0.8rem;
        }
    }
    
    /* Formula unit toggle — sticky, phone/tablet friendly */
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
    .formula-unit-bar-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px 12px;
    }
    .formula-unit-label {
        font-weight: 600;
        color: #2c3e50;
        font-size: 0.95rem;
    }
    .formula-unit-switch {
        display: flex;
        flex: 1 1 220px;
        min-width: 0;
        border: 1px solid #8db59a;
        border-radius: 10px;
        overflow: hidden;
        background: #f3fbf5;
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
    .formula-unit-btn:last-child { border-right: 0; }
    .formula-unit-btn.is-active,
    .formula-unit-btn[aria-checked="true"] {
        background: #1f6b35;
        color: #fff;
    }
    .formula-lang-switch {
        display: flex;
        gap: 8px;
        margin-left: auto;
        font-size: 0.9rem;
    }
    .formula-lang-switch a {
        color: #1f6b35;
        text-decoration: none;
        min-height: 44px;
        display: inline-flex;
        align-items: center;
        padding: 0 8px;
        border-radius: 8px;
    }
    .formula-lang-switch a[aria-current="true"] {
        font-weight: 700;
        background: #e8f5e9;
    }
    .formula-unit-help {
        margin-top: 10px;
        font-size: 0.88rem;
        color: #4b6351;
    }
    .formula-unit-help summary {
        cursor: pointer;
        font-weight: 600;
        min-height: 36px;
        display: flex;
        align-items: center;
    }
    .formula-unit-help p { margin: 8px 0; }
    .formula-unit-help ul {
        margin: 0;
        padding-left: 1.2em;
    }
    .ingredient-amount {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: baseline;
        gap: 0;
        text-align: right;
        max-width: 70%;
    }
    [data-unit-mode="g"] .qty-lb,
    [data-unit-mode="g"] .qty-gal,
    [data-unit-mode="g"] .qty-sep { display: none; }
    [data-unit-mode="lb"] .qty-g,
    [data-unit-mode="lb"] .qty-gal,
    [data-unit-mode="lb"] .qty-sep { display: none; }
    [data-unit-mode="gal"] .qty-g,
    [data-unit-mode="gal"] .qty-sep { display: none; }
    [data-unit-mode="gal"] .qty-gal { display: inline; }
    [data-unit-mode="gal"] .ingredient-item:not(.is-liquid) .qty-gal { display: none; }
    [data-unit-mode="gal"] .ingredient-item:not(.is-liquid) .qty-lb { display: inline; }
    [data-unit-mode="all"] .qty-sep { display: inline; }

    /* Landscape mobile */
    @media (max-width: 896px) and (orientation: landscape) {
        .ingredients-grid,
        .products-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 6px;
        }
        
        .ingredient-item,
        .product-item {
            font-size: 0.8rem;
            padding: 6px 8px;
        }
    }

    @media (max-width: 480px) {
        [data-unit-mode="all"] .ingredient-amount {
            flex-direction: column;
            align-items: flex-end;
            max-width: 48%;
        }
        [data-unit-mode="all"] .qty-sep { display: none; }
        .formula-unit-bar-row { flex-direction: column; align-items: stretch; }
        .formula-lang-switch { margin-left: 0; justify-content: flex-start; }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    (function () {
        var storageKey = 'bakery.formulaUnitMode';
        var modes = ['g', 'lb', 'gal', 'all'];
        var root = document;

        function applyFormulaUnitMode(mode) {
            if (modes.indexOf(mode) === -1) mode = 'all';
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

    document.querySelectorAll('.quantity-stepper').forEach(function (stepper) {
        var input = stepper.querySelector('input[type="number"]');
        if (!input) return;

        function changeBy(amount) {
            var current = parseInt(input.value, 10);
            if (isNaN(current)) current = 0;
            input.value = Math.max(0, current + amount);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        stepper.querySelectorAll('.quantity-step').forEach(function (button) {
            button.addEventListener('click', function () {
                changeBy(parseInt(button.getAttribute('data-step'), 10) || 0);
                input.focus({ preventScroll: true });
            });
        });

        input.addEventListener('wheel', function (event) {
            if (document.activeElement !== input || event.deltaY === 0) return;
            event.preventDefault();
            changeBy(event.deltaY < 0 ? 1 : -1);
        }, { passive: false });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
