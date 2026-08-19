<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = bakery_t('page.pan_dulce_quantities');
$message = null;
$error = null;

try {
    $db->exec("CREATE TABLE IF NOT EXISTS pan_dulce_quantity_standards (
        dough_type_id INT NOT NULL PRIMARY KEY,
        standard_quantity INT NOT NULL DEFAULT 12,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pan_dulce_quantity_standards_dough_type
            FOREIGN KEY (dough_type_id) REFERENCES dough_types(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pan_dulce_product_quantity_standards (
        product_id INT NOT NULL PRIMARY KEY,
        standard_quantity INT NOT NULL DEFAULT 12,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_pan_dulce_product_quantity_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $quantities = $_POST['standard_quantity'] ?? [];
        $stmt = $db->prepare("INSERT INTO pan_dulce_product_quantity_standards (product_id, standard_quantity)
            VALUES (?, ?) ON DUPLICATE KEY UPDATE standard_quantity = VALUES(standard_quantity)");
        foreach ($quantities as $productId => $quantity) {
            $quantity = (int)$quantity;
            if ($quantity < 0 || $quantity > 1000) {
                throw new Exception('Standard quantities must be between 0 and 1000.');
            }
            $stmt->execute([(int)$productId, $quantity]);
        }
        $message = 'Pan Dulce standard quantities saved.';
    }

    $types = $db->query("SELECT p.id AS product_id, p.name AS product_name,
        dt.name AS dough_type_name, COALESCE(s.standard_quantity, 12) AS standard_quantity
        FROM products p
        JOIN dough_types dt ON dt.id = p.dough_type_id
        JOIN product_lines pl ON pl.id = dt.product_line_id AND pl.name = 'Pan Dulce'
        LEFT JOIN pan_dulce_product_quantity_standards s ON s.product_id = p.id
        ORDER BY dt.name, p.name")->fetchAll();
} catch (Exception $e) {
    $error = $e->getMessage();
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<div class="container" style="max-width:760px; margin:0 auto; padding:20px;">
    <h1>Pan Dulce Standard Quantities</h1>
    <p>Set the default quantity for each Pan Dulce product. Use <strong>0</strong> to exclude a product from standard apply. Daily Orders offers 1×, 1.5×, and 2× of each product’s amount.</p>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" novalidate>
        <?php echo bakery_csrf_field(); ?>
        <table class="items-table">
            <thead><tr><th>Dough Type</th><th>Product</th><th>Standard quantity</th></tr></thead>
            <tbody>
            <?php $lastDoughType = null; foreach ($types ?? [] as $type): ?>
                <?php if ($lastDoughType !== $type['dough_type_name']): $lastDoughType = $type['dough_type_name']; ?>
                    <tr class="dough-group-row"><th colspan="3"><?= htmlspecialchars($lastDoughType) ?></th></tr>
                <?php endif; ?>
                <tr>
                    <td class="dough-type-repeat"><?= htmlspecialchars($type['dough_type_name']) ?></td>
                    <td><strong><?= htmlspecialchars($type['product_name']) ?></strong></td>
                    <td><input class="standard-quantity-input" type="number" min="0" max="1000" step="1" inputmode="numeric" name="standard_quantity[<?= (int)$type['product_id'] ?>]" value="<?= (int)$type['standard_quantity'] ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (empty($types)): ?><p>No Pan Dulce dough types are configured yet.</p><?php else: ?><button class="btn btn-primary" type="submit">Save Standard Quantities</button><?php endif; ?>
    </form>
</div>
<style>
.dough-group-row th{padding:9px 10px;background:#f4ebe5;color:#5d3b2d;text-align:left;font-size:.95rem}.dough-type-repeat{color:#6d7771;font-size:.9rem}@media(max-width:600px){.items-table th,.items-table td{padding:9px 7px;vertical-align:top}.standard-quantity-input{width:90px;min-height:42px;font-size:16px}}
</style>
<?php require_once 'includes/footer.php'; ?>
