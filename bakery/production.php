<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
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

// Date picker (default: today)
$selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || strtotime($selectedDate) === false) {
    $selectedDate = date('Y-m-d');
}
$selectedDay = bakery_standing_day_from_date($selectedDate);
$productionSource = 'daily';
$usingStandingFallback = false;

// Fetch production data for the selected date
$integrityWarnings = [];
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
            WHERE do.order_date = ?
            GROUP BY p.id, p.name, p.weight_grams, p.dough_type_id, dt.name, dt.id
            HAVING total_quantity > 0
            ORDER BY dt.name, p.name
        ");
        $orders->execute([$selectedDate]);
    } else {
        $productionSource = 'standing';
        $usingStandingFallback = true;
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
            WHERE so.day_of_week {$dayClause['sql']}
            GROUP BY p.id, p.name, p.weight_grams, p.dough_type_id, dt.name, dt.id
            HAVING total_quantity > 0
            ORDER BY dt.name, p.name
        ");
        $orders->execute($dayClause['values']);
    }
    $productionData = $orders->fetchAll();

    $integrityWarnings = [];
    foreach ($productionData as $item) {
        $qty = (int)$item['total_quantity'];
        $weight = $item['weight_grams'];
        if ($qty > 0 && ($weight === null || (int)$weight <= 0)) {
            $integrityWarnings[] = sprintf(
                'Product "%s" has %d units scheduled but no weight (weight_grams missing or zero) — dough totals will be understated.',
                $item['product_name'],
                $qty
            );
        }
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

    foreach ($groupedData as $doughType => $data) {
        if ((int)$data['total_items'] <= 0) {
            continue;
        }
        if (empty($data['formula'])) {
            $integrityWarnings[] = sprintf(
                'Dough type "%s" has %d units scheduled but no formula — ingredient amounts cannot be calculated.',
                $doughType,
                (int)$data['total_items']
            );
        } elseif ((float)($data['formula']['total_percentage'] ?? 0) <= 0) {
            $integrityWarnings[] = sprintf(
                'Dough type "%s" has a formula with 0%% total — ingredient amounts cannot be calculated.',
                $doughType
            );
        }
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
    $error = 'Error loading production data: ' . $e->getMessage();
}

// Set page title
$page_title = 'Production Schedule';
?>

<div class="container">
    <h1>Production Schedule</h1>
    
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

    <?php if ($usingStandingFallback): ?>
        <div class="alert alert-info">
            <strong>No daily orders for <?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>
                (<?php echo $days[$selectedDay]; ?>).</strong>
            Showing standing-order totals for this weekday instead. Generate daily orders to reflect same-day edits.
        </div>
    <?php elseif ($productionSource === 'daily'): ?>
        <div class="alert alert-info production-source-banner">
            Totals from <strong>daily orders</strong> for <?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>
            (<?php echo $days[$selectedDay]; ?>).
        </div>
    <?php endif; ?>
    
    <?php if (!empty($integrityWarnings)): ?>
        <div class="alert alert-warning">
            <strong>Data integrity warnings (<?php echo count($integrityWarnings); ?>):</strong>
            <ul class="integrity-warning-list">
                <?php foreach ($integrityWarnings as $warning): ?>
                    <li><?php echo htmlspecialchars($warning); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
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
                            ?>
                                <div class="formula-info">
                                    <div class="formula-details">
                                        <h4>Ingredients Needed:</h4>
                                        <div class="ingredients-grid">
                                            <?php foreach ($ingredients as $ingredient): 
                                                $amount = $totalFlour * ($ingredient['percentage'] / 100);
                                            ?>
                                                <div class="ingredient-item">
                                                    <span class="ingredient-name"><?php echo htmlspecialchars($ingredient['name']); ?></span>
                                                    <span class="ingredient-amount"><?php echo number_format($amount, 0); ?>g</span>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="ingredient-item total">
                                                <span class="ingredient-name"><strong>Total Dough</strong></span>
                                                <span class="ingredient-amount"><strong><?php echo number_format($data['total_weight_grams'], 0); ?>g</strong></span>
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

    .alert-warning {
        color: #856404;
        background-color: #fff3cd;
        border-color: #ffc107;
    }

    .alert-info {
        color: #0c5460;
        background-color: #d1ecf1;
        border-color: #bee5eb;
    }

    .production-source-banner {
        margin-bottom: 12px;
    }

    .integrity-warning-list {
        margin: 8px 0 0 0;
        padding-left: 20px;
    }

    .integrity-warning-list li {
        margin-bottom: 4px;
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
</style>

<?php require_once 'includes/footer.php'; ?>
