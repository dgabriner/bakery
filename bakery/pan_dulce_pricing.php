<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = 'Pan Dulce Pricing by Zone';

function panDulceZoneClass(?string $zone): string
{
    $zone = $zone ?: 'No Zone';
    return 'zone-' . strtolower(str_replace([' ', '/'], ['-', '-'], $zone));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'update_price') {
        try {
            $customerId = (int)$_POST['customer_id'];
            $price = trim($_POST['price'] ?? '');

            if ($customerId <= 0) {
                throw new Exception('Invalid customer');
            }

            if ($price === '') {
                $priceValue = null;
            } else {
                $priceValue = round((float)$price, 2);
                if ($priceValue < 0 || $priceValue > 99.99) {
                    throw new Exception('Price must be between $0.00 and $99.99');
                }
            }

            $stmt = $db->prepare('UPDATE customers SET default_pan_dulce_price = ? WHERE id = ?');
            $stmt->execute([$priceValue, $customerId]);

            echo json_encode([
                'success' => true,
                'price' => $priceValue,
                'display' => $priceValue !== null ? '$' . number_format($priceValue, 2) : null,
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($_POST['action'] === 'update_standard_price') {
        try {
            $price = trim($_POST['price'] ?? '');
            if ($price === '') {
                throw new Exception('Standard price is required');
            }

            $priceValue = round((float)$price, 2);
            if ($priceValue < 0 || $priceValue > 99.99) {
                throw new Exception('Price must be between $0.00 and $99.99');
            }

            $stmt = $db->prepare("
                UPDATE products p
                JOIN dough_types dt ON p.dough_type_id = dt.id
                JOIN product_lines pl ON dt.product_line_id = pl.id
                SET p.price = ?
                WHERE pl.name = 'Pan Dulce'
            ");
            $stmt->execute([$priceValue]);
            $updatedCount = $stmt->rowCount();

            if ($updatedCount === 0) {
                throw new Exception('No Pan Dulce products found to update');
            }

            echo json_encode([
                'success' => true,
                'price' => $priceValue,
                'display' => '$' . number_format($priceValue, 2),
                'updated_count' => $updatedCount,
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

$customersByZone = [];
$standardPriceInfo = null;
$error = null;
$stats = ['total' => 0, 'custom' => 0, 'standard' => 0];

try {
    $stmt = $db->query("
        SELECT
            c.id,
            c.name,
            c.address,
            c.zone,
            c.default_pan_dulce_price
        FROM customers c
        ORDER BY
            CASE WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ_No Zone' ELSE c.zone END,
            c.name
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $zone = $row['zone'] ?: 'No Zone';
        if (!isset($customersByZone[$zone])) {
            $customersByZone[$zone] = [];
        }
        $customersByZone[$zone][] = $row;
        $stats['total']++;
        if ($row['default_pan_dulce_price'] !== null && $row['default_pan_dulce_price'] !== '') {
            $stats['custom']++;
        } else {
            $stats['standard']++;
        }
    }

    $priceStmt = $db->query("
        SELECT MIN(p.price) AS min_price, MAX(p.price) AS max_price, COUNT(*) AS product_count
        FROM products p
        JOIN dough_types dt ON p.dough_type_id = dt.id
        JOIN product_lines pl ON dt.product_line_id = pl.id
        WHERE pl.name = 'Pan Dulce'
    ");
    $standardPriceInfo = $priceStmt->fetch(PDO::FETCH_ASSOC);
    $standardPriceUnified = null;
    $standardPriceMixed = false;
    if ($standardPriceInfo && $standardPriceInfo['min_price'] !== null) {
        if ((float)$standardPriceInfo['min_price'] === (float)$standardPriceInfo['max_price']) {
            $standardPriceUnified = (float)$standardPriceInfo['min_price'];
        } else {
            $standardPriceMixed = true;
        }
    }
} catch (Exception $e) {
    $error = 'Error loading data: ' . htmlspecialchars($e->getMessage());
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<div class="container pan-dulce-pricing-page">
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <h1>🍞 Pan Dulce Pricing by Zone</h1>

    <div class="instruction-text">
        Set the default standard price for all Pan Dulce products below. Stores without a custom price use this rate on orders.
        Click a store price to override it for that location only.
    </div>

    <div class="standard-price-panel">
        <div class="standard-price-header">
            <h3>Default Standard Pricing</h3>
            <?php if ($standardPriceInfo && (int)$standardPriceInfo['product_count'] > 0): ?>
                <span class="standard-product-count">
                    <?php echo (int)$standardPriceInfo['product_count']; ?> Pan Dulce product<?php echo (int)$standardPriceInfo['product_count'] === 1 ? '' : 's'; ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($standardPriceMixed): ?>
            <p class="standard-price-note mixed">
                Products currently have mixed prices ($<?php echo number_format((float)$standardPriceInfo['min_price'], 2); ?>
                – $<?php echo number_format((float)$standardPriceInfo['max_price'], 2); ?>).
                Saving will set all Pan Dulce products to one price.
            </p>
        <?php else: ?>
            <p class="standard-price-note">
                Applies to all Pan Dulce products. Stores on standard pricing use this rate when orders are generated.
            </p>
        <?php endif; ?>
        <div class="standard-price-form">
            <label for="standard-price-input">Standard price</label>
            <div class="standard-price-controls">
                <span class="price-prefix">$</span>
                <input type="number"
                       id="standard-price-input"
                       step="0.01"
                       min="0"
                       max="99.99"
                       placeholder="0.00"
                       value="<?php echo $standardPriceUnified !== null ? htmlspecialchars(number_format($standardPriceUnified, 2, '.', '')) : ''; ?>"
                       <?php echo !$standardPriceInfo || (int)$standardPriceInfo['product_count'] === 0 ? 'disabled' : ''; ?>>
                <button type="button"
                        id="save-standard-price"
                        class="btn-save-standard"
                        <?php echo !$standardPriceInfo || (int)$standardPriceInfo['product_count'] === 0 ? 'disabled' : ''; ?>>
                    Save Standard Price
                </button>
            </div>
            <?php if (!$standardPriceInfo || (int)$standardPriceInfo['product_count'] === 0): ?>
                <p class="standard-price-note mixed">No Pan Dulce products found. Add products under the Pan Dulce product line first.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="pricing-summary">
        <div class="summary-card">
            <span class="summary-value"><?php echo $stats['total']; ?></span>
            <span class="summary-label">Total Stores</span>
        </div>
        <div class="summary-card custom">
            <span class="summary-value"><?php echo $stats['custom']; ?></span>
            <span class="summary-label">Custom Pricing</span>
        </div>
        <div class="summary-card standard">
            <span class="summary-value"><?php echo $stats['standard']; ?></span>
            <span class="summary-label">Standard Pricing</span>
        </div>
        <?php if ($standardPriceUnified !== null): ?>
            <div class="summary-card reference">
                <span class="summary-value" id="standard-price-display">$<?php echo number_format($standardPriceUnified, 2); ?></span>
                <span class="summary-label">Current Standard Price</span>
            </div>
        <?php elseif ($standardPriceMixed): ?>
            <div class="summary-card reference mixed">
                <span class="summary-value" id="standard-price-display">
                    $<?php echo number_format((float)$standardPriceInfo['min_price'], 2); ?>
                    – $<?php echo number_format((float)$standardPriceInfo['max_price'], 2); ?>
                </span>
                <span class="summary-label">Mixed Product Prices</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="filter-bar">
        <input type="search" id="store-search" placeholder="Search stores by name..." autocomplete="off">
        <label class="filter-toggle">
            <input type="checkbox" id="custom-only-filter">
            Show custom pricing only
        </label>
    </div>

    <div class="zone-legend">
        <h4>Zone Color Legend</h4>
        <div class="zone-colors">
            <div class="zone-color-item zone-centro"><span>Centro</span></div>
            <div class="zone-color-item zone-mission"><span>Mission</span></div>
            <div class="zone-color-item zone-ruta-sour-flour"><span>Ruta Sour Flour</span></div>
            <div class="zone-color-item zone-daly-city-san-mateo"><span>Daly City San Mateo</span></div>
            <div class="zone-color-item zone-north-bay"><span>North Bay</span></div>
            <div class="zone-color-item zone-east-bay"><span>East Bay</span></div>
            <div class="zone-color-item zone-no-zone"><span>No Zone</span></div>
        </div>
    </div>

    <div id="status-message" class="status-message" style="display:none;"></div>

    <?php if (empty($customersByZone)): ?>
        <div class="alert alert-info">No stores found.</div>
    <?php else: ?>
        <?php foreach ($customersByZone as $zoneName => $zoneCustomers):
            $zoneClass = panDulceZoneClass($zoneName);
            $zoneIcons = [
                'Centro' => '🏢',
                'Mission' => '🌮',
                'Ruta Sour Flour' => '🍞',
                'Daly City/San Mateo' => '🌉',
                'North Bay' => '🌲',
                'East Bay' => '🏔️',
                'No Zone' => '📍',
            ];
        ?>
            <div class="zone-group-block" data-zone="<?php echo htmlspecialchars($zoneName); ?>">
                <div class="zone-group-header <?php echo $zoneClass; ?>" onclick="toggleZoneGroup(this)">
                    <div class="zone-header-content">
                        <h3><?php echo ($zoneIcons[$zoneName] ?? '🗺️') . ' ' . htmlspecialchars($zoneName); ?></h3>
                        <span class="zone-customer-count"><?php echo count($zoneCustomers); ?> stores</span>
                    </div>
                    <span class="zone-toggle-icon">▼</span>
                </div>

                <div class="pricing-table-wrap">
                    <table class="pricing-table">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th>Address</th>
                                <th>Pan Dulce Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($zoneCustomers as $customer):
                                $hasCustom = $customer['default_pan_dulce_price'] !== null && $customer['default_pan_dulce_price'] !== '';
                                $priceValue = $hasCustom ? (float)$customer['default_pan_dulce_price'] : '';
                            ?>
                                <tr class="store-row"
                                    data-customer-id="<?php echo (int)$customer['id']; ?>"
                                    data-custom-pricing="<?php echo $hasCustom ? '1' : '0'; ?>"
                                    data-store-name="<?php echo htmlspecialchars(strtolower($customer['name'])); ?>">
                                    <td class="store-name"><?php echo htmlspecialchars($customer['name']); ?></td>
                                    <td class="store-address"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></td>
                                    <td class="price-cell">
                                        <button type="button"
                                                class="price-edit-btn <?php echo $hasCustom ? 'has-custom' : 'standard'; ?>"
                                                data-customer-id="<?php echo (int)$customer['id']; ?>"
                                                data-price="<?php echo $hasCustom ? htmlspecialchars(number_format($priceValue, 2, '.', '')) : ''; ?>"
                                                title="Click to edit price">
                                            <?php if ($hasCustom): ?>
                                                $<?php echo number_format($priceValue, 2); ?>
                                            <?php else: ?>
                                                <span class="standard-label">Standard pricing</span>
                                            <?php endif; ?>
                                        </button>
                                    </td>
                                    <td>
                                        <span class="pricing-badge <?php echo $hasCustom ? 'custom' : 'standard'; ?>">
                                            <?php echo $hasCustom ? 'Custom' : 'Standard'; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
.pan-dulce-pricing-page .instruction-text {
    background: #eef6ff;
    border: 1px solid #b8daff;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 20px;
    color: #004085;
}

.standard-price-panel {
    background: #fff;
    border: 1px solid #dee2e6;
    border-left: 4px solid #e74c3c;
    border-radius: 8px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.standard-price-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.standard-price-header h3 {
    margin: 0;
    font-size: 1.05rem;
    color: #2c3e50;
}

.standard-product-count {
    font-size: 0.85rem;
    color: #6c757d;
    background: #f8f9fa;
    padding: 4px 10px;
    border-radius: 12px;
}

.standard-price-note {
    margin: 0 0 14px;
    font-size: 0.9rem;
    color: #6c757d;
}

.standard-price-note.mixed {
    color: #856404;
}

.standard-price-form label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
}

.standard-price-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
}

.price-prefix {
    font-size: 1.1rem;
    font-weight: 600;
    color: #495057;
}

#standard-price-input {
    width: 120px;
    padding: 10px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 1rem;
}

#standard-price-input:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.15);
}

#standard-price-input:disabled {
    background: #e9ecef;
    cursor: not-allowed;
}

.btn-save-standard {
    padding: 10px 18px;
    background: #e74c3c;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease;
}

.btn-save-standard:hover:not(:disabled) {
    background: #c0392b;
}

.btn-save-standard:disabled {
    background: #adb5bd;
    cursor: not-allowed;
}

.summary-card.reference.mixed .summary-value {
    font-size: 1.1rem;
}

.pricing-summary {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.summary-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 14px 20px;
    min-width: 140px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

.summary-card.custom { border-left: 4px solid #28a745; }
.summary-card.standard { border-left: 4px solid #6c757d; }
.summary-card.reference { border-left: 4px solid #e74c3c; }

.summary-value {
    display: block;
    font-size: 1.5rem;
    font-weight: 700;
    color: #2c3e50;
}

.summary-label {
    display: block;
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 4px;
}

.filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: center;
    margin-bottom: 20px;
}

#store-search {
    flex: 1;
    min-width: 220px;
    padding: 10px 14px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 0.95rem;
}

.filter-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    color: #495057;
    cursor: pointer;
    user-select: none;
}

.zone-legend {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 24px;
}

.zone-legend h4 {
    margin: 0 0 10px;
    font-size: 0.95rem;
    color: #495057;
}

.zone-colors {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.zone-color-item {
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
    color: #fff;
}

.zone-group-block {
    margin-bottom: 24px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.zone-group-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    cursor: pointer;
    color: #fff;
    user-select: none;
}

.zone-header-content {
    display: flex;
    align-items: center;
    gap: 16px;
}

.zone-group-header h3 {
    margin: 0;
    font-size: 1.1rem;
}

.zone-customer-count {
    font-size: 0.85rem;
    opacity: 0.9;
}

.zone-toggle-icon {
    font-size: 0.8rem;
    transition: transform 0.2s ease;
}

.zone-group-block.collapsed .zone-toggle-icon {
    transform: rotate(-90deg);
}

.zone-group-block.collapsed .pricing-table-wrap {
    display: none;
}

.zone-group-header.zone-centro { background: #007bff; }
.zone-group-header.zone-mission { background: #28a745; }
.zone-group-header.zone-ruta-sour-flour { background: #fd7e14; }
.zone-group-header.zone-daly-city-san-mateo { background: #6f42c1; }
.zone-group-header.zone-north-bay { background: #20c997; }
.zone-group-header.zone-east-bay { background: #dc3545; }
.zone-group-header.zone-no-zone { background: #6c757d; }

.zone-color-item.zone-centro { background: #007bff; }
.zone-color-item.zone-mission { background: #28a745; }
.zone-color-item.zone-ruta-sour-flour { background: #fd7e14; }
.zone-color-item.zone-daly-city-san-mateo { background: #6f42c1; }
.zone-color-item.zone-north-bay { background: #20c997; }
.zone-color-item.zone-east-bay { background: #dc3545; }
.zone-color-item.zone-no-zone { background: #6c757d; }

.pricing-table-wrap {
    overflow-x: auto;
}

.pricing-table {
    width: 100%;
    border-collapse: collapse;
}

.pricing-table th,
.pricing-table td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.pricing-table th {
    background: #f8f9fa;
    font-size: 0.85rem;
    font-weight: 600;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.pricing-table tbody tr:hover {
    background: #fafbfc;
}

.store-name {
    font-weight: 600;
    color: #2c3e50;
}

.store-address {
    font-size: 0.85rem;
    color: #6c757d;
    max-width: 280px;
}

.price-edit-btn {
    background: none;
    border: 1px dashed transparent;
    border-radius: 4px;
    padding: 4px 8px;
    cursor: pointer;
    font-size: 0.95rem;
    font-family: inherit;
    color: #28a745;
    font-weight: 600;
    transition: all 0.15s ease;
}

.price-edit-btn.standard {
    color: #6c757d;
    font-weight: 400;
    font-style: italic;
}

.price-edit-btn:hover {
    border-color: #007bff;
    background: #eef6ff;
}

.price-edit-btn.has-custom:hover {
    color: #1e7e34;
}

.price-input-inline {
    width: 90px;
    padding: 4px 8px;
    border: 2px solid #007bff;
    border-radius: 4px;
    font-size: 0.95rem;
    font-family: inherit;
}

.pricing-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.pricing-badge.custom {
    background: #d4edda;
    color: #155724;
}

.pricing-badge.standard {
    background: #e9ecef;
    color: #6c757d;
}

.status-message {
    position: fixed;
    top: 80px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 6px;
    font-size: 0.9rem;
    font-weight: 500;
    z-index: 2000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    transition: opacity 0.3s ease;
}

.status-message.success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.status-message.error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.store-row.hidden {
    display: none;
}

.zone-group-block.hidden {
    display: none;
}

@media (max-width: 768px) {
    .pricing-table th:nth-child(2),
    .pricing-table td:nth-child(2) {
        display: none;
    }

    .pricing-summary {
        flex-direction: column;
    }

    .summary-card {
        min-width: unset;
    }
}
</style>

<script>
function toggleZoneGroup(header) {
    header.closest('.zone-group-block').classList.toggle('collapsed');
}

function showStatusMessage(text, type) {
    const el = document.getElementById('status-message');
    el.textContent = text;
    el.className = 'status-message ' + type;
    el.style.display = 'block';
    clearTimeout(el._timeout);
    el._timeout = setTimeout(function() {
        el.style.display = 'none';
    }, 3000);
}

async function savePrice(customerId, price) {
    const formData = new FormData();
    formData.append('action', 'update_price');
    formData.append('customer_id', customerId);
    formData.append('price', price);

    const response = await fetch('pan_dulce_pricing.php', {
        method: 'POST',
        body: formData
    });

    return response.json();
}

async function saveStandardPrice(price) {
    const formData = new FormData();
    formData.append('action', 'update_standard_price');
    formData.append('price', price);

    const response = await fetch('pan_dulce_pricing.php', {
        method: 'POST',
        body: formData
    });

    return response.json();
}

function updateStandardPriceDisplay(display) {
    const el = document.getElementById('standard-price-display');
    if (el) {
        el.textContent = display;
    }
    const mixedNote = document.querySelector('.standard-price-note.mixed');
    if (mixedNote && mixedNote.textContent.indexOf('mixed prices') !== -1) {
        mixedNote.textContent = 'Applies to all Pan Dulce products. Stores on standard pricing use this rate when orders are generated.';
        mixedNote.classList.remove('mixed');
    }
    const mixedCard = document.querySelector('.summary-card.reference.mixed');
    if (mixedCard) {
        mixedCard.classList.remove('mixed');
    }
}

function startPriceEdit(btn) {
    if (btn.dataset.editing === '1') return;
    btn.dataset.editing = '1';

    const currentPrice = btn.dataset.price || '';
    const input = document.createElement('input');
    input.type = 'number';
    input.className = 'price-input-inline';
    input.step = '0.01';
    input.min = '0';
    input.max = '99.99';
    input.placeholder = 'Standard';
    input.value = currentPrice;

    const originalHtml = btn.innerHTML;
    btn.innerHTML = '';
    btn.appendChild(input);
    input.focus();
    input.select();

    function finishEdit(save) {
        if (btn.dataset.editing !== '1') return;
        btn.dataset.editing = '0';

        if (!save) {
            btn.innerHTML = originalHtml;
            return;
        }

        const newValue = input.value.trim();
        const row = btn.closest('.store-row');
        const customerId = btn.dataset.customerId;

        savePrice(customerId, newValue)
            .then(function(result) {
                if (!result.success) {
                    throw new Error(result.error || 'Failed to update price');
                }

                const hasCustom = result.price !== null;
                btn.dataset.price = hasCustom ? parseFloat(result.price).toFixed(2) : '';

                if (hasCustom) {
                    btn.innerHTML = result.display;
                    btn.className = 'price-edit-btn has-custom';
                } else {
                    btn.innerHTML = '<span class="standard-label">Standard pricing</span>';
                    btn.className = 'price-edit-btn standard';
                }

                const badge = row.querySelector('.pricing-badge');
                badge.textContent = hasCustom ? 'Custom' : 'Standard';
                badge.className = 'pricing-badge ' + (hasCustom ? 'custom' : 'standard');
                row.dataset.customPricing = hasCustom ? '1' : '0';

                applyFilters();
                showStatusMessage('Pan Dulce price updated', 'success');
            })
            .catch(function(err) {
                btn.innerHTML = originalHtml;
                showStatusMessage(err.message, 'error');
            });
    }

    input.addEventListener('blur', function() { finishEdit(true); });
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            input.blur();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            finishEdit(false);
        }
    });
}

function applyFilters() {
    const query = document.getElementById('store-search').value.trim().toLowerCase();
    const customOnly = document.getElementById('custom-only-filter').checked;

    document.querySelectorAll('.zone-group-block').forEach(function(zoneBlock) {
        let visibleRows = 0;

        zoneBlock.querySelectorAll('.store-row').forEach(function(row) {
            const nameMatch = !query || row.dataset.storeName.indexOf(query) !== -1;
            const customMatch = !customOnly || row.dataset.customPricing === '1';
            const visible = nameMatch && customMatch;

            row.classList.toggle('hidden', !visible);
            if (visible) visibleRows++;
        });

        zoneBlock.classList.toggle('hidden', visibleRows === 0);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.price-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            startPriceEdit(btn);
        });
    });

    document.getElementById('store-search').addEventListener('input', applyFilters);
    document.getElementById('custom-only-filter').addEventListener('change', applyFilters);

    const saveStandardBtn = document.getElementById('save-standard-price');
    const standardInput = document.getElementById('standard-price-input');
    if (saveStandardBtn && standardInput) {
        saveStandardBtn.addEventListener('click', function() {
            const price = standardInput.value.trim();
            if (price === '') {
                showStatusMessage('Enter a standard price', 'error');
                standardInput.focus();
                return;
            }

            saveStandardBtn.disabled = true;
            saveStandardBtn.textContent = 'Saving...';

            saveStandardPrice(price)
                .then(function(result) {
                    if (!result.success) {
                        throw new Error(result.error || 'Failed to update standard price');
                    }
                    standardInput.value = parseFloat(result.price).toFixed(2);
                    updateStandardPriceDisplay(result.display);
                    showStatusMessage(
                        'Standard price updated for ' + result.updated_count + ' product' + (result.updated_count === 1 ? '' : 's'),
                        'success'
                    );
                })
                .catch(function(err) {
                    showStatusMessage(err.message, 'error');
                })
                .finally(function() {
                    saveStandardBtn.disabled = false;
                    saveStandardBtn.textContent = 'Save Standard Price';
                });
        });

        standardInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveStandardBtn.click();
            }
        });
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
