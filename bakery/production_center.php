<?php
/** Weekly planning workspace. This complements the existing production.php page. */
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';

function production_center_week_start(string $value): string {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) $date = new DateTime('monday this week');
    $date->modify('monday this week');
    return $date->format('Y-m-d');
}

$weekStart = production_center_week_start((string)($_GET['week'] ?? $_POST['week'] ?? date('Y-m-d')));
$weekDates = [];
for ($offset = 0; $offset < 7; $offset++) $weekDates[] = date('Y-m-d', strtotime($weekStart . " +{$offset} days"));
$weekEnd = end($weekDates);
$showAll = ($_GET['show_all'] ?? '') === '1';
$planTableReady = table_exists($db, 'production_plan_items');
$inventoryReady = bakery_inventory_ready($db);
$notice = '';
$error = '';

// Use the same product-line visibility rules as the trusted daily production page.
$bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
$productClause = '';
if (is_array($bakerProductIds)) {
    $productClause = empty($bakerProductIds) ? ' WHERE 1 = 0' : ' WHERE p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
}
$productStmt = $db->prepare("SELECT p.id, p.name, p.weight_grams, dt.name AS dough_type_name FROM products p LEFT JOIN dough_types dt ON dt.id = p.dough_type_id {$productClause} ORDER BY dt.name, p.name");
$productStmt->execute($bakerProductIds ?? []);
$products = $productStmt->fetchAll();
$productIds = array_map(static fn($product) => (int)$product['id'], $products);
$allowedProductIds = array_fill_keys($productIds, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plan') {
    try {
        if (!$planTableReady) throw new RuntimeException('Saved production plans are not installed yet. Run scripts/run_migrations.php first.');
        if (production_center_week_start((string)($_POST['week'] ?? '')) !== $weekStart) throw new InvalidArgumentException('The production week changed. Reload the page and try again.');
        $planned = $_POST['planned'] ?? [];
        if (!is_array($planned)) throw new InvalidArgumentException('Invalid production plan.');
        $save = $db->prepare('INSERT INTO production_plan_items (delivery_date, product_id, planned_quantity, created_by_user_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE planned_quantity = VALUES(planned_quantity), created_by_user_id = VALUES(created_by_user_id)');
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $saved = 0;
        $db->beginTransaction();
        foreach ($planned as $date => $productQuantities) {
            if (!in_array($date, $weekDates, true) || !is_array($productQuantities)) throw new InvalidArgumentException('A submitted plan item is outside the selected week.');
            foreach ($productQuantities as $productId => $quantity) {
                $productId = (int)$productId;
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
                if (!isset($allowedProductIds[$productId]) || $quantity === false || $quantity < 0) throw new InvalidArgumentException('Batch targets must be whole numbers of zero or more.');
                $save->execute([$date, $productId, $quantity, $user['id'] ?? null]);
                $saved++;
            }
        }
        $db->commit();
        $notice = "Saved {$saved} production target" . ($saved === 1 ? '' : 's') . '.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$standingByWeekday = $actualByDate = $actualDayExists = $inventoryByDate = $plansByDate = [];
try {
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $standingStmt = $db->prepare("SELECT so.day_of_week, so.product_id, SUM(so.quantity) AS quantity FROM standing_orders so WHERE so.product_id IN ({$placeholders}) GROUP BY so.day_of_week, so.product_id");
        $standingStmt->execute($productIds);
        foreach ($standingStmt->fetchAll() as $row) $standingByWeekday[(int)$row['day_of_week']][(int)$row['product_id']] = (int)$row['quantity'];

        // Detect real orders independently of the viewer's product-line scope.
        // This matches production.php: a generated daily order replaces standing demand for that date.
        $actualDayStmt = $db->prepare('SELECT DISTINCT do.order_date FROM daily_orders do JOIN daily_order_items doi ON doi.daily_order_id = do.id WHERE do.order_date BETWEEN ? AND ?');
        $actualDayStmt->execute([$weekStart, $weekEnd]);
        foreach ($actualDayStmt->fetchAll(PDO::FETCH_COLUMN) as $actualDate) $actualDayExists[$actualDate] = true;

        $actualStmt = $db->prepare("SELECT do.order_date, doi.product_id, SUM(doi.quantity) AS quantity FROM daily_orders do JOIN daily_order_items doi ON doi.daily_order_id = do.id WHERE do.order_date BETWEEN ? AND ? AND doi.product_id IN ({$placeholders}) GROUP BY do.order_date, doi.product_id");
        $actualStmt->execute(array_merge([$weekStart, $weekEnd], $productIds));
        foreach ($actualStmt->fetchAll() as $row) {
            $actualByDate[$row['order_date']][(int)$row['product_id']] = (int)$row['quantity'];
        }
        if ($inventoryReady) {
            $inventoryStmt = $db->prepare("SELECT delivery_date, product_id, available_quantity, produced_quantity, loaded_quantity FROM product_inventory_days WHERE delivery_date BETWEEN ? AND ? AND product_id IN ({$placeholders})");
            $inventoryStmt->execute(array_merge([$weekStart, $weekEnd], $productIds));
            foreach ($inventoryStmt->fetchAll() as $row) $inventoryByDate[$row['delivery_date']][(int)$row['product_id']] = $row;
        }
        if ($planTableReady) {
            $planStmt = $db->prepare("SELECT delivery_date, product_id, planned_quantity FROM production_plan_items WHERE delivery_date BETWEEN ? AND ? AND product_id IN ({$placeholders})");
            $planStmt->execute(array_merge([$weekStart, $weekEnd], $productIds));
            foreach ($planStmt->fetchAll() as $row) $plansByDate[$row['delivery_date']][(int)$row['product_id']] = (int)$row['planned_quantity'];
        }
    }
} catch (Throwable $e) {
    $error = $error ?: ('Unable to load the production center: ' . $e->getMessage());
}

$days = [];
$totals = ['current_stock' => 0, 'produced' => 0, 'deliveries' => 0, 'projected' => 0, 'shortfall' => 0];
foreach ($weekDates as $date) {
    $weekday = (int)bakery_standing_day_from_date($date);
    $hasActualOrders = !empty($actualDayExists[$date]);
    $rows = [];
    $summary = ['deliveries' => 0, 'projected' => 0, 'shortfall' => 0];
    foreach ($products as $product) {
        $productId = (int)$product['id'];
        $standing = (int)($standingByWeekday[$weekday][$productId] ?? 0);
        $actual = (int)($actualByDate[$date][$productId] ?? 0);
        // Any created daily order turns that day's actual orders into the delivery source of truth.
        $deliveryForecast = $hasActualOrders ? $actual : $standing;
        $inventory = $inventoryByDate[$date][$productId] ?? [];
        $produced = (int)($inventory['produced_quantity'] ?? 0);
        $currentStock = (int)($inventory['available_quantity'] ?? 0) + (int)($inventory['loaded_quantity'] ?? 0);
        $hasPlan = isset($plansByDate[$date]) && array_key_exists($productId, $plansByDate[$date]);
        $target = $hasPlan ? (int)$plansByDate[$date][$productId] : $deliveryForecast;
        // A target represents the desired total stock for that delivery day; existing units are not double counted.
        $projectedStock = max($currentStock, $target);
        $afterDelivery = $projectedStock - $deliveryForecast;
        $shortfall = max(0, -$afterDelivery);
        if (!$showAll && $standing === 0 && $actual === 0 && $currentStock === 0 && $produced === 0 && !$hasPlan) continue;
        $rows[] = compact('productId', 'product', 'standing', 'actual', 'deliveryForecast', 'produced', 'currentStock', 'hasPlan', 'target', 'projectedStock', 'afterDelivery', 'shortfall');
        $summary['deliveries'] += $deliveryForecast;
        $summary['projected'] += $projectedStock;
        $summary['shortfall'] += $shortfall;
        $totals['current_stock'] += $currentStock;
        $totals['produced'] += $produced;
        $totals['deliveries'] += $deliveryForecast;
        $totals['projected'] += $projectedStock;
        $totals['shortfall'] += $shortfall;
    }
    $days[] = compact('date', 'hasActualOrders', 'rows', 'summary');
}

$page_title = 'Production Center';
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<main class="production-center container">
    <div class="pc-heading"><div><p class="pc-eyebrow">Weekly planning workspace</p><h1>Production Center</h1><p>Plan finished-goods targets across the week using standing orders, real orders, and what is already in stock.</p></div><a class="btn btn-outline" href="production.php?date=<?php echo urlencode($weekStart); ?>">Open classic daily production</a></div>
    <?php if ($notice): ?><div class="pc-notice success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="pc-notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!$planTableReady): ?><div class="pc-notice warning">Saved targets are unavailable until the Production Center migration is run. The planning view remains read-only.</div><?php endif; ?>
    <?php if (!$inventoryReady): ?><div class="pc-notice warning">Finished-goods inventory is unavailable, so stock and produced values are shown as zero until its migration is run.</div><?php endif; ?>
    <form method="get" class="pc-week-picker"><label>Week of <input type="date" name="week" value="<?php echo htmlspecialchars($weekStart); ?>"></label><?php if ($showAll): ?><input type="hidden" name="show_all" value="1"><?php endif; ?><button class="btn btn-outline" type="submit">View week</button><a href="production_center.php?week=<?php echo urlencode($weekStart); ?>&amp;show_all=<?php echo $showAll ? '0' : '1'; ?>" class="pc-text-link"><?php echo $showAll ? 'Hide inactive products' : 'Show all products'; ?></a></form>
    <section class="pc-summary" aria-label="Weekly production summary">
        <div><span>Current stock</span><strong><?php echo number_format($totals['current_stock']); ?></strong><small>available + loaded</small></div><div><span>Produced</span><strong><?php echo number_format($totals['produced']); ?></strong><small>recorded this week</small></div><div><span>Delivery forecast</span><strong><?php echo number_format($totals['deliveries']); ?></strong><small>real orders or standing</small></div><div><span>Projected stock</span><strong><?php echo number_format($totals['projected']); ?></strong><small>after plan targets</small></div><div class="<?php echo $totals['shortfall'] ? 'pc-short' : 'pc-covered'; ?>"><span>At-risk units</span><strong><?php echo number_format($totals['shortfall']); ?></strong><small><?php echo $totals['shortfall'] ? 'need a larger target' : 'all forecast covered'; ?></small></div>
    </section>
    <div class="pc-explainer"><strong>How the plan works:</strong> standing orders are the forecast until real daily orders exist. Once they do, real orders replace the standing target for that date. A batch target is the desired finished-goods total for the day, so units already in stock are not counted twice.</div>
    <form method="post" class="pc-plan-form">
        <?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="save_plan"><input type="hidden" name="week" value="<?php echo htmlspecialchars($weekStart); ?>">
        <?php foreach ($days as $day): ?>
            <section class="pc-day-card"><header class="pc-day-header"><div><h2><?php echo htmlspecialchars(date('l, M j', strtotime($day['date']))); ?></h2><span class="pc-source <?php echo $day['hasActualOrders'] ? 'real' : 'standing'; ?>"><?php echo $day['hasActualOrders'] ? 'Real orders' : 'Standing forecast'; ?></span></div><div class="pc-day-metrics"><span>Deliver <?php echo number_format($day['summary']['deliveries']); ?></span><span>Reach <?php echo number_format($day['summary']['projected']); ?></span><span class="<?php echo $day['summary']['shortfall'] ? 'pc-short' : 'pc-covered'; ?>"><?php echo $day['summary']['shortfall'] ? number_format($day['summary']['shortfall']) . ' short' : 'Covered'; ?></span></div></header>
            <?php if ($day['rows']): ?><div class="pc-table-wrap"><table class="pc-table"><thead><tr><th>Product</th><th>Standing</th><th>Real</th><th>Deliver</th><th>Produced</th><th>Current stock</th><th>Batch target</th><th>Reach</th><th>After delivery</th></tr></thead><tbody>
            <?php foreach ($day['rows'] as $row): ?><tr><td><strong><?php echo htmlspecialchars($row['product']['name']); ?></strong><?php if (!empty($row['product']['dough_type_name'])): ?><small><?php echo htmlspecialchars($row['product']['dough_type_name']); ?></small><?php endif; ?></td><td><?php echo number_format($row['standing']); ?></td><td class="<?php echo $day['hasActualOrders'] ? 'pc-real-value' : ''; ?>"><?php echo number_format($row['actual']); ?></td><td><strong><?php echo number_format($row['deliveryForecast']); ?></strong></td><td><?php echo number_format($row['produced']); ?></td><td><?php echo number_format($row['currentStock']); ?></td><td><input type="number" min="0" step="1" name="planned[<?php echo htmlspecialchars($day['date']); ?>][<?php echo (int)$row['productId']; ?>]" value="<?php echo (int)$row['target']; ?>" <?php echo !$planTableReady ? 'disabled' : ''; ?> aria-label="Batch target for <?php echo htmlspecialchars($row['product']['name']); ?> on <?php echo htmlspecialchars($day['date']); ?>"><small><?php echo $row['hasPlan'] ? 'saved target' : 'forecast target'; ?></small></td><td><?php echo number_format($row['projectedStock']); ?></td><td class="<?php echo $row['shortfall'] ? 'pc-short' : 'pc-covered'; ?>"><?php echo $row['afterDelivery'] >= 0 ? number_format($row['afterDelivery']) . ' left' : number_format(abs($row['afterDelivery'])) . ' short'; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php else: ?><p class="pc-empty">No standing orders, real orders, inventory, or saved targets for this day. <a href="production_center.php?week=<?php echo urlencode($weekStart); ?>&amp;show_all=1">Show all products</a> to plan ahead.</p><?php endif; ?></section>
        <?php endforeach; ?>
        <?php if ($planTableReady): ?><div class="pc-save-bar"><span>Batch targets are saved by delivery day and product.</span><button class="btn btn-primary" type="submit">Save weekly production plan</button></div><?php endif; ?>
    </form>
</main>
<style>
.production-center{max-width:1440px;padding-bottom:42px}.pc-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:28px 0 18px}.pc-heading h1{margin:0;color:#193b2a}.pc-heading p{margin:6px 0 0;color:#586b60;max-width:720px}.pc-eyebrow{color:#287449!important;font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:.76rem}.pc-notice{padding:12px 15px;border-radius:7px;margin:12px 0}.pc-notice.success{background:#e5f5e9;color:#195f35}.pc-notice.error{background:#fdeaea;color:#9f2727}.pc-notice.warning{background:#fff5dd;color:#80590d}.pc-week-picker{display:flex;align-items:center;gap:10px;margin:18px 0}.pc-week-picker input,.pc-table input{border:1px solid #cbd7cf;border-radius:5px;padding:8px;background:#fff}.pc-text-link{color:#246b43;font-weight:600}.pc-summary{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:12px;margin:18px 0}.pc-summary>div{background:#fff;border:1px solid #dce8df;border-radius:9px;padding:14px;box-shadow:0 1px 2px rgba(21,48,33,.04)}.pc-summary span,.pc-summary small{display:block;color:#64756a;font-size:.8rem}.pc-summary strong{display:block;font-size:1.55rem;color:#1d3f2c;margin:3px 0}.pc-explainer{background:#eef7ef;border-left:4px solid #398451;padding:13px 16px;color:#3f5948;margin:18px 0 22px}.pc-day-card{background:#fff;border:1px solid #dce8df;border-radius:10px;margin:16px 0;overflow:hidden}.pc-day-header{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:14px 18px;background:#f7faf7;border-bottom:1px solid #e1e9e2}.pc-day-header h2{font-size:1.08rem;margin:0 0 5px;color:#254632}.pc-source{font-size:.76rem;font-weight:700;padding:3px 8px;border-radius:999px}.pc-source.real{color:#0b5d87;background:#e1f2fa}.pc-source.standing{color:#735412;background:#fff3d3}.pc-day-metrics{display:flex;gap:16px;flex-wrap:wrap;font-size:.86rem;color:#506257}.pc-table-wrap{overflow:auto}.pc-table{width:100%;border-collapse:collapse;min-width:930px}.pc-table th,.pc-table td{padding:11px 13px;text-align:left;border-bottom:1px solid #edf1ed;vertical-align:middle}.pc-table th{color:#597064;background:#fbfcfb;font-size:.77rem;text-transform:uppercase;letter-spacing:.03em}.pc-table tr:last-child td{border-bottom:0}.pc-table td small{display:block;color:#77877c;font-size:.73rem;margin-top:3px}.pc-table input{width:78px;padding:6px}.pc-real-value{color:#0b668f;font-weight:700}.pc-covered{color:#247142!important;font-weight:700}.pc-short{color:#b72c2c!important;font-weight:700}.pc-empty{padding:18px;margin:0;color:#66786c}.pc-save-bar{position:sticky;bottom:12px;background:#193b2a;color:#fff;border-radius:9px;padding:13px 16px;display:flex;justify-content:space-between;align-items:center;gap:16px;box-shadow:0 6px 18px rgba(13,42,23,.2)}.pc-save-bar .btn{white-space:nowrap}@media(max-width:860px){.pc-summary{grid-template-columns:repeat(2,1fr)}.pc-heading,.pc-day-header{flex-direction:column;align-items:flex-start}.pc-day-metrics{gap:11px}.pc-save-bar{align-items:flex-start;flex-direction:column}.pc-save-bar .btn{width:100%}}@media(max-width:500px){.pc-summary{grid-template-columns:1fr}.pc-week-picker{align-items:flex-start;flex-direction:column}.pc-week-picker .btn{width:100%}}
</style>
<?php require_once 'includes/footer.php'; ?>
