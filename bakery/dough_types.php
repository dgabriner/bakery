<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Set page title
$page_title = 'Dough Types & Product Lines';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    $stmt = $db->prepare("INSERT INTO dough_types (name, description, product_line_id) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'] ?? '',
                        $_POST['product_line_id'] ?: null
                    ]);
                    header("Location: dough_types.php?success=created");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to create dough type: " . $e->getMessage();
                }
                break;

            case 'update':
                try {
                    $stmt = $db->prepare("UPDATE dough_types SET name = ?, description = ?, product_line_id = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'] ?? '',
                        $_POST['product_line_id'] ?: null,
                        $_POST['id']
                    ]);
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