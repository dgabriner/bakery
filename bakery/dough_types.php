<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/ingredient_requirements.php';

$batchReferenceReady = bakery_ingredient_batch_reference_ready($db);

// Set page title
$page_title = bakery_t('page.dough_types');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    $batchGrams = ($batchReferenceReady && isset($_POST['standard_batch_dough_grams']) && $_POST['standard_batch_dough_grams'] !== '')
                        ? (float)$_POST['standard_batch_dough_grams']
                        : null;
                    if ($batchReferenceReady) {
                        $stmt = $db->prepare('INSERT INTO dough_types (name, description, product_line_id, standard_batch_dough_grams) VALUES (?, ?, ?, ?)');
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['description'] ?? '',
                            $_POST['product_line_id'] ?: null,
                            $batchGrams,
                        ]);
                    } else {
                        $stmt = $db->prepare('INSERT INTO dough_types (name, description, product_line_id) VALUES (?, ?, ?)');
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['description'] ?? '',
                            $_POST['product_line_id'] ?: null,
                        ]);
                    }
                    header("Location: dough_types.php?success=created");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to create dough type: " . $e->getMessage();
                }
                break;

            case 'update':
                try {
                    $batchGrams = ($batchReferenceReady && isset($_POST['standard_batch_dough_grams']) && $_POST['standard_batch_dough_grams'] !== '')
                        ? (float)$_POST['standard_batch_dough_grams']
                        : null;
                    if ($batchReferenceReady) {
                        $stmt = $db->prepare('UPDATE dough_types SET name = ?, description = ?, product_line_id = ?, standard_batch_dough_grams = ? WHERE id = ?');
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['description'] ?? '',
                            $_POST['product_line_id'] ?: null,
                            $batchGrams,
                            $_POST['id'],
                        ]);
                    } else {
                        $stmt = $db->prepare('UPDATE dough_types SET name = ?, description = ?, product_line_id = ? WHERE id = ?');
                        $stmt->execute([
                            $_POST['name'],
                            $_POST['description'] ?? '',
                            $_POST['product_line_id'] ?: null,
                            $_POST['id'],
                        ]);
                    }
                    header("Location: dough_types.php?success=updated");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to update dough type: " . $e->getMessage();
                }
                break;

            case 'delete':
                try {
                    $stmt = $db->prepare("DELETE FROM dough_types WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    header("Location: dough_types.php?success=deleted");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to delete dough type: " . $e->getMessage();
                }
                break;

            case 'create_product_line':
                try {
                    $stmt = $db->prepare("INSERT INTO product_lines (name, description, color_code, sort_order) VALUES (?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'] ?? '',
                        $_POST['color_code'] ?? '#3498db',
                        $_POST['sort_order'] ?? 0
                    ]);
                    header("Location: dough_types.php?success=product_line_created");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to create product line: " . $e->getMessage();
                }
                break;

            case 'update_product_line':
                try {
                    $stmt = $db->prepare("UPDATE product_lines SET name = ?, description = ?, color_code = ?, sort_order = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'] ?? '',
                        $_POST['color_code'] ?? '#3498db',
                        $_POST['sort_order'] ?? 0,
                        $_POST['id']
                    ]);
                    header("Location: dough_types.php?success=product_line_updated");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to update product line: " . $e->getMessage();
                }
                break;

            case 'delete_product_line':
                try {
                    $stmt = $db->prepare("DELETE FROM product_lines WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    header("Location: dough_types.php?success=product_line_deleted");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to delete product line: " . $e->getMessage();
                }
                break;

            case 'update_product_dough_type':
                try {
                    $stmt = $db->prepare("UPDATE products SET dough_type_id = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['dough_type_id'] === 'unclassified' ? null : $_POST['dough_type_id'],
                        $_POST['product_id']
                    ]);
                    echo json_encode(['success' => true]);
                    exit;
                } catch (Exception $e) {
                    http_response_code(500);
                    echo json_encode(['error' => $e->getMessage()]);
                    exit;
                }
                break;

            case 'update_dough_type_product_line':
                try {
                    $stmt = $db->prepare("UPDATE dough_types SET product_line_id = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['product_line_id'] === 'unassigned' ? null : $_POST['product_line_id'],
                        $_POST['dough_type_id']
                    ]);
                    echo json_encode(['success' => true]);
                    exit;
                } catch (Exception $e) {
                    http_response_code(500);
                    echo json_encode(['error' => $e->getMessage()]);
                    exit;
                }
                break;

            case 'update_product_weight':
                try {
                    $stmt = $db->prepare("UPDATE products SET weight_grams = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['weight_grams'] ?: null,
                        $_POST['product_id']
                    ]);
                    echo json_encode(['success' => true]);
                    exit;
                } catch (Exception $e) {
                    http_response_code(500);
                    echo json_encode(['error' => $e->getMessage()]);
                    exit;
                }
                break;
        }
    }
}

// Include header
require_once 'includes/header.php';

// Include navigation
require_once 'includes/nav.php';

// Get success message if any
$success_message = '';
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'created':
            $success_message = 'Dough type created successfully!';
            break;
        case 'updated':
            $success_message = 'Dough type updated successfully!';
            break;
        case 'deleted':
            $success_message = 'Dough type deleted successfully!';
            break;
        case 'product_line_created':
            $success_message = 'Product line created successfully!';
            break;
        case 'product_line_updated':
            $success_message = 'Product line updated successfully!';
            break;
        case 'product_line_deleted':
            $success_message = 'Product line deleted successfully!';
            break;
    }
}

function dough_types_line_class($name) {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', (string)$name), '-'));
    $known = [
        'sour-flour' => 'product-line-sour-flour',
        'pan-dulce' => 'product-line-pan-dulce',
        'pan-grande' => 'product-line-pan-grande',
        'traditional' => 'product-line-traditional',
    ];
    return $known[$slug] ?? 'product-line-custom';
}

function dough_types_darken_hex($hex, $factor = 0.85) {
    $hex = ltrim((string)$hex, '#');
    if (strlen($hex) !== 6) {
        return '#2980b9';
    }
    $r = max(0, min(255, (int)round(hexdec(substr($hex, 0, 2)) * $factor)));
    $g = max(0, min(255, (int)round(hexdec(substr($hex, 2, 2)) * $factor)));
    $b = max(0, min(255, (int)round(hexdec(substr($hex, 4, 2)) * $factor)));
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}

try {
    $product_lines = $db->query(
        'SELECT * FROM product_lines ORDER BY sort_order, name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $dough_types_rows = $db->query(
        'SELECT dt.*, pl.name AS product_line_name, pl.color_code AS product_line_color
         FROM dough_types dt
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         ORDER BY pl.sort_order, dt.name'
    )->fetchAll(PDO::FETCH_ASSOC);
    $all_products = $db->query(
        'SELECT p.id, p.name, p.weight_grams, p.dough_type_id, dt.name AS dough_type_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         ORDER BY p.name'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $product_lines = [];
    $dough_types_rows = [];
    $all_products = [];
    $error = 'Failed to load dough types: ' . $e->getMessage();
}

$products_by_dough = [];
$unclassified_products = [];
foreach ($all_products as $product) {
    if (empty($product['dough_type_id'])) {
        $unclassified_products[] = $product;
        continue;
    }
    $products_by_dough[(int)$product['dough_type_id']][] = $product;
}

$dough_by_line = [];
$unassigned_dough_types = [];
foreach ($dough_types_rows as $dough_type) {
    if (empty($dough_type['product_line_id'])) {
        $unassigned_dough_types[] = $dough_type;
        continue;
    }
    $dough_by_line[(int)$dough_type['product_line_id']][] = $dough_type;
}
?>

<style>
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.page-title {
    font-size: 2rem;
    color: #2c3e50;
    margin: 0;
}

.btn-primary {
    background-color: #3498db;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    transition: background-color 0.2s;
}

.btn-primary:hover {
    background-color: #2980b9;
}

.btn-secondary {
    background-color: #95a5a6;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    transition: background-color 0.2s;
}

.btn-secondary:hover {
    background-color: #7f8c8d;
}

.btn-danger {
    background-color: #e74c3c;
    color: white;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    transition: background-color 0.2s;
}

.btn-danger:hover {
    background-color: #c0392b;
}

.dough-types-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-top: 1.5rem;
}

.dough-type-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.dough-type-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.card-header {
    background-color: #f8f9fa;
    padding: 1.25rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: #2c3e50;
}

.card-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 50%;
    transition: background-color 0.2s;
}

.btn-icon:hover {
    background-color: rgba(0, 0, 0, 0.05);
}

.edit-btn {
    color: #3498db;
}

.delete-btn {
    color: #e74c3c;
}

.card-content {
    padding: 1.25rem;
}

.card-description {
    color: #666;
    margin-bottom: 1.25rem;
    line-height: 1.5;
}

.card-section {
    border-top: 1px solid #e9ecef;
    padding: 1.25rem;
}

.card-section h3 {
    margin: 0 0 1rem 0;
    font-size: 1rem;
    color: #2c3e50;
}

.products-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.products-list li {
    padding: 0.75rem;
    margin: 0.25rem 0;
    border-radius: 6px;
    background-color: white;
    border: 1px solid #e9ecef;
}

.products-list a {
    color: #3498db;
    text-decoration: none;
    transition: color 0.2s;
}

.products-list a:hover {
    color: #2980b9;
}

.no-items {
    color: #95a5a6;
    font-style: italic;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 1000;
}

.modal-content {
    background-color: white;
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    margin: 2rem auto;
    position: relative;
    padding: 2rem;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

.modal-content h2 {
    margin: 0 0 1.5rem 0;
    color: #2c3e50;
}

.close {
    position: absolute;
    right: 1.5rem;
    top: 1.5rem;
    font-size: 1.5rem;
    cursor: pointer;
    color: #95a5a6;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    color: #2c3e50;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 2rem;
}

.error {
    background-color: #fde8e8;
    color: #c0392b;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.success-message {
    background-color: #d4edda;
    color: #155724;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.unclassified-card {
    grid-column: 1 / -1;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.unclassified-header {
    background-color: #f8f9fa;
    padding: 1.25rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.unclassified-header h2 {
    margin: 0;
    font-size: 1.25rem;
    color: #2c3e50;
}

.unclassified-content {
    padding: 1.25rem;
}

.unclassified-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
}

.unclassified-product {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    background: white;
}

.draggable {
    cursor: move;
    padding: 0.5rem 1rem;
    margin: 0;
    border: none;
    background: transparent;
}

.dough-type-buttons {
    display: flex;
    gap: 0.25rem;
}

.dough-type-btn {
    width: 28px;
    height: 28px;
    border: 1px solid #3498db;
    background: white;
    color: #3498db;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: bold;
    transition: all 0.2s;
}

.dough-type-btn:hover {
    background: #3498db;
    color: white;
}

.unclassified-count {
    background-color: #e74c3c;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.8rem;
    margin-left: 0.5rem;
}

.product-line-section {
    margin-bottom: 3rem;
}

.product-line-header {
    background: linear-gradient(135deg, var(--product-line-color), var(--product-line-color-dark));
    color: white;
    padding: 1.5rem 2rem;
    border-radius: 12px 12px 0 0;
    margin-bottom: 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.product-line-title {
    margin: 0;
    font-size: 1.5rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}

.product-line-actions {
    display: flex;
    gap: 0.5rem;
}

.btn-white {
    background: rgba(255, 255, 255, 0.2);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.3);
    padding: 0.5rem 1rem;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-white:hover {
    background: rgba(255, 255, 255, 0.3);
    border-color: rgba(255, 255, 255, 0.5);
}

.product-line-content {
    background: white;
    border-radius: 0 0 12px 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    min-height: 200px;
}

.product-line-dough-types {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    padding: 2rem;
}

.unassigned-section {
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 12px;
    margin-bottom: 3rem;
}

.unassigned-header {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid #dee2e6;
    background: #e9ecef;
    border-radius: 10px 10px 0 0;
}

.unassigned-content {
    padding: 2rem;
}

.unassigned-dough-types {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}

.product-line-management {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    margin-bottom: 2rem;
    overflow: hidden;
}

.management-header {
    background: #34495e;
    color: white;
    padding: 1.5rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.management-content {
    padding: 2rem;
}

.product-lines-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.product-line-item {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.product-line-color {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    margin-right: 0.5rem;
}

.product-line-info {
    display: flex;
    align-items: center;
    flex-grow: 1;
}

.dough-type-card.drop-target-active {
    border: 2px dashed #3498db;
    background-color: rgba(52, 152, 219, 0.1);
}

/* Color variables for each product line */
.product-line-sour-flour {
    --product-line-color: #e67e22;
    --product-line-color-dark: #d35400;
}

.product-line-pan-dulce {
    --product-line-color: #e74c3c;
    --product-line-color-dark: #c0392b;
}

.product-line-pan-grande {
    --product-line-color: #2ecc71;
    --product-line-color-dark: #27ae60;
}

.product-line-traditional {
    --product-line-color: #3498db;
    --product-line-color-dark: #2980b9;
}

.product-line-unassigned {
    --product-line-color: #95a5a6;
    --product-line-color-dark: #7f8c8d;
}

.color-input {
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.product-line-assignment-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.5rem;
}

.product-line-assign-btn {
    color: white;
    border: 2px solid;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.2s;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.product-line-assign-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    filter: brightness(1.1);
}

.product-line-assign-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.product-line-assign-btn.unassign-btn {
    background-color: #95a5a6;
    border-color: #95a5a6;
}

.product-line-assign-btn.unassign-btn:hover {
    background-color: #7f8c8d;
    border-color: #7f8c8d;
}

.current-assignment {
    border-left: 4px solid #3498db;
}

.current-assignment.assigned {
    border-left-color: #27ae60;
    background-color: #d5f4e6 !important;
}

.loading {
    position: relative;
    pointer-events: none;
    opacity: 0.6;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #3498db;
    border-radius: 50%;
    animation: spin 1s linear infinite;
    z-index: 1000;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.product-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    margin: 0.25rem 0;
    border-radius: 6px;
    background-color: white;
    border: 1px solid #e9ecef;
    transition: all 0.2s;
}

.product-item:hover {
    border-color: #3498db;
    box-shadow: 0 2px 4px rgba(52, 152, 219, 0.1);
}

.product-info {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-grow: 1;
}

.product-name {
    font-weight: 500;
    color: #2c3e50;
}

.product-weight {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.9rem;
    color: #666;
}

.weight-input {
    width: 60px;
    padding: 0.25rem 0.5rem;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 0.9rem;
    text-align: center;
}

.weight-input:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

.weight-unit {
    color: #95a5a6;
    font-size: 0.8rem;
}

.no-weight {
    color: #e74c3c;
    font-style: italic;
    font-size: 0.8rem;
}
</style>

<!-- BUILD: dough-types-20260804 -->
<div class="container">
    <div class="page-header">
        <h1 class="page-title">Dough Types &amp; Product Lines</h1>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <button type="button" class="btn-primary" onclick="showDoughTypeModal()">
                <span aria-hidden="true">+</span> Add Dough Type
            </button>
            <button type="button" class="btn-secondary" onclick="showProductLineModal()">
                <span aria-hidden="true">+</span> Add Product Line
            </button>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="error"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <section class="product-line-management" aria-label="Product line management">
        <div class="management-header">
            <h2 style="margin:0; font-size:1.25rem;">Product Lines</h2>
            <button type="button" class="btn-white" onclick="showProductLineModal()">Manage lines</button>
        </div>
        <div class="management-content">
            <?php if (empty($product_lines)): ?>
                <p class="no-items">No product lines yet. Create one to organize dough types.</p>
            <?php else: ?>
                <div class="product-lines-list">
                    <?php foreach ($product_lines as $line): ?>
                        <div class="product-line-item" data-line-id="<?php echo (int)$line['id']; ?>">
                            <div class="product-line-info">
                                <span class="product-line-color" style="background-color: <?php echo htmlspecialchars($line['color_code'] ?? '#3498db'); ?>;"></span>
                                <div>
                                    <strong><?php echo htmlspecialchars($line['name']); ?></strong>
                                    <?php if (!empty($line['description'])): ?>
                                        <div style="color:#666; font-size:0.85rem;"><?php echo htmlspecialchars($line['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-actions">
                                <button type="button" class="btn-icon edit-btn" title="Edit product line" onclick='showProductLineModal(<?php echo (int)$line['id']; ?>, <?php echo bakery_json_for_html([
                                    'name' => $line['name'],
                                    'description' => $line['description'] ?? '',
                                    'color_code' => $line['color_code'] ?? '#3498db',
                                    'sort_order' => (int)($line['sort_order'] ?? 0),
                                ]); ?>)'>&#9998;</button>
                                <button type="button" class="btn-icon delete-btn" title="Delete product line" onclick="confirmDeleteProductLine(<?php echo (int)$line['id']; ?>, <?php echo bakery_json_for_html($line['name']); ?>)">&#128465;</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!empty($unclassified_products)): ?>
        <section class="unclassified-card" aria-label="Unclassified products">
            <div class="unclassified-header">
                <h2>
                    Unclassified Products
                    <span class="unclassified-count"><?php echo count($unclassified_products); ?></span>
                </h2>
            </div>
            <div class="unclassified-content">
                <p style="color:#666; margin-top:0;">Assign each product to a dough type.</p>
                <div class="unclassified-grid">
                    <?php foreach ($unclassified_products as $product): ?>
                        <div class="unclassified-product" data-product-id="<?php echo (int)$product['id']; ?>">
                            <span class="draggable" draggable="true" data-product-id="<?php echo (int)$product['id']; ?>">
                                <?php echo htmlspecialchars($product['name']); ?>
                            </span>
                            <div class="dough-type-buttons" aria-label="Assign dough type">
                                <?php foreach ($dough_types_rows as $dough_type): ?>
                                    <button type="button"
                                        class="dough-type-btn"
                                        title="Assign to <?php echo htmlspecialchars($dough_type['name']); ?>"
                                        onclick="assignProductToDoughType(<?php echo (int)$product['id']; ?>, <?php echo (int)$dough_type['id']; ?>)">
                                        <?php echo htmlspecialchars(substr($dough_type['name'], 0, 1)); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php foreach ($product_lines as $line):
        $line_id = (int)$line['id'];
        $line_dough_types = $dough_by_line[$line_id] ?? [];
        $line_color = $line['color_code'] ?? '#3498db';
        $line_class = dough_types_line_class($line['name']);
        ?>
        <section class="product-line-section <?php echo htmlspecialchars($line_class); ?>"
            style="--product-line-color: <?php echo htmlspecialchars($line_color); ?>; --product-line-color-dark: <?php echo htmlspecialchars(dough_types_darken_hex($line_color)); ?>;">
            <div class="product-line-header">
                <h2 class="product-line-title"><?php echo htmlspecialchars($line['name']); ?></h2>
                <div class="product-line-actions">
                    <button type="button" class="btn-white" onclick="showDoughTypeModal(null, <?php echo (int)$line_id; ?>)">Add dough type</button>
                </div>
            </div>
            <div class="product-line-content">
                <?php if (empty($line_dough_types)): ?>
                    <p class="no-items" style="padding:2rem;">No dough types assigned to this line yet.</p>
                <?php else: ?>
                    <div class="product-line-dough-types">
                        <?php foreach ($line_dough_types as $dough_type):
                            $dough_id = (int)$dough_type['id'];
                            $dough_products = $products_by_dough[$dough_id] ?? [];
                            ?>
                            <article class="dough-type-card drop-target"
                                data-dough-type-id="<?php echo $dough_id; ?>"
                                data-product-line-id="<?php echo $line_id; ?>">
                                <div class="card-header">
                                    <h2><?php echo htmlspecialchars($dough_type['name']); ?></h2>
                                    <div class="card-actions">
                                        <button type="button" class="btn-icon edit-btn" title="Edit dough type"
                                            onclick='showDoughTypeModal(<?php echo $dough_id; ?>, <?php echo (int)($dough_type['product_line_id'] ?? 0); ?>, <?php echo bakery_json_for_html([
                                                'name' => $dough_type['name'],
                                                'description' => $dough_type['description'] ?? '',
                                                'standard_batch_dough_grams' => $dough_type['standard_batch_dough_grams'] ?? '',
                                            ]); ?>)'>&#9998;</button>
                                        <button type="button" class="btn-icon delete-btn" title="Delete dough type"
                                            onclick="confirmDeleteDoughType(<?php echo $dough_id; ?>, <?php echo bakery_json_for_html($dough_type['name']); ?>)">&#128465;</button>
                                    </div>
                                </div>
                                <div class="card-content">
                                    <p class="card-description">
                                        <?php echo $dough_type['description'] !== '' && $dough_type['description'] !== null
                                            ? htmlspecialchars($dough_type['description'])
                                            : '<span class="no-items">No description</span>'; ?>
                                    </p>
                                    <?php if ($batchReferenceReady): ?>
                                        <p class="card-batch-ref">
                                            <?php if (!empty($dough_type['standard_batch_dough_grams'])): ?>
                                                Standard batch: <?php echo rtrim(rtrim(number_format((float)$dough_type['standard_batch_dough_grams'], 1), '0'), '.'); ?> g dough
                                            <?php else: ?>
                                                <span class="no-items">No standard batch size — Ingredient Planner uses continuous dough grams</span>
                                            <?php endif; ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="product-line-assignment-buttons">
                                        <button type="button"
                                            class="product-line-assign-btn unassign-btn"
                                            onclick="assignDoughTypeToLine(<?php echo $dough_id; ?>, 'unassigned')">
                                            Unassign line
                                        </button>
                                        <?php foreach ($product_lines as $assign_line):
                                            if ((int)$assign_line['id'] === $line_id) {
                                                continue;
                                            }
                                            $assign_color = $assign_line['color_code'] ?? '#3498db';
                                            ?>
                                            <button type="button"
                                                class="product-line-assign-btn"
                                                style="background-color: <?php echo htmlspecialchars($assign_color); ?>; border-color: <?php echo htmlspecialchars($assign_color); ?>;"
                                                onclick="assignDoughTypeToLine(<?php echo $dough_id; ?>, <?php echo (int)$assign_line['id']; ?>)">
                                                <?php echo htmlspecialchars($assign_line['name']); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="card-section">
                                    <h3>Products (<?php echo count($dough_products); ?>)</h3>
                                    <?php if (empty($dough_products)): ?>
                                        <p class="no-items">Drop products here or assign from the unclassified list.</p>
                                    <?php else: ?>
                                        <ul class="products-list">
                                            <?php foreach ($dough_products as $product): ?>
                                                <li class="product-item" data-product-id="<?php echo (int)$product['id']; ?>">
                                                    <div class="product-info">
                                                        <span class="product-name draggable" draggable="true" data-product-id="<?php echo (int)$product['id']; ?>">
                                                            <?php echo htmlspecialchars($product['name']); ?>
                                                        </span>
                                                        <div class="product-weight">
                                                            <input type="number"
                                                                class="weight-input"
                                                                min="0"
                                                                step="1"
                                                                value="<?php echo $product['weight_grams'] !== null ? (int)$product['weight_grams'] : ''; ?>"
                                                                placeholder="—"
                                                                aria-label="Weight in grams for <?php echo htmlspecialchars($product['name']); ?>"
                                                                onchange="updateProductWeight(<?php echo (int)$product['id']; ?>, this.value)">
                                                            <span class="weight-unit">g</span>
                                                            <?php if ($product['weight_grams'] === null || $product['weight_grams'] === ''): ?>
                                                                <span class="no-weight">No weight</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="dough-type-buttons">
                                                        <button type="button" class="dough-type-btn" title="Unassign product"
                                                            onclick="assignProductToDoughType(<?php echo (int)$product['id']; ?>, 'unclassified')">×</button>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <p style="margin:1rem 0 0;">
                                        <a href="<?php echo BASE_URL; ?>formulas.php?dough_type=<?php echo $dough_id; ?>#formula-<?php echo $dough_id; ?>">Edit formula</a>
                                    </p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if (!empty($unassigned_dough_types)): ?>
        <section class="unassigned-section product-line-unassigned" aria-label="Unassigned dough types">
            <div class="unassigned-header">
                <h2 style="margin:0;">Unassigned Dough Types</h2>
            </div>
            <div class="unassigned-content">
                <div class="unassigned-dough-types">
                    <?php foreach ($unassigned_dough_types as $dough_type):
                        $dough_id = (int)$dough_type['id'];
                        $dough_products = $products_by_dough[$dough_id] ?? [];
                        ?>
                        <article class="dough-type-card drop-target current-assignment"
                            data-dough-type-id="<?php echo $dough_id; ?>"
                            data-product-line-id="">
                            <div class="card-header">
                                <h2><?php echo htmlspecialchars($dough_type['name']); ?></h2>
                                <div class="card-actions">
                                    <button type="button" class="btn-icon edit-btn" title="Edit dough type"
                                        onclick='showDoughTypeModal(<?php echo $dough_id; ?>, 0, <?php echo bakery_json_for_html([
                                            'name' => $dough_type['name'],
                                            'description' => $dough_type['description'] ?? '',
                                            'standard_batch_dough_grams' => $dough_type['standard_batch_dough_grams'] ?? '',
                                        ]); ?>)'>&#9998;</button>
                                    <button type="button" class="btn-icon delete-btn" title="Delete dough type"
                                        onclick="confirmDeleteDoughType(<?php echo $dough_id; ?>, <?php echo bakery_json_for_html($dough_type['name']); ?>)">&#128465;</button>
                                </div>
                            </div>
                            <div class="card-content">
                                <p class="card-description">
                                    <?php echo $dough_type['description'] !== '' && $dough_type['description'] !== null
                                        ? htmlspecialchars($dough_type['description'])
                                        : '<span class="no-items">No description</span>'; ?>
                                </p>
                                <div class="product-line-assignment-buttons">
                                    <?php foreach ($product_lines as $assign_line):
                                        $assign_color = $assign_line['color_code'] ?? '#3498db';
                                        ?>
                                        <button type="button"
                                            class="product-line-assign-btn"
                                            style="background-color: <?php echo htmlspecialchars($assign_color); ?>; border-color: <?php echo htmlspecialchars($assign_color); ?>;"
                                            onclick="assignDoughTypeToLine(<?php echo $dough_id; ?>, <?php echo (int)$assign_line['id']; ?>)">
                                            <?php echo htmlspecialchars($assign_line['name']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="card-section">
                                <h3>Products (<?php echo count($dough_products); ?>)</h3>
                                <?php if (empty($dough_products)): ?>
                                    <p class="no-items">No products assigned yet.</p>
                                <?php else: ?>
                                    <ul class="products-list">
                                        <?php foreach ($dough_products as $product): ?>
                                            <li class="product-item">
                                                <div class="product-info">
                                                    <span class="product-name"><?php echo htmlspecialchars($product['name']); ?></span>
                                                    <div class="product-weight">
                                                        <input type="number" class="weight-input" min="0" step="1"
                                                            value="<?php echo $product['weight_grams'] !== null ? (int)$product['weight_grams'] : ''; ?>"
                                                            onchange="updateProductWeight(<?php echo (int)$product['id']; ?>, this.value)">
                                                        <span class="weight-unit">g</span>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>

<div id="doughTypeModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="doughTypeModalTitle">
    <div class="modal-content">
        <button type="button" class="close" onclick="hideDoughTypeModal()" aria-label="Close">&times;</button>
        <h2 id="doughTypeModalTitle">Add Dough Type</h2>
        <form method="POST">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" id="doughTypeId" value="">
            <div class="form-group">
                <label for="doughTypeName">Name *</label>
                <input type="text" id="doughTypeName" name="name" required>
            </div>
            <div class="form-group">
                <label for="doughTypeDescription">Description</label>
                <textarea id="doughTypeDescription" name="description" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label for="doughTypeProductLine">Product Line</label>
                <select id="doughTypeProductLine" name="product_line_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($product_lines as $line): ?>
                        <option value="<?php echo (int)$line['id']; ?>"><?php echo htmlspecialchars($line['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($batchReferenceReady): ?>
            <div class="form-group">
                <label for="doughTypeBatchGrams">Standard batch dough (grams)</label>
                <input type="number" id="doughTypeBatchGrams" name="standard_batch_dough_grams" min="0" step="1" placeholder="e.g. 18000 for one standard mix">
                <small>Optional. One reference mix weight for batch planning in Ingredient Planner. Products with weight_grams derive units/batch from this.</small>
            </div>
            <?php endif; ?>
            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="hideDoughTypeModal()">Cancel</button>
                <button type="submit" class="btn-primary">Save Dough Type</button>
            </div>
        </form>
    </div>
</div>

<div id="productLineModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="productLineModalTitle">
    <div class="modal-content">
        <button type="button" class="close" onclick="hideProductLineModal()" aria-label="Close">&times;</button>
        <h2 id="productLineModalTitle">Add Product Line</h2>
        <form method="POST">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="create_product_line">
            <input type="hidden" name="id" id="productLineId" value="">
            <div class="form-group">
                <label for="productLineName">Name *</label>
                <input type="text" id="productLineName" name="name" required>
            </div>
            <div class="form-group">
                <label for="productLineDescription">Description</label>
                <textarea id="productLineDescription" name="description" rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="productLineColor">Color</label>
                    <input type="color" id="productLineColor" name="color_code" value="#3498db" class="color-input">
                </div>
                <div class="form-group">
                    <label for="productLineSortOrder">Sort order</label>
                    <input type="number" id="productLineSortOrder" name="sort_order" value="0" min="0">
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="hideProductLineModal()">Cancel</button>
                <button type="submit" class="btn-primary">Save Product Line</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteDoughTypeModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteDoughTypeTitle">
    <div class="modal-content">
        <h2 id="deleteDoughTypeTitle">Delete dough type?</h2>
        <p>Delete <strong id="deleteDoughTypeName"></strong>? Products linked to this dough type will also be removed.</p>
        <form method="POST">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="deleteDoughTypeId" value="">
            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="hideDeleteDoughTypeModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteProductLineModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteProductLineTitle">
    <div class="modal-content">
        <h2 id="deleteProductLineTitle">Delete product line?</h2>
        <p>Delete <strong id="deleteProductLineName"></strong>? Dough types on this line will become unassigned.</p>
        <form method="POST">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="delete_product_line">
            <input type="hidden" name="id" id="deleteProductLineId" value="">
            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="hideDeleteProductLineModal()">Cancel</button>
                <button type="submit" class="btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function showDoughTypeModal(id = null, productLineId = null, data = null) {
    const modal = document.getElementById('doughTypeModal');
    const form = modal.querySelector('form');
    const title = document.getElementById('doughTypeModalTitle');
    const actionInput = form.querySelector('input[name="action"]');
    const idInput = form.querySelector('input[name="id"]');
    form.reset();
    actionInput.value = id ? 'update' : 'create';
    idInput.value = id || '';
    title.textContent = id ? 'Edit Dough Type' : 'Add Dough Type';
    if (data) {
        form.elements.name.value = data.name || '';
        form.elements.description.value = data.description || '';
        if (form.elements.standard_batch_dough_grams) {
            form.elements.standard_batch_dough_grams.value = data.standard_batch_dough_grams ?? '';
        }
    }
    if (productLineId) {
        form.elements.product_line_id.value = String(productLineId);
    }
    modal.style.display = 'block';
    form.elements.name.focus();
}

function hideDoughTypeModal() {
    document.getElementById('doughTypeModal').style.display = 'none';
}

function showProductLineModal(id = null, data = null) {
    const modal = document.getElementById('productLineModal');
    const form = modal.querySelector('form');
    const title = document.getElementById('productLineModalTitle');
    const actionInput = form.querySelector('input[name="action"]');
    const idInput = form.querySelector('input[name="id"]');
    form.reset();
    actionInput.value = id ? 'update_product_line' : 'create_product_line';
    idInput.value = id || '';
    title.textContent = id ? 'Edit Product Line' : 'Add Product Line';
    if (data) {
        form.elements.name.value = data.name || '';
        form.elements.description.value = data.description || '';
        form.elements.color_code.value = data.color_code || '#3498db';
        form.elements.sort_order.value = data.sort_order ?? 0;
    }
    modal.style.display = 'block';
    form.elements.name.focus();
}

function hideProductLineModal() {
    document.getElementById('productLineModal').style.display = 'none';
}

function confirmDeleteDoughType(id, name) {
    document.getElementById('deleteDoughTypeId').value = id;
    document.getElementById('deleteDoughTypeName').textContent = name;
    document.getElementById('deleteDoughTypeModal').style.display = 'block';
}

function hideDeleteDoughTypeModal() {
    document.getElementById('deleteDoughTypeModal').style.display = 'none';
}

function confirmDeleteProductLine(id, name) {
    document.getElementById('deleteProductLineId').value = id;
    document.getElementById('deleteProductLineName').textContent = name;
    document.getElementById('deleteProductLineModal').style.display = 'block';
}

function hideDeleteProductLineModal() {
    document.getElementById('deleteProductLineModal').style.display = 'none';
}

async function postDoughTypesAction(action, payload) {
    const body = new URLSearchParams({ action, ...payload });
    const response = await fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.error) {
        throw new Error(data.error || 'Request failed');
    }
    return data;
}

async function assignProductToDoughType(productId, doughTypeId) {
    try {
        await postDoughTypesAction('update_product_dough_type', {
            product_id: productId,
            dough_type_id: doughTypeId,
        });
        window.location.reload();
    } catch (error) {
        window.alert(error.message);
    }
}

async function assignDoughTypeToLine(doughTypeId, productLineId) {
    try {
        await postDoughTypesAction('update_dough_type_product_line', {
            dough_type_id: doughTypeId,
            product_line_id: productLineId,
        });
        window.location.reload();
    } catch (error) {
        window.alert(error.message);
    }
}

async function updateProductWeight(productId, weightGrams) {
    const card = document.querySelector(`[data-product-id="${productId}"]`)?.closest('.product-item, .unclassified-product');
    if (card) {
        card.classList.add('loading');
    }
    try {
        await postDoughTypesAction('update_product_weight', {
            product_id: productId,
            weight_grams: weightGrams,
        });
    } catch (error) {
        window.alert(error.message);
    } finally {
        if (card) {
            card.classList.remove('loading');
        }
    }
}

let draggedProductId = null;

document.querySelectorAll('[draggable="true"][data-product-id]').forEach(el => {
    el.addEventListener('dragstart', event => {
        draggedProductId = event.currentTarget.dataset.productId;
        event.dataTransfer?.setData('text/plain', draggedProductId);
    });
});

document.querySelectorAll('.drop-target').forEach(target => {
    target.addEventListener('dragover', event => {
        event.preventDefault();
        target.classList.add('drop-target-active');
    });
    target.addEventListener('dragleave', () => target.classList.remove('drop-target-active'));
    target.addEventListener('drop', async event => {
        event.preventDefault();
        target.classList.remove('drop-target-active');
        const productId = draggedProductId || event.dataTransfer?.getData('text/plain');
        const doughTypeId = target.dataset.doughTypeId;
        if (productId && doughTypeId) {
            await assignProductToDoughType(productId, doughTypeId);
        }
    });
});

window.addEventListener('click', event => {
    ['doughTypeModal', 'productLineModal', 'deleteDoughTypeModal', 'deleteProductLineModal'].forEach(id => {
        const modal = document.getElementById(id);
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>