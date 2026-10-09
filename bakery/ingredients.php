<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/ingredient_prices.php';

// Set page title
$page_title = bakery_t('page.ingredients');

function ingredients_parse_decimal($value) {
    if ($value === null || $value === '') {
        return null;
    }
    return round((float)$value, 3);
}

function ingredients_parse_cost($value) {
    if ($value === null || $value === '') {
        return null;
    }
    return round((float)$value, 2);
}

function ingredients_stock_fields_from_post() {
    return [
        'quantity_on_hand' => ingredients_parse_decimal($_POST['quantity_on_hand'] ?? null),
        'reorder_level' => ingredients_parse_decimal($_POST['reorder_level'] ?? null),
        'supplier_name' => trim((string)($_POST['supplier_name'] ?? '')) ?: null,
    ];
}

function ingredients_purchasing_fields_from_post() {
    return [
        'package_size' => ingredients_parse_decimal($_POST['package_size'] ?? null),
        'unit_cost' => ingredients_parse_cost($_POST['unit_cost'] ?? null),
    ];
}

$reorder_qty_ready = (isset($db) && $db instanceof PDO) ? bakery_ingredient_reorder_qty_ready($db) : false;

function ingredients_redirect($params = []) {
    $query = http_build_query(array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    }));
    header('Location: ingredients.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bakery_require_csrf();
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_inventory_counts':
                try {
                    if (!bakery_ingredients_inventory_ready($db)) {
                        throw new Exception('Ingredient inventory is not installed. Run database migrations first.');
                    }
                    $counts = $_POST['counts'] ?? [];
                    if (!is_array($counts)) {
                        throw new Exception('Invalid inventory data.');
                    }
                    $stmt = $db->prepare('UPDATE ingredients SET quantity_on_hand = ? WHERE id = ?');
                    $updated = 0;
                    foreach ($counts as $id => $qty) {
                        $id = (int)$id;
                        if ($id <= 0) {
                            continue;
                        }
                        $parsed = ingredients_parse_decimal($qty);
                        $stmt->execute([$parsed, $id]);
                        $updated++;
                    }
                    ingredients_redirect([
                        'success' => 'counts_saved',
                        'view' => $_POST['return_view'] ?? 'count',
                        'q' => trim((string)($_POST['return_q'] ?? '')),
                    ]);
                } catch (Exception $e) {
                    $error = 'Failed to save inventory counts: ' . $e->getMessage();
                }
                break;

            case 'update_purchasing':
                try {
                    if (!bakery_ingredients_inventory_ready($db)) {
                        throw new Exception('Ingredient inventory is not installed. Run database migrations first.');
                    }
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id <= 0) {
                        throw new Exception('Invalid ingredient.');
                    }
                    $stock = ingredients_stock_fields_from_post();
                    $fields = [
                        'unit' => trim((string)($_POST['unit'] ?? '')),
                        'quantity_on_hand' => $stock['quantity_on_hand'],
                        'reorder_level' => $stock['reorder_level'],
                        'supplier_name' => $stock['supplier_name'],
                    ];
                    $sql = 'UPDATE ingredients SET unit = ?, quantity_on_hand = ?, reorder_level = ?, supplier_name = ?';
                    $params = [
                        $fields['unit'],
                        $fields['quantity_on_hand'],
                        $fields['reorder_level'],
                        $fields['supplier_name'],
                    ];
                    if (bakery_ingredients_purchasing_ready($db)) {
                        $purchasing = ingredients_purchasing_fields_from_post();
                        $sql .= ', package_size = ?, unit_cost = ?';
                        $params[] = $purchasing['package_size'];
                        $params[] = $purchasing['unit_cost'];
                    }
                    if ($reorder_qty_ready) {
                        $sql .= ', reorder_qty = ?';
                        $params[] = ingredients_parse_decimal($_POST['reorder_qty'] ?? null);
                    }
                    $sql .= ' WHERE id = ?';
                    $params[] = $id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    ingredients_redirect([
                        'success' => 'updated',
                        'view' => $_POST['return_view'] ?? 'count',
                        'q' => trim((string)($_POST['return_q'] ?? '')),
                    ]);
                } catch (Exception $e) {
                    $error = 'Failed to update ingredient: ' . $e->getMessage();
                }
                break;

            case 'add_ingredient':
                try {
                    $stock = ingredients_stock_fields_from_post();
                    $purchasing = ingredients_purchasing_fields_from_post();
                    if (bakery_ingredients_inventory_ready($db) && bakery_ingredients_purchasing_ready($db) && $reorder_qty_ready) {
                        $stmt = $db->prepare(
                            'INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level, supplier_name, package_size, unit_cost, reorder_qty)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $stock['quantity_on_hand'],
                            $stock['reorder_level'],
                            $stock['supplier_name'],
                            $purchasing['package_size'],
                            $purchasing['unit_cost'],
                            ingredients_parse_decimal($_POST['reorder_qty'] ?? null),
                        ]);
                    } elseif (bakery_ingredients_inventory_ready($db) && bakery_ingredients_purchasing_ready($db)) {
                        $stmt = $db->prepare(
                            'INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level, supplier_name, package_size, unit_cost)
                             VALUES (?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $stock['quantity_on_hand'],
                            $stock['reorder_level'],
                            $stock['supplier_name'],
                            $purchasing['package_size'],
                            $purchasing['unit_cost'],
                        ]);
                    } elseif (bakery_ingredients_inventory_ready($db)) {
                        $stmt = $db->prepare(
                            'INSERT INTO ingredients (name, unit, quantity_on_hand, reorder_level, supplier_name)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $stock['quantity_on_hand'],
                            $stock['reorder_level'],
                            $stock['supplier_name'],
                        ]);
                    } else {
                        $stmt = $db->prepare('INSERT INTO ingredients (name, unit) VALUES (?, ?)');
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                        ]);
                    }
                    ingredients_redirect(['success' => 'added', 'view' => 'manage']);
                } catch (Exception $e) {
                    $error = 'Failed to add ingredient: ' . $e->getMessage();
                }
                break;

            case 'edit_ingredient':
                try {
                    $stock = ingredients_stock_fields_from_post();
                    $purchasing = ingredients_purchasing_fields_from_post();
                    if (bakery_ingredients_inventory_ready($db) && bakery_ingredients_purchasing_ready($db) && $reorder_qty_ready) {
                        $stmt = $db->prepare(
                            'UPDATE ingredients
                             SET name = ?, unit = ?, quantity_on_hand = ?, reorder_level = ?, supplier_name = ?,
                                 package_size = ?, unit_cost = ?, reorder_qty = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $stock['quantity_on_hand'],
                            $stock['reorder_level'],
                            $stock['supplier_name'],
                            $purchasing['package_size'],
                            $purchasing['unit_cost'],
                            ingredients_parse_decimal($_POST['reorder_qty'] ?? null),
                            $_POST['id'],
                        ]);
                    } elseif (bakery_ingredients_inventory_ready($db) && bakery_ingredients_purchasing_ready($db)) {
                        $stmt = $db->prepare(
                            'UPDATE ingredients
                             SET name = ?, unit = ?, quantity_on_hand = ?, reorder_level = ?, supplier_name = ?,
                                 package_size = ?, unit_cost = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $stock['quantity_on_hand'],
                            $stock['reorder_level'],
                            $stock['supplier_name'],
                            $purchasing['package_size'],
                            $purchasing['unit_cost'],
                            $_POST['id'],
                        ]);
                    } elseif (bakery_ingredients_inventory_ready($db)) {
                        $stmt = $db->prepare(
                            'UPDATE ingredients
                             SET name = ?, unit = ?, quantity_on_hand = ?, reorder_level = ?, supplier_name = ?
                             WHERE id = ?'
                        );
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $stock['quantity_on_hand'],
                            $stock['reorder_level'],
                            $stock['supplier_name'],
                            $_POST['id'],
                        ]);
                    } else {
                        $stmt = $db->prepare('UPDATE ingredients SET name = ?, unit = ? WHERE id = ?');
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['unit'],
                            $_POST['id'],
                        ]);
                    }
                    ingredients_redirect(['success' => 'updated', 'view' => 'manage']);
                } catch (Exception $e) {
                    $error = 'Failed to update ingredient: ' . $e->getMessage();
                }
                break;

            case 'add_ingredient_price':
                bakery_require_role(['administrator', 'manager']);
                $priceIngredientId = (int)($_POST['ingredient_id'] ?? 0);
                $enteredBy = function_exists('bakery_current_user') ? (int)(bakery_current_user()['id'] ?? 0) : 0;
                $result = bakery_ingredient_price_add($db, [
                    'ingredient_id' => $priceIngredientId,
                    'vendor' => $_POST['vendor'] ?? '',
                    'vendor_sku' => $_POST['vendor_sku'] ?? '',
                    'pack_size_grams' => $_POST['pack_size_grams'] ?? '',
                    'pack_price' => $_POST['pack_price'] ?? '',
                    'invoice_number' => $_POST['invoice_number'] ?? '',
                    'invoice_date' => $_POST['invoice_date'] ?? '',
                    'entered_by' => $enteredBy,
                ]);
                if (!($result['ok'] ?? false)) {
                    $error = bakery_t((string)($result['error'] ?? 'ingredient_prices.invalid_ingredient'));
                    $_GET['view'] = 'prices';
                    $_GET['id'] = $priceIngredientId;
                    break;
                }
                ingredients_redirect([
                    'success' => 'price_added',
                    'view' => 'prices',
                    'id' => $priceIngredientId,
                ]);
                break;

            case 'delete_ingredient':
                try {
                    $check = $db->prepare('SELECT COUNT(*) FROM formula_ingredients WHERE ingredient_id = ?');
                    $check->execute([$_POST['id']]);
                    if ($check->fetchColumn() > 0) {
                        throw new Exception('Cannot delete ingredient as it is used in one or more formulas.');
                    }

                    $stmt = $db->prepare('DELETE FROM ingredients WHERE id = ?');
                    $stmt->execute([$_POST['id']]);
                    ingredients_redirect(['success' => 'deleted', 'view' => 'manage']);
                } catch (Exception $e) {
                    $error = 'Failed to delete ingredient: ' . $e->getMessage();
                }
                break;
        }
    }
}

// Include header and navigation
require_once 'includes/header.php';
require_once 'includes/nav.php';

$success_message = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'added':
            $success_message = 'Ingredient added successfully!';
            break;
        case 'updated':
            $success_message = 'Ingredient updated successfully!';
            break;
        case 'deleted':
            $success_message = 'Ingredient deleted successfully!';
            break;
        case 'counts_saved':
            $success_message = 'Inventory counts saved!';
            break;
        case 'price_added':
            $success_message = bakery_t('ingredient_prices.saved');
            break;
    }
}

$inventory_ready = bakery_ingredients_inventory_ready($db);
$purchasing_ready = bakery_ingredients_purchasing_ready($db);
$prices_ready = bakery_ingredient_prices_ready($db);
$current_prices = $prices_ready ? bakery_ingredient_current_prices_map($db) : [];
$low_stock_ingredients = $inventory_ready ? bakery_low_stock_ingredients($db) : [];
$requested_view = (string)($_GET['view'] ?? 'count');
$active_view = in_array($requested_view, ['count', 'manage', 'prices', 'po'], true) ? $requested_view : 'count';
$price_ingredient_id = (int)($_GET['id'] ?? 0);
$search_query = trim((string)($_GET['q'] ?? ''));

$unit_options = [
    'g' => 'Grams (g)',
    'kg' => 'Kilograms (kg)',
    'ml' => 'Milliliters (ml)',
    'L' => 'Liters (L)',
    'oz' => 'Ounces (oz)',
    'lb' => 'Pounds (lb)',
    'tsp' => 'Teaspoons (tsp)',
    'tbsp' => 'Tablespoons (tbsp)',
    'cup' => 'Cups',
    'pc' => 'Pieces (pc)',
];

$ingredients = [];
try {
    $ingredients = $db->query('SELECT * FROM ingredients ORDER BY name')->fetchAll();
} catch (Exception $e) {
    $error = ($error ?? '') ?: 'Error loading ingredients: ' . $e->getMessage();
}

function ingredients_format_qty($value) {
    if ($value === null || $value === '') {
        return '';
    }
    return rtrim(rtrim(number_format((float)$value, 3, '.', ''), '0'), '.');
}

function ingredients_format_cost($value) {
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, 2, '.', '');
}
?>

<style>
.ingredients-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 1rem 1rem 6rem;
}

.ingredients-page h1 {
    margin: 0 0 0.35rem;
    font-size: 1.6rem;
    color: #2c3e50;
}

.ingredients-subtitle {
    margin: 0 0 1rem;
    color: #62706a;
    font-size: 0.95rem;
}

.view-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
    background: #eef2f0;
    padding: 0.35rem;
    border-radius: 12px;
}

.view-tab {
    flex: 1;
    text-align: center;
    padding: 0.75rem 1rem;
    border: none;
    border-radius: 10px;
    background: transparent;
    color: #56655d;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
}

.view-tab.active {
    background: #fff;
    color: #1e6b3a;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.search-bar {
    margin-bottom: 1rem;
}

.search-bar input {
    width: 100%;
    padding: 0.85rem 1rem;
    border: 1px solid #cbd4cf;
    border-radius: 10px;
    font-size: 1rem;
    box-sizing: border-box;
}

.notice {
    padding: 0.85rem 1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
}

.notice.success {
    background: #e7f6ea;
    color: #1d6534;
}

.notice.error {
    background: #fdecec;
    color: #9b2525;
}

.notice.warning {
    background: #fff3e0;
    border: 1px solid #ffb74d;
    color: #5d4037;
}

.notice.warning h2 {
    margin: 0 0 0.5rem;
    font-size: 1rem;
    color: #e65100;
}

.notice.warning ul {
    margin: 0;
    padding-left: 1.2rem;
}

.notice.warning li {
    margin-bottom: 0.25rem;
}

.inventory-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.inventory-item {
    background: #fff;
    border: 1px solid #e2e8e4;
    border-radius: 12px;
    overflow: hidden;
}

.inventory-item.low-stock {
    border-color: #ff9800;
    box-shadow: inset 3px 0 0 #ff9800;
}

.inventory-item-main {
    padding: 0.85rem 1rem;
}

.inventory-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.5rem;
}

.inventory-item-name {
    margin: 0;
    font-size: 1.05rem;
    color: #2c3e50;
    line-height: 1.3;
}

.low-stock-badge {
    display: inline-block;
    background: #ff5722;
    color: #fff;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.15rem 0.45rem;
    border-radius: 999px;
    margin-left: 0.35rem;
    vertical-align: middle;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.inventory-meta {
    font-size: 0.82rem;
    color: #6c757d;
    margin-bottom: 0.65rem;
    line-height: 1.4;
}

.inventory-meta span + span::before {
    content: ' · ';
}

.qty-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.qty-stepper {
    display: flex;
    align-items: stretch;
    flex: 1;
    min-width: 0;
}

.qty-btn {
    width: 44px;
    min-width: 44px;
    border: 1px solid #cbd4cf;
    background: #f5f8f6;
    color: #2c3e50;
    font-size: 1.25rem;
    font-weight: 600;
    cursor: pointer;
    touch-action: manipulation;
}

.qty-btn:first-child {
    border-radius: 10px 0 0 10px;
}

.qty-btn:last-child {
    border-radius: 0 10px 10px 0;
}

.qty-input {
    flex: 1;
    min-width: 0;
    text-align: center;
    border: 1px solid #cbd4cf;
    border-left: none;
    border-right: none;
    padding: 0.65rem 0.35rem;
    font-size: 1.15rem;
    font-weight: 600;
    -moz-appearance: textfield;
}

.qty-input::-webkit-outer-spin-button,
.qty-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.qty-unit {
    font-size: 0.9rem;
    color: #56655d;
    min-width: 2.5rem;
    font-weight: 600;
}

.details-toggle {
    display: block;
    width: 100%;
    padding: 0.55rem 1rem;
    border: none;
    border-top: 1px solid #eef2f0;
    background: #fafbfa;
    color: #1e6b3a;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    text-align: left;
}

.details-panel {
    display: none;
    padding: 0 1rem 1rem;
    border-top: 1px solid #eef2f0;
    background: #fafbfa;
}

.details-panel.open {
    display: block;
}

.details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-top: 0.75rem;
}

.detail-field label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    color: #56655d;
    margin-bottom: 0.3rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.detail-field input,
.detail-field select {
    width: 100%;
    padding: 0.65rem 0.55rem;
    border: 1px solid #cbd4cf;
    border-radius: 8px;
    font-size: 1rem;
    box-sizing: border-box;
}

.detail-field.full {
    grid-column: 1 / -1;
}

.detail-save {
    margin-top: 0.75rem;
    width: 100%;
    padding: 0.7rem;
    border: none;
    border-radius: 8px;
    background: #1e6b3a;
    color: #fff;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
}

.sticky-save {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    padding: 0.75rem 1rem calc(0.75rem + env(safe-area-inset-bottom, 0));
    background: rgba(255, 255, 255, 0.95);
    border-top: 1px solid #e2e8e4;
    box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.06);
    z-index: 100;
}

.sticky-save button {
    width: 100%;
    max-width: 900px;
    margin: 0 auto;
    display: block;
    padding: 0.9rem 1rem;
    border: none;
    border-radius: 12px;
    background: #1e88e5;
    color: #fff;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
}

.manage-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 1rem;
    margin-top: 0.5rem;
}

.manage-card {
    background: #fff;
    border: 1px solid #e2e8e4;
    border-radius: 12px;
    padding: 1rem;
}

.manage-card h3 {
    margin: 0 0 0.35rem;
    font-size: 1.05rem;
}

.manage-card .meta {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 0.75rem;
}

.manage-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.45rem 0.75rem;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    cursor: pointer;
}

.btn-edit {
    background: #e3f2fd;
    color: #1e88e5;
}

.btn-delete {
    background: #ffebee;
    color: #e53935;
}

.add-card {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    border: 2px dashed #cbd4cf;
    border-radius: 12px;
    background: #f8f9fa;
    cursor: pointer;
    color: #6c757d;
    text-align: center;
    padding: 1rem;
}

.add-card:hover {
    border-color: #1e88e5;
    background: #f1f8fe;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    overflow-y: auto;
    padding: 1rem;
}

.modal-content {
    background: #fff;
    border-radius: 12px;
    max-width: 500px;
    width: 100%;
    margin: 0 auto;
    padding: 1.25rem;
    box-sizing: border-box;
}

.modal-header h2 {
    margin: 0 0 1rem;
    font-size: 1.25rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.35rem;
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.9rem;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.7rem;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    font-size: 1rem;
    box-sizing: border-box;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1.25rem;
}

.btn-secondary {
    background: #e9ecef;
    color: #495057;
    padding: 0.6rem 1rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
}

.btn-primary {
    background: #1e88e5;
    color: #fff;
    padding: 0.6rem 1rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
}

.empty-state {
    text-align: center;
    padding: 2rem 1rem;
    color: #6c757d;
}

.hidden-by-search {
    display: none !important;
}

@media (min-width: 768px) {
    .ingredients-page {
        padding: 2rem 2rem 2rem;
    }

    .sticky-save {
        position: static;
        padding: 0;
        background: transparent;
        border: none;
        box-shadow: none;
        margin-top: 1rem;
    }

    .sticky-save button {
        max-width: none;
    }
}

.price-table, .po-table {
    width: 100%;
    border-collapse: collapse;
    background: #fff;
    margin: 0.75rem 0 1.25rem;
}

.price-table th, .price-table td, .po-table th, .po-table td {
    text-align: left;
    padding: 0.45rem 0.55rem;
    border-bottom: 1px solid #e2e8e4;
    font-size: 0.92rem;
    vertical-align: top;
}

.po-vendor {
    margin: 1.25rem 0 0.35rem;
    font-size: 1.15rem;
}

.price-form {
    background: #fff;
    border: 1px solid #e2e8e4;
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
}

@media print {
    .bakery-nav, .view-tabs, .sticky-save, .no-print, .search-bar {
        display: none !important;
    }

    .ingredients-page {
        max-width: none;
        padding: 0;
    }
}

@media (max-width: 480px) {
    .details-grid {
        grid-template-columns: 1fr;
    }

    .form-row {
        grid-template-columns: 1fr;
    }
}
</style>

<main class="ingredients-page">
    <h1>Ingredients</h1>
    <p class="ingredients-subtitle">Count stock on your phone, then update package size and cost for future ordering.</p>

    <?php if (isset($error)): ?>
        <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="notice success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <?php if (!$inventory_ready): ?>
        <div class="notice error">Ingredient inventory columns are missing. Run <code>scripts/run_migrations.php</code> to enable stock tracking.</div>
    <?php endif; ?>

    <nav class="view-tabs" aria-label="Ingredients views">
        <a class="view-tab<?php echo $active_view === 'count' ? ' active' : ''; ?>" href="ingredients.php?view=count<?php echo $search_query !== '' ? '&q=' . urlencode($search_query) : ''; ?>">Take Inventory</a>
        <a class="view-tab<?php echo $active_view === 'manage' ? ' active' : ''; ?>" href="ingredients.php?view=manage">Manage</a>
        <a class="view-tab<?php echo $active_view === 'prices' ? ' active' : ''; ?>" href="ingredients.php?view=prices"><?php bakery_te('ingredient_prices.tab_prices'); ?></a>
        <a class="view-tab<?php echo $active_view === 'po' ? ' active' : ''; ?>" href="ingredients.php?view=po"><?php bakery_te('ingredient_prices.tab_po'); ?></a>
    </nav>

    <?php if ($active_view === 'po'): ?>
        <h2><?php bakery_te('ingredient_prices.draft_title'); ?></h2>
        <p class="notice warning"><?php bakery_te('ingredient_prices.draft_only'); ?></p>
        <p class="no-print"><button type="button" class="btn-primary" onclick="window.print()"><?php bakery_te('ingredient_prices.print'); ?></button></p>
        <?php if (!$prices_ready && !$inventory_ready): ?>
            <div class="notice error"><?php bakery_te('ingredient_prices.migration_needed'); ?></div>
        <?php else:
            $draft = bakery_ingredient_draft_purchase_order($db);
            if ($draft['vendors'] === []): ?>
                <div class="notice success"><?php bakery_te('ingredient_prices.no_flagged'); ?></div>
            <?php else: ?>
                <?php foreach ($draft['vendors'] as $group): ?>
                    <h3 class="po-vendor"><?php echo htmlspecialchars($group['unassigned'] ? bakery_t('ingredient_prices.unassigned_vendor') : $group['vendor']); ?></h3>
                    <table class="po-table">
                        <thead>
                            <tr>
                                <th><?php bakery_te('common.name'); ?></th>
                                <th><?php bakery_te('ingredient_prices.sku'); ?></th>
                                <th><?php bakery_te('ingredient_prices.on_hand'); ?></th>
                                <th><?php bakery_te('ingredient_prices.reorder_point'); ?></th>
                                <th><?php bakery_te('ingredient_prices.suggested_qty'); ?></th>
                                <th><?php bakery_te('ingredient_prices.pack_price_col'); ?></th>
                                <th><?php bakery_te('ingredient_prices.cost_per_kg'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['lines'] as $line): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($line['name']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($line['vendor_sku'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars(ingredients_format_qty($line['on_hand'])); ?> <?php echo htmlspecialchars($line['unit']); ?></td>
                                    <td><?php echo htmlspecialchars(ingredients_format_qty($line['reorder_point'])); ?></td>
                                    <td><?php echo htmlspecialchars(ingredients_format_qty($line['suggested_qty'])); ?> <?php echo htmlspecialchars($line['unit']); ?></td>
                                    <td><?php echo $line['pack_price'] === null ? '' : '$' . htmlspecialchars(ingredients_format_cost($line['pack_price'])); ?></td>
                                    <td><?php echo $line['cost_per_kg'] === null ? '' : '$' . htmlspecialchars(bakery_ingredient_format_cost_per_kg($line['cost_per_kg'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($active_view === 'prices'): ?>
        <?php if (!$prices_ready): ?>
            <div class="notice error"><?php bakery_te('ingredient_prices.migration_needed'); ?></div>
        <?php else:
            $priceIngredient = null;
            if ($price_ingredient_id > 0) {
                $priceStmt = $db->prepare('SELECT * FROM ingredients WHERE id = ?');
                $priceStmt->execute([$price_ingredient_id]);
                $priceIngredient = $priceStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $priceHistory = $priceIngredient ? bakery_ingredient_price_history($db, (int)$priceIngredient['id']) : [];
            $priceCurrent = $priceIngredient ? ($current_prices[(int)$priceIngredient['id']] ?? null) : null;
        ?>
            <p class="ingredients-subtitle"><?php bakery_te('ingredient_prices.pick'); ?></p>
            <div class="inventory-list">
                <?php foreach ($ingredients as $ingredient): ?>
                    <a class="inventory-item" href="ingredients.php?view=prices&amp;id=<?php echo (int)$ingredient['id']; ?>">
                        <div class="inventory-item-main">
                            <h2 class="inventory-item-name"><?php echo htmlspecialchars($ingredient['name'] ?? ''); ?></h2>
                            <?php $rowPrice = $current_prices[(int)$ingredient['id']] ?? null; ?>
                            <div class="inventory-meta">
                                <span><?php bakery_te('ingredient_prices.cost_per_kg'); ?> <?php echo $rowPrice ? '$' . htmlspecialchars(bakery_ingredient_format_cost_per_kg($rowPrice['cost_per_kg'])) : '—'; ?></span>
                                <span><?php bakery_te('ingredient_prices.vendor'); ?> <?php echo htmlspecialchars((string)($rowPrice['vendor'] ?? ($ingredient['supplier_name'] ?? '—'))); ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($priceIngredient): ?>
                <h2><?php echo htmlspecialchars($priceIngredient['name']); ?></h2>
                <?php if ($priceCurrent): ?>
                    <p><?php bakery_te('ingredient_prices.current'); ?>: $<?php echo htmlspecialchars(bakery_ingredient_format_cost_per_kg($priceCurrent['cost_per_kg'])); ?> / kg · <?php echo htmlspecialchars((string)$priceCurrent['vendor']); ?></p>
                <?php endif; ?>
                <form method="POST" class="price-form">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="add_ingredient_price">
                    <input type="hidden" name="ingredient_id" value="<?php echo (int)$priceIngredient['id']; ?>">
                    <h3><?php bakery_te('ingredient_prices.add_line'); ?></h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vendor"><?php bakery_te('ingredient_prices.vendor'); ?></label>
                            <input type="text" id="vendor" name="vendor" maxlength="255" required value="<?php echo htmlspecialchars((string)($priceCurrent['vendor'] ?? $priceIngredient['supplier_name'] ?? '')); ?>">
                        </div>
                        <div class="form-group">
                            <label for="vendor_sku"><?php bakery_te('ingredient_prices.vendor_sku'); ?></label>
                            <input type="text" id="vendor_sku" name="vendor_sku" maxlength="80" value="">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="pack_size_grams"><?php bakery_te('ingredient_prices.pack_grams'); ?></label>
                            <input type="number" id="pack_size_grams" name="pack_size_grams" min="0.001" step="0.001" required>
                        </div>
                        <div class="form-group">
                            <label for="pack_price"><?php bakery_te('ingredient_prices.pack_price'); ?></label>
                            <input type="number" id="pack_price" name="pack_price" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="invoice_number"><?php bakery_te('ingredient_prices.invoice_number'); ?></label>
                            <input type="text" id="invoice_number" name="invoice_number" maxlength="64">
                        </div>
                        <div class="form-group">
                            <label for="invoice_date"><?php bakery_te('ingredient_prices.invoice_date'); ?></label>
                            <input type="date" id="invoice_date" name="invoice_date" required value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary"><?php bakery_te('ingredient_prices.save_price'); ?></button>
                </form>
                <h3><?php bakery_te('ingredient_prices.history'); ?></h3>
                <?php if (!$priceHistory): ?>
                    <p><?php bakery_te('ingredient_prices.none'); ?></p>
                <?php else: ?>
                    <table class="price-table">
                        <thead>
                            <tr>
                                <th><?php bakery_te('ingredient_prices.invoice_date'); ?></th>
                                <th><?php bakery_te('ingredient_prices.vendor'); ?></th>
                                <th><?php bakery_te('ingredient_prices.sku'); ?></th>
                                <th><?php bakery_te('ingredient_prices.pack_grams'); ?></th>
                                <th><?php bakery_te('ingredient_prices.pack_price'); ?></th>
                                <th><?php bakery_te('ingredient_prices.cost_per_kg'); ?></th>
                                <th><?php bakery_te('ingredient_prices.invoice_number'); ?></th>
                                <th><?php bakery_te('ingredient_prices.entered_by'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceHistory as $line): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)$line['invoice_date']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$line['vendor']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($line['vendor_sku'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars(ingredients_format_qty($line['pack_size_grams'])); ?></td>
                                    <td>$<?php echo htmlspecialchars(ingredients_format_cost($line['pack_price'])); ?></td>
                                    <td>$<?php echo htmlspecialchars(bakery_ingredient_format_cost_per_kg($line['cost_per_kg'])); ?></td>
                                    <td><?php echo htmlspecialchars((string)($line['invoice_number'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($line['entered_by_name'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($active_view === 'count'): ?>
        <?php if ($inventory_ready && count($low_stock_ingredients) > 0): ?>
            <div class="notice warning" role="status">
                <h2>Low stock: <?php echo count($low_stock_ingredients); ?> ingredient<?php echo count($low_stock_ingredients) === 1 ? '' : 's'; ?></h2>
                <ul>
                    <?php foreach ($low_stock_ingredients as $low): ?>
                        <li>
                            <?php echo htmlspecialchars($low['name']); ?> —
                            <?php echo htmlspecialchars(ingredients_format_qty($low['quantity_on_hand'] ?? 0)); ?>
                            <?php echo htmlspecialchars($low['unit'] ?? ''); ?>
                            (reorder at <?php echo htmlspecialchars(ingredients_format_qty($low['reorder_level'])); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($inventory_ready && count($ingredients) > 0): ?>
            <div class="notice success">All tracked ingredients are above reorder levels.</div>
        <?php endif; ?>

        <div class="search-bar">
            <input type="search" id="inventorySearch" placeholder="Search ingredients…" value="<?php echo htmlspecialchars($search_query); ?>" autocomplete="off" aria-label="Search ingredients">
        </div>

        <?php if ($inventory_ready): ?>
        <form method="POST" id="inventoryForm">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="save_inventory_counts">
            <input type="hidden" name="return_view" value="count">
            <input type="hidden" name="return_q" id="returnQ" value="<?php echo htmlspecialchars($search_query); ?>">
        </form>

            <div class="inventory-list">
                <?php foreach ($ingredients as $ingredient):
                    $isLowStock = bakery_ingredient_is_low_stock($ingredient);
                    $packageLabel = bakery_ingredient_package_label($ingredient);
                    $qtyValue = ingredients_format_qty($ingredient['quantity_on_hand'] ?? '');
                ?>
                <?php
                    $priceRow = $current_prices[(int)$ingredient['id']] ?? null;
                    $displayVendor = trim((string)($priceRow['vendor'] ?? ''));
                    if ($displayVendor === '') {
                        $displayVendor = trim((string)($ingredient['supplier_name'] ?? ''));
                    }
                ?>
                <article class="inventory-item<?php echo $isLowStock ? ' low-stock' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($ingredient['name'] . ' ' . $displayVendor)); ?>">
                    <div class="inventory-item-main">
                        <div class="inventory-item-header">
                            <h2 class="inventory-item-name">
                                <?php echo htmlspecialchars($ingredient['name'] ?? ''); ?>
                                <?php if ($isLowStock): ?>
                                    <span class="low-stock-badge reorder-flag"><?php bakery_te('ingredient_prices.reorder_flag'); ?></span>
                                <?php endif; ?>
                            </h2>
                            <?php if ($prices_ready): ?>
                                <a class="no-print" href="ingredients.php?view=prices&amp;id=<?php echo (int)$ingredient['id']; ?>"><?php bakery_te('ingredient_prices.open_history'); ?></a>
                            <?php endif; ?>
                        </div>
                        <?php if ($inventory_ready && ($purchasing_ready || $displayVendor !== '' || $priceRow || ($ingredient['reorder_level'] !== null && $ingredient['reorder_level'] !== '') || ($ingredient['package_size'] ?? null) !== null)): ?>
                        <div class="inventory-meta">
                            <?php if ($packageLabel): ?>
                                <span><?php echo htmlspecialchars($packageLabel); ?> pkg</span>
                            <?php endif; ?>
                            <?php if ($priceRow): ?>
                                <span><?php bakery_te('ingredient_prices.cost_per_kg'); ?> $<?php echo htmlspecialchars(bakery_ingredient_format_cost_per_kg($priceRow['cost_per_kg'])); ?></span>
                            <?php elseif ($purchasing_ready && $ingredient['unit_cost'] !== null && $ingredient['unit_cost'] !== ''): ?>
                                <span>$<?php echo htmlspecialchars(ingredients_format_cost($ingredient['unit_cost'])); ?>/pkg</span>
                            <?php endif; ?>
                            <?php if ($displayVendor !== ''): ?>
                                <span><?php bakery_te('ingredient_prices.vendor'); ?> <?php echo htmlspecialchars($displayVendor); ?></span>
                            <?php endif; ?>
                            <?php if ($ingredient['reorder_level'] !== null && $ingredient['reorder_level'] !== ''): ?>
                                <span><?php bakery_te('ingredient_prices.reorder_point'); ?> <?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_level'])); ?> <?php echo htmlspecialchars($ingredient['unit'] ?? ''); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="qty-row">
                            <div class="qty-stepper">
                                <button type="button" class="qty-btn" data-step="-1" aria-label="Decrease quantity">−</button>
                                <input
                                    type="number"
                                    class="qty-input"
                                    form="inventoryForm"
                                    name="counts[<?php echo (int)$ingredient['id']; ?>]"
                                    value="<?php echo htmlspecialchars($qtyValue); ?>"
                                    step="0.001"
                                    min="0"
                                    inputmode="decimal"
                                    aria-label="Quantity on hand for <?php echo htmlspecialchars($ingredient['name']); ?>"
                                >
                                <button type="button" class="qty-btn" data-step="1" aria-label="Increase quantity">+</button>
                            </div>
                            <span class="qty-unit"><?php echo htmlspecialchars($ingredient['unit'] ?? ''); ?></span>
                        </div>
                    </div>
                    <button type="button" class="details-toggle" aria-expanded="false">Package &amp; supplier details</button>
                    <div class="details-panel">
                        <form method="POST" class="purchasing-form">
                            <?php echo bakery_csrf_field(); ?>
                            <input type="hidden" name="action" value="update_purchasing">
                            <input type="hidden" name="id" value="<?php echo (int)$ingredient['id']; ?>">
                            <input type="hidden" name="return_view" value="count">
                            <input type="hidden" name="return_q" value="<?php echo htmlspecialchars($search_query); ?>">
                            <input type="hidden" name="quantity_on_hand" class="sync-qty" value="<?php echo htmlspecialchars($qtyValue); ?>">

                            <div class="details-grid">
                                <div class="detail-field">
                                    <label>Stock unit</label>
                                    <select name="unit">
                                        <?php foreach ($unit_options as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>"<?php echo ($ingredient['unit'] ?? '') === $value ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if ($purchasing_ready): ?>
                                <div class="detail-field">
                                    <label>Package size</label>
                                    <input type="number" name="package_size" step="0.001" min="0" value="<?php echo htmlspecialchars(ingredients_format_qty($ingredient['package_size'] ?? '')); ?>" placeholder="e.g. 50">
                                </div>
                                <div class="detail-field">
                                    <label>Cost per package ($)</label>
                                    <input type="number" name="unit_cost" step="0.01" min="0" value="<?php echo htmlspecialchars(ingredients_format_cost($ingredient['unit_cost'] ?? '')); ?>" placeholder="0.00">
                                </div>
                                <?php endif; ?>
                                <div class="detail-field">
                                    <label><?php bakery_te('ingredient_prices.reorder_point'); ?></label>
                                    <input type="number" name="reorder_level" step="0.001" min="0" value="<?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_level'] ?? '')); ?>">
                                </div>
                                <?php if ($reorder_qty_ready): ?>
                                <div class="detail-field">
                                    <label><?php bakery_te('ingredient_prices.reorder_qty'); ?></label>
                                    <input type="number" name="reorder_qty" step="0.001" min="0" value="<?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_qty'] ?? '')); ?>">
                                </div>
                                <?php endif; ?>
                                <div class="detail-field full">
                                    <label>Supplier</label>
                                    <input type="text" name="supplier_name" maxlength="255" value="<?php echo htmlspecialchars($ingredient['supplier_name'] ?? ''); ?>" placeholder="Sysco, Restaurant Depot…">
                                </div>
                            </div>
                            <button type="submit" class="detail-save">Save details</button>
                        </form>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php if (!$ingredients): ?>
                    <div class="empty-state">No ingredients yet. Switch to Manage to add your first one.</div>
                <?php endif; ?>
            </div>

            <?php if ($ingredients): ?>
            <div class="sticky-save">
                <button type="submit" form="inventoryForm">Save all counts</button>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-state">Run migrations to enable inventory counting.</div>
        <?php endif; ?>

    <?php else: /* manage view */ ?>
        <div class="manage-grid">
            <div class="add-card" onclick="showAddModal()" role="button" tabindex="0">
                <div>
                    <div style="font-size:1.5rem;margin-bottom:0.25rem;">+</div>
                    Add New Ingredient
                </div>
            </div>

            <?php foreach ($ingredients as $ingredient):
                $isLowStock = bakery_ingredient_is_low_stock($ingredient);
                $packageLabel = bakery_ingredient_package_label($ingredient);
            ?>
            <div class="manage-card<?php echo $isLowStock ? ' low-stock' : ''; ?>">
                <?php
                    $priceRow = $current_prices[(int)$ingredient['id']] ?? null;
                    $displayVendor = trim((string)($priceRow['vendor'] ?? ''));
                    if ($displayVendor === '') {
                        $displayVendor = trim((string)($ingredient['supplier_name'] ?? ''));
                    }
                ?>
                <h3>
                    <?php echo htmlspecialchars($ingredient['name'] ?? ''); ?>
                    <?php if ($isLowStock): ?><span class="low-stock-badge reorder-flag"><?php bakery_te('ingredient_prices.reorder_flag'); ?></span><?php endif; ?>
                </h3>
                <div class="meta">
                    Unit: <?php echo htmlspecialchars($ingredient['unit'] ?? '—'); ?><br>
                    <?php if ($inventory_ready): ?>
                        On hand: <?php echo htmlspecialchars(ingredients_format_qty($ingredient['quantity_on_hand'] ?? '') ?: '—'); ?><br>
                        <?php bakery_te('ingredient_prices.reorder_point'); ?>: <?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_level'] ?? '') ?: '—'); ?><br>
                    <?php endif; ?>
                    <?php if ($purchasing_ready && $packageLabel): ?>
                        Package: <?php echo htmlspecialchars($packageLabel); ?><br>
                    <?php endif; ?>
                    <?php if ($priceRow): ?>
                        <?php bakery_te('ingredient_prices.cost_per_kg'); ?>: $<?php echo htmlspecialchars(bakery_ingredient_format_cost_per_kg($priceRow['cost_per_kg'])); ?><br>
                    <?php elseif ($purchasing_ready && $ingredient['unit_cost'] !== null && $ingredient['unit_cost'] !== ''): ?>
                        Cost: $<?php echo htmlspecialchars(ingredients_format_cost($ingredient['unit_cost'])); ?>/pkg<br>
                    <?php endif; ?>
                    <?php if ($displayVendor !== ''): ?>
                        <?php bakery_te('ingredient_prices.vendor'); ?>: <?php echo htmlspecialchars($displayVendor); ?>
                    <?php endif; ?>
                </div>
                <div class="manage-actions">
                    <button type="button" class="btn-sm btn-edit" onclick='showEditModal(<?php echo bakery_json_for_html([
                        'id' => $ingredient['id'],
                        'name' => $ingredient['name'] ?? '',
                        'unit' => $ingredient['unit'] ?? '',
                        'quantity_on_hand' => ingredients_format_qty($ingredient['quantity_on_hand'] ?? ''),
                        'reorder_level' => ingredients_format_qty($ingredient['reorder_level'] ?? ''),
                        'supplier_name' => $ingredient['supplier_name'] ?? '',
                        'package_size' => ingredients_format_qty($ingredient['package_size'] ?? ''),
                        'unit_cost' => ingredients_format_cost($ingredient['unit_cost'] ?? ''),
                        'reorder_qty' => ingredients_format_qty($ingredient['reorder_qty'] ?? ''),
                    ]); ?>)'>Edit</button>
                    <button type="button" class="btn-sm btn-delete" onclick='confirmDelete(<?php echo bakery_json_for_html([
                        'id' => $ingredient['id'],
                        'name' => $ingredient['name'] ?? '',
                    ]); ?>)'>Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<!-- Add Ingredient Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>Add New Ingredient</h2></div>
        <form method="POST">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="add_ingredient">

            <div class="form-group">
                <label for="name">Ingredient Name</label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-group">
                <label for="unit">Stock Unit</label>
                <select id="unit" name="unit" required>
                    <?php foreach ($unit_options as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($inventory_ready): ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="quantity_on_hand">Quantity on hand</label>
                    <input type="number" id="quantity_on_hand" name="quantity_on_hand" step="0.001" min="0">
                </div>
                <div class="form-group">
                    <label for="reorder_level"><?php bakery_te('ingredient_prices.reorder_point'); ?></label>
                    <input type="number" id="reorder_level" name="reorder_level" step="0.001" min="0">
                </div>
            </div>
            <?php if ($reorder_qty_ready): ?>
            <div class="form-group">
                <label for="reorder_qty"><?php bakery_te('ingredient_prices.reorder_qty'); ?></label>
                <input type="number" id="reorder_qty" name="reorder_qty" step="0.001" min="0">
            </div>
            <?php endif; ?>
            <?php if ($purchasing_ready): ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="package_size">Package size</label>
                    <input type="number" id="package_size" name="package_size" step="0.001" min="0" placeholder="Amount per package">
                </div>
                <div class="form-group">
                    <label for="unit_cost">Cost per package ($)</label>
                    <input type="number" id="unit_cost" name="unit_cost" step="0.01" min="0">
                </div>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="supplier_name">Supplier (optional)</label>
                <input type="text" id="supplier_name" name="supplier_name" maxlength="255">
            </div>
            <?php endif; ?>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="hideModal('addModal')">Cancel</button>
                <button type="submit" class="btn-primary">Add Ingredient</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Ingredient Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>Edit Ingredient</h2></div>
        <form method="POST">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="edit_ingredient">
            <input type="hidden" name="id" id="edit_id">

            <div class="form-group">
                <label for="edit_name">Ingredient Name</label>
                <input type="text" id="edit_name" name="name" required>
            </div>

            <div class="form-group">
                <label for="edit_unit">Stock Unit</label>
                <select id="edit_unit" name="unit" required>
                    <?php foreach ($unit_options as $value => $label): ?>
                        <option value="<?php echo htmlspecialchars($value); ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($inventory_ready): ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="edit_quantity_on_hand">Quantity on hand</label>
                    <input type="number" id="edit_quantity_on_hand" name="quantity_on_hand" step="0.001" min="0">
                </div>
                <div class="form-group">
                    <label for="edit_reorder_level"><?php bakery_te('ingredient_prices.reorder_point'); ?></label>
                    <input type="number" id="edit_reorder_level" name="reorder_level" step="0.001" min="0">
                </div>
            </div>
            <?php if ($reorder_qty_ready): ?>
            <div class="form-group">
                <label for="edit_reorder_qty"><?php bakery_te('ingredient_prices.reorder_qty'); ?></label>
                <input type="number" id="edit_reorder_qty" name="reorder_qty" step="0.001" min="0">
            </div>
            <?php endif; ?>
            <?php if ($purchasing_ready): ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="edit_package_size">Package size</label>
                    <input type="number" id="edit_package_size" name="package_size" step="0.001" min="0">
                </div>
                <div class="form-group">
                    <label for="edit_unit_cost">Cost per package ($)</label>
                    <input type="number" id="edit_unit_cost" name="unit_cost" step="0.01" min="0">
                </div>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="edit_supplier_name">Supplier (optional)</label>
                <input type="text" id="edit_supplier_name" name="supplier_name" maxlength="255">
            </div>
            <?php endif; ?>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const searchInput = document.getElementById('inventorySearch');
    const returnQ = document.getElementById('returnQ');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            if (returnQ) {
                returnQ.value = this.value.trim();
            }
            document.querySelectorAll('.inventory-item').forEach(function (item) {
                const haystack = item.getAttribute('data-search') || '';
                item.classList.toggle('hidden-by-search', query !== '' && !haystack.includes(query));
            });
        });
    }

    document.querySelectorAll('.qty-stepper').forEach(function (stepper) {
        const input = stepper.querySelector('.qty-input');
        const item = stepper.closest('.inventory-item');
        const syncField = item ? item.querySelector('.sync-qty') : null;

        function syncQty() {
            if (syncField) {
                syncField.value = input.value;
            }
        }

        stepper.querySelectorAll('.qty-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const step = parseFloat(btn.getAttribute('data-step')) || 0;
                const current = parseFloat(input.value) || 0;
                const next = Math.max(0, Math.round((current + step) * 1000) / 1000);
                input.value = next === 0 ? '' : String(next);
                syncQty();
            });
        });

        input.addEventListener('input', syncQty);
    });

    document.querySelectorAll('.details-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const panel = toggle.nextElementSibling;
            const isOpen = panel.classList.toggle('open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            toggle.textContent = isOpen ? 'Hide package & supplier details' : 'Package & supplier details';
        });
    });
})();

function showAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function showEditModal(ingredient) {
    document.getElementById('edit_id').value = ingredient.id;
    document.getElementById('edit_name').value = ingredient.name;
    document.getElementById('edit_unit').value = ingredient.unit;
    const fields = [
        ['edit_quantity_on_hand', 'quantity_on_hand'],
        ['edit_reorder_level', 'reorder_level'],
        ['edit_supplier_name', 'supplier_name'],
        ['edit_package_size', 'package_size'],
        ['edit_unit_cost', 'unit_cost'],
        ['edit_reorder_qty', 'reorder_qty'],
    ];
    fields.forEach(function ([elementId, key]) {
        const el = document.getElementById(elementId);
        if (el) {
            el.value = ingredient[key] ?? '';
        }
    });
    document.getElementById('editModal').style.display = 'block';
}

function hideModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function confirmDelete(ingredient) {
    if (!confirm('Delete ' + ingredient.name + '? This cannot be undone if the ingredient is used in formulas.')) {
        return;
    }
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML =
        '<input type="hidden" name="csrf_token" value="' + csrf + '">' +
        '<input type="hidden" name="action" value="delete_ingredient">' +
        '<input type="hidden" name="id" value="' + ingredient.id + '">';
    document.body.appendChild(form);
    form.submit();
}

window.addEventListener('click', function (event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
