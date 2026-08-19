<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

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
                    if (bakery_ingredients_inventory_ready($db) && bakery_ingredients_purchasing_ready($db)) {
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
                    if (bakery_ingredients_inventory_ready($db) && bakery_ingredients_purchasing_ready($db)) {
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
    }
}

$inventory_ready = bakery_ingredients_inventory_ready($db);
$purchasing_ready = bakery_ingredients_purchasing_ready($db);
$low_stock_ingredients = $inventory_ready ? bakery_low_stock_ingredients($db) : [];
$active_view = ($_GET['view'] ?? 'count') === 'manage' ? 'manage' : 'count';
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
    </nav>

    <?php if ($active_view === 'count'): ?>
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
                <article class="inventory-item<?php echo $isLowStock ? ' low-stock' : ''; ?>" data-search="<?php echo htmlspecialchars(strtolower($ingredient['name'] . ' ' . ($ingredient['supplier_name'] ?? ''))); ?>">
                    <div class="inventory-item-main">
                        <div class="inventory-item-header">
                            <h2 class="inventory-item-name">
                                <?php echo htmlspecialchars($ingredient['name'] ?? ''); ?>
                                <?php if ($isLowStock): ?>
                                    <span class="low-stock-badge">Low</span>
                                <?php endif; ?>
                            </h2>
                        </div>
                        <?php if ($inventory_ready && ($purchasing_ready || !empty($ingredient['supplier_name']) || ($ingredient['reorder_level'] !== null && $ingredient['reorder_level'] !== '') || ($ingredient['package_size'] ?? null) !== null)): ?>
                        <div class="inventory-meta">
                            <?php if ($packageLabel): ?>
                                <span><?php echo htmlspecialchars($packageLabel); ?> pkg</span>
                            <?php endif; ?>
                            <?php if ($purchasing_ready && $ingredient['unit_cost'] !== null && $ingredient['unit_cost'] !== ''): ?>
                                <span>$<?php echo htmlspecialchars(ingredients_format_cost($ingredient['unit_cost'])); ?>/pkg</span>
                            <?php endif; ?>
                            <?php if (!empty($ingredient['supplier_name'])): ?>
                                <span><?php echo htmlspecialchars($ingredient['supplier_name']); ?></span>
                            <?php endif; ?>
                            <?php if ($ingredient['reorder_level'] !== null && $ingredient['reorder_level'] !== ''): ?>
                                <span>Reorder <?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_level'])); ?> <?php echo htmlspecialchars($ingredient['unit'] ?? ''); ?></span>
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
                                    <label>Reorder level</label>
                                    <input type="number" name="reorder_level" step="0.001" min="0" value="<?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_level'] ?? '')); ?>">
                                </div>
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
                <h3>
                    <?php echo htmlspecialchars($ingredient['name'] ?? ''); ?>
                    <?php if ($isLowStock): ?><span class="low-stock-badge">Low</span><?php endif; ?>
                </h3>
                <div class="meta">
                    Unit: <?php echo htmlspecialchars($ingredient['unit'] ?? '—'); ?><br>
                    <?php if ($inventory_ready): ?>
                        On hand: <?php echo htmlspecialchars(ingredients_format_qty($ingredient['quantity_on_hand'] ?? '') ?: '—'); ?><br>
                        Reorder: <?php echo htmlspecialchars(ingredients_format_qty($ingredient['reorder_level'] ?? '') ?: '—'); ?><br>
                    <?php endif; ?>
                    <?php if ($purchasing_ready && $packageLabel): ?>
                        Package: <?php echo htmlspecialchars($packageLabel); ?><br>
                    <?php endif; ?>
                    <?php if ($purchasing_ready && $ingredient['unit_cost'] !== null && $ingredient['unit_cost'] !== ''): ?>
                        Cost: $<?php echo htmlspecialchars(ingredients_format_cost($ingredient['unit_cost'])); ?>/pkg<br>
                    <?php endif; ?>
                    <?php if (!empty($ingredient['supplier_name'])): ?>
                        Supplier: <?php echo htmlspecialchars($ingredient['supplier_name']); ?>
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
                    <label for="reorder_level">Reorder level</label>
                    <input type="number" id="reorder_level" name="reorder_level" step="0.001" min="0">
                </div>
            </div>
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
                    <label for="edit_reorder_level">Reorder level</label>
                    <input type="number" id="edit_reorder_level" name="reorder_level" step="0.001" min="0">
                </div>
            </div>
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
