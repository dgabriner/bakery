<?php
/** Weekly plan-vs-need workspace. Complements production.php; does not replace it. */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/demand_review.php';
require_once 'includes/operational_exceptions.php';

function production_center_week_start(string $value): string {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) $date = new DateTime('monday this week');
    $date->modify('monday this week');
    return $date->format('Y-m-d');
}

/**
 * Status flags for a product/day row. Arithmetic helpers stay next to the row builder.
 *
 * @return list<array{code:string,label:string,tone:string}>
 */
function production_center_row_statuses(array $row, bool $hasActualOrders, bool $inventoryReady): array {
    $statuses = [];
    $demand = (int)$row['demand'];
    $hasPlan = (bool)$row['hasPlan'];
    $planned = (int)$row['planned'];
    $onHand = (int)$row['onHand'];
    $makeNeed = (int)$row['makeNeed'];
    $confirmed = (int)$row['confirmed'];
    $shortfall = (int)$row['shortfall'];
    $afterDelivery = (int)$row['afterDelivery'];
    $configIssues = $row['configIssues'] ?? [];

    if ($hasActualOrders && $demand > 0 && !$hasPlan) {
        $statuses[] = ['code' => 'no_plan', 'label' => 'No saved plan', 'tone' => 'warn'];
    }
    if ($hasPlan && $planned < $demand) {
        $statuses[] = ['code' => 'plan_below', 'label' => 'Plan below demand', 'tone' => 'warn'];
    }
    if ($shortfall > 0) {
        $statuses[] = ['code' => 'fg_short', 'label' => $shortfall . ' short after delivery', 'tone' => 'danger'];
    }
    // Only trust "still to make" when on-hand for this delivery day is available.
    if ($inventoryReady && $makeNeed > 0 && ($demand > 0 || $hasPlan)) {
        $statuses[] = ['code' => 'incomplete', 'label' => $makeNeed . ' still to make', 'tone' => 'warn'];
    }
    if ($inventoryReady && $confirmed > 0 && $makeNeed === 0 && $afterDelivery > max($demand, 1) && $afterDelivery >= 10) {
        $statuses[] = ['code' => 'over', 'label' => 'Large surplus', 'tone' => 'info'];
    } elseif ($inventoryReady && !$hasPlan && $afterDelivery > max($demand, 1) && $afterDelivery >= 10 && $onHand > $demand) {
        $statuses[] = ['code' => 'over', 'label' => 'Large surplus', 'tone' => 'info'];
    }
    foreach ($configIssues as $issue) {
        $statuses[] = ['code' => 'config', 'label' => $issue, 'tone' => 'muted'];
    }
    if (!$statuses && $demand > 0 && $shortfall === 0 && $makeNeed === 0) {
        $statuses[] = ['code' => 'ok', 'label' => 'Covered', 'tone' => 'ok'];
    } elseif (!$statuses && ($demand > 0 || $hasPlan || $onHand > 0 || $confirmed > 0)) {
        $statuses[] = ['code' => 'ok', 'label' => 'On track', 'tone' => 'ok'];
    }
    return $statuses;
}

$weekStart = production_center_week_start((string)($_GET['week'] ?? $_POST['week'] ?? date('Y-m-d')));
$weekDates = [];
for ($offset = 0; $offset < 7; $offset++) $weekDates[] = date('Y-m-d', strtotime($weekStart . " +{$offset} days"));
$weekEnd = end($weekDates);
$showAll = ($_GET['show_all'] ?? '') === '1';
$attentionOnly = (string)($_GET['attention'] ?? '') === '1';
$focusDate = trim((string)($_GET['date'] ?? ''));
if ($focusDate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $focusDate) || !in_array($focusDate, $weekDates, true))) {
    $focusDate = '';
}
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $focusDate !== '' ? $focusDate : $weekStart);
$pageReturnKey = $returnTarget['key'] ?? null;
$attentionLabel = $attentionOnly ? 'Showing product-day rows requiring attention' : '';
$planTableReady = table_exists($db, 'production_plan_items');
$inventoryReady = bakery_inventory_ready($db);
$notice = '';
$error = '';

// Same product-line visibility rules as Daily Production (managers see all).
$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$productClause = '';
if (is_array($bakerProductIds)) {
    $productClause = empty($bakerProductIds) ? ' WHERE 1 = 0' : ' WHERE p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
}
$productStmt = $db->prepare(
    "SELECT p.id, p.name, p.weight_grams, p.dough_type_id, dt.name AS dough_type_name, dt.product_line_id
     FROM products p
     LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
     {$productClause}
     ORDER BY dt.name, p.name"
);
$productStmt->execute($bakerProductIds ?? []);
$products = $productStmt->fetchAll();
$productIds = array_map(static fn($product) => (int)$product['id'], $products);
$allowedProductIds = array_fill_keys($productIds, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plan') {
    try {
        if (!$planTableReady) throw new RuntimeException('Saved production plans are not installed yet. Run scripts/run_migrations.php first.');
        if (production_center_week_start((string)($_POST['week'] ?? '')) !== $weekStart) {
            throw new InvalidArgumentException('The production week changed. Reload the page and try again.');
        }
        $planned = $_POST['planned'] ?? [];
        if (!is_array($planned) || $planned === []) {
            throw new InvalidArgumentException('No changed targets to save. Edit a quantity, then save.');
        }
        $save = $db->prepare(
            'INSERT INTO production_plan_items (delivery_date, product_id, planned_quantity, created_by_user_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE planned_quantity = VALUES(planned_quantity), created_by_user_id = VALUES(created_by_user_id)'
        );
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $saved = 0;
        $db->beginTransaction();
        foreach ($planned as $date => $productQuantities) {
            if (!in_array($date, $weekDates, true) || !is_array($productQuantities)) {
                throw new InvalidArgumentException('A submitted plan item is outside the selected week.');
            }
            foreach ($productQuantities as $productId => $quantity) {
                $productId = (int)$productId;
                if (is_string($quantity)) $quantity = trim($quantity);
                if ($quantity === '' || $quantity === null) {
                    throw new InvalidArgumentException('Batch targets cannot be blank. Use 0 if nothing is planned.');
                }
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
                if (!isset($allowedProductIds[$productId]) || $quantity === false || $quantity < 0) {
                    throw new InvalidArgumentException('Batch targets must be whole numbers of zero or more.');
                }
                $save->execute([$date, $productId, $quantity, $user['id'] ?? null]);
                $saved++;
            }
        }
        if ($saved === 0) throw new InvalidArgumentException('No changed targets to save.');
        $db->commit();
        bakery_record_operational_event($db, BAKERY_OP_PRODUCTION_PLAN_SAVED,
            'Saved ' . $saved . ' production target' . ($saved === 1 ? '' : 's') . ' for week of ' . date('M j', strtotime($weekStart)), [
            'operational_date' => $weekStart,
            'metadata' => ['targets_saved' => $saved, 'week_start' => $weekStart],
        ]);
        $notice = "Saved {$saved} production target" . ($saved === 1 ? '' : 's') . ' for week of ' . date('M j', strtotime($weekStart)) . '.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$standingByWeekday = $actualByDate = $actualDayExists = $inventoryByDate = $plansByDate = [];
$formulaByDoughType = [];
try {
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $standingStmt = $db->prepare(
            "SELECT so.day_of_week, so.product_id, SUM(so.quantity) AS quantity
             FROM standing_orders so
             WHERE so.product_id IN ({$placeholders})
             GROUP BY so.day_of_week, so.product_id"
        );
        $standingStmt->execute($productIds);
        foreach ($standingStmt->fetchAll() as $row) {
            // Normalize legacy Sunday (0) to canonical 7 used by bakery_standing_day_from_date().
            $weekday = function_exists('bakery_normalize_standing_day')
                ? bakery_normalize_standing_day((int)$row['day_of_week'])
                : (((int)$row['day_of_week'] === 0) ? 7 : (int)$row['day_of_week']);
            $productId = (int)$row['product_id'];
            $standingByWeekday[$weekday][$productId] = (int)($standingByWeekday[$weekday][$productId] ?? 0) + (int)$row['quantity'];
        }

        // Scope-independent: positive daily order lines make that date "committed" (matches production.php).
        $actualDayStmt = $db->prepare(
            'SELECT DISTINCT do.order_date
             FROM daily_orders do
             JOIN daily_order_items doi ON doi.daily_order_id = do.id
             WHERE do.order_date BETWEEN ? AND ?
               AND doi.quantity > 0
               AND do.status <> \'cancelled\''
        );
        $actualDayStmt->execute([$weekStart, $weekEnd]);
        foreach ($actualDayStmt->fetchAll(PDO::FETCH_COLUMN) as $actualDate) $actualDayExists[$actualDate] = true;

        $actualStmt = $db->prepare(
            "SELECT do.order_date, doi.product_id, SUM(doi.quantity) AS quantity
             FROM daily_orders do
             JOIN daily_order_items doi ON doi.daily_order_id = do.id
             WHERE do.order_date BETWEEN ? AND ? AND doi.product_id IN ({$placeholders})
             GROUP BY do.order_date, doi.product_id"
        );
        $actualStmt->execute(array_merge([$weekStart, $weekEnd], $productIds));
        foreach ($actualStmt->fetchAll() as $row) {
            $actualByDate[$row['order_date']][(int)$row['product_id']] = (int)$row['quantity'];
        }

        if ($inventoryReady) {
            $inventoryStmt = $db->prepare(
                "SELECT delivery_date, product_id, available_quantity, produced_quantity, loaded_quantity
                 FROM product_inventory_days
                 WHERE delivery_date BETWEEN ? AND ? AND product_id IN ({$placeholders})"
            );
            $inventoryStmt->execute(array_merge([$weekStart, $weekEnd], $productIds));
            foreach ($inventoryStmt->fetchAll() as $row) $inventoryByDate[$row['delivery_date']][(int)$row['product_id']] = $row;
        }

        if ($planTableReady) {
            $planStmt = $db->prepare(
                "SELECT delivery_date, product_id, planned_quantity
                 FROM production_plan_items
                 WHERE delivery_date BETWEEN ? AND ? AND product_id IN ({$placeholders})"
            );
            $planStmt->execute(array_merge([$weekStart, $weekEnd], $productIds));
            foreach ($planStmt->fetchAll() as $row) {
                $plansByDate[$row['delivery_date']][(int)$row['product_id']] = (int)$row['planned_quantity'];
            }
        }

        $doughTypeIds = [];
        foreach ($products as $product) {
            $doughTypeId = (int)($product['dough_type_id'] ?? 0);
            if ($doughTypeId > 0) $doughTypeIds[$doughTypeId] = $doughTypeId;
        }
        if ($doughTypeIds && table_exists($db, 'formula_ingredients')) {
            $dtPlaceholders = implode(',', array_fill(0, count($doughTypeIds), '?'));
            $formulaStmt = $db->prepare(
                "SELECT dough_type_id, COALESCE(SUM(percentage), 0) AS total_percentage
                 FROM formula_ingredients
                 WHERE dough_type_id IN ({$dtPlaceholders})
                 GROUP BY dough_type_id"
            );
            $formulaStmt->execute(array_values($doughTypeIds));
            foreach ($formulaStmt->fetchAll() as $row) {
                $formulaByDoughType[(int)$row['dough_type_id']] = (float)$row['total_percentage'];
            }
        }
    }
} catch (Throwable $e) {
    $error = $error ?: ('Unable to load the production center: ' . $e->getMessage());
}

$days = [];
$totals = [
    'on_hand' => 0,
    'confirmed' => 0,
    'demand' => 0,
    'planned' => 0,
    'make_need' => 0,
    'shortfall' => 0,
    'attention' => 0,
];
$attentionCodes = ['no_plan', 'plan_below', 'fg_short', 'incomplete', 'config'];

$operatingDemandByDate = [];
foreach ($weekDates as $demandDate) {
    $operatingDemandByDate[$demandDate] = bakery_operating_demand_by_product($db, $demandDate);
}

foreach ($weekDates as $date) {
    $weekday = (int)bakery_standing_day_from_date($date);
    $operatingDemand = $operatingDemandByDate[$date] ?? ['by_product' => [], 'has_daily' => false];
    $hasActualOrders = !empty($operatingDemand['has_daily']);
    $rows = [];
    $summary = [
        'demand' => 0,
        'on_hand' => 0,
        'planned' => 0,
        'make_need' => 0,
        'shortfall' => 0,
        'attention' => 0,
    ];

    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $standing = (int)($standingByWeekday[$weekday][$productId] ?? 0);
        $actual = (int)($operatingDemand['by_product'][$productId] ?? 0);
        $demand = $hasActualOrders ? $actual : max($actual, $standing);
        $demandSource = $hasActualOrders ? 'committed' : 'forecast';

        $inventory = $inventoryByDate[$date][$productId] ?? [];
        $confirmed = $inventoryReady ? (int)($inventory['produced_quantity'] ?? 0) : 0;
        $onHand = $inventoryReady
            ? ((int)($inventory['available_quantity'] ?? 0) + (int)($inventory['loaded_quantity'] ?? 0))
            : 0;

        $hasPlan = isset($plansByDate[$date]) && array_key_exists($productId, $plansByDate[$date]);
        $planned = $hasPlan ? (int)$plansByDate[$date][$productId] : $demand;
        // Desired finished-goods total for the delivery day; stock already held is not double-counted.
        $projectedStock = max($onHand, $planned);
        // Without inventory, do not invent a bake need from a fake zero on-hand.
        $makeNeed = $inventoryReady ? max(0, $planned - $onHand) : 0;
        $afterDelivery = $projectedStock - $demand;
        $shortfall = max(0, -$afterDelivery);
        $surplus = max(0, $afterDelivery);
        $remainingToPlan = $makeNeed;
        // Plan-vs-actual: implied bake assumes confirmed units sit inside on-hand for this delivery day.
        // If counts/loads moved stock independently, variance is directional—not a ledger balance.
        $stockBeforeConfirmed = $inventoryReady ? max(0, $onHand - $confirmed) : 0;
        $impliedBake = $inventoryReady ? max(0, $planned - $stockBeforeConfirmed) : 0;
        $variance = $inventoryReady ? ($confirmed - $impliedBake) : 0;

        $configIssues = [];
        if ($demand > 0 || $hasPlan) {
            $doughTypeId = (int)($product['dough_type_id'] ?? 0);
            if ($doughTypeId <= 0) {
                $configIssues[] = 'No dough type';
            } elseif (!array_key_exists($doughTypeId, $formulaByDoughType) || $formulaByDoughType[$doughTypeId] <= 0) {
                $configIssues[] = 'No formula';
            }
            if ($product['weight_grams'] === null || (int)$product['weight_grams'] <= 0) {
                $configIssues[] = 'Missing weight';
            }
            if ($doughTypeId > 0 && empty($product['product_line_id'])) {
                $configIssues[] = 'No product line';
            }
        }

        if (!$showAll && $standing === 0 && $actual === 0 && $onHand === 0 && $confirmed === 0 && !$hasPlan) {
            continue;
        }

        $row = [
            'productId' => $productId,
            'product' => $product,
            'standing' => $standing,
            'actual' => $actual,
            'demand' => $demand,
            'demandSource' => $demandSource,
            'onHand' => $onHand,
            'confirmed' => $confirmed,
            'hasPlan' => $hasPlan,
            'planned' => $planned,
            'makeNeed' => $makeNeed,
            'projectedStock' => $projectedStock,
            'afterDelivery' => $afterDelivery,
            'shortfall' => $shortfall,
            'surplus' => $surplus,
            'impliedBake' => $impliedBake,
            'variance' => $variance,
            'remainingToPlan' => $remainingToPlan,
            'configIssues' => $configIssues,
            'inputBaseline' => $planned,
        ];
        $row['statuses'] = production_center_row_statuses($row, $hasActualOrders, $inventoryReady);
        $needsAttention = false;
        foreach ($row['statuses'] as $status) {
            if (in_array($status['code'], $attentionCodes, true)) {
                $needsAttention = true;
                break;
            }
        }
        $row['needsAttention'] = $needsAttention;

        $rows[] = $row;
        $summary['demand'] += $demand;
        $summary['on_hand'] += $onHand;
        $summary['planned'] += $planned;
        $summary['make_need'] += $makeNeed;
        $summary['shortfall'] += $shortfall;
        if ($needsAttention) $summary['attention']++;

        $totals['on_hand'] += $onHand;
        $totals['confirmed'] += $confirmed;
        $totals['demand'] += $demand;
        $totals['planned'] += $planned;
        $totals['make_need'] += $makeNeed;
        $totals['shortfall'] += $shortfall;
        if ($needsAttention) $totals['attention']++;
    }

    $days[] = compact('date', 'hasActualOrders', 'rows', 'summary');
}

$pageExceptions = [];
$pageExceptionsDate = $focusDate !== '' ? $focusDate : '';
if ($pageExceptionsDate !== '') {
    try {
        $pageExceptions = bakery_ops_exceptions_for_date($db, $pageExceptionsDate, $pageReturnKey);
    } catch (Throwable $e) {
        error_log('production_center exceptions: ' . $e->getMessage());
    }
}

$page_title = bakery_t('page.production_center');
require_once 'includes/header.php';
require_once 'includes/nav.php';

$weekLabel = date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd));
?>
<main class="production-center container">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <div class="pc-heading">
        <div>
            <p class="pc-eyebrow">Plan versus need</p>
            <h1>Production Center</h1>
            <p>For each product: what demand requires, what you decided to make, what finished goods you already have, and where you are short.</p>
        </div>
        <div class="pc-heading-actions">
            <a class="btn btn-outline" href="production.php?date=<?php echo urlencode($weekDates[0]); ?>">Open Daily Production</a>
            <a class="btn btn-outline" href="ingredient_requirements.php?date=<?php echo urlencode($weekDates[0]); ?>&amp;source=plan">Ingredient Planner</a>
            <?php if ($inventoryReady): ?>
                <a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($weekStart); ?>">Finished goods</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($notice): ?><div class="pc-notice success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="pc-notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!$planTableReady): ?><div class="pc-notice warning">Saved targets are unavailable until the Production Center migration is run. The planning view remains read-only.</div><?php endif; ?>
    <?php if (!$inventoryReady): ?><div class="pc-notice warning">Finished-goods inventory is unavailable, so on-hand and confirmed production show as zero until its migration is run.</div><?php endif; ?>

    <form method="get" class="pc-week-picker">
        <label>Week of <input type="date" name="week" value="<?php echo htmlspecialchars($weekStart); ?>"></label>
        <?php if ($showAll): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
        <button class="btn btn-outline" type="submit">View week</button>
        <span class="pc-week-label">Showing <strong><?php echo htmlspecialchars($weekLabel); ?></strong> · dates are <strong>delivery days</strong></span>
        <a href="production_center.php?week=<?php echo urlencode($weekStart); ?>&amp;show_all=<?php echo $showAll ? '0' : '1'; ?>" class="pc-text-link"><?php echo $showAll ? 'Hide inactive products' : 'Show all products'; ?></a>
    </form>

    <section class="pc-summary" aria-label="Weekly production summary">
        <div><span>Demand</span><strong><?php echo number_format($totals['demand']); ?></strong><small>committed or forecast</small></div>
        <div><span>On hand</span><strong><?php echo number_format($totals['on_hand']); ?></strong><small>available + loaded</small></div>
        <div><span>Planned</span><strong><?php echo number_format($totals['planned']); ?></strong><small>desired FG total</small></div>
        <div class="<?php echo $totals['make_need'] ? 'pc-short' : 'pc-covered'; ?>"><span>Still to make</span><strong><?php echo number_format($totals['make_need']); ?></strong><small>to reach plan</small></div>
        <div class="<?php echo $totals['shortfall'] ? 'pc-short' : 'pc-covered'; ?>"><span>Delivery shortfall</span><strong><?php echo number_format($totals['shortfall']); ?></strong><small><?php echo $totals['shortfall'] ? 'plan/stock cannot cover' : 'demand covered'; ?></small></div>
        <div class="<?php echo $totals['attention'] ? 'pc-short' : 'pc-covered'; ?>"><span>Needs attention</span><strong><?php echo number_format($totals['attention']); ?></strong><small>product-day rows</small></div>
    </section>

    <div class="pc-explainer">
        <strong>Date assumption:</strong> this screen, saved targets, finished-goods stock, and Daily Production confirmation all use the same calendar date as the <em>delivery day</em>. There is no separate bake-date column.
        <br><strong>Demand:</strong> per customer, a committed daily order replaces that customer's standing forecast; customers without a dated order still contribute standing quantities for the weekday.
        <br><strong>Planned:</strong> a saved batch target is the desired finished-goods total for that delivery day — not a separate work-order table. <strong>Still to make</strong> = max(0, Planned − On hand).
        <br><strong>On hand:</strong> available + already loaded for that delivery day only. Stock is not borrowed from other dates.
        <br><strong>Confirmed:</strong> units recorded via Daily Production for that same date (<code>produced_quantity</code>). Daily Production still builds its bake list from demand, not from these saved targets.
    </div>

    <form method="post" class="pc-plan-form" id="pc-plan-form" novalidate>
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_plan">
        <input type="hidden" name="week" value="<?php echo htmlspecialchars($weekStart); ?>">

        <?php foreach ($days as $day): ?>
            <?php
            $dayDate = $day['date'];
            $dayHref = 'production.php?date=' . urlencode($dayDate);
            $dayAttention = (int)$day['summary']['attention'];
            ?>
            <section class="pc-day-card<?php echo $dayAttention ? ' pc-day-attention' : ''; ?>" id="day-<?php echo htmlspecialchars($dayDate); ?>">
                <header class="pc-day-header">
                    <div>
                        <h2><?php echo htmlspecialchars(date('l, M j', strtotime($dayDate))); ?></h2>
                        <div class="pc-day-badges">
                            <span class="pc-source <?php echo $day['hasActualOrders'] ? 'real' : 'standing'; ?>">
                                <?php echo $day['hasActualOrders'] ? 'Committed demand (real orders)' : 'Forecast demand (standing)'; ?>
                            </span>
                            <span class="pc-date-chip">Delivery day <?php echo htmlspecialchars($dayDate); ?></span>
                        </div>
                    </div>
                    <div class="pc-day-metrics">
                        <span>Demand <?php echo number_format($day['summary']['demand']); ?></span>
                        <span>On hand <?php echo number_format($day['summary']['on_hand']); ?></span>
                        <span>Planned <?php echo number_format($day['summary']['planned']); ?></span>
                        <span class="<?php echo $day['summary']['make_need'] ? 'pc-short' : ''; ?>">Make <?php echo number_format($day['summary']['make_need']); ?></span>
                        <span class="<?php echo $day['summary']['shortfall'] ? 'pc-short' : 'pc-covered'; ?>">
                            <?php echo $day['summary']['shortfall'] ? number_format($day['summary']['shortfall']) . ' short' : 'Covered'; ?>
                        </span>
                        <?php if ($dayAttention): ?>
                            <span class="pc-short"><?php echo number_format($dayAttention); ?> need attention</span>
                        <?php endif; ?>
                        <a class="pc-day-link" href="<?php echo htmlspecialchars($dayHref); ?>">Daily Production →</a>
                        <a class="pc-day-link" href="ingredient_requirements.php?date=<?php echo urlencode($dayDate); ?>&amp;source=plan">Ingredient Planner →</a>
                    </div>
                </header>

                <?php if ($day['rows']): ?>
                    <div class="pc-table-wrap">
                        <table class="pc-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Demand</th>
                                    <th>On hand</th>
                                    <th>Planned</th>
                                    <th>Still to make</th>
                                    <th>Confirmed</th>
                                    <th>Variance</th>
                                    <th>After delivery</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($day['rows'] as $row): ?>
                                <?php
                                if ($attentionOnly && empty($row['needsAttention'])) {
                                    continue;
                                }
                                $rowClass = $row['needsAttention'] ? 'pc-row-attention' : 'pc-row-normal';
                                if ($row['shortfall'] > 0) $rowClass .= ' pc-row-short';
                                if ($row['needsAttention'] && ($focusDate === '' || $focusDate === $dayDate)) {
                                    $rowClass .= ' ops-attention-row';
                                }
                                $demandTitle = $day['hasActualOrders']
                                    ? ('Committed from daily orders: ' . number_format($row['actual']) . ' (standing was ' . number_format($row['standing']) . ')')
                                    : ('Standing forecast: ' . number_format($row['standing']) . ' (no daily orders for this date yet)');
                                $varianceClass = $row['variance'] < 0 ? 'pc-short' : ($row['variance'] > 0 ? 'pc-covered' : '');
                                ?>
                                <tr class="<?php echo $rowClass; ?>" id="pc-<?php echo htmlspecialchars($dayDate); ?>-<?php echo (int)$row['productId']; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['product']['name']); ?></strong>
                                        <?php
                                        if ($pageExceptionsDate === $dayDate) {
                                            $pcFlags = [];
                                            foreach ($row['statuses'] as $st) {
                                                if (($st['code'] ?? '') === 'plan_below') {
                                                    $pcFlags['plan_short'] = true;
                                                }
                                                if (($st['code'] ?? '') === 'fg_short') {
                                                    $pcFlags['fg_shortfall'] = true;
                                                }
                                            }
                                            echo bakery_ops_render_row_chips($pageExceptions, [
                                                'product_id' => (int)$row['productId'],
                                                'flags' => $pcFlags,
                                            ], ['date' => $dayDate, 'return' => (string)$pageReturnKey]);
                                        }
                                        ?>
                                        <?php if (!empty($row['product']['dough_type_name'])): ?>
                                            <small><?php echo htmlspecialchars($row['product']['dough_type_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td title="<?php echo htmlspecialchars($demandTitle); ?>">
                                        <strong><?php echo number_format($row['demand']); ?></strong>
                                        <small class="<?php echo $day['hasActualOrders'] ? 'pc-real-value' : 'pc-forecast-value'; ?>">
                                            <?php echo $day['hasActualOrders'] ? 'committed' : 'forecast'; ?>
                                            <?php if ($day['hasActualOrders'] && $row['standing'] !== $row['actual']): ?>
                                                · stand <?php echo number_format($row['standing']); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if (!$inventoryReady): ?>
                                            <span class="pc-muted">—</span>
                                            <small>inventory off</small>
                                        <?php else: ?>
                                            <?php echo number_format($row['onHand']); ?>
                                            <small>avail + loaded</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pc-plan-cell">
                                        <input
                                            type="number"
                                            min="0"
                                            step="1"
                                            inputmode="numeric"
                                            class="pc-plan-input"
                                            data-date="<?php echo htmlspecialchars($dayDate); ?>"
                                            data-product-id="<?php echo (int)$row['productId']; ?>"
                                            data-baseline="<?php echo (int)$row['inputBaseline']; ?>"
                                            data-demand="<?php echo (int)$row['demand']; ?>"
                                            value="<?php echo (int)$row['planned']; ?>"
                                            <?php echo !$planTableReady ? 'disabled' : ''; ?>
                                            aria-label="Planned finished-goods total for <?php echo htmlspecialchars($row['product']['name']); ?> on <?php echo htmlspecialchars($dayDate); ?>"
                                        >
                                        <small class="pc-plan-meta"><?php echo $row['hasPlan'] ? 'saved target' : 'unsaved · defaults to demand'; ?></small>
                                        <?php if ($planTableReady): ?>
                                            <button type="button" class="pc-set-demand" title="Set planned equal to demand">= demand</button>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo (!$inventoryReady) ? '' : ($row['makeNeed'] ? 'pc-short' : 'pc-covered'); ?>">
                                        <?php if (!$inventoryReady): ?>
                                            <span class="pc-muted">—</span>
                                            <small>needs inventory</small>
                                        <?php else: ?>
                                            <strong class="pc-make-need" data-date="<?php echo htmlspecialchars($dayDate); ?>" data-product-id="<?php echo (int)$row['productId']; ?>" data-on-hand="<?php echo (int)$row['onHand']; ?>">
                                                <?php echo number_format($row['makeNeed']); ?>
                                            </strong>
                                            <small>max(0, plan − on hand)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$inventoryReady): ?>
                                            <span class="pc-muted">—</span>
                                        <?php else: ?>
                                            <?php echo number_format($row['confirmed']); ?>
                                            <small>Daily Production</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $varianceClass; ?>">
                                        <?php if (!$inventoryReady): ?>
                                            <span class="pc-muted">—</span>
                                        <?php else: ?>
                                            <?php
                                            $v = (int)$row['variance'];
                                            echo ($v > 0 ? '+' : '') . number_format($v);
                                            ?>
                                            <small>confirmed − implied bake</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $row['shortfall'] ? 'pc-short' : 'pc-covered'; ?>">
                                        <?php
                                        if ($row['afterDelivery'] >= 0) {
                                            echo number_format($row['afterDelivery']) . ' left';
                                        } else {
                                            echo number_format(abs($row['afterDelivery'])) . ' short';
                                        }
                                        ?>
                                        <small>max(on hand, plan) − demand</small>
                                    </td>
                                    <td class="pc-status-cell">
                                        <?php foreach ($row['statuses'] as $status): ?>
                                            <span class="pc-status pc-status-<?php echo htmlspecialchars($status['tone']); ?>"><?php echo htmlspecialchars($status['label']); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="pc-day-foot">
                        Baker handoff uses Daily Production for <strong><?php echo htmlspecialchars(date('l, M j', strtotime($dayDate))); ?></strong>
                        (<a href="<?php echo htmlspecialchars($dayHref); ?>">Open bake schedule for this delivery day</a>.
                        Saved targets here are planning decisions; they do not automatically rewrite the baker’s demand list.
                    </p>
                <?php else: ?>
                    <p class="pc-empty">
                        No standing orders, real orders, inventory, or saved targets for this day.
                        <a href="production_center.php?week=<?php echo urlencode($weekStart); ?>&amp;show_all=1">Show all products</a> to plan ahead.
                    </p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($planTableReady): ?>
            <div class="pc-save-bar" id="pc-save-bar">
                <div>
                    <strong id="pc-save-state">No unsaved changes</strong>
                    <span id="pc-save-detail">Week of <?php echo htmlspecialchars($weekLabel); ?> · only edited targets are saved · delivery-day keys</span>
                </div>
                <button class="btn btn-primary" type="submit" id="pc-save-btn" disabled>Save changed targets</button>
            </div>
        <?php endif; ?>
    </form>
</main>
<style>
.production-center{max-width:1480px;padding-bottom:56px}
.pc-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:28px 0 18px}
.pc-heading h1{margin:0;color:#193b2a}
.pc-heading p{margin:6px 0 0;color:#586b60;max-width:760px}
.pc-heading-actions{display:flex;flex-wrap:wrap;gap:8px}
.pc-eyebrow{color:var(--sf-primary,#287449)!important;font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:.76rem}
.pc-notice{padding:12px 15px;border-radius:var(--sf-radius-sm,7px);margin:12px 0;border:1px solid transparent}
.pc-notice.success{background:var(--sf-success-bg,#e5f5e9);border-color:var(--sf-success-border,#b9dfc4);color:var(--sf-success,#195f35)}
.pc-notice.error{background:var(--sf-danger-bg,#fdeaea);border-color:var(--sf-danger-border,#efc2c2);color:var(--sf-danger,#9f2727)}
.pc-notice.warning{background:var(--sf-warning-bg,#fff5dd);border-color:var(--sf-warning-border,#efd7a8);color:var(--sf-warning,#80590d)}
.pc-week-picker{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:18px 0}
.pc-week-picker input,.pc-table input{border:1px solid #cbd7cf;border-radius:5px;padding:8px;background:#fff}
.pc-week-label{color:#5a6d61;font-size:.9rem}
.pc-text-link{color:#246b43;font-weight:600}
.pc-summary{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:12px;margin:18px 0}
.pc-summary>div{background:#fff;border:1px solid #dce8df;border-radius:9px;padding:14px;box-shadow:0 1px 2px rgba(21,48,33,.04)}
.pc-summary span,.pc-summary small{display:block;color:#64756a;font-size:.8rem}
.pc-summary strong{display:block;font-size:1.45rem;color:#1d3f2c;margin:3px 0}
.pc-explainer{background:#eef7ef;border-left:4px solid #398451;padding:13px 16px;color:#3f5948;margin:18px 0 22px;line-height:1.45}
.pc-explainer code{font-size:.85em;background:#e2efe4;padding:1px 4px;border-radius:3px}
.pc-day-card{background:#fff;border:1px solid #dce8df;border-radius:10px;margin:16px 0;overflow:hidden}
.pc-day-attention{border-color:#e2b46a;box-shadow:0 0 0 1px rgba(176,120,20,.12)}
.pc-day-header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;padding:14px 18px;background:#f7faf7;border-bottom:1px solid #e1e9e2}
.pc-day-header h2{font-size:1.08rem;margin:0 0 6px;color:#254632}
.pc-day-badges{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.pc-source{font-size:.76rem;font-weight:700;padding:3px 8px;border-radius:999px}
.pc-source.real{color:#0b5d87;background:#e1f2fa}
.pc-source.standing{color:#735412;background:#fff3d3}
.pc-date-chip{font-size:.74rem;color:#5d7164;background:#eef3ef;padding:3px 8px;border-radius:999px}
.pc-day-metrics{display:flex;gap:14px;flex-wrap:wrap;font-size:.86rem;color:#506257;align-items:center;justify-content:flex-end}
.pc-day-link{font-weight:700;color:#1f6b35;text-decoration:none;border:1px solid #b7d4bf;background:#f3fbf5;padding:5px 10px;border-radius:6px}
.pc-day-link:hover{background:#e5f5ea}
.pc-table-wrap{overflow:auto}
.pc-table{width:100%;border-collapse:collapse;min-width:1100px}
.pc-table th,.pc-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #edf1ed;vertical-align:top}
.pc-table th{color:#597064;background:#fbfcfb;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;position:sticky;top:0}
.pc-table tr:last-child td{border-bottom:0}
.pc-table td small{display:block;color:#77877c;font-size:.72rem;margin-top:3px}
.pc-row-normal td{background:#fff}
.pc-row-attention td{background:#fffaf0}
.pc-row-short td{background:#fff5f5}
.pc-table input{width:84px;padding:7px}
.pc-table input.pc-dirty{border-color:#c9861a;background:#fff8e8;box-shadow:0 0 0 2px rgba(201,134,26,.18)}
.pc-table input.pc-invalid{border-color:#b72c2c;background:#fff1f1}
.pc-plan-cell{min-width:132px}
.pc-set-demand{display:inline-block;margin-top:4px;border:0;background:transparent;color:#246b43;font-size:.72rem;font-weight:700;padding:0;cursor:pointer;text-decoration:underline}
.pc-set-demand:hover{color:#14532d}
.pc-real-value{color:#0b668f;font-weight:700}
.pc-forecast-value{color:#8a6a1d;font-weight:600}
.pc-covered{color:#247142!important;font-weight:700}
.pc-short{color:#b72c2c!important;font-weight:700}
.pc-muted{color:#8a968d}
.pc-status-cell{min-width:140px}
.pc-status{display:inline-block;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:999px;margin:0 4px 4px 0;white-space:nowrap}
.pc-status-ok{background:#e5f5e9;color:#1f6b35}
.pc-status-warn{background:#fff3d3;color:#80590d}
.pc-status-danger{background:#fdeaea;color:#9f2727}
.pc-status-info{background:#e1f2fa;color:#0b5d87}
.pc-status-muted{background:#eef1ef;color:#5a6a5f}
.pc-empty,.pc-day-foot{padding:14px 18px;margin:0;color:#66786c;font-size:.9rem}
.pc-day-foot a{font-weight:700;color:#246b43}
.pc-save-bar{position:sticky;bottom:12px;background:#193b2a;color:#fff;border-radius:9px;padding:13px 16px;display:flex;justify-content:space-between;align-items:center;gap:16px;box-shadow:0 6px 18px rgba(13,42,23,.2);z-index:5}
.pc-save-bar.is-dirty{background:#5c3d0c}
.pc-save-bar.is-invalid{background:#6b1d1d}
.pc-save-bar strong{display:block}
.pc-save-bar span{display:block;opacity:.88;font-size:.85rem;margin-top:2px}
.pc-save-bar .btn{white-space:nowrap}
.pc-save-bar .btn:disabled{opacity:.55;cursor:not-allowed}
@media(max-width:980px){.pc-summary{grid-template-columns:repeat(3,1fr)}}
@media(max-width:860px){
    .pc-summary{grid-template-columns:repeat(2,1fr)}
    .pc-heading,.pc-day-header{flex-direction:column;align-items:flex-start}
    .pc-day-metrics{gap:10px;justify-content:flex-start}
    .pc-save-bar{align-items:flex-start;flex-direction:column}
    .pc-save-bar .btn{width:100%}
}
@media(max-width:500px){
    .pc-summary{grid-template-columns:1fr}
    .pc-week-picker{align-items:flex-start;flex-direction:column}
    .pc-week-picker .btn{width:100%}
}
</style>
<?php if ($planTableReady): ?>
<script>
(function () {
    var form = document.getElementById('pc-plan-form');
    if (!form) return;
    var saveBar = document.getElementById('pc-save-bar');
    var saveState = document.getElementById('pc-save-state');
    var saveDetail = document.getElementById('pc-save-detail');
    var saveBtn = document.getElementById('pc-save-btn');
    var inputs = Array.prototype.slice.call(form.querySelectorAll('.pc-plan-input'));
    var weekLabel = <?php echo json_encode($weekLabel); ?>;

    function parseQty(value) {
        if (value === null || value === undefined) return null;
        var trimmed = String(value).trim();
        if (trimmed === '') return null;
        if (!/^\d+$/.test(trimmed)) return NaN;
        return parseInt(trimmed, 10);
    }

    function refreshMakeNeed(input) {
        var row = input.closest('tr');
        if (!row) return;
        var cell = row.querySelector('.pc-make-need');
        if (!cell) return;
        var planned = parseQty(input.value);
        var onHand = parseInt(cell.getAttribute('data-on-hand'), 10) || 0;
        if (planned === null || isNaN(planned) || planned < 0) {
            cell.textContent = '—';
            return;
        }
        var need = Math.max(0, planned - onHand);
        cell.textContent = String(need);
        cell.parentElement.classList.toggle('pc-short', need > 0);
        cell.parentElement.classList.toggle('pc-covered', need === 0);
    }

    function syncState() {
        var dirty = 0;
        var invalid = 0;
        inputs.forEach(function (input) {
            if (input.disabled) return;
            var baseline = String(input.getAttribute('data-baseline'));
            var current = String(input.value).trim();
            var qty = parseQty(current);
            var isDirty = current !== baseline;
            var isInvalid = qty === null || isNaN(qty) || qty < 0;
            input.classList.toggle('pc-dirty', isDirty && !isInvalid);
            input.classList.toggle('pc-invalid', isDirty && isInvalid);
            if (isDirty) dirty++;
            if (isDirty && isInvalid) invalid++;
            refreshMakeNeed(input);
        });
        if (saveBar) {
            saveBar.classList.toggle('is-dirty', dirty > 0 && invalid === 0);
            saveBar.classList.toggle('is-invalid', invalid > 0);
        }
        if (saveState) {
            if (invalid > 0) saveState.textContent = 'Fix invalid quantities';
            else if (dirty > 0) saveState.textContent = dirty + ' unsaved target' + (dirty === 1 ? '' : 's');
            else saveState.textContent = 'No unsaved changes';
        }
        if (saveDetail) {
            saveDetail.textContent = 'Week of ' + weekLabel + ' · only edited targets are saved · delivery-day keys';
        }
        if (saveBtn) saveBtn.disabled = dirty === 0 || invalid > 0;
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', syncState);
        input.addEventListener('change', syncState);
    });

    form.querySelectorAll('.pc-set-demand').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var cell = btn.closest('.pc-plan-cell');
            if (!cell) return;
            var input = cell.querySelector('.pc-plan-input');
            if (!input || input.disabled) return;
            input.value = String(parseInt(input.getAttribute('data-demand'), 10) || 0);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
    });

    form.addEventListener('submit', function (event) {
        // Strip unchanged inputs so save cannot bulk-overwrite the whole week by accident.
        var pending = [];
        inputs.forEach(function (input) {
            input.removeAttribute('name');
            if (input.disabled) return;
            var baseline = String(input.getAttribute('data-baseline'));
            var current = String(input.value).trim();
            var qty = parseQty(current);
            if (current === baseline) return;
            if (qty === null || isNaN(qty) || qty < 0) {
                pending.push(null);
                return;
            }
            input.setAttribute(
                'name',
                'planned[' + input.getAttribute('data-date') + '][' + input.getAttribute('data-product-id') + ']'
            );
            pending.push(input);
        });
        if (pending.indexOf(null) !== -1) {
            event.preventDefault();
            syncState();
            alert('Batch targets must be whole numbers of zero or more. Blank values are not allowed.');
            return;
        }
        var named = pending.filter(Boolean);
        if (!named.length) {
            event.preventDefault();
            syncState();
            alert('No changed targets to save.');
            return;
        }
    });

    window.addEventListener('beforeunload', function (event) {
        var dirty = inputs.some(function (input) {
            return !input.disabled && String(input.value).trim() !== String(input.getAttribute('data-baseline'));
        });
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    syncState();
})();
</script>
<?php endif; ?>
<?php require_once 'includes/footer.php'; ?>
