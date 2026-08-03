<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';

$selectedDate = $_GET['date'] ?? $_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day'));
try { $selectedDate = bakery_inventory_validate_date((string)$selectedDate); } catch (Throwable $e) { $selectedDate = date('Y-m-d', strtotime('+1 day')); }
$selectedDriverId = (int)($_GET['driver_id'] ?? $_POST['driver_id'] ?? 0);
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_load') {
    try {
        if (!bakery_inventory_ready($db)) throw new RuntimeException('Finished-goods inventory is not installed. Run the database migrations first.');
        bakery_inventory_save_driver_load($db, $selectedDate, $selectedDriverId, $_POST['load'] ?? [], trim((string)($_POST['notes'] ?? '')));
        $notice = 'Driver pickup saved. Inventory has been reserved for this route.';
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$drivers = bakery_get_drivers($db);
if (!$selectedDriverId && $drivers) $selectedDriverId = (int)$drivers[0]['id'];
$page_title = 'Driver Pickup Loads';
require_once 'includes/header.php';
require_once 'includes/nav.php';

$products = [];
$driverName = '';
if (bakery_inventory_ready($db) && $selectedDriverId > 0) {
    $driverStmt = $db->prepare('SELECT name FROM drivers WHERE id = ?'); $driverStmt->execute([$selectedDriverId]); $driverName = (string)$driverStmt->fetchColumn();
    $stmt = $db->prepare(
        "SELECT p.id, p.name,
                COALESCE(required.required_quantity, 0) AS required_quantity,
                COALESCE(inv.available_quantity, 0) AS available_quantity,
                COALESCE(li.loaded_quantity, 0) AS loaded_quantity
         FROM products p
         LEFT JOIN (
            SELECT doi.product_id, SUM(doi.quantity) AS required_quantity
            FROM daily_order_assignments doa
            JOIN daily_orders do ON do.id = doa.daily_order_id
            JOIN daily_order_items doi ON doi.daily_order_id = do.id
            WHERE doa.driver_id = ? AND doa.delivery_date = ? AND doa.delivery_status <> 'cancelled'
            GROUP BY doi.product_id
         ) required ON required.product_id = p.id
         LEFT JOIN product_inventory_days inv ON inv.product_id = p.id AND inv.delivery_date = ?
         LEFT JOIN driver_loads dl ON dl.driver_id = ? AND dl.delivery_date = ?
         LEFT JOIN driver_load_items li ON li.driver_load_id = dl.id AND li.product_id = p.id
         WHERE required.required_quantity IS NOT NULL OR inv.id IS NOT NULL OR li.id IS NOT NULL
         ORDER BY p.name"
    );
    $stmt->execute([$selectedDriverId, $selectedDate, $selectedDate, $selectedDriverId, $selectedDate]);
    $products = $stmt->fetchAll();
}
?>
<main class="load-page container">
    <div class="load-heading"><div><h1>Driver Pickup Loads</h1><p>Start each route with its order quantities, then add extras or reduce a short item before the driver leaves.</p></div><a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($selectedDate); ?>">Inventory board</a></div>
    <?php if ($notice): ?><div class="load-notice success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="load-notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="get" class="load-selector"><label>Delivery day <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>"></label><label>Driver <select name="driver_id"><?php foreach ($drivers as $driver): ?><option value="<?php echo (int)$driver['id']; ?>" <?php echo (int)$driver['id'] === $selectedDriverId ? 'selected' : ''; ?>><?php echo htmlspecialchars($driver['name']); ?></option><?php endforeach; ?></select></label><button class="btn btn-outline">Open load sheet</button></form>
    <?php if ($driverName): ?>
    <form method="post" class="load-sheet">
        <?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="save_load"><input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($selectedDate); ?>"><input type="hidden" name="driver_id" value="<?php echo $selectedDriverId; ?>">
        <h2><?php echo htmlspecialchars($driverName); ?> · <?php echo date('D, M j', strtotime($selectedDate)); ?></h2>
        <p class="load-hint">Suggested is the total on this driver’s assigned customer orders. “Pickup” is the actual number put on the vehicle.</p>
        <table class="load-table"><thead><tr><th>Product</th><th>Suggested from orders</th><th>Available before this load</th><th>Pickup</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?><tr><td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td><td><?php echo number_format($product['required_quantity']); ?></td><td><?php echo number_format($product['available_quantity'] + $product['loaded_quantity']); ?></td><td><input type="number" min="0" step="1" name="load[<?php echo (int)$product['id']; ?>]" value="<?php echo (int)$product['loaded_quantity'] ?: (int)$product['required_quantity']; ?>"></td></tr><?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="4" class="empty-state">No assigned products or inventory for this driver/day yet.</td></tr><?php endif; ?>
        </tbody></table>
        <label class="load-notes">Load note (optional)<input name="notes" maxlength="500" placeholder="e.g. Added 2 extra country loaves"></label>
        <button class="btn btn-success" type="submit">Save pickup and reserve inventory</button>
    </form>
    <?php endif; ?>
</main>
<style>
.load-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;margin:24px 0 14px}.load-heading h1{margin:0}.load-heading p,.load-hint{color:#607067}.load-selector{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;background:#f6f8f7;border-radius:8px}.load-selector label,.load-notes{display:flex;flex-direction:column;gap:5px;font-weight:600}.load-selector input,.load-selector select,.load-notes input,.load-table input{padding:8px;border:1px solid #cbd4cf;border-radius:5px}.load-sheet{margin-top:18px}.load-sheet h2{margin-bottom:4px}.load-table{width:100%;border-collapse:collapse;background:#fff;margin:15px 0}.load-table th,.load-table td{padding:12px;border-bottom:1px solid #e2e8e4;text-align:left}.load-table th{background:#f2f6f3}.load-table input{width:95px}.load-notes{max-width:520px;margin:18px 0}.load-notice{padding:11px 14px;border-radius:6px;margin:12px 0}.load-notice.success{background:#e7f6ea;color:#1d6534}.load-notice.error{background:#fdecec;color:#9b2525}@media(max-width:680px){.load-heading{align-items:flex-start;flex-direction:column}.load-table{font-size:.9rem}.load-table th,.load-table td{padding:8px}.load-heading .btn{width:100%;text-align:center}}
</style>
<?php require_once 'includes/footer.php'; ?>
