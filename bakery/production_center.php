<?php
/** Weekly plan-vs-need workspace. Complements production.php; does not replace it. */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/demand_review.php';
require_once 'includes/production_plan.php';
require_once 'includes/production_assign.php';
require_once 'includes/production_cadence.php';
require_once 'includes/production_workflow_strip.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/product_pack_yields.php';
require_once 'includes/schema_sql.php';

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

function production_center_cadence_day_label(string $date, bool $short = false): string
{
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return $date;
    }
    $dow = (int)$dt->format('N');
    $names = function_exists('bakery_day_names') ? bakery_day_names($short) : [];
    $dayName = $names[$dow] ?? $dt->format($short ? 'D' : 'l');
    $monthDay = function_exists('bakery_localized_month_day') ? bakery_localized_month_day($dt) : $dt->format('M j');
    return trim($dayName . ' ' . $monthDay);
}

function production_center_cadence_family_label(string $family): string
{
    $key = 'production_cadence.family.' . $family;
    if (function_exists('bakery_t')) {
        $label = bakery_t($key);
        if ($label !== $key) {
            return $label;
        }
    }
    return $family === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR ? 'Sour Flour' : 'Pan Dulce';
}

function production_center_day_href(string $date, bool $showAll, bool $attentionOnly, ?string $returnKey = null): string
{
    $q = ['date' => $date];
    if ($showAll) {
        $q['show_all'] = '1';
    }
    if ($attentionOnly) {
        $q['attention'] = '1';
    }
    $href = 'production_center.php?' . http_build_query($q);
    if ($returnKey && function_exists('bakery_ops_link_append_return')) {
        $href = bakery_ops_link_append_return($href, $returnKey);
    }
    return $href;
}

$defaultDate = date('Y-m-d', strtotime('+1 day'));
$focus = bakery_production_center_resolve_focus(
    (string)($_GET['date'] ?? $_POST['date'] ?? ''),
    (string)($_GET['week'] ?? $_POST['week'] ?? ''),
    $defaultDate
);
$selectedDate = $focus['date'];
$weekStart = $focus['week_start'];
$weekDates = $focus['week_dates'];
$weekEnd = end($weekDates);
$showAll = ($_GET['show_all'] ?? '') === '1';
$attentionOnly = (string)($_GET['attention'] ?? '') === '1';
$focusDate = $selectedDate;
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$pageReturnKey = $returnTarget['key'] ?? null;
$attentionLabel = $attentionOnly ? 'Showing product-day rows requiring attention' : '';
$planTableReady = table_exists($db, 'production_plan_items');
$inventoryReady = bakery_inventory_ready($db);
$notice = '';
$error = '';
$kitchenParse = null;
$kitchenNote = trim((string)($_POST['kitchen_note'] ?? ''));
$routeCapacity = [];

// Same product-line visibility rules as Daily Production (managers see all).
$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$productClause = '';
if (is_array($bakerProductIds)) {
    $productClause = empty($bakerProductIds) ? ' WHERE 1 = 0' : ' WHERE p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
}
$productStmt = $db->prepare(
    "SELECT p.id, p.name, p.weight_grams, p.dough_type_id, dt.name AS dough_type_name, dt.product_line_id,
            pl.name AS product_line_name
     FROM products p
     LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
     LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
     {$productClause}
     ORDER BY dt.name, p.name"
);
$productStmt->execute($bakerProductIds ?? []);
$products = $productStmt->fetchAll();
$productIds = array_map(static fn($product) => (int)$product['id'], $products);
$allowedProductIds = array_fill_keys($productIds, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plan') {
    $wantsJson = function_exists('bakery_wants_json') && bakery_wants_json();
    try {
        if (!$planTableReady) throw new RuntimeException('Saved production plans are not installed yet. Run scripts/run_migrations.php first.');
        $planned = $_POST['planned'] ?? [];
        if (!is_array($planned) || $planned === []) {
            throw new InvalidArgumentException('No changed targets to save. Edit a quantity, then save.');
        }
        foreach (array_keys($planned) as $postedDate) {
            if ($postedDate !== $selectedDate) {
                throw new InvalidArgumentException('A submitted plan item is outside the selected day.');
            }
        }
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        if ($wantsJson) {
            if (count($planned) !== 1) throw new InvalidArgumentException('Autosave accepts one target at a time.');
            $postedDate = (string)array_key_first($planned);
            $postedProducts = $planned[$postedDate];
            if (!is_array($postedProducts) || count($postedProducts) !== 1) throw new InvalidArgumentException('Autosave accepts one target at a time.');
            $productId = (int)array_key_first($postedProducts);
            $quantity = filter_var($postedProducts[$productId], FILTER_VALIDATE_INT);
            $expectedQuantity = filter_var($_POST['expected_quantity'] ?? null, FILTER_VALIDATE_INT);
            $expectedHasPlan = (string)($_POST['expected_has_plan'] ?? '') === '1';
            if ($quantity === false || $expectedQuantity === false) throw new InvalidArgumentException('Batch targets must be whole numbers of zero or more.');
            $result = bakery_production_plan_save_target_cas($db, $postedDate, $productId, (int)$quantity, $allowedProductIds, $userId, $expectedHasPlan, (int)$expectedQuantity);
            $saved = $result['saved'];
        } else {
            $saved = bakery_production_plan_save_targets($db, $planned, $allowedProductIds, $userId);
        }
        bakery_record_operational_event($db, BAKERY_OP_PRODUCTION_PLAN_SAVED,
            'Saved ' . $saved . ' production target' . ($saved === 1 ? '' : 's') . ' for ' . date('D, M j', strtotime($selectedDate)), [
            'operational_date' => $selectedDate,
            'metadata' => ['targets_saved' => $saved, 'delivery_date' => $selectedDate],
        ]);
        $notice = bakery_t('production_center.autosave_notice', ['count' => $saved]);
        $notice .= ' ' . bakery_t('production_center.save_is_not_commit');
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'saved' => $saved,
                'notice' => $notice,
                'batch_label' => bakery_pack_batch_label($db, $productId, (int)$quantity),
                'planned_quantity' => (int)$quantity,
            ]);
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
        if ($wantsJson) {
            $isConflict = str_starts_with($error, 'production_plan_conflict:');
            http_response_code($isConflict ? 409 : 400);
            header('Content-Type: application/json; charset=utf-8');
            if ($isConflict) {
                $current = substr($error, strlen('production_plan_conflict:'));
                echo json_encode([
                    'ok' => false,
                    'conflict' => true,
                    'current_has_plan' => $current !== 'none',
                    'current_quantity' => $current === 'none' ? 0 : (int)$current,
                    'error' => bakery_t('production_center.autosave_conflict'),
                ]);
            } else {
                echo json_encode(['ok' => false, 'error' => $error]);
            }
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['product_formula', 'store_demand', 'save_store_demand'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        bakery_require_csrf();
        $action = (string)$_POST['action'];
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0 || empty($allowedProductIds[$productId])) {
            throw new InvalidArgumentException('Unknown product.');
        }
        if ($action === 'product_formula') {
            $pieces = max(0, (int)($_POST['pieces'] ?? 0));
            echo json_encode(['ok' => true, 'formula' => bakery_pack_formula_sheet($db, $productId, $pieces)]);
            exit;
        }
        $pool = max(0, (int)($_POST['pool'] ?? 0));
        if ($pool <= 0 && table_exists($db, 'production_plan_items')) {
            $poolStmt = $db->prepare(
                'SELECT planned_quantity FROM production_plan_items WHERE delivery_date = ? AND product_id = ? LIMIT 1'
            );
            $poolStmt->execute([$selectedDate, $productId]);
            $pool = max(0, (int)$poolStmt->fetchColumn());
        }
        if ($action === 'store_demand') {
            $customers = bakery_production_store_demand_rows($db, $selectedDate, $productId, $pool);
            $nameStmt = $db->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
            $nameStmt->execute([$productId]);
            echo json_encode([
                'ok' => true,
                'date' => $selectedDate,
                'product_id' => $productId,
                'product_name' => (string)$nameStmt->fetchColumn(),
                'customers' => $customers,
            ]);
            exit;
        }
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($customerId <= 0 || $quantity === false || $quantity < 0) {
            throw new InvalidArgumentException('Store quantity must be a whole number of zero or more.');
        }
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        $saved = bakery_production_store_demand_save(
            $db,
            $selectedDate,
            $productId,
            $customerId,
            (int)$quantity,
            $userId,
            $pool
        );
        echo json_encode([
            'ok' => true,
            'customers' => $saved['customers'],
            'saved_quantity' => $saved['quantity'],
            'demand_total' => $saved['demand_total'],
            'customer_id' => $customerId,
            'product_id' => $productId,
            'notice' => bakery_t('production_center.store_demand_saved'),
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['parse_kitchen_note', 'apply_kitchen_note'], true)) {
    try {
        if (!bakery_pack_yields_ready($db)) {
            throw new RuntimeException(bakery_t('pan_dulce.err_pack_not_ready'));
        }
        if ($kitchenNote === '') {
            throw new InvalidArgumentException(bakery_t('production_center.kitchen_empty'));
        }
        $kitchenParse = bakery_pack_parse_kitchen_note($db, $kitchenNote);
        $routeCapacity = bakery_production_route_desired_vs_bake($db, $selectedDate, $kitchenParse['by_product']);
        if (($_POST['action'] ?? '') === 'apply_kitchen_note') {
            if ($kitchenParse['by_product'] === []) {
                throw new InvalidArgumentException(bakery_t('production_center.kitchen_empty'));
            }
            if (!$planTableReady) {
                throw new RuntimeException('Saved production plans are not installed yet. Run scripts/run_migrations.php first.');
            }
            $planQtys = $kitchenParse['by_product'];
            foreach (bakery_pack_kitchen_managed_ids($db) as $pid) {
                if (!isset($planQtys[$pid]) && !empty($allowedProductIds[$pid])) {
                    $planQtys[$pid] = 0;
                }
            }
            $planned = [$selectedDate => $planQtys];
            $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
            $userId = isset($user['id']) ? (int)$user['id'] : null;
            $saved = bakery_production_plan_save_targets($db, $planned, $allowedProductIds, $userId);
            bakery_record_operational_event($db, BAKERY_OP_PRODUCTION_PLAN_SAVED,
                'Saved kitchen-note production targets for ' . date('D, M j', strtotime($selectedDate)), [
                'operational_date' => $selectedDate,
                'metadata' => [
                    'targets_saved' => $saved,
                    'delivery_date' => $selectedDate,
                    'source' => 'kitchen_note',
                    'unknown' => $kitchenParse['unknown'],
                ],
            ]);
            $notice = bakery_t('production_center.kitchen_saved', ['count' => $saved]);
            $notice .= ' ' . bakery_t('production_center.save_is_not_commit');
            header('Location: production_center.php?date=' . rawurlencode($selectedDate) . '&from_kitchen=1');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'cut_apply_all') {
    try {
        $cutDate = trim((string)($_POST['delivery_date'] ?? $_POST['date'] ?? $selectedDate));
        if ($cutDate !== $selectedDate) {
            throw new InvalidArgumentException('Cut the day you are viewing.');
        }
        if ($cutDate < date('Y-m-d')) {
            throw new InvalidArgumentException('Cannot cut past deliveries');
        }
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        $result = bakery_production_cut_apply_all_recommended($db, $cutDate, $allowedProductIds, $userId);
        if ((int)$result['updated'] === 0) {
            $notice = bakery_t('production_center.cut_apply_all_none');
        } else {
            $notice = bakery_t('production_center.cut_apply_all_saved', [
                'products' => (int)$result['products'],
                'count' => (int)$result['updated'],
                'skipped' => (int)$result['skipped'],
            ]);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['assign_preview', 'assign_apply', 'cut_preview', 'cut_apply'], true)) {
    $wantsJson = true;
    $assignAction = (string)($_POST['action'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    try {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0 || empty($allowedProductIds[$productId])) {
            throw new InvalidArgumentException('Unknown product.');
        }
        $assignDate = trim((string)($_POST['delivery_date'] ?? $_POST['date'] ?? $selectedDate));
        if ($assignDate !== $selectedDate) {
            throw new InvalidArgumentException($assignAction === 'cut_preview' || $assignAction === 'cut_apply' ? 'Cut the day you are viewing.' : 'Assign the day you are viewing.');
        }
        $pool = max(0, (int)($_POST['pool'] ?? 0));
        if ($assignAction === 'assign_preview' || $assignAction === 'cut_preview') {
            $customers = $assignAction === 'cut_preview'
                ? bakery_production_cut_preview($db, $assignDate, $productId, $pool)
                : bakery_production_assign_preview($db, $assignDate, $productId, $pool);
            $demand = 0;
            foreach ($customers as $row) {
                $demand += (int)$row['quantity'];
            }
            echo json_encode([
                'ok' => true,
                'date' => $assignDate,
                'product_id' => $productId,
                'pool' => $pool,
                'demand' => $demand,
                'customers' => $customers,
            ]);
            exit;
        }

        $raw = $_POST['assignments'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw) || $raw === []) {
            throw new InvalidArgumentException($assignAction === 'cut_apply' ? 'No customer quantities to cut.' : 'No customer quantities to assign.');
        }
        $assignments = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assignments[] = [
                'customer_id' => (int)($row['customer_id'] ?? $row['id'] ?? 0),
                'quantity' => (int)($row['quantity'] ?? 0),
            ];
        }
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        if ($assignAction === 'cut_apply') {
            $result = bakery_production_cut_apply($db, $assignDate, $productId, $assignments, $userId);
            $notice = bakery_t('production_center.cut_saved', [
                'count' => (int)$result['updated'],
                'skipped' => (int)$result['skipped'],
            ]);
        } else {
            $scope = (string)($_POST['scope'] ?? 'standing');
            $result = bakery_production_assign_apply(
                $db,
                $assignDate,
                $productId,
                $assignments,
                $scope,
                $userId
            );
            $notice = bakery_t('production_center.assign_saved', [
                'count' => (int)$result['updated'],
                'skipped' => (int)$result['skipped'],
            ]);
        }
        echo json_encode([
            'ok' => true,
            'result' => $result,
            'notice' => $notice,
        ]);
        exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit_plan') {
    try {
        if (production_center_week_start((string)($_POST['week'] ?? '')) !== $weekStart) {
            throw new InvalidArgumentException('The production week changed. Reload the page and try again.');
        }
        $commitDate = trim((string)($_POST['delivery_date'] ?? ''));
        if ($commitDate !== $selectedDate) {
            throw new InvalidArgumentException('Commit the day you are viewing.');
        }
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $result = bakery_production_plan_commit($db, $commitDate, isset($user['id']) ? (int)$user['id'] : null);
        $notice = bakery_t('production_center.commit_notice', [
            'date' => date('l, M j', strtotime($commitDate)),
            'products' => (int)$result['products_count'],
            'units' => number_format((int)$result['units_count']),
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['from_kitchen'] ?? '') === '1' && $notice === '') {
    $notice = bakery_t('production_center.kitchen_landed');
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

$commitsByDate = [];
$commitDriftByDate = [];
$commitsReady = function_exists('bakery_production_plan_commits_ready') && bakery_production_plan_commits_ready($db);
if ($commitsReady) {
    $commitsByDate = bakery_production_plan_commits_for_dates($db, $weekDates);
    foreach ($weekDates as $commitDate) {
        $commitRow = $commitsByDate[$commitDate] ?? null;
        $changed = ['count' => 0, 'latest' => null, 'examples' => []];
        if ($commitRow !== null && !empty($commitRow['committed_at'])) {
            $changed = bakery_production_plan_changes_since($db, $commitDate, (string)$commitRow['committed_at']);
        }
        $commitDriftByDate[$commitDate] = $changed;
    }
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
            'family' => bakery_production_cadence_family($product['product_line_name'] ?? null),
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

    $days[] = [
        'date' => $date,
        'hasActualOrders' => $hasActualOrders,
        'rows' => $rows,
        'summary' => $summary,
        'commit' => $commitsByDate[$date] ?? null,
        'commit_drift' => $commitDriftByDate[$date] ?? ['count' => 0, 'latest' => null, 'examples' => []],
    ];
}

$familyProductIds = [
    BAKERY_PRODUCTION_CADENCE_DAILY => [],
    BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR => [],
];
foreach ($products as $product) {
    $family = bakery_production_cadence_family($product['product_line_name'] ?? null);
    $familyProductIds[$family][] = (int)$product['id'];
}
$cadenceRuns = [];
if (function_exists('bakery_production_cadence_runs_for_week')) {
    foreach (bakery_production_cadence_runs_for_week($weekStart, $weekEnd) as $run) {
        if (empty($familyProductIds[$run['family']])) {
            continue;
        }
        foreach ($run['cover_dates'] as $coverDate) {
            if (!isset($operatingDemandByDate[$coverDate])) {
                $operatingDemandByDate[$coverDate] = bakery_operating_demand_by_product($db, $coverDate);
            }
        }
        $units = 0;
        foreach ($run['cover_dates'] as $coverDate) {
            $byProduct = $operatingDemandByDate[$coverDate]['by_product'] ?? [];
            foreach ($familyProductIds[$run['family']] as $pid) {
                $units += (int)($byProduct[$pid] ?? 0);
            }
        }
        $run['demand_units'] = $units;
        $cadenceRuns[] = $run;
    }
}

$cadenceRuns = array_values(array_filter($cadenceRuns, static function (array $run) use ($selectedDate): bool {
    if ($run['bake_date'] === $selectedDate) {
        return true;
    }
    return in_array($selectedDate, $run['cover_dates'], true);
}));
$days = array_values(array_filter($days, static function (array $day) use ($selectedDate): bool {
    return $day['date'] === $selectedDate;
}));
$dayView = $days[0] ?? null;
$totals = [
    'on_hand' => (int)($dayView['summary']['on_hand'] ?? 0),
    'confirmed' => 0,
    'demand' => (int)($dayView['summary']['demand'] ?? 0),
    'planned' => (int)($dayView['summary']['planned'] ?? 0),
    'make_need' => (int)($dayView['summary']['make_need'] ?? 0),
    'shortfall' => (int)($dayView['summary']['shortfall'] ?? 0),
    'attention' => (int)($dayView['summary']['attention'] ?? 0),
];

$savedBake = [];
foreach (($plansByDate[$selectedDate] ?? []) as $pid => $qty) {
    $pid = (int)$pid;
    $qty = (int)$qty;
    if ($pid > 0 && $qty > 0) {
        $savedBake[$pid] = $qty;
    }
}
$bakeBoard = [];
foreach ($savedBake as $pid => $pcs) {
    $pname = '';
    $dough = '';
    foreach ($products as $p) {
        if ((int)$p['id'] === $pid) {
            $pname = (string)$p['name'];
            $dough = (string)($p['dough_type_name'] ?? '');
            break;
        }
    }
    $demandPcs = 0;
    foreach (($dayView['rows'] ?? []) as $row) {
        if ((int)$row['productId'] === $pid) {
            $demandPcs = (int)$row['demand'];
            break;
        }
    }
    $scale = bakery_pack_input_scale($db, $pid);
    $bakeBoard[] = [
        'product_id' => $pid,
        'name' => $pname !== '' ? $pname : ('#' . $pid),
        'dough' => $dough,
        'pieces' => $pcs,
        'batch' => bakery_pack_batch_label($db, $pid, $pcs),
        'demand' => $demandPcs,
        'gap' => $pcs - $demandPcs,
        'input_unit' => $scale['unit'],
        'pcs_per' => $scale['pcs_per'],
        'has_plan' => true,
    ];
}
$storeAlloc = $savedBake !== []
    ? bakery_production_store_allocation_from_plan($db, $selectedDate, $savedBake)
    : [];
if ($routeCapacity === [] && $savedBake !== []) {
    $routeCapacity = bakery_production_route_desired_vs_bake($db, $selectedDate, $savedBake);
}

$prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));

$pageExceptions = [];
$pageExceptionsDate = $focusDate !== '' ? $focusDate : '';
if ($pageExceptionsDate !== '') {
    try {
        $pageExceptions = bakery_ops_exceptions_for_date($db, $pageExceptionsDate, $pageReturnKey);
    } catch (Throwable $e) {
        error_log('production_center exceptions: ' . $e->getMessage());
    }
}

$hubStages = [];
try {
    $hubStages = bakery_production_workflow_kitchen_stages($db, $selectedDate);
} catch (Throwable $e) {
    error_log('production_center workflow strip: ' . $e->getMessage());
}

$ordersHref = function_exists('bakery_ops_link_daily_orders')
    ? bakery_ops_link_daily_orders($selectedDate, [], $pageReturnKey ?: 'production_center')
    : ('daily_orders.php?date=' . rawurlencode($selectedDate));
$packHref = function_exists('bakery_ops_link_pack_list')
    ? bakery_ops_link_pack_list($selectedDate, [], $pageReturnKey ?: 'production_center')
    : ('pack_list.php?date=' . rawurlencode($selectedDate));
$productionHref = function_exists('bakery_ops_link_production')
    ? bakery_ops_link_production($selectedDate, [], $pageReturnKey ?: 'production_center')
    : ('production.php?date=' . rawurlencode($selectedDate));
$loadHref = function_exists('bakery_ops_link_driver_load')
    ? bakery_ops_link_driver_load($selectedDate, [], $pageReturnKey ?: 'production_center')
    : ('driver_load.php?date=' . rawurlencode($selectedDate));

$page_title = bakery_t('page.production_center');
require_once 'includes/header.php';
require_once 'includes/nav.php';

$weekLabel = date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd));
?>
<main class="production-center container">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <div class="pc-heading">
        <div>
            <p class="pc-eyebrow"><?php bakery_te('production_center.hub_eyebrow'); ?></p>
            <h1><?php bakery_te('production_center.hub_title'); ?></h1>
            <p><?php bakery_te('production_center.hub_lead'); ?></p>
        </div>
        <div class="pc-heading-actions">
            <a class="btn btn-primary" href="production_manager.php?date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('production_center.link_dashboard'); ?></a>
            <a class="btn btn-outline" href="product_manager_plan.php?date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('production_center.link_product_plan'); ?></a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($ordersHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production_center.link_orders'); ?></a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production_center.link_bake'); ?></a>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($packHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production_center.link_pack'); ?></a>
            <a class="btn btn-outline" href="ingredient_requirements.php?date=<?php echo urlencode($selectedDate); ?>&amp;source=plan"><?php bakery_te('production_center.link_ingredients'); ?></a>
            <?php if ($inventoryReady): ?>
                <a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($selectedDate); ?>"><?php bakery_te('production_center.link_fg'); ?></a>
            <?php endif; ?>
            <a class="btn btn-outline" href="<?php echo htmlspecialchars($loadHref, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('production_center.link_loads'); ?></a>
        </div>
    </div>

    <?php
    echo bakery_production_workflow_strip_css();
    echo bakery_production_workflow_strip_html($hubStages, [
        'current' => 'production_plan',
        'title' => bakery_t('production_workflow.title'),
        'lead' => bakery_t('production_workflow.lead_manager'),
    ]);
    ?>

    <?php if ($notice): ?><div class="pc-notice success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="pc-notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!$planTableReady): ?><div class="pc-notice warning">Saved targets are unavailable until the Production Center migration is run. The planning view remains read-only.</div><?php endif; ?>
    <?php if (!$inventoryReady): ?><div class="pc-notice warning">Finished-goods inventory is unavailable, so on-hand and confirmed production show as zero until its migration is run.</div><?php endif; ?>

    <form method="get" class="pc-day-picker" action="production_center.php">
        <?php if ($pageReturnKey): ?><input type="hidden" name="return" value="<?php echo htmlspecialchars((string)$pageReturnKey); ?>"><?php endif; ?>
        <?php if ($showAll): ?><input type="hidden" name="show_all" value="1"><?php endif; ?>
        <?php if ($attentionOnly): ?><input type="hidden" name="attention" value="1"><?php endif; ?>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars(production_center_day_href($prevDate, $showAll, $attentionOnly, $pageReturnKey)); ?>"><?php bakery_te('production_center.prev_day'); ?></a>
        <label class="pc-day-picker-date"><?php bakery_te('production_center.day_label'); ?>
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>" onchange="this.form.submit()">
        </label>
        <a class="btn btn-outline" href="<?php echo htmlspecialchars(production_center_day_href($nextDate, $showAll, $attentionOnly, $pageReturnKey)); ?>"><?php bakery_te('production_center.next_day'); ?></a>
        <nav class="pc-week-pills" aria-label="<?php echo htmlspecialchars(bakery_t('production_center.week_nav')); ?>">
            <?php foreach ($weekDates as $pillDate): ?>
                <a class="pc-week-pill<?php echo $pillDate === $selectedDate ? ' is-current' : ''; ?>"
                   href="<?php echo htmlspecialchars(production_center_day_href($pillDate, $showAll, $attentionOnly, $pageReturnKey)); ?>"
                   <?php echo $pillDate === $selectedDate ? 'aria-current="date"' : ''; ?>>
                    <?php echo htmlspecialchars(production_center_cadence_day_label($pillDate, true)); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <span class="pc-autosave-note" id="pc-autosave-note"><?php bakery_te('production_center.autosave_hint'); ?></span>
        <a href="<?php echo htmlspecialchars(production_center_day_href($selectedDate, !$showAll, $attentionOnly, $pageReturnKey)); ?>" class="pc-text-link"><?php echo $showAll ? htmlspecialchars(bakery_t('production_center.hide_inactive')) : htmlspecialchars(bakery_t('production_center.show_all')); ?></a>
    </form>

    <?php if ($cadenceRuns): ?>
        <section class="pc-cadence-runs" aria-label="<?php echo htmlspecialchars(bakery_t('production_cadence.runs_aria')); ?>">
            <div class="pc-cadence-runs-head">
                <strong><?php bakery_te('production_cadence.runs_title'); ?></strong>
                <span><?php bakery_te('production_cadence.runs_lead'); ?></span>
            </div>
            <ol class="pc-cadence-run-list">
                <?php foreach ($cadenceRuns as $run): ?>
                    <?php
                    $coverLabels = array_map('production_center_cadence_day_label', $run['cover_dates']);
                    $bakeHref = production_center_day_href($run['cover_dates'][0] ?? $run['bake_date'], $showAll, $attentionOnly, $pageReturnKey);
                    ?>
                    <li class="pc-cadence-run">
                        <span class="pc-cadence-kicker"><?php echo htmlspecialchars(production_center_cadence_family_label($run['family'])); ?></span>
                        <a href="<?php echo htmlspecialchars($bakeHref, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(bakery_t('production_cadence.bake_on', [
                                'day' => production_center_cadence_day_label($run['bake_date']),
                            ])); ?>
                        </a>
                        <span class="pc-cadence-run-meta">
                            <?php echo htmlspecialchars(bakery_t('production_cadence.covers', [
                                'days' => implode(', ', $coverLabels),
                                'units' => number_format((int)($run['demand_units'] ?? 0)),
                            ])); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <section class="pc-summary" aria-label="<?php echo htmlspecialchars(bakery_t('production_center.day_summary')); ?>">
        <div><span>Demand</span><strong><?php echo number_format($totals['demand']); ?></strong><small>committed or forecast</small></div>
        <div><span>On hand</span><strong><?php echo number_format($totals['on_hand']); ?></strong><small>available + loaded</small></div>
        <div><span>Planned</span><strong><?php echo number_format($totals['planned']); ?></strong><small>desired FG total</small></div>
        <div class="<?php echo $totals['make_need'] ? 'pc-short' : 'pc-covered'; ?>"><span>Still to make</span><strong><?php echo number_format($totals['make_need']); ?></strong><small>to reach plan</small></div>
        <div class="<?php echo $totals['shortfall'] ? 'pc-short' : 'pc-covered'; ?>"><span>Delivery shortfall</span><strong><?php echo number_format($totals['shortfall']); ?></strong><small><?php echo $totals['shortfall'] ? 'plan/stock cannot cover' : 'demand covered'; ?></small></div>
        <div class="<?php echo $totals['attention'] ? 'pc-short' : 'pc-covered'; ?>"><span>Needs attention</span><strong><?php echo number_format($totals['attention']); ?></strong><small>product-day rows</small></div>
    </section>

    <div class="pc-explainer">
        <?php bakery_te('production_cadence.explainer_one_day'); ?>
        <p class="pc-assign-lead"><?php bakery_te('production_center.assign_lead'); ?></p>
    </div>

    <form method="post" class="pc-kitchen-form" id="pc-kitchen-form">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="week" value="<?php echo htmlspecialchars($weekStart); ?>">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        <label for="pc-kitchen-note"><strong><?php bakery_te('production_center.kitchen_title'); ?></strong></label>
        <p class="pc-kitchen-lead"><?php bakery_te('production_center.kitchen_lead'); ?></p>
        <textarea id="pc-kitchen-note" name="kitchen_note" rows="12" spellcheck="false"><?php echo htmlspecialchars($kitchenNote); ?></textarea>
        <div class="pc-kitchen-actions">
            <button class="btn btn-outline" type="submit" name="action" value="parse_kitchen_note"><?php bakery_te('production_center.kitchen_parse'); ?></button>
            <button class="btn btn-primary" type="submit" name="action" value="apply_kitchen_note"><?php bakery_te('production_center.kitchen_apply'); ?></button>
        </div>
        <?php if (is_array($kitchenParse)): ?>
            <div class="pc-kitchen-preview">
                <h3><?php bakery_te('production_center.kitchen_preview'); ?></h3>
                <ul>
                    <?php foreach ($kitchenParse['lines'] as $kLine): ?>
                        <li class="<?php echo ($kLine['kind'] ?? '') === 'unknown' ? 'pc-short' : ''; ?>">
                            <?php
                            echo htmlspecialchars((string)($kLine['raw'] ?? ''));
                            if (!empty($kLine['pieces'])) {
                                echo ' → ' . number_format((int)$kLine['pieces']) . ' pcs';
                            }
                            if (!empty($kLine['unit'])) {
                                echo ' (' . htmlspecialchars((string)$kLine['unit']) . ')';
                            }
                            if (!empty($kLine['note'])) {
                                echo ' — ' . htmlspecialchars((string)$kLine['note']);
                            }
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!empty($kitchenParse['by_product'])): ?>
                    <p><strong><?php bakery_te('production_center.kitchen_totals'); ?></strong></p>
                    <ul>
                        <?php
                        foreach ($kitchenParse['by_product'] as $pid => $pcs) {
                            $pname = '';
                            foreach ($products as $p) {
                                if ((int)$p['id'] === (int)$pid) {
                                    $pname = (string)$p['name'];
                                    break;
                                }
                            }
                            echo '<li>' . htmlspecialchars($pname !== '' ? $pname : ('#' . $pid)) . ': ' . number_format((int)$pcs) . '</li>';
                        }
                        ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($bakeBoard): ?>
        <section class="pc-kitchen-preview pc-bake-board" id="pc-bake-board">
            <h3><?php bakery_te('production_center.bake_board_title'); ?></h3>
            <p><?php bakery_te('production_center.bake_board_lead'); ?></p>
            <div class="pc-table-wrap">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th><?php bakery_te('production_center.bake_product'); ?></th>
                            <th><?php bakery_te('production_center.bake_batch'); ?></th>
                            <th><?php bakery_te('production_center.bake_pieces'); ?></th>
                            <th><?php bakery_te('production_center.bake_demand'); ?></th>
                            <th><?php bakery_te('production_center.route_gap'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bakeBoard as $item): ?>
                            <tr class="<?php echo ((int)$item['gap'] < 0) ? 'pc-row-short' : ''; ?>">
                                <td>
                                    <button type="button" class="pc-product-open" data-product-id="<?php echo (int)$item['product_id']; ?>" data-pieces="<?php echo (int)$item['pieces']; ?>">
                                        <strong><?php echo htmlspecialchars((string)$item['name']); ?></strong>
                                    </button>
                                    <?php if ($item['dough'] !== ''): ?>
                                        <small><?php echo htmlspecialchars((string)$item['dough']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="pc-batch-label"><?php echo htmlspecialchars((string)$item['batch']); ?></td>
                                <td class="pc-plan-cell">
                                    <input
                                        type="number"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        class="pc-plan-input"
                                        data-date="<?php echo htmlspecialchars($selectedDate); ?>"
                                        data-product-id="<?php echo (int)$item['product_id']; ?>"
                                        data-baseline="<?php echo (int)$item['pieces']; ?>"
                                        data-has-plan="1"
                                        data-demand="<?php echo (int)$item['demand']; ?>"
                                        data-input-unit="<?php echo htmlspecialchars((string)$item['input_unit']); ?>"
                                        data-pcs-per="<?php echo htmlspecialchars((string)$item['pcs_per']); ?>"
                                        value="<?php echo (int)$item['pieces']; ?>"
                                        <?php echo !$planTableReady ? 'disabled' : ''; ?>
                                        aria-label="<?php echo htmlspecialchars(bakery_t('production_center.bake_pieces') . ' ' . $item['name']); ?>"
                                    >
                                </td>
                                <td>
                                    <button type="button" class="pc-store-demand-open" data-product-id="<?php echo (int)$item['product_id']; ?>" data-pool="<?php echo (int)$item['pieces']; ?>">
                                        <?php echo number_format((int)$item['demand']); ?>
                                    </button>
                                </td>
                                <td class="<?php echo ((int)$item['gap'] < 0) ? 'pc-short' : 'pc-covered'; ?>">
                                    <?php echo ((int)$item['gap'] > 0 ? '+' : '') . number_format((int)$item['gap']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($storeAlloc): ?>
        <section class="pc-kitchen-preview" id="pc-store-alloc">
            <h3>
                <button type="button" class="pc-store-demand-heading" data-product-id="<?php echo (int)($bakeBoard[0]['product_id'] ?? 0); ?>">
                    <?php bakery_te('production_center.store_alloc_title'); ?>
                </button>
            </h3>
            <p><?php bakery_te('production_center.store_alloc_lead'); ?></p>
            <div class="pc-table-wrap">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th><?php bakery_te('production_center.assign_customer'); ?></th>
                            <th><?php bakery_te('production_center.bake_product'); ?></th>
                            <th><?php bakery_te('production_center.route_desired'); ?></th>
                            <th><?php bakery_te('production_center.store_from_bake'); ?></th>
                            <th><?php bakery_te('production_center.route_gap'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storeAlloc as $row): ?>
                            <tr class="<?php echo ((int)$row['gap'] < 0) ? 'pc-row-short' : ''; ?>">
                                <td><?php echo htmlspecialchars((string)($row['customer_name'] !== '' ? $row['customer_name'] : '—')); ?></td>
                                <td>
                                    <button type="button" class="pc-store-demand-open" data-product-id="<?php echo (int)$row['product_id']; ?>" data-pool="<?php echo (int)($savedBake[(int)$row['product_id']] ?? 0); ?>">
                                        <?php echo htmlspecialchars((string)$row['product_name']); ?>
                                    </button>
                                </td>
                                <td><?php echo number_format((int)$row['desired']); ?></td>
                                <td><?php echo number_format((int)$row['from_bake']); ?></td>
                                <td class="<?php echo ((int)$row['gap'] < 0) ? 'pc-short' : 'pc-covered'; ?>">
                                    <?php echo ((int)$row['gap'] > 0 ? '+' : '') . number_format((int)$row['gap']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($routeCapacity): ?>
        <section class="pc-kitchen-preview">
            <h3><?php bakery_te('production_center.route_capacity_title'); ?></h3>
            <p><?php bakery_te('production_center.route_capacity_lead'); ?></p>
            <div class="pc-table-wrap">
                <table class="pc-table">
                    <thead>
                        <tr>
                            <th><?php bakery_te('production_center.route_driver'); ?></th>
                            <th><?php bakery_te('production_center.bake_product'); ?></th>
                            <th><?php bakery_te('production_center.route_desired'); ?></th>
                            <th><?php bakery_te('production_center.route_available'); ?></th>
                            <th><?php bakery_te('production_center.route_gap'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routeCapacity as $cap): ?>
                            <tr class="<?php echo ((int)$cap['gap'] < 0) ? 'pc-row-short' : ''; ?>">
                                <td><?php echo htmlspecialchars((string)($cap['driver_name'] ?: '—')); ?></td>
                                <td><?php echo htmlspecialchars((string)$cap['product_name']); ?></td>
                                <td><?php echo number_format((int)$cap['desired']); ?></td>
                                <td><?php echo number_format((int)$cap['available']); ?></td>
                                <td class="<?php echo ((int)$cap['gap'] < 0) ? 'pc-short' : 'pc-covered'; ?>">
                                    <?php echo ((int)$cap['gap'] > 0 ? '+' : '') . number_format((int)$cap['gap']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <form method="post" class="pc-plan-form" id="pc-plan-form" novalidate>
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_plan">
        <input type="hidden" name="week" value="<?php echo htmlspecialchars($weekStart); ?>">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">

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
                            <?php
                            $dayLegs = bakery_production_cadence_delivery_legs($dayDate);
                            $dayWeekday = (int)date('N', strtotime($dayDate));
                            foreach ($dayLegs as $leg) {
                                if (empty($familyProductIds[$leg['family']])) {
                                    continue;
                                }
                                $coverLabels = array_map(
                                    static fn($d) => production_center_cadence_day_label($d, true),
                                    $leg['cover_dates']
                                );
                                echo '<span class="pc-bake-chip">' . htmlspecialchars(bakery_t('production_cadence.day_chip', [
                                    'family' => production_center_cadence_family_label($leg['family']),
                                    'bake' => production_center_cadence_day_label($leg['bake_date'], true),
                                    'days' => implode(', ', $coverLabels),
                                ])) . '</span>';
                            }
                            if ($dayWeekday === 7) {
                                echo '<span class="pc-bake-chip pc-bake-chip-quiet">' . htmlspecialchars(bakery_t('production_cadence.sunday_light')) . '</span>';
                            }
                            ?>
                            <?php if ($commitsReady): ?>
                                <?php
                                $dayCommit = $day['commit'] ?? null;
                                $dayDrift = (int)(($day['commit_drift']['count'] ?? 0));
                                ?>
                                <?php if ($dayCommit): ?>
                                    <span class="pc-source real"><?php echo htmlspecialchars(bakery_t('production_center.committed_badge', [
                                        'at' => date('M j, g:i A', strtotime($dayCommit['committed_at'])),
                                    ])); ?></span>
                                    <?php if ($dayDrift > 0): ?>
                                        <span class="pc-source standing"><?php echo htmlspecialchars(bakery_t('production_center.drift_badge', ['count' => $dayDrift])); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="pc-source standing"><?php bakery_te('production_center.not_committed_badge'); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
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
                        <?php
                        $dayCutProducts = 0;
                        foreach ($day['rows'] as $cutRow) {
                            foreach ($cutRow['statuses'] as $cutSt) {
                                if (($cutSt['code'] ?? '') === 'plan_below') {
                                    $dayCutProducts++;
                                    break;
                                }
                            }
                        }
                        ?>
                        <?php if ($dayCutProducts > 0): ?>
                            <button type="submit" class="pc-day-link pc-cut-all-btn" form="pc-cut-all-<?php echo htmlspecialchars($dayDate); ?>"
                                    onclick="return confirm(<?php echo json_encode(bakery_t('production_center.cut_apply_all_confirm', ['count' => $dayCutProducts])); ?>);">
                                <?php bakery_te('production_center.cut_apply_all'); ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($commitsReady && $planTableReady): ?>
                            <button type="submit" class="pc-day-link pc-commit-btn" form="pc-commit-<?php echo htmlspecialchars($dayDate); ?>"
                                    onclick="return confirm(<?php echo json_encode(bakery_t($day['commit'] ? 'production_center.commit_again_prompt' : 'production_center.commit_prompt')); ?>);">
                                <?php bakery_te(!empty($day['commit']) ? 'production_center.commit_again' : 'production_center.commit'); ?>
                            </button>
                        <?php endif; ?>
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
                                        <button type="button" class="pc-product-open" data-product-id="<?php echo (int)$row['productId']; ?>" data-pieces="<?php echo (int)$row['planned']; ?>">
                                            <strong><?php echo htmlspecialchars($row['product']['name']); ?></strong>
                                        </button>
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
                                        <?php
                                        $rowFamily = $row['family'] ?? bakery_production_cadence_family($row['product']['product_line_name'] ?? null);
                                        $rowBake = bakery_production_cadence_bake_date_for_delivery($rowFamily, $dayDate);
                                        if ($rowBake) {
                                            echo '<small class="pc-row-cadence">' . htmlspecialchars(bakery_t('production_cadence.row_bake', [
                                                'day' => production_center_cadence_day_label($rowBake, true),
                                            ])) . '</small>';
                                        }
                                        $rowPlanBelow = false;
                                        foreach ($row['statuses'] as $st) {
                                            if (($st['code'] ?? '') === 'plan_below') {
                                                $rowPlanBelow = true;
                                                break;
                                            }
                                        }
                                        $assignPool = bakery_production_assign_pool_from_row($row);
                                        ?>
                                        <button
                                            type="button"
                                            class="pc-assign-open<?php echo $rowPlanBelow ? ' pc-assign-open-needed' : ''; ?>"
                                            data-date="<?php echo htmlspecialchars($dayDate); ?>"
                                            data-product-id="<?php echo (int)$row['productId']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($row['product']['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-demand="<?php echo (int)$row['demand']; ?>"
                                            data-has-plan="<?php echo $row['hasPlan'] ? '1' : '0'; ?>"
                                            data-on-hand="<?php echo (int)$row['onHand']; ?>"
                                            data-confirmed="<?php echo (int)$row['confirmed']; ?>"
                                            data-pool-source="<?php echo htmlspecialchars($assignPool['source'], ENT_QUOTES, 'UTF-8'); ?>"
                                        ><?php bakery_te($rowPlanBelow ? 'production_center.assign_short' : 'production_center.assign'); ?></button>
                                        <?php if ($rowPlanBelow): ?>
                                            <button
                                                type="button"
                                                class="pc-cut-open"
                                                aria-expanded="false"
                                                data-date="<?php echo htmlspecialchars($dayDate); ?>"
                                                data-product-id="<?php echo (int)$row['productId']; ?>"
                                                data-product-name="<?php echo htmlspecialchars($row['product']['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                data-demand="<?php echo (int)$row['demand']; ?>"
                                                data-on-hand="<?php echo (int)$row['onHand']; ?>"
                                                data-confirmed="<?php echo (int)$row['confirmed']; ?>"
                                                data-pool-source="<?php echo htmlspecialchars($assignPool['source'], ENT_QUOTES, 'UTF-8'); ?>"
                                            ><?php bakery_te('production_center.cut_open'); ?></button>
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
                                        data-has-plan="<?php echo $row['hasPlan'] ? '1' : '0'; ?>"
                                        data-demand="<?php echo (int)$row['demand']; ?>"
                                        <?php
                                        $rowScale = bakery_pack_input_scale($db, (int)$row['productId']);
                                        ?>
                                        data-input-unit="<?php echo htmlspecialchars((string)$rowScale['unit']); ?>"
                                        data-pcs-per="<?php echo htmlspecialchars((string)$rowScale['pcs_per']); ?>"
                                        value="<?php echo (int)$row['planned']; ?>"
                                            <?php echo !$planTableReady ? 'disabled' : ''; ?>
                                            aria-label="Planned finished-goods total for <?php echo htmlspecialchars($row['product']['name']); ?> on <?php echo htmlspecialchars($dayDate); ?>"
                                        >
                                        <small class="pc-plan-meta"><?php echo $row['hasPlan'] ? 'saved target' : 'unsaved · defaults to demand'; ?></small>
                                        <?php if ($row['hasPlan']): ?>
                                            <small class="pc-batch-label"><?php echo htmlspecialchars(bakery_pack_batch_label($db, (int)$row['productId'], (int)$row['planned'])); ?></small>
                                        <?php endif; ?>
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
                        (<a href="<?php echo htmlspecialchars($dayHref); ?>">Open bake schedule for this delivery day</a>).
                        Saved targets are a draft until a manager commits this delivery day.
                    </p>
                <?php else: ?>
                    <p class="pc-empty">
                        No standing orders, real orders, inventory, or saved targets for this day.
                        <a href="<?php echo htmlspecialchars(production_center_day_href($selectedDate, true, $attentionOnly, $pageReturnKey)); ?>">Show all products</a> to plan ahead.
                    </p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php if ($planTableReady): ?>
            <div class="pc-autosave-bar" id="pc-autosave-bar" aria-live="polite">
                <strong id="pc-save-state"><?php bakery_te('production_center.autosave_idle'); ?></strong>
                <span id="pc-save-detail"><?php bakery_te('production_center.autosave_detail'); ?></span>
            </div>
            <noscript>
                <div class="pc-save-bar">
                    <div>
                        <strong><?php bakery_te('production_center.save_noscript'); ?></strong>
                    </div>
                    <button class="btn btn-primary" type="submit"><?php bakery_te('production_center.save_targets'); ?></button>
                </div>
            </noscript>
        <?php endif; ?>
    </form>
    <form method="post" id="pc-cut-all-<?php echo htmlspecialchars($selectedDate); ?>" class="pc-commit-form">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="cut_apply_all">
        <input type="hidden" name="week" value="<?php echo htmlspecialchars($weekStart); ?>">
        <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        <input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
    </form>
    <?php if (!empty($commitsReady) && $planTableReady): ?>
        <form method="post" id="pc-commit-<?php echo htmlspecialchars($selectedDate); ?>" class="pc-commit-form">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="commit_plan">
            <input type="hidden" name="week" value="<?php echo htmlspecialchars($weekStart); ?>">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
            <input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </form>
    <?php endif; ?>
    <dialog class="pc-dialog" id="pc-formula-dialog">
        <form method="dialog" class="pc-dialog-close"><button type="submit"><?php bakery_te('production_center.assign_close'); ?></button></form>
        <div id="pc-formula-body"></div>
    </dialog>
    <dialog class="pc-dialog" id="pc-store-dialog">
        <form method="dialog" class="pc-dialog-close"><button type="submit"><?php bakery_te('production_center.assign_close'); ?></button></form>
        <div id="pc-store-body"></div>
    </dialog>
</main>
<style>
.production-center{max-width:1480px;padding-bottom:56px}
.pc-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:28px 0 18px}
.pc-product-open,.pc-store-demand-open,.pc-store-demand-heading{display:inline;margin:0;padding:0;border:0;background:none;color:#1f6b35;font:inherit;font-weight:700;cursor:pointer;text-align:left;text-decoration:underline;text-underline-offset:2px}
.pc-store-demand-heading{font-size:inherit;color:#193b2a}
.pc-product-open:hover,.pc-store-demand-open:hover,.pc-store-demand-heading:hover{color:#154e2a}
.pc-dialog{max-width:720px;width:calc(100% - 24px);border:1px solid #c5d9cb;border-radius:12px;padding:16px 18px 20px;box-shadow:0 12px 40px rgba(21,48,33,.18)}
.pc-dialog::backdrop{background:rgba(20,40,28,.35)}
.pc-dialog-close{display:flex;justify-content:flex-end;margin:0 0 8px}
.pc-dialog-close button{border:1px solid #b7d4bf;background:#f3fbf5;color:#1f6b35;font-weight:700;padding:4px 10px;border-radius:6px;cursor:pointer}
.pc-formula-list{list-style:none;margin:10px 0 0;padding:0;display:grid;gap:6px}
.pc-formula-list li{display:flex;justify-content:space-between;gap:12px;padding:8px 10px;background:#f7faf7;border:1px solid #e1e9e2;border-radius:8px}
.pc-store-qty{width:5.5rem;padding:6px 8px;border:1px solid #cbd7cf;border-radius:5px}
.pc-store-status{min-height:1.2em;margin:10px 0;color:#1f6b35;font-weight:600}
.pc-heading h1{margin:0;color:#193b2a}
.pc-heading p{margin:6px 0 0;color:#586b60;max-width:760px}
.pc-heading-actions{display:flex;flex-wrap:wrap;gap:8px}
.pc-eyebrow{color:var(--sf-primary,#287449)!important;font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:.76rem}
.pc-notice{padding:12px 15px;border-radius:var(--sf-radius-sm,7px);margin:12px 0;border:1px solid transparent}
.pc-notice.success{background:var(--sf-success-bg,#e5f5e9);border-color:var(--sf-success-border,#b9dfc4);color:var(--sf-success,#195f35)}
.pc-notice.error{background:var(--sf-danger-bg,#fdeaea);border-color:var(--sf-danger-border,#efc2c2);color:var(--sf-danger,#9f2727)}
.pc-notice.warning{background:var(--sf-warning-bg,#fff5dd);border-color:var(--sf-warning-border,#efd7a8);color:var(--sf-warning,#80590d)}
.pc-week-picker,.pc-day-picker{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:18px 0}
.pc-day-picker-date{display:flex;align-items:center;gap:8px;font-weight:600;color:#254632}
.pc-week-picker input,.pc-day-picker input,.pc-table input{border:1px solid #cbd7cf;border-radius:5px;padding:8px;background:#fff}
.pc-week-pills{display:flex;flex-wrap:wrap;gap:6px}
.pc-week-pill{font-size:.78rem;font-weight:700;color:#246b43;text-decoration:none;border:1px solid #c5d9cb;background:#f3fbf5;padding:5px 9px;border-radius:999px}
.pc-week-pill.is-current{background:#193b2a;border-color:#193b2a;color:#fff}
.pc-autosave-note{color:#5a6d61;font-size:.88rem;font-weight:600}
.pc-autosave-bar{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:baseline;margin:8px 0 0;color:#506257;font-size:.9rem}
.pc-autosave-bar.is-saving strong{color:#80590d}
.pc-autosave-bar.is-saved strong{color:#1f6b35}
.pc-autosave-bar.is-error strong{color:#9f2727}
.pc-week-label{color:#5a6d61;font-size:.9rem}
.pc-cadence-runs{background:#fff;border:1px solid #dce8df;border-left:4px solid #287449;border-radius:9px;padding:14px 16px;margin:16px 0 8px}
.pc-cadence-runs-head{display:flex;flex-direction:column;gap:4px;margin-bottom:10px}
.pc-cadence-runs-head strong{color:#193b2a}
.pc-cadence-runs-head span{color:#5a6d61;font-size:.9rem}
.pc-cadence-run-list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
.pc-cadence-run{display:flex;flex-direction:column;gap:2px;padding:8px 10px;background:#f7faf7;border-radius:8px;border:1px solid #e1e9e2}
.pc-cadence-run-prior{background:#f4f1ea;border-color:#e4dcc8}
.pc-cadence-kicker{font-size:.7rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#5d7164}
.pc-cadence-run a{font-weight:700;color:#1f6b35;text-decoration:none}
.pc-cadence-run a:hover{text-decoration:underline}
.pc-cadence-run-meta{font-size:.8rem;color:#506257}
.pc-bake-chip{font-size:.74rem;color:#1d4d33;background:#e5f3ea;padding:3px 8px;border-radius:999px}
.pc-bake-chip-quiet{color:#735412;background:#fff3d3}
.pc-row-cadence{color:#2f6a45!important;font-weight:600}
.pc-text-link{color:#246b43;font-weight:600}
.pc-summary{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:12px;margin:18px 0}
.pc-summary>div{background:#fff;border:1px solid #dce8df;border-radius:9px;padding:14px;box-shadow:0 1px 2px rgba(21,48,33,.04)}
.pc-summary span,.pc-summary small{display:block;color:#64756a;font-size:.8rem}
.pc-summary strong{display:block;font-size:1.45rem;color:#1d3f2c;margin:3px 0}
.pc-explainer{background:#eef7ef;border-left:4px solid #398451;padding:13px 16px;color:#3f5948;margin:18px 0 22px;line-height:1.45}
.pc-kitchen-form{background:#f7f4ee;border:1px solid #e2d9c8;border-radius:12px;padding:16px 18px;margin:0 0 22px}
.pc-kitchen-form textarea{width:100%;max-width:720px;font:inherit;padding:10px 12px;border:1px solid #cfc4ae;border-radius:8px}
.pc-kitchen-lead{margin:6px 0 10px;color:#5a6d61;max-width:760px}
.pc-kitchen-actions{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}
.pc-kitchen-preview{margin-top:14px}
.pc-kitchen-preview ul{margin:8px 0 0;padding-left:18px}
.pc-assign-lead{margin:8px 0 0;color:#3f5948}
.pc-assign-open{display:inline-block;margin-top:6px;border:1px solid #b7d4bf;background:#f3fbf5;color:#1f6b35;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:6px;cursor:pointer;font-family:inherit}
.pc-assign-open:hover{background:#e5f5ea}
.pc-assign-open-needed{background:#fff3d3;border-color:#e2b46a;color:#80590d}
.pc-cut-all-btn{border-color:#e2b46a;background:#fff3d3;color:#80590d}
.pc-cut-all-btn:hover{background:#fdeac2}
.pc-cut-open{display:inline-block;margin:6px 0 0 6px;border:1px solid #e2b46a;background:#fff3d3;color:#80590d;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:6px;cursor:pointer;font-family:inherit}
.pc-cut-open:hover{background:#fdeac2}
.pc-cut-open[aria-expanded="true"]{background:#80590d;border-color:#80590d;color:#fff}
.pc-cut-focus{margin:10px 0;padding:10px 12px;border:1px solid #e2d3b0;border-radius:8px;background:#fffaf0}
.pc-cut-focus-label{margin:0 0 8px;font-size:.82rem;font-weight:700;color:#80590d}
.pc-cut-focus-choices{display:flex;flex-wrap:wrap;gap:10px 16px;margin:0 0 8px}
.pc-cut-focus-choices label{display:flex;gap:6px;align-items:center;font-size:.88rem;color:#254632}
.pc-cut-focus-picks{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.pc-cut-focus-picks label{font-size:.85rem;color:#254632;font-weight:600}
.pc-cut-focus-picks select{min-width:10rem;padding:5px 8px;border:1px solid #cbd7cf;border-radius:5px}
.pc-cut-focus-note{margin:8px 0 0;font-size:.82rem;color:#66786c}
.pc-assign-table tr.pc-cut-dim td{color:#8a968d}
.pc-assign-table .pc-cut-pick{width:auto}
.pc-assign-row td{background:#f4f8f4;padding:0 12px 14px}
.pc-assign-panel{padding:12px 4px 8px;max-width:920px}
.pc-assign-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.pc-assign-head h3{margin:0;font-size:1rem;color:#193b2a}
.pc-assign-meter{display:flex;flex-wrap:wrap;gap:12px;margin:8px 0 10px;font-size:.85rem;color:#506257}
.pc-assign-meter strong{color:#1d3f2c}
.pc-assign-scope{display:flex;flex-direction:column;gap:6px;margin:8px 0 12px}
.pc-assign-scope label{display:flex;gap:8px;align-items:flex-start;font-size:.88rem;color:#254632}
.pc-assign-scope small{display:block;color:#66786c;font-weight:400}
.pc-assign-actions{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}
.pc-assign-table{width:100%;border-collapse:collapse;margin-top:8px}
.pc-assign-table th,.pc-assign-table td{padding:6px 8px;text-align:left;border-bottom:1px solid #e1e9e2;font-size:.85rem}
.pc-assign-table input{width:72px;padding:5px;border:1px solid #cbd7cf;border-radius:5px}
.pc-assign-table input:disabled{background:#eef1ef;color:#8a968d}
.pc-assign-locked{color:#80590d;font-size:.75rem;font-weight:700}
.pc-assign-msg{margin:8px 0 0;font-size:.88rem}
.pc-assign-msg.is-error{color:#9f2727}
.pc-assign-close{border:0;background:transparent;color:#246b43;font-weight:700;cursor:pointer;font-family:inherit}
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
.pc-commit-btn{cursor:pointer;font-family:inherit}
.pc-commit-form{display:none}
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
.pc-table input.pc-saving{border-color:#246b43;background:#f3fbf5}
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
    .pc-week-picker,.pc-day-picker{align-items:flex-start;flex-direction:column}
    .pc-week-picker .btn,.pc-day-picker .btn{width:100%}
}
</style>
<?php if ($planTableReady): ?>
<script>
(function () {
    var form = document.getElementById('pc-plan-form');
    if (!form) return;
    var saveBar = document.getElementById('pc-autosave-bar');
    var saveState = document.getElementById('pc-save-state');
    var saveDetail = document.getElementById('pc-save-detail');
    var note = document.getElementById('pc-autosave-note');
    var inputs = Array.prototype.slice.call(document.querySelectorAll('.pc-plan-input'));
    var timers = {};
    var inflight = 0;
    var copy = {
        idle: <?php echo json_encode(bakery_t('production_center.autosave_idle')); ?>,
        saving: <?php echo json_encode(bakery_t('production_center.autosave_saving')); ?>,
        saved: <?php echo json_encode(bakery_t('production_center.autosave_saved')); ?>,
        error: <?php echo json_encode(bakery_t('production_center.autosave_error')); ?>,
        invalid: <?php echo json_encode(bakery_t('production_center.autosave_invalid')); ?>,
        hint: <?php echo json_encode(bakery_t('production_center.autosave_hint')); ?>,
        savedTarget: <?php echo json_encode(bakery_t('production_center.saved_target')); ?>,
        conflict: <?php echo json_encode(bakery_t('production_center.autosave_conflict')); ?>
    };

    function parseQty(value) {
        if (value === null || value === undefined) return null;
        var trimmed = String(value).trim();
        if (trimmed === '') return null;
        if (!/^\d+$/.test(trimmed)) return NaN;
        return parseInt(trimmed, 10);
    }

    function formatBatchQty(n) {
        var s = String(Math.round(n * 100) / 100);
        if (s.indexOf('.') !== -1) s = s.replace(/0+$/, '').replace(/\.$/, '');
        return s;
    }

    function refreshBatchLabel(input) {
        var qty = parseQty(input.value);
        var unit = input.getAttribute('data-input-unit') || '';
        var pcsPer = parseFloat(input.getAttribute('data-pcs-per') || '0');
        var row = input.closest('tr');
        var label = row ? row.querySelector('.pc-batch-label') : null;
        if (!label || qty === null || isNaN(qty) || qty < 0) return;
        if (!pcsPer || pcsPer <= 0 || unit === 'piece') {
            label.textContent = String(qty) + ' pcs';
            return;
        }
        if (unit === 'tray') {
            var trays = Math.floor(qty / pcsPer);
            var loose = qty % pcsPer;
            var text = trays + ' tray' + (trays === 1 ? '' : 's');
            if (loose > 0) text += ' + ' + loose + ' pcs';
            label.textContent = text + ' · ' + qty.toLocaleString() + ' pcs';
            return;
        }
        if (unit === 'barra') {
            label.textContent = qty.toLocaleString() + ' barras';
            return;
        }
        label.textContent = formatBatchQty(qty / pcsPer) + ' gal · ' + qty.toLocaleString() + ' pcs';
    }

    function csrfToken() {
        var field = form.querySelector('input[name="csrf_token"]');
        return field ? field.value : '';
    }

    function setStatus(kind, message, detail) {
        if (saveBar) {
            saveBar.classList.remove('is-saving', 'is-saved', 'is-error');
            if (kind) saveBar.classList.add('is-' + kind);
        }
        if (saveState) saveState.textContent = message;
        if (saveDetail) saveDetail.textContent = detail || '';
        if (note) note.textContent = kind === 'saved' ? copy.saved : copy.hint;
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

    function markInput(input, state) {
        input.classList.toggle('pc-dirty', state === 'dirty');
        input.classList.toggle('pc-invalid', state === 'invalid');
        input.classList.toggle('pc-saving', state === 'saving');
    }

    function saveInput(input) {
        if (input.disabled) return;
        var qty = parseQty(input.value);
        var baseline = String(input.getAttribute('data-baseline'));
        var expectedHasPlan = input.getAttribute('data-has-plan') === '1';
        var current = String(input.value).trim();
        refreshMakeNeed(input);
        if (current === baseline) {
            markInput(input, '');
            if (inflight === 0) setStatus('', copy.idle, copy.hint);
            return;
        }
        if (qty === null || isNaN(qty) || qty < 0) {
            markInput(input, 'invalid');
            setStatus('error', copy.invalid, '');
            return;
        }
        markInput(input, 'saving');
        inflight += 1;
        setStatus('saving', copy.saving, '');
        var body = new URLSearchParams();
        body.set('action', 'save_plan');
        body.set('csrf_token', csrfToken());
        body.set('week', form.querySelector('input[name="week"]').value);
        body.set('date', form.querySelector('input[name="date"]').value);
        body.set('planned[' + input.getAttribute('data-date') + '][' + input.getAttribute('data-product-id') + ']', String(qty));
        body.set('expected_has_plan', expectedHasPlan ? '1' : '0');
        body.set('expected_quantity', baseline);
        fetch('production_center.php?date=' + encodeURIComponent(input.getAttribute('data-date')), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().then(function (data) {
                return { okHttp: res.ok, data: data };
            });
        }).then(function (result) {
            inflight = Math.max(0, inflight - 1);
            if (!result.data || result.data.ok !== true) {
                if (result.data && result.data.conflict) {
                    input.setAttribute('data-has-plan', result.data.current_has_plan ? '1' : '0');
                    input.setAttribute('data-baseline', String(result.data.current_quantity));
                }
                markInput(input, 'dirty');
                setStatus('error', result.data && result.data.conflict ? copy.conflict : copy.error, (result.data && result.data.error) ? result.data.error : '');
                return;
            }
            input.setAttribute('data-baseline', String(qty));
            input.setAttribute('data-has-plan', '1');
            if (String(input.value).trim() === String(qty)) {
                markInput(input, '');
            } else {
                markInput(input, 'dirty');
                scheduleSave(input);
            }
            var meta = input.parentElement.querySelector('.pc-plan-meta');
            if (meta) meta.textContent = copy.savedTarget;
            if (result.data.batch_label) {
                var batchEl = input.closest('tr') ? input.closest('tr').querySelector('.pc-batch-label') : null;
                if (batchEl) batchEl.textContent = result.data.batch_label;
            }
            var row = input.closest('tr');
            if (row) {
                var productBtn = row.querySelector('.pc-product-open');
                if (productBtn) productBtn.setAttribute('data-pieces', String(qty));
                var demandBtn = row.querySelector('.pc-store-demand-open');
                if (demandBtn) demandBtn.setAttribute('data-pool', String(qty));
            }
            if (inflight === 0) setStatus('saved', copy.saved, copy.hint);
        }).catch(function () {
            inflight = Math.max(0, inflight - 1);
            markInput(input, 'dirty');
            setStatus('error', copy.error, '');
        });
    }

    function scheduleSave(input) {
        var key = input.getAttribute('data-date') + ':' + input.getAttribute('data-product-id');
        if (timers[key]) clearTimeout(timers[key]);
        timers[key] = setTimeout(function () {
            timers[key] = null;
            saveInput(input);
        }, 400);
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', function () {
            var qty = parseQty(input.value);
            var baseline = String(input.getAttribute('data-baseline'));
            var current = String(input.value).trim();
            refreshMakeNeed(input);
            refreshBatchLabel(input);
            if (qty === null || isNaN(qty) || qty < 0) {
                markInput(input, 'invalid');
                setStatus('error', copy.invalid, '');
                return;
            }
            markInput(input, current === baseline ? '' : 'dirty');
            scheduleSave(input);
        });
        input.addEventListener('change', function () {
            var key = input.getAttribute('data-date') + ':' + input.getAttribute('data-product-id');
            if (timers[key]) {
                clearTimeout(timers[key]);
                timers[key] = null;
            }
            saveInput(input);
        });
        input.addEventListener('blur', function () {
            var key = input.getAttribute('data-date') + ':' + input.getAttribute('data-product-id');
            if (timers[key]) {
                clearTimeout(timers[key]);
                timers[key] = null;
                saveInput(input);
            }
        });
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
        if (pending.indexOf(null) !== -1 || !pending.filter(Boolean).length) {
            event.preventDefault();
        }
    });

    window.addEventListener('beforeunload', function (event) {
        var pending = inputs.some(function (input) {
            return !input.disabled && String(input.value).trim() !== String(input.getAttribute('data-baseline'));
        });
        if (pending || inflight > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    var form = document.getElementById('pc-plan-form');
    if (!form) return;
    var copy = {
        title: <?php echo json_encode(bakery_t('production_center.assign_title')); ?>,
        poolPlanned: <?php echo json_encode(bakery_t('production_center.assign_pool_planned')); ?>,
        poolOnHand: <?php echo json_encode(bakery_t('production_center.assign_pool_on_hand')); ?>,
        poolConfirmed: <?php echo json_encode(bakery_t('production_center.assign_pool_confirmed')); ?>,
        demand: <?php echo json_encode(bakery_t('production_center.assign_demand')); ?>,
        assigned: <?php echo json_encode(bakery_t('production_center.assign_assigned')); ?>,
        leftover: <?php echo json_encode(bakery_t('production_center.assign_left')); ?>,
        over: <?php echo json_encode(bakery_t('production_center.assign_over')); ?>,
        scopeStanding: <?php echo json_encode(bakery_t('production_center.assign_scope_standing')); ?>,
        scopeStandingHint: <?php echo json_encode(bakery_t('production_center.assign_scope_standing_hint')); ?>,
        scopeDaily: <?php echo json_encode(bakery_t('production_center.assign_scope_daily')); ?>,
        scopeDailyHint: <?php echo json_encode(bakery_t('production_center.assign_scope_daily_hint')); ?>,
        recommend: <?php echo json_encode(bakery_t('production_center.assign_recommend')); ?>,
        apply: <?php echo json_encode(bakery_t('production_center.assign_apply')); ?>,
        close: <?php echo json_encode(bakery_t('production_center.assign_close')); ?>,
        customer: <?php echo json_encode(bakery_t('production_center.assign_customer')); ?>,
        qty: <?php echo json_encode(bakery_t('production_center.assign_qty')); ?>,
        now: <?php echo json_encode(bakery_t('production_center.assign_now')); ?>,
        empty: <?php echo json_encode(bakery_t('production_center.assign_empty')); ?>,
        error: <?php echo json_encode(bakery_t('production_center.assign_error')); ?>,
        locked: <?php echo json_encode(bakery_t('production_center.assign_locked')); ?>,
        sourceDaily: <?php echo json_encode(bakery_t('distribution.source_badge_daily')); ?>,
        sourceStanding: <?php echo json_encode(bakery_t('distribution.source_badge_standing')); ?>,
        sourceStandard: <?php echo json_encode(bakery_t('distribution.source_badge_standard')); ?>,
        cutTitle: <?php echo json_encode(bakery_t('production_center.cut_title')); ?>,
        cutHint: <?php echo json_encode(bakery_t('production_center.cut_hint')); ?>,
        cutAfter: <?php echo json_encode(bakery_t('production_center.cut_after')); ?>,
        cutApply: <?php echo json_encode(bakery_t('production_center.cut_apply')); ?>,
        cutFocus: <?php echo json_encode(bakery_t('production_center.cut_focus')); ?>,
        cutFocusAll: <?php echo json_encode(bakery_t('production_center.cut_focus_all')); ?>,
        cutFocusZone: <?php echo json_encode(bakery_t('production_center.cut_focus_zone')); ?>,
        cutFocusDriver: <?php echo json_encode(bakery_t('production_center.cut_focus_driver')); ?>,
        cutFocusChecked: <?php echo json_encode(bakery_t('production_center.cut_focus_checked')); ?>,
        cutZone: <?php echo json_encode(bakery_t('production_center.cut_zone')); ?>,
        cutDriver: <?php echo json_encode(bakery_t('production_center.cut_driver')); ?>,
        cutPick: <?php echo json_encode(bakery_t('production_center.cut_pick')); ?>,
        cutFill: <?php echo json_encode(bakery_t('production_center.cut_fill')); ?>,
        cutApplyRecommended: <?php echo json_encode(bakery_t('production_center.cut_apply_recommended')); ?>,
        cutOutsideKeep: <?php echo json_encode(bakery_t('production_center.cut_outside_keep')); ?>,
        cutNoFocus: <?php echo json_encode(bakery_t('production_center.cut_no_focus')); ?>
    };

    function csrfToken() {
        var field = form.querySelector('input[name="csrf_token"]');
        return field ? field.value : '';
    }

    function parseQty(value) {
        var trimmed = String(value == null ? '' : value).trim();
        if (trimmed === '' || !/^\d+$/.test(trimmed)) return 0;
        return parseInt(trimmed, 10);
    }

    function currentPool(btn) {
        var row = btn.closest('tr');
        var input = row ? row.querySelector('.pc-plan-input') : null;
        if (input) {
            var typed = String(input.value).trim();
            if (typed !== '' && /^\d+$/.test(typed)) return parseInt(typed, 10);
        }
        var onHand = parseInt(btn.getAttribute('data-on-hand'), 10) || 0;
        var confirmed = parseInt(btn.getAttribute('data-confirmed'), 10) || 0;
        if (onHand > 0) return onHand;
        if (confirmed > 0) return confirmed;
        return 0;
    }

    function sourceLabel(source) {
        if (source === 'daily') return copy.sourceDaily;
        if (source === 'pan_dulce_standard') return copy.sourceStandard;
        return copy.sourceStanding;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function updateMeter(panel) {
        var pool = parseInt(panel.getAttribute('data-pool'), 10) || 0;
        var demand = parseInt(panel.getAttribute('data-demand'), 10) || 0;
        var assigned = 0;
        panel.querySelectorAll('.pc-assign-qty').forEach(function (input) {
            if (input.disabled) {
                assigned += parseQty(input.getAttribute('data-current'));
                return;
            }
            assigned += parseQty(input.value);
        });
        var leftover = pool - assigned;
        var leftoverLabel = leftover >= 0 ? copy.leftover : copy.over;
        var assignedLabel = panel.getAttribute('data-mode') === 'cut' ? copy.cutAfter : copy.assigned;
        var meter = panel.querySelector('[data-meter]');
        if (meter) {
            meter.innerHTML =
                '<span>' + escapeHtml(poolLabelFromPanel(panel)) + ' <strong>' + pool + '</strong></span>' +
                '<span>' + escapeHtml(copy.demand) + ' <strong>' + demand + '</strong></span>' +
                '<span>' + escapeHtml(assignedLabel) + ' <strong>' + assigned + '</strong></span>' +
                '<span class="' + (leftover === 0 ? 'pc-covered' : 'pc-short') + '">' +
                escapeHtml(leftoverLabel) + ' <strong>' + Math.abs(leftover) + '</strong></span>';
        }
    }

    function poolLabelFromPanel(panel) {
        var source = panel.getAttribute('data-pool-source') || 'planned';
        if (source === 'on_hand') return copy.poolOnHand;
        if (source === 'confirmed') return copy.poolConfirmed;
        return copy.poolPlanned;
    }

    function closePanel() {
        var open = document.querySelector('.pc-assign-row');
        if (open) open.parentNode.removeChild(open);
        document.querySelectorAll('.pc-assign-open, .pc-cut-open').forEach(function (btn) {
            btn.setAttribute('aria-expanded', 'false');
        });
    }

    function uniqueSorted(values) {
        var seen = {};
        var out = [];
        values.forEach(function (value) {
            var key = String(value);
            if (key === '' || seen[key]) return;
            seen[key] = true;
            out.push(value);
        });
        out.sort(function (a, b) {
            return String(a).localeCompare(String(b));
        });
        return out;
    }

    function cutRecommend(customers, pool) {
        var n = customers.length;
        if (!n) return customers;
        var total = 0;
        customers.forEach(function (row) {
            total += Math.max(0, parseInt(row.quantity, 10) || 0);
        });
        if (total <= 0 || pool <= 0) {
            return customers.map(function (row) {
                var copyRow = Object.assign({}, row);
                copyRow.recommended = 0;
                return copyRow;
            });
        }
        var shares = [];
        var used = 0;
        var remainders = [];
        customers.forEach(function (row, i) {
            var qty = Math.max(0, parseInt(row.quantity, 10) || 0);
            var raw = (qty / total) * pool;
            var floor = Math.floor(raw);
            shares[i] = floor;
            used += floor;
            remainders.push({ i: i, rem: raw - floor });
        });
        remainders.sort(function (a, b) {
            if (b.rem === a.rem) return a.i - b.i;
            return b.rem - a.rem;
        });
        var left = pool - used;
        remainders.forEach(function (item) {
            if (left <= 0) return;
            shares[item.i]++;
            left--;
        });
        return customers.map(function (row, i) {
            var copyRow = Object.assign({}, row);
            copyRow.recommended = shares[i];
            return copyRow;
        });
    }

    function cutShare(rows, pool, focusIds) {
        var focusSet = null;
        if (focusIds !== null && focusIds !== undefined) {
            focusSet = {};
            focusIds.forEach(function (id) {
                var n = parseInt(id, 10);
                if (n > 0) focusSet[n] = true;
            });
        }
        var reserved = 0;
        var share = [];
        var shareIdx = [];
        var out = rows.map(function (row, i) {
            var copyRow = Object.assign({}, row);
            var qty = Math.max(0, parseInt(copyRow.quantity, 10) || 0);
            var locked = !!copyRow.locked;
            var inFocus = !locked && (focusSet === null || focusSet[parseInt(copyRow.id, 10)]);
            copyRow.in_focus = inFocus;
            if (locked || !inFocus) {
                copyRow.recommended = qty;
                reserved += qty;
            } else {
                share.push(copyRow);
                shareIdx.push(i);
            }
            return copyRow;
        });
        var shareTotal = 0;
        share.forEach(function (row) {
            shareTotal += Math.max(0, parseInt(row.quantity, 10) || 0);
        });
        var effective = Math.min(Math.max(0, pool - reserved), shareTotal);
        var recommended = cutRecommend(share, effective);
        shareIdx.forEach(function (i, j) {
            out[i].recommended = recommended[j] ? recommended[j].recommended : 0;
        });
        return { rows: out, focusPool: effective };
    }

    function cutFocusIds(panel, customers) {
        var modeEl = panel.querySelector('input[name="pc-cut-focus"]:checked');
        var mode = modeEl ? modeEl.value : 'all';
        if (mode === 'all') return null;
        if (mode === 'zone') {
            var zone = panel.querySelector('[data-cut-zone]');
            var zoneVal = zone ? zone.value : '';
            return customers.filter(function (row) {
                return !row.locked && String(row.zone || '') === zoneVal;
            }).map(function (row) { return row.id; });
        }
        if (mode === 'driver') {
            var driver = panel.querySelector('[data-cut-driver]');
            var driverVal = driver ? driver.value : '';
            return customers.filter(function (row) {
                return !row.locked && String(row.driver_id || 0) === driverVal;
            }).map(function (row) { return row.id; });
        }
        var ids = [];
        panel.querySelectorAll('.pc-cut-pick:checked').forEach(function (box) {
            ids.push(parseInt(box.getAttribute('data-customer-id'), 10));
        });
        return ids;
    }

    function cutOutsideKeepNote(pool) {
        return String(copy.cutOutsideKeep).replace(':pool', String(pool));
    }

    function renderPanel(btn, data, mode) {
        mode = mode === 'cut' ? 'cut' : 'assign';
        closePanel();
        var row = btn.closest('tr');
        if (!row) return;
        var panelRow = document.createElement('tr');
        panelRow.className = 'pc-assign-row';
        var planInput = row.querySelector('.pc-plan-input');
        var poolSource = (planInput && String(planInput.value).trim() !== '')
            ? 'planned'
            : (btn.getAttribute('data-pool-source') || 'planned');
        var cell = document.createElement('td');
        cell.colSpan = row.children.length;
        var customers = data.customers || [];
        var zones = uniqueSorted(customers.map(function (c) { return c.zone || ''; }));
        var drivers = [];
        var driverSeen = {};
        customers.forEach(function (c) {
            var id = parseInt(c.driver_id, 10) || 0;
            if (!id || driverSeen[id]) return;
            driverSeen[id] = true;
            drivers.push({ id: id, name: c.driver_name || ('#' + id) });
        });
        drivers.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        var rowsHtml = customers.map(function (customer) {
            var locked = !!customer.locked;
            var rec = customer.recommended != null ? customer.recommended : customer.quantity;
            var pick = mode === 'cut' && !locked
                ? '<td><input type="checkbox" class="pc-cut-pick" data-customer-id="' + String(customer.id) + '" aria-label="' + escapeHtml(copy.cutPick) + '"></td>'
                : (mode === 'cut' ? '<td></td>' : '');
            return '<tr data-customer-id="' + String(customer.id) + '">' +
                pick +
                '<td>' + escapeHtml(customer.name || '') +
                (customer.zone ? '<small>' + escapeHtml(customer.zone) + '</small>' : '') +
                '</td>' +
                (mode === 'cut' ? '<td>' + escapeHtml(customer.driver_name || '—') + '</td>' : '') +
                '<td>' + escapeHtml(sourceLabel(customer.source)) +
                '<small>now ' + String(customer.quantity) +
                (customer.standing_qty != null ? ' · stand ' + String(customer.standing_qty) : '') +
                '</small></td>' +
                '<td><input class="pc-assign-qty" inputmode="numeric" min="0" step="1" max="' +
                String(mode === 'cut' ? customer.quantity : '') +
                '" data-customer-id="' + String(customer.id) + '" data-current="' + String(customer.quantity) + '" ' +
                'data-recommended="' + String(rec) + '" data-in-focus="1" value="' + String(mode === 'cut' ? rec : customer.quantity) + '"' +
                (locked ? ' disabled' : '') + '>' +
                (locked ? '<div class="pc-assign-locked">' + escapeHtml(copy.locked) + '</div>' : '') +
                '</td></tr>';
        }).join('');
        var zoneOptions = zones.map(function (zone) {
            return '<option value="' + escapeHtml(zone) + '">' + escapeHtml(zone) + '</option>';
        }).join('');
        var driverOptions = drivers.map(function (driver) {
            return '<option value="' + String(driver.id) + '">' + escapeHtml(driver.name) + '</option>';
        }).join('');
        var cutFocusHtml = mode !== 'cut' ? '' :
            '<div class="pc-cut-focus">' +
            '<p class="pc-cut-focus-label">' + escapeHtml(copy.cutFocus) + '</p>' +
            '<div class="pc-cut-focus-choices">' +
            '<label><input type="radio" name="pc-cut-focus" value="all" checked> ' + escapeHtml(copy.cutFocusAll) + '</label>' +
            '<label><input type="radio" name="pc-cut-focus" value="zone"' + (zones.length ? '' : ' disabled') + '> ' + escapeHtml(copy.cutFocusZone) + '</label>' +
            '<label><input type="radio" name="pc-cut-focus" value="driver"' + (drivers.length ? '' : ' disabled') + '> ' + escapeHtml(copy.cutFocusDriver) + '</label>' +
            '<label><input type="radio" name="pc-cut-focus" value="checked"> ' + escapeHtml(copy.cutFocusChecked) + '</label>' +
            '</div>' +
            '<div class="pc-cut-focus-picks">' +
            '<label data-cut-zone-wrap hidden>' + escapeHtml(copy.cutZone) +
            ' <select data-cut-zone>' + zoneOptions + '</select></label>' +
            '<label data-cut-driver-wrap hidden>' + escapeHtml(copy.cutDriver) +
            ' <select data-cut-driver>' + driverOptions + '</select></label>' +
            '</div>' +
            '<p class="pc-cut-focus-note" data-cut-note></p>' +
            '</div>';
        var scopeHtml = mode === 'cut'
            ? ''
            : '<div class="pc-assign-scope">' +
              '<label><input type="radio" name="pc-assign-scope" value="standing" checked>' +
              '<span>' + escapeHtml(copy.scopeStanding) + '<small>' + escapeHtml(copy.scopeStandingHint) + '</small></span></label>' +
              '<label><input type="radio" name="pc-assign-scope" value="daily">' +
              '<span>' + escapeHtml(copy.scopeDaily) + '<small>' + escapeHtml(copy.scopeDailyHint) + '</small></span></label>' +
              '</div>';
        var hintHtml = mode === 'cut'
            ? '<p class="pc-assign-msg">' + escapeHtml(copy.cutHint) + '</p>'
            : '';
        var cutHead = mode === 'cut'
            ? '<th>' + escapeHtml(copy.cutPick) + '</th><th>' + escapeHtml(copy.customer) + '</th><th>' + escapeHtml(copy.cutDriver) + '</th>'
            : '<th>' + escapeHtml(copy.customer) + '</th>';
        var actionsHtml = mode === 'cut'
            ? '<div class="pc-assign-actions">' +
              '<button type="button" class="btn btn-outline pc-assign-recommend">' + escapeHtml(copy.cutFill) + '</button>' +
              '<button type="button" class="btn btn-primary pc-cut-apply-recommended">' + escapeHtml(copy.cutApplyRecommended) + '</button>' +
              '<button type="button" class="btn btn-outline pc-assign-apply">' + escapeHtml(copy.cutApply) + '</button>' +
              '</div>'
            : '<div class="pc-assign-actions">' +
              '<button type="button" class="btn btn-outline pc-assign-recommend">' + escapeHtml(copy.recommend) + '</button>' +
              '<button type="button" class="btn btn-primary pc-assign-apply">' + escapeHtml(copy.apply) + '</button>' +
              '</div>';
        cell.innerHTML =
            '<div class="pc-assign-panel" data-pool="' + String(data.pool) + '" data-demand="' + String(data.demand) +
            '" data-pool-source="' + escapeHtml(poolSource) +
            '" data-mode="' + mode +
            '" data-date="' + escapeHtml(btn.getAttribute('data-date')) +
            '" data-product-id="' + escapeHtml(btn.getAttribute('data-product-id')) + '">' +
            '<div class="pc-assign-head">' +
            '<h3>' + escapeHtml((mode === 'cut' ? copy.cutTitle : copy.title)) + ' — ' + escapeHtml(btn.getAttribute('data-product-name') || '') + '</h3>' +
            '<button type="button" class="pc-assign-close">' + escapeHtml(copy.close) + '</button>' +
            '</div>' +
            '<div class="pc-assign-meter" data-meter></div>' +
            hintHtml +
            cutFocusHtml +
            scopeHtml +
            actionsHtml +
            (customers.length
                ? '<table class="pc-assign-table"><thead><tr>' + cutHead + '<th>' +
                  escapeHtml(copy.now) + '</th><th>' + escapeHtml(copy.qty) + '</th></tr></thead><tbody>' +
                  rowsHtml + '</tbody></table>'
                : '<p class="pc-assign-msg">' + escapeHtml(copy.empty) + '</p>') +
            '<p class="pc-assign-msg" data-assign-msg hidden></p>' +
            '</div>';
        panelRow.appendChild(cell);
        row.parentNode.insertBefore(panelRow, row.nextSibling);
        btn.setAttribute('aria-expanded', 'true');
        var panel = cell.querySelector('.pc-assign-panel');
        function fillCutFocus() {
            var ids = cutFocusIds(panel, customers);
            var shared = cutShare(customers, parseInt(panel.getAttribute('data-pool'), 10) || 0, ids);
            var note = panel.querySelector('[data-cut-note]');
            if (note) {
                note.textContent = (ids !== null && !ids.length)
                    ? copy.cutNoFocus
                    : cutOutsideKeepNote(shared.focusPool);
            }
            var modeEl = panel.querySelector('input[name="pc-cut-focus"]:checked');
            var focusMode = modeEl ? modeEl.value : 'all';
            var zoneWrap = panel.querySelector('[data-cut-zone-wrap]');
            var driverWrap = panel.querySelector('[data-cut-driver-wrap]');
            if (zoneWrap) zoneWrap.hidden = focusMode !== 'zone';
            if (driverWrap) driverWrap.hidden = focusMode !== 'driver';
            shared.rows.forEach(function (item) {
                var input = panel.querySelector('.pc-assign-qty[data-customer-id="' + String(item.id) + '"]');
                var tr = panel.querySelector('tr[data-customer-id="' + String(item.id) + '"]');
                if (tr) tr.classList.toggle('pc-cut-dim', !item.in_focus);
                if (!input || input.disabled) return;
                input.setAttribute('data-recommended', String(item.recommended));
                input.setAttribute('data-in-focus', item.in_focus ? '1' : '0');
                input.value = String(item.in_focus ? item.recommended : item.quantity);
            });
            updateMeter(panel);
        }
        updateMeter(panel);
        panel.querySelectorAll('.pc-assign-qty').forEach(function (input) {
            input.addEventListener('input', function () { updateMeter(panel); });
        });
        panel.querySelector('.pc-assign-close').addEventListener('click', closePanel);
        panel.querySelector('.pc-assign-recommend').addEventListener('click', function () {
            if (mode === 'cut') {
                fillCutFocus();
                return;
            }
            panel.querySelectorAll('.pc-assign-qty').forEach(function (input) {
                if (input.disabled) return;
                input.value = String(parseInt(input.getAttribute('data-recommended'), 10) || 0);
            });
            updateMeter(panel);
        });
        panel.querySelector('.pc-assign-apply').addEventListener('click', function () {
            applyPanel(panel, btn, mode);
        });
        var applyRec = panel.querySelector('.pc-cut-apply-recommended');
        if (applyRec) {
            applyRec.addEventListener('click', function () {
                fillCutFocus();
                applyPanel(panel, btn, 'cut');
            });
        }
        panel.querySelectorAll('input[name="pc-cut-focus"], [data-cut-zone], [data-cut-driver], .pc-cut-pick').forEach(function (el) {
            el.addEventListener('change', fillCutFocus);
        });
        if (mode === 'cut') fillCutFocus();
    }

    function applyPanel(panel, btn, mode) {
        mode = mode === 'cut' ? 'cut' : 'assign';
        var msg = panel.querySelector('[data-assign-msg]');
        var assignments = [];
        panel.querySelectorAll('.pc-assign-qty').forEach(function (input) {
            if (input.disabled) return;
            if (mode === 'cut' && input.getAttribute('data-in-focus') !== '1') return;
            assignments.push({
                customer_id: parseInt(input.getAttribute('data-customer-id'), 10),
                quantity: parseQty(input.value)
            });
        });
        if (!assignments.length) {
            msg.hidden = false;
            msg.className = 'pc-assign-msg is-error';
            msg.textContent = mode === 'cut' ? copy.cutNoFocus : copy.empty;
            return;
        }
        var scopeEl = panel.querySelector('input[name="pc-assign-scope"]:checked');
        var body = new URLSearchParams();
        body.set('action', mode === 'cut' ? 'cut_apply' : 'assign_apply');
        body.set('csrf_token', csrfToken());
        body.set('week', form.querySelector('input[name="week"]').value);
        body.set('date', form.querySelector('input[name="date"]').value);
        body.set('delivery_date', panel.getAttribute('data-date'));
        body.set('product_id', panel.getAttribute('data-product-id'));
        body.set('pool', panel.getAttribute('data-pool'));
        if (mode === 'assign') {
            body.set('scope', scopeEl ? scopeEl.value : 'standing');
        }
        body.set('assignments', JSON.stringify(assignments));
        panel.querySelectorAll('.pc-assign-apply, .pc-cut-apply-recommended').forEach(function (el) {
            el.disabled = true;
        });
        fetch('production_center.php?date=' + encodeURIComponent(panel.getAttribute('data-date')), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': csrfToken()
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().then(function (data) { return { okHttp: res.ok, data: data }; });
        }).then(function (result) {
            if (!result.data || result.data.ok !== true) {
                panel.querySelectorAll('.pc-assign-apply, .pc-cut-apply-recommended').forEach(function (el) {
                    el.disabled = false;
                });
                msg.hidden = false;
                msg.className = 'pc-assign-msg is-error';
                msg.textContent = (result.data && result.data.error) ? result.data.error : copy.error;
                return;
            }
            window.location.reload();
        }).catch(function () {
            panel.querySelectorAll('.pc-assign-apply, .pc-cut-apply-recommended').forEach(function (el) {
                el.disabled = false;
            });
            msg.hidden = false;
            msg.className = 'pc-assign-msg is-error';
            msg.textContent = copy.error;
        });
    }

    document.querySelectorAll('.pc-assign-open, .pc-cut-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.getAttribute('aria-expanded') === 'true') {
                closePanel();
                return;
            }
            var mode = btn.classList.contains('pc-cut-open') ? 'cut' : 'assign';
            var pool = currentPool(btn);
            var body = new URLSearchParams();
            body.set('action', mode === 'cut' ? 'cut_preview' : 'assign_preview');
            body.set('csrf_token', csrfToken());
            body.set('week', form.querySelector('input[name="week"]').value);
            body.set('date', form.querySelector('input[name="date"]').value);
            body.set('delivery_date', btn.getAttribute('data-date'));
            body.set('product_id', btn.getAttribute('data-product-id'));
            body.set('pool', String(pool));
            fetch('production_center.php?date=' + encodeURIComponent(btn.getAttribute('data-date')), {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (res) {
                return res.json().then(function (data) { return { okHttp: res.ok, data: data }; });
            }).then(function (result) {
                if (!result.data || result.data.ok !== true) {
                    window.alert((result.data && result.data.error) ? result.data.error : copy.error);
                    return;
                }
                result.data.pool = pool;
                renderPanel(btn, result.data, mode);
            }).catch(function () {
                window.alert(copy.error);
            });
        });
    });
})();
</script>
<script>
(function () {
    var dateField = document.querySelector('#pc-plan-form input[name="date"]');
    var csrfField = document.querySelector('#pc-plan-form input[name="csrf_token"]');
    if (!dateField || !csrfField) return;
    var formulaDialog = document.getElementById('pc-formula-dialog');
    var storeDialog = document.getElementById('pc-store-dialog');
    var formulaBody = document.getElementById('pc-formula-body');
    var storeBody = document.getElementById('pc-store-body');
    var copy = {
        formulaTitle: <?php echo json_encode(bakery_t('production_center.formula_title')); ?>,
        formulaEmpty: <?php echo json_encode(bakery_t('production_center.formula_empty')); ?>,
        pct: <?php echo json_encode(bakery_t('production_center.formula_pct')); ?>,
        storeTitle: <?php echo json_encode(bakery_t('production_center.store_demand_title')); ?>,
        storeLead: <?php echo json_encode(bakery_t('production_center.store_demand_lead')); ?>,
        storeZone: <?php echo json_encode(bakery_t('production_center.store_demand_zone')); ?>,
        storeGoing: <?php echo json_encode(bakery_t('production_center.store_demand_going')); ?>,
        storeQty: <?php echo json_encode(bakery_t('production_center.store_demand_qty')); ?>,
        storeLocked: <?php echo json_encode(bakery_t('production_center.store_demand_locked')); ?>,
        storeSave: <?php echo json_encode(bakery_t('production_center.store_demand_save')); ?>,
        storeSaving: <?php echo json_encode(bakery_t('production_center.store_demand_saving')); ?>,
        storeSaved: <?php echo json_encode(bakery_t('production_center.store_demand_saved')); ?>,
        customer: <?php echo json_encode(bakery_t('production_center.assign_customer')); ?>,
        error: <?php echo json_encode(bakery_t('production_center.assign_error')); ?>
    };

    function post(action, extra) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('csrf_token', csrfField.value);
        body.set('date', dateField.value);
        Object.keys(extra || {}).forEach(function (key) {
            body.set(key, String(extra[key]));
        });
        return fetch('production_center.php?date=' + encodeURIComponent(dateField.value), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-TOKEN': csrfField.value
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (res) {
            return res.json().then(function (data) {
                return { okHttp: res.ok, data: data };
            });
        });
    }

    function openFormula(productId, pieces) {
        if (!formulaDialog || !formulaBody) return;
        formulaBody.textContent = '…';
        if (formulaDialog.showModal) formulaDialog.showModal();
        post('product_formula', { product_id: productId, pieces: pieces || 0 }).then(function (result) {
            if (!result.data || !result.data.ok) {
                formulaBody.textContent = (result.data && result.data.error) ? result.data.error : copy.error;
                return;
            }
            var f = result.data.formula;
            var html = '<h3>' + copy.formulaTitle + ' — ' + String(f.product || '') + '</h3>';
            if (f.dough) html += '<p>' + copy.formulaTitle + ': ' + String(f.dough) + '</p>';
            if (f.dough_grams > 0) {
                html += '<p>' + String(f.pieces) + ' × ' + String(f.piece_weight_grams) + ' g = ' + String(f.dough_grams) + ' g</p>';
            }
            if (!f.lines || !f.lines.length) {
                html += '<p>' + copy.formulaEmpty + '</p>';
            } else {
                html += '<ul class="pc-formula-list">';
                f.lines.forEach(function (line) {
                    html += '<li><span>' + String(line.name) + '</span><strong>' + String(line.percentage) + copy.pct;
                    if (line.grams > 0) html += ' · ' + String(line.grams) + ' g';
                    html += '</strong></li>';
                });
                html += '</ul>';
            }
            formulaBody.innerHTML = html;
        }).catch(function () {
            formulaBody.textContent = copy.error;
        });
    }

    function jsonError(result) {
        if (result && result.data) {
            if (result.data.error) return result.data.error;
            if (result.data.ok === false) return copy.error;
        }
        return copy.error;
    }

    function updateBakeDemand(productId, demandTotal) {
        var btn = document.querySelector('.pc-store-demand-open[data-product-id="' + String(productId) + '"]');
        if (!btn) return;
        btn.textContent = Number(demandTotal).toLocaleString();
        var row = btn.closest('tr');
        if (!row) return;
        var piecesInput = row.querySelector('.pc-plan-input');
        var gapCell = row.querySelector('td.pc-short, td.pc-covered');
        if (!piecesInput || !gapCell) return;
        var pieces = parseInt(String(piecesInput.value), 10);
        if (isNaN(pieces)) return;
        var gap = pieces - Number(demandTotal);
        gapCell.textContent = (gap > 0 ? '+' : '') + Number(gap).toLocaleString();
        gapCell.className = gap < 0 ? 'pc-short' : 'pc-covered';
    }

    function renderStore(data) {
        var html = '<h3>' + copy.storeTitle + ' — ' + String(data.product_name || '') + '</h3>';
        html += '<p>' + copy.storeLead + '</p>';
        var rows = data.customers || [];
        if (!rows.length) {
            html += '<p>' + copy.error + '</p>';
            storeBody.innerHTML = html;
            return;
        }
        html += '<form id="pc-store-form">';
        html += '<table class="pc-assign-table"><thead><tr><th>' + copy.customer + '</th><th>' + copy.storeZone + '</th><th>' + copy.storeGoing + '</th><th>' + copy.storeQty + '</th></tr></thead><tbody>';
        rows.forEach(function (row) {
            var locked = !!row.locked;
            html += '<tr><td>' + String(row.name || '') + '</td><td>' + String(row.zone || '—') + '</td><td>' + String(row.driver_name || '—') + '</td><td>';
            if (locked) {
                html += String(row.quantity) + ' <small>' + copy.storeLocked + '</small>';
            } else {
                html += '<input class="pc-store-qty" type="number" min="0" step="1" inputmode="numeric" data-customer-id="' + String(row.id) + '" data-product-id="' + String(data.product_id) + '" data-saved="' + String(row.quantity) + '" value="' + String(row.quantity) + '">';
            }
            html += '</td></tr>';
        });
        html += '</tbody></table>';
        html += '<p id="pc-store-status" class="pc-store-status"></p>';
        html += '<button type="submit" class="button" id="pc-store-save">' + copy.storeSave + '</button>';
        html += '</form>';
        storeBody.innerHTML = html;
        var form = storeBody.querySelector('#pc-store-form');
        var statusEl = storeBody.querySelector('#pc-store-status');
        var saveBtn = storeBody.querySelector('#pc-store-save');
        var saving = false;

        function setStatus(text) {
            if (statusEl) statusEl.textContent = text || '';
        }

        function saveInput(input) {
            var qty = String(input.value).trim();
            if (!/^\d+$/.test(qty)) {
                setStatus(copy.error);
                return Promise.resolve(false);
            }
            if (String(input.getAttribute('data-saved')) === qty) {
                return Promise.resolve(true);
            }
            input.disabled = true;
            return post('save_store_demand', {
                product_id: input.getAttribute('data-product-id'),
                customer_id: input.getAttribute('data-customer-id'),
                quantity: qty,
                pool: '0'
            }).then(function (result) {
                input.disabled = false;
                if (!result.okHttp || !result.data || !result.data.ok) {
                    setStatus(jsonError(result));
                    return false;
                }
                input.setAttribute('data-saved', String(result.data.saved_quantity != null ? result.data.saved_quantity : qty));
                input.value = input.getAttribute('data-saved');
                if (result.data.demand_total != null) {
                    updateBakeDemand(input.getAttribute('data-product-id'), result.data.demand_total);
                }
                setStatus(result.data.notice || copy.storeSaved);
                return true;
            }).catch(function () {
                input.disabled = false;
                setStatus(copy.error);
                return false;
            });
        }

        Array.prototype.slice.call(storeBody.querySelectorAll('.pc-store-qty')).forEach(function (input) {
            input.addEventListener('change', function () {
                saveInput(input);
            });
        });
        if (form) {
            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                if (saving) return;
                var inputs = Array.prototype.slice.call(storeBody.querySelectorAll('.pc-store-qty'));
                var dirty = inputs.filter(function (input) {
                    return String(input.value).trim() !== String(input.getAttribute('data-saved'));
                });
                if (!dirty.length) {
                    setStatus(copy.storeSaved);
                    return;
                }
                saving = true;
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.textContent = copy.storeSaving;
                }
                var chain = Promise.resolve(true);
                dirty.forEach(function (input) {
                    chain = chain.then(function (ok) {
                        if (!ok) return false;
                        return saveInput(input);
                    });
                });
                chain.then(function () {
                    saving = false;
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = copy.storeSave;
                    }
                });
            });
        }
    }

    function openStore(productId, pool) {
        if (!storeDialog || !storeBody || !productId) return;
        storeBody.textContent = '…';
        if (storeDialog.showModal) storeDialog.showModal();
        post('store_demand', { product_id: productId, pool: pool || 0 }).then(function (result) {
            if (!result.data || !result.data.ok) {
                storeBody.textContent = (result.data && result.data.error) ? result.data.error : copy.error;
                return;
            }
            renderStore(result.data);
        }).catch(function () {
            storeBody.textContent = copy.error;
        });
    }

    document.querySelectorAll('.pc-product-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openFormula(btn.getAttribute('data-product-id'), btn.getAttribute('data-pieces'));
        });
    });
    document.querySelectorAll('.pc-store-demand-open, .pc-store-demand-heading').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pid = btn.getAttribute('data-product-id');
            var pool = btn.getAttribute('data-pool') || '0';
            openStore(pid, pool);
        });
    });
})();
</script>
<?php require_once 'includes/footer.php'; ?>
