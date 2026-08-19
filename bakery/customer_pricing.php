<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_portal.php';
bakery_ensure_portal_schema($db);

$page_title = bakery_t('page.customer_pricing');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prices') {
    header('Content-Type: application/json');
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $prices = json_decode($_POST['prices'] ?? '[]', true);
    if ($customerId <= 0 || !is_array($prices)) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $upsert = $db->prepare(
        'INSERT INTO customer_product_prices (customer_id, product_id, unit_price)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price)'
    );
    $delete = $db->prepare(
        'DELETE FROM customer_product_prices WHERE customer_id = ? AND product_id = ?'
    );

    foreach ($prices as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        $price = trim((string)($row['unit_price'] ?? ''));
        if ($productId <= 0) {
            continue;
        }
        if ($price === '') {
            $delete->execute([$customerId, $productId]);
        } else {
            $upsert->execute([$customerId, $productId, (float)$price]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

$customers = $db->query(
    "SELECT id, name, pricing_tier FROM customers WHERE is_active = 1 ORDER BY name"
)->fetchAll();

$selectedId = (int)($_GET['customer_id'] ?? ($customers[0]['id'] ?? 0));
$selectedCustomer = null;
foreach ($customers as $c) {
    if ((int)$c['id'] === $selectedId) {
        $selectedCustomer = $c;
        break;
    }
}

$products = $db->query(
    'SELECT p.id, p.name, p.price, pl.name AS product_line_name
     FROM products p
     LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
     LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY pl.name, p.name'
)->fetchAll();

$customPrices = [];
if ($selectedId > 0) {
    $cp = $db->prepare('SELECT product_id, unit_price FROM customer_product_prices WHERE customer_id = ?');
    $cp->execute([$selectedId]);
    foreach ($cp->fetchAll() as $row) {
        $customPrices[(int)$row['product_id']] = $row['unit_price'];
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="container" style="max-width:960px;margin:0 auto;padding:20px;">
  <h1>Customer Custom Pricing</h1>
  <p style="color:#666;margin-bottom:20px;">Set per-product prices for customers on the <strong>Custom</strong> pricing tier. Leave blank to use retail price as fallback.</p>

  <div style="margin-bottom:20px;">
    <label style="font-weight:600;display:block;margin-bottom:6px;">Customer</label>
    <select id="customerSelect" style="min-width:280px;padding:8px;">
      <?php foreach ($customers as $c): ?>
        <option value="<?php echo (int)$c['id']; ?>" <?php echo (int)$c['id'] === $selectedId ? 'selected' : ''; ?>>
          <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars(bakery_pricing_tier_label($c['pricing_tier'] ?? 'retail')); ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <?php if ($selectedCustomer && ($selectedCustomer['pricing_tier'] ?? '') !== 'custom'): ?>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:16px;">
      This customer is on <strong><?php echo htmlspecialchars(bakery_pricing_tier_label($selectedCustomer['pricing_tier'])); ?></strong> pricing.
      Change their tier to Custom in <a href="customers.php">Customers</a> to use these overrides in the catalog.
    </div>
  <?php endif; ?>

  <table style="width:100%;border-collapse:collapse;background:#fff;">
    <thead>
      <tr style="background:#faf6f1;text-align:left;">
        <th style="padding:10px;border-bottom:1px solid #e8ddd2;">Product</th>
        <th style="padding:10px;border-bottom:1px solid #e8ddd2;">Retail</th>
        <th style="padding:10px;border-bottom:1px solid #e8ddd2;">Custom price</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($products as $product): ?>
        <tr data-product-id="<?php echo (int)$product['id']; ?>">
          <td style="padding:10px;border-bottom:1px solid #eee;">
            <?php if ($product['product_line_name']): ?>
              <small style="color:#888;"><?php echo htmlspecialchars($product['product_line_name']); ?></small><br>
            <?php endif; ?>
            <?php echo htmlspecialchars($product['name']); ?>
          </td>
          <td style="padding:10px;border-bottom:1px solid #eee;">$<?php echo number_format((float)$product['price'], 2); ?></td>
          <td style="padding:10px;border-bottom:1px solid #eee;">
            <input type="number" class="custom-price" step="0.01" min="0" style="width:100px;padding:6px;"
                   value="<?php echo isset($customPrices[(int)$product['id']]) ? htmlspecialchars($customPrices[(int)$product['id']]) : ''; ?>"
                   placeholder="—">
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:18px;">
    <button type="button" id="saveBtn" class="btn-primary" style="padding:10px 20px;">Save prices</button>
    <span id="saveStatus" style="margin-left:12px;color:#666;"></span>
  </div>
</div>

<script>
(function () {
  var customerSelect = document.getElementById('customerSelect');
  var saveBtn = document.getElementById('saveBtn');
  var saveStatus = document.getElementById('saveStatus');

  customerSelect.addEventListener('change', function () {
    window.location.href = 'customer_pricing.php?customer_id=' + encodeURIComponent(customerSelect.value);
  });

  saveBtn.addEventListener('click', function () {
    var prices = [];
    document.querySelectorAll('tbody tr[data-product-id]').forEach(function (row) {
      prices.push({
        product_id: row.getAttribute('data-product-id'),
        unit_price: row.querySelector('.custom-price').value.trim()
      });
    });
    saveStatus.textContent = 'Saving…';
    var body = new URLSearchParams({
      action: 'save_prices',
      customer_id: customerSelect.value,
      prices: JSON.stringify(prices)
    });
    fetch('customer_pricing.php', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        saveStatus.textContent = res.success ? 'Saved!' : (res.error || 'Failed');
      })
      .catch(function () { saveStatus.textContent = 'Network error'; });
  });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
