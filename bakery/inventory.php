<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/operational_exceptions.php';

$selectedDate = $_GET['date'] ?? $_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day'));
try { $selectedDate = bakery_inventory_validate_date((string)$selectedDate); } catch (Throwable $e) { $selectedDate = date('Y-m-d', strtotime('+1 day')); }
$attentionShortfall = (string)($_GET['attention'] ?? '') === 'shortfall';
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$attentionLabel = $attentionShortfall ? 'Showing products with finished-goods shortfall' : '';
$notice = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_count') {
    try {
        if (!bakery_inventory_ready($db)) throw new RuntimeException('Finished-goods inventory is not installed. Run the database migrations first.');
        $db->beginTransaction();
        bakery_inventory_set_count($db, $selectedDate, (int)$_POST['product_id'], (int)$_POST['quantity'], trim((string)($_POST['notes'] ?? 'Admin count')));
        $db->commit();
        $notice = 'Count saved. Available inventory has been set for this delivery day.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

$page_title = bakery_t('page.inventory');
require_once 'includes/header.php';
require_once 'includes/nav.php';

$products = [];
if (bakery_inventory_ready($db)) {
    $stmt = $db->prepare(
        "SELECT p.id, p.name,
                COALESCE(SUM(CASE WHEN do.status <> 'cancelled' THEN doi.quantity ELSE 0 END), 0) AS ordered_quantity,
                COALESCE(inv.available_quantity, 0) AS available_quantity,
                COALESCE(inv.produced_quantity, 0) AS produced_quantity,
                inv.counted_quantity,
                COALESCE(inv.loaded_quantity, 0) AS loaded_quantity
         FROM products p
         LEFT JOIN daily_order_items doi ON doi.product_id = p.id
         LEFT JOIN daily_orders do ON do.id = doi.daily_order_id AND do.order_date = ?
         LEFT JOIN product_inventory_days inv ON inv.product_id = p.id AND inv.delivery_date = ?
         GROUP BY p.id, p.name, inv.available_quantity, inv.produced_quantity, inv.counted_quantity, inv.loaded_quantity
         ORDER BY p.name"
    );
    $stmt->execute([$selectedDate, $selectedDate]);
    $products = $stmt->fetchAll();
} else {
    $error = $error ?: 'Finished-goods inventory is not installed. Run scripts/run_migrations.php first.';
}
?>
<main class="inventory-page container">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <div class="inventory-heading">
        <div><h1>Finished Goods Inventory</h1><p>Set what is available for a delivery day, then load drivers against it.</p></div>
        <a class="btn btn-primary" href="driver_load.php?date=<?php echo urlencode($selectedDate); ?>">Assign driver pickups</a>
    </div>
    <?php if ($notice): ?><div class="inventory-notice success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="inventory-notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="get" class="inventory-date"><label>Delivery day <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>"></label><button class="btn btn-outline">View day</button></form>
    <div class="inventory-summary">
        <span><strong><?php echo date('l, M j', strtotime($selectedDate)); ?></strong> delivery inventory</span>
        <span>Production adds units · count sets remaining warehouse units · loads reserve units for drivers.</span>
    </div>
    <div class="inventory-table-wrap"><table class="inventory-table">
        <thead><tr><th>Product</th><th>Orders</th><th>Produced</th><th>Loaded</th><th>Available now</th><th>Coverage</th><th>Admin count / set available</th></tr></thead>
        <tbody>
        <?php foreach ($products as $product):
            $stock = (int)$product['available_quantity'] + (int)$product['loaded_quantity'];
            $gap = max(0, (int)$product['ordered_quantity'] - $stock);
            if ($attentionShortfall && $gap === 0) {
                continue;
            } ?>
            <tr>
                <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                <td><?php echo number_format($product['ordered_quantity']); ?></td>
                <td><?php echo number_format($product['produced_quantity']); ?></td>
                <td><?php echo number_format($product['loaded_quantity']); ?></td>
                <td><strong><?php echo number_format($product['available_quantity']); ?></strong></td>
                <td class="<?php echo $gap ? 'coverage-gap' : 'coverage-good'; ?>"><?php echo $gap ? $gap . ' short' : 'Covered'; ?></td>
                <td><form method="post" class="count-form"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="set_count"><input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($selectedDate); ?>"><input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>"><input type="number" min="0" step="1" name="quantity" value="<?php echo $product['counted_quantity'] === null ? (int)$product['available_quantity'] : (int)$product['counted_quantity']; ?>" aria-label="Available count for <?php echo htmlspecialchars($product['name']); ?>"><button class="btn btn-outline" type="submit">Set</button></form></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="7" class="empty-state">No products found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</main>
<style>
.inventory-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:24px 0 14px}.inventory-heading h1{margin:0}.inventory-heading p{margin:5px 0 0;color:#62706a}.inventory-date,.count-form{display:flex;align-items:center;gap:8px}.inventory-date{margin:16px 0}.inventory-date input,.count-form input{padding:7px;border:1px solid #cbd4cf;border-radius:5px}.inventory-summary{display:flex;justify-content:space-between;gap:16px;background:#eef6f0;border-left:4px solid #2e7d4b;padding:12px 15px;margin-bottom:16px}.inventory-summary span:last-child{color:#56655d}.inventory-table-wrap{overflow:auto}.inventory-table{width:100%;border-collapse:collapse;background:#fff}.inventory-table th,.inventory-table td{padding:12px;border-bottom:1px solid #e2e8e4;text-align:left;white-space:nowrap}.inventory-table th{background:#f5f8f6;color:#48564e}.coverage-good{color:#21713f;font-weight:700}.coverage-gap{color:#b42c2c;font-weight:700}.inventory-notice{padding:11px 14px;border-radius:6px;margin:12px 0}.inventory-notice.success{background:#e7f6ea;color:#1d6534}.inventory-notice.error{background:#fdecec;color:#9b2525}@media(max-width:720px){.inventory-heading,.inventory-summary{align-items:flex-start;flex-direction:column}.inventory-heading .btn{width:100%;text-align:center}}
</style>
<?php require_once 'includes/footer.php'; ?>
