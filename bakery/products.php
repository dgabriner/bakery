<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/customer_portal.php';
bakery_ensure_portal_schema($db);

// Set page title
$page_title = bakery_t('page.products');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    $stmt = $db->prepare("INSERT INTO products (name, description, price, wholesale_price, weight_grams, dough_type_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'],
                        $_POST['price'] === '' ? null : $_POST['price'],
                        empty($_POST['wholesale_price']) ? null : $_POST['wholesale_price'],
                        $_POST['weight_grams'] ?: null,
                        $_POST['dough_type_id'] ?: null // Convert empty string to null
                    ]);
                    header("Location: products.php?success=created");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to create product: " . $e->getMessage();
                }
                break;

            case 'update':
                try {
                    $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, price = ?, wholesale_price = ?, weight_grams = ?, dough_type_id = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'],
                        $_POST['price'] === '' ? null : $_POST['price'],
                        empty($_POST['wholesale_price']) ? null : $_POST['wholesale_price'],
                        $_POST['weight_grams'] ?: null,
                        $_POST['dough_type_id'] ?: null, // Convert empty string to null
                        $_POST['id']
                    ]);
                    if (isset($_POST['inline']) && $_POST['inline'] === '1') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true]);
                        exit;
                    }
                    header("Location: products.php?success=updated");
                    exit;
                } catch (Exception $e) {
                    if (isset($_POST['inline']) && $_POST['inline'] === '1') {
                        http_response_code(422);
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => 'Product could not be saved.']);
                        exit;
                    }
                    $error = "Failed to update product: " . $e->getMessage();
                }
                break;

            case 'delete':
                try {
                    $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    header("Location: products.php?success=deleted");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to delete product: " . $e->getMessage();
                }
                break;

            case 'batch_update':
                try {
                    $updates = json_decode($_POST['updates'], true);
                    $db->beginTransaction();
                    
                    $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, price = ?, weight_grams = ?, dough_type_id = ? WHERE id = ?");
                    foreach ($updates as $update) {
                        $stmt->execute([
                            $update['name'],
                            $update['description'],
                            $update['price'],
                            $update['weight_grams'] ?: null,
                            $update['dough_type_id'] ?: null, // Convert empty string to null
                            $update['id']
                        ]);
                    }
                    
                    $db->commit();
                    header("Location: products.php?success=batch_updated");
                    exit;
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = "Failed to update products: " . $e->getMessage();
                }
                break;

            case 'bulk_create_products':
                try {
                    $products = json_decode($_POST['products'], true);
                    $db->beginTransaction();
                    $stmt = $db->prepare("INSERT INTO products (name, description, price, dough_type_id) VALUES (?, ?, ?, ?)");
                    $debug = isset($_GET['debug']) && $_GET['debug'] == '1';
                    $debug_log = [];
                    
                    foreach ($products as $product) {
                        $name = isset($product['name']) ? trim($product['name']) : '';
                        if ($name === '') {
                            if ($debug) $debug_log[] = 'Skipped empty name.';
                            continue;
                        }
                        
                        // Set default values
                        $description = '';  // Empty description by default
                        $price = null;      // Null price by default
                        $dough_type_id = null; // Null dough type by default
                        
                        try {
                            $stmt->execute([$name, $description, $price, $dough_type_id]);
                            if ($debug) $debug_log[] = "Added product: '$name'";
                        } catch (Exception $e) {
                            if ($debug) $debug_log[] = "Error adding '$name': " . $e->getMessage();
                        }
                    }
                    
                    $db->commit();
                    if ($debug) {
                        $_SESSION['bulk_debug_log'] = $debug_log;
                        header("Location: products.php?success=bulk_created&debug=1");
                    } else {
                        header("Location: products.php?success=bulk_created");
                    }
                    exit;
                } catch (Exception $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $error = "Failed to create products: " . $e->getMessage();
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
            $success_message = 'Product created successfully!';
            break;
        case 'updated':
            $success_message = 'Product updated successfully!';
            break;
        case 'deleted':
            $success_message = 'Product deleted successfully!';
            break;
        case 'batch_updated':
            $success_message = 'All products updated successfully!';
            break;
        case 'bulk_created':
            $success_message = 'Products created successfully!';
            break;
    }
}

try {
    $dough_types = $db->query("SELECT id, name FROM dough_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $products = $db->query("
        SELECT p.*, dt.name AS dough_type_name
        FROM products p
        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $dough_types = [];
    $products = [];
    $error = "Failed to load the product catalog: " . $e->getMessage();
}

$product_count = count($products);
$priced_product_count = count(array_filter($products, static fn($product) => $product['price'] !== null));
$unassigned_dough_count = count(array_filter($products, static fn($product) => empty($product['dough_type_id'])));

$product_groups = [];
foreach ($dough_types as $dough_type) {
    $product_groups[(string)$dough_type['id']] = [
        'id' => (int)$dough_type['id'],
        'name' => $dough_type['name'],
        'products' => [],
    ];
}
$product_groups['unassigned'] = [
    'id' => null,
    'name' => 'Not assigned',
    'products' => [],
];
foreach ($products as $product) {
    $group_key = empty($product['dough_type_id']) ? 'unassigned' : (string)$product['dough_type_id'];
    if (!isset($product_groups[$group_key])) {
        $product_groups[$group_key] = [
            'id' => (int)$product['dough_type_id'],
            'name' => $product['dough_type_name'] ?? 'Unknown dough type',
            'products' => [],
        ];
    }
    $product_groups[$group_key]['products'][] = $product;
}
?>

<div class="container products-page">
    <header class="products-header">
        <div>
            <p class="eyebrow">Catalog management</p>
            <h1>Products</h1>
            <p class="page-description">Manage the items your bakery produces, prices, and assigns to dough types.</p>
        </div>
        <button class="btn-primary primary-action" type="button" onclick="showProductModal()">
            <span aria-hidden="true">+</span> Add product
        </button>
    </header>
    
    <?php if (isset($error)): ?>
        <div class="error">
            <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <section class="catalog-summary" aria-label="Product catalog summary">
        <div class="summary-card">
            <span class="summary-label">Total products</span>
            <strong><?php echo number_format($product_count); ?></strong>
        </div>
        <div class="summary-card">
            <span class="summary-label">Priced</span>
            <strong><?php echo number_format($priced_product_count); ?></strong>
        </div>
        <div class="summary-card <?php echo $unassigned_dough_count > 0 ? 'needs-attention' : ''; ?>">
            <span class="summary-label">Missing dough type</span>
            <strong><?php echo number_format($unassigned_dough_count); ?></strong>
        </div>
    </section>

    <section class="catalog-panel">
        <div class="catalog-toolbar">
            <div class="catalog-filters">
                <label class="search-field" for="productSearch">
                    <span class="sr-only">Search products</span>
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m21 21-4.35-4.35m2.35-5.65a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z"/></svg>
                    <input id="productSearch" type="search" placeholder="Search name or description" autocomplete="off">
                </label>
                <label class="select-field" for="doughTypeFilter">
                    <span class="sr-only">Filter by dough type</span>
                    <select id="doughTypeFilter">
                        <option value="">All dough types</option>
                        <option value="unassigned">Not assigned</option>
                        <?php foreach ($dough_types as $dough_type): ?>
                            <option value="<?php echo htmlspecialchars(strtolower($dough_type['name']), ENT_QUOTES); ?>"><?php echo htmlspecialchars($dough_type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="table-meta">
                <span id="productResultCount"><?php echo number_format($product_count); ?> product<?php echo $product_count === 1 ? '' : 's'; ?></span>
                <button id="clearProductFilters" type="button" class="text-button" hidden>Clear filters</button>
            </div>
        </div>

    <!-- Catalog actions -->
    <div class="action-bar">
        <div class="action-group">
            <button class="btn-secondary" type="button" onclick="showBulkAddProductModal()">Add multiple</button>
        </div>
        <p class="autosave-note"><span class="autosave-dot" aria-hidden="true"></span> Changes save automatically</p>
    </div>

    <!-- Products Table -->
    <div class="table-responsive">
        <table class="table-hover" aria-label="Product catalog">
            <thead>
                <tr>
                    <th scope="col"><button class="sort-button is-active" type="button" data-sort="name">Product <span aria-hidden="true">↑</span></button></th>
                    <th scope="col">Description</th>
                    <th scope="col"><button class="sort-button" type="button" data-sort="price">Price <span aria-hidden="true">↕</span></button></th>
                    <th scope="col"><button class="sort-button" type="button" data-sort="weight">Weight <span aria-hidden="true">↕</span></button></th>
                    <th scope="col">Dough type</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <?php foreach ($product_groups as $group_key => $product_group): ?>
            <tbody class="product-group" data-group="<?php echo htmlspecialchars($group_key, ENT_QUOTES); ?>">
                <tr class="product-group-row">
                    <th colspan="6" scope="rowgroup">
                        <div class="product-group-heading">
                            <?php if ($product_group['id'] !== null): ?>
                                <a class="formula-link" href="formulas.php?dough_type=<?php echo (int)$product_group['id']; ?>#formula-<?php echo (int)$product_group['id']; ?>">
                                    <span><?php echo htmlspecialchars($product_group['name']); ?></span>
                                    <span class="formula-link-action">Open formula <span aria-hidden="true">→</span></span>
                                </a>
                            <?php else: ?>
                                <span class="unassigned-group-title"><?php echo htmlspecialchars($product_group['name']); ?></span>
                            <?php endif; ?>
                            <span class="group-count"><?php echo count($product_group['products']); ?> product<?php echo count($product_group['products']) === 1 ? '' : 's'; ?></span>
                        </div>
                    </th>
                </tr>
                <?php foreach ($product_group['products'] as $product): ?>
                    <tr id="product-<?php echo (int)$product['id']; ?>"
                        data-id="<?php echo (int)$product['id']; ?>"
                        data-name="<?php echo htmlspecialchars(strtolower($product['name']), ENT_QUOTES); ?>"
                        data-description="<?php echo htmlspecialchars((string)($product['description'] ?? ''), ENT_QUOTES); ?>"
                        data-price="<?php echo htmlspecialchars((string)($product['price'] ?? ''), ENT_QUOTES); ?>"
                        data-wholesale-price="<?php echo htmlspecialchars((string)($product['wholesale_price'] ?? ''), ENT_QUOTES); ?>"
                        data-weight="<?php echo htmlspecialchars((string)($product['weight_grams'] ?? ''), ENT_QUOTES); ?>"
                        data-dough="<?php echo htmlspecialchars(empty($product['dough_type_id']) ? 'unassigned' : strtolower($product['dough_type_name']), ENT_QUOTES); ?>">
                        <td class="product-name-cell">
                            <label class="sr-only" for="product-name-<?php echo (int)$product['id']; ?>">Product name</label>
                            <input class="inline-product-control inline-product-name" id="product-name-<?php echo (int)$product['id']; ?>" data-field="name" type="text" value="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" required>
                        </td>
                        <td class="description-cell">
                            <label class="sr-only" for="product-description-<?php echo (int)$product['id']; ?>">Description</label>
                            <input class="inline-product-control" id="product-description-<?php echo (int)$product['id']; ?>" data-field="description" type="text" value="<?php echo htmlspecialchars((string)($product['description'] ?? ''), ENT_QUOTES); ?>" placeholder="Add description">
                        </td>
                        <td class="price-cell">
                            <label class="inline-number-control"><span aria-hidden="true">$</span><span class="sr-only">Price</span><input class="inline-product-control" data-field="price" type="number" value="<?php echo htmlspecialchars((string)($product['price'] ?? ''), ENT_QUOTES); ?>" min="0" step="0.01" placeholder="0.00"></label>
                        </td>
                        <td class="weight-cell">
                            <label class="inline-number-control"><span class="sr-only">Weight in grams</span><input class="inline-product-control" data-field="weight_grams" type="number" value="<?php echo htmlspecialchars((string)($product['weight_grams'] ?? ''), ENT_QUOTES); ?>" min="0" step="0.01" placeholder="—"><span aria-hidden="true">g</span></label>
                        </td>
                        <td class="dough-type-cell">
                            <label class="sr-only" for="product-dough-<?php echo (int)$product['id']; ?>">Dough type</label>
                            <select class="inline-product-control inline-dough-select" id="product-dough-<?php echo (int)$product['id']; ?>" data-field="dough_type_id">
                                <option value="">Not assigned</option>
                                <?php foreach ($dough_types as $dough_type): ?>
                                    <option value="<?php echo (int)$dough_type['id']; ?>" data-name="<?php echo htmlspecialchars(strtolower($dough_type['name']), ENT_QUOTES); ?>" <?php echo (int)$product['dough_type_id'] === (int)$dough_type['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dough_type['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="actions">
                            <span class="inline-save-status" role="status" aria-live="polite">Saved</span>
                            <button class="btn-icon delete-btn" type="button" onclick="deleteProduct(<?php echo (int)$product['id']; ?>)" title="Delete product">Delete</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
            <tbody class="product-empty-results">
                <tr id="noProductResults" hidden>
                    <td colspan="6" class="empty-state"><strong>No products found</strong><span>Try a different search or clear the filters.</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    </section>

    <!-- Product Modal (Add/Edit) -->
    <div id="productModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-content">
            <button class="close" type="button" onclick="hideProductModal()" aria-label="Close product form">&times;</button>
            <h2 id="modalTitle">Add New Product</h2>
            <form id="productForm" method="POST">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="id" value="">

                <div class="form-group">
                    <label for="name">Name *</label>
                    <input type="text" id="name" name="name" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="price">Retail price *</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required>
                </div>

                <div class="form-group">
                    <label for="wholesale_price">Wholesale price</label>
                    <input type="number" id="wholesale_price" name="wholesale_price" step="0.01" min="0">
                    <small class="help-text">Used for customers on the wholesale pricing tier.</small>
                </div>

                <div class="form-group">
                    <label for="weight_grams">Weight (g)</label>
                    <input type="number" id="weight_grams" name="weight_grams" step="0.01" min="0">
                </div>

                <div class="form-group">
                    <label for="dough_type_id">Dough Type</label>
                    <select id="dough_type_id" name="dough_type_id">
                        <option value="">Select a dough type</option>
                        <?php foreach ($dough_types as $dough_type): ?>
                            <option value="<?php echo $dough_type['id']; ?>">
                                <?php echo htmlspecialchars($dough_type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="hideProductModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
        <div class="modal-content">
            <h2 id="deleteModalTitle">Delete product?</h2>
            <p>Are you sure you want to delete product: <strong id="deleteProductName"></strong>?</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteProductId">
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="hideDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-danger">Delete Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Add Products Modal -->
    <div id="bulkAddProductModal" class="modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="bulkAddModalTitle">
        <div class="modal-content">
            <button class="close" type="button" onclick="hideBulkAddProductModal()" aria-label="Close bulk product form">&times;</button>
            <h2 id="bulkAddModalTitle">Add multiple products</h2>
            <p class="help-text">Enter one product name per line. You can add descriptions and prices later.</p>
            <form id="bulkAddProductForm" method="POST" onsubmit="return submitBulkAddProduct(event)">
                <input type="hidden" name="action" value="bulk_create_products">
                <div class="form-group">
                    <label for="bulkProducts">Product Names *</label>
                    <textarea id="bulkProducts" name="bulkProducts" rows="10" required 
                        placeholder="Chocolate Cake&#13;&#10;Baguette&#13;&#10;Croissant"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="hideBulkAddProductModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Add Products</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['debug']) && isset($_SESSION['bulk_debug_log'])): ?>
        <div class="debug-log">
            <h3>Debug Log</h3>
            <pre><?php 
            foreach ($_SESSION['bulk_debug_log'] as $line) {
                echo htmlspecialchars($line) . "\n";
            }
            unset($_SESSION['bulk_debug_log']);
            ?></pre>
        </div>
    <?php endif; ?>

    <script>
        let originalValues = {};
        let isEditingAll = false;
        let originalTableState = {};

        // Modal functions
        function showProductModal(id = null) {
            const modal = document.getElementById('productModal');
            const form = document.getElementById('productForm');
            const title = document.getElementById('modalTitle');
            
            // Reset form
            form.reset();
            
            if (id) {
                // Edit mode
                title.textContent = 'Edit Product';
                form.elements['action'].value = 'update';
                form.elements['id'].value = id;
                
                // Get current row data
                const row = document.getElementById('product-' + id);
                if (row) {
                    form.elements['name'].value = row.querySelector('.product-name-cell').textContent.trim();
                    form.elements['description'].value = row.dataset.description || '';
                    form.elements['price'].value = row.dataset.price || '';
                    form.elements['wholesale_price'].value = row.dataset.wholesalePrice || '';
                    form.elements['weight_grams'].value = row.dataset.weight || '';
                    
                    // Set dough type if exists
                    const doughTypeName = row.querySelector('.dough-type-cell').textContent.trim();
                    const doughTypeSelect = form.elements['dough_type_id'];
                    Array.from(doughTypeSelect.options).forEach(option => {
                        if (option.text === doughTypeName) {
                            option.selected = true;
                        }
                    });
                }
            } else {
                // Add mode
                title.textContent = 'Add New Product';
                form.elements['action'].value = 'create';
                form.elements['id'].value = '';
            }
            
            modal.style.display = 'block';
            form.elements['name'].focus();
        }

        function hideProductModal() {
            const modal = document.getElementById('productModal');
            modal.style.display = 'none';
        }

        function showBulkAddProductModal() {
            const modal = document.getElementById('bulkAddProductModal');
            modal.style.display = 'block';
            document.getElementById('bulkProducts').focus();
        }

        function hideBulkAddProductModal() {
            const modal = document.getElementById('bulkAddProductModal');
            modal.style.display = 'none';
        }

        function deleteProduct(id) {
            const modal = document.getElementById('deleteModal');
            const nameSpan = document.getElementById('deleteProductName');
            const idInput = document.getElementById('deleteProductId');
            const row = document.getElementById('product-' + id);
            const nameInput = row ? row.querySelector('[data-field="name"]') : null;
            const name = nameInput ? nameInput.value.trim() : 'this product';
            
            nameSpan.textContent = name;
            idInput.value = id;
            modal.style.display = 'block';
        }

        function hideDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.style.display = 'none';
        }

        function editProduct(id) {
            showProductModal(id);
        }

        // Bulk add products functionality
        function submitBulkAddProduct(event) {
            event.preventDefault();
            
            const textarea = document.getElementById('bulkProducts');
            const lines = textarea.value.split('\n').filter(line => line.trim() !== '');
            
            if (lines.length === 0) {
                alert('Please enter at least one product name.');
                return false;
            }
            
            const products = lines.map(line => ({
                name: line.trim()
            }));
            
            const form = event.target;
            const productsInput = document.createElement('input');
            productsInput.type = 'hidden';
            productsInput.name = 'products';
            productsInput.value = JSON.stringify(products);
            form.appendChild(productsInput);
            
            // Submit the form manually since we prevented default
            form.submit();
        }

        // Edit All functionality
        function startEditAll() {
            if (isEditingAll) return;
            
            isEditingAll = true;
            originalTableState = {};
            
            // Show/hide buttons
            document.getElementById('editAllBtn').style.display = 'none';
            document.getElementById('saveAllBtn').style.display = 'inline-block';
            document.getElementById('cancelEditBtn').style.display = 'inline-block';
            
            // Make all rows editable
            const rows = document.querySelectorAll('tbody tr[data-id]');
            rows.forEach(row => {
                const id = row.getAttribute('data-id');
                if (id) {
                    originalTableState[id] = row.outerHTML;
                    makeRowEditable(id);
                }
            });
        }

        function escapeHtml(value) {
            const element = document.createElement('div');
            element.textContent = value == null ? '' : String(value);
            return element.innerHTML;
        }

        function makeRowEditable(id) {
            const row = document.getElementById('product-' + id);
            if (!row) return;
            
            const cells = row.getElementsByTagName('td');
            
            // Store original values
            originalValues[id] = {
                name: row.querySelector('.product-name-cell').textContent.trim(),
                description: row.dataset.description || '',
                price: row.dataset.price || '',
                weight: row.dataset.weight || '',
                doughType: cells[4].textContent.trim()
            };
            
            // Create edit inputs
            cells[0].innerHTML = `<input type="text" class="edit-input" value="${escapeHtml(originalValues[id].name)}" data-field="name">`;
            cells[1].innerHTML = `<textarea class="edit-input" data-field="description" rows="2">${escapeHtml(originalValues[id].description)}</textarea>`;
            cells[2].innerHTML = `$<input type="number" step="0.01" class="edit-input" value="${originalValues[id].price}" data-field="price" style="width: 80px;">`;
            cells[3].innerHTML = `<input type="number" class="edit-input" value="${originalValues[id].weight}" data-field="weight_grams" style="width: 70px;">`;
            
            // Create dough type select
            const doughTypes = <?php 
                $types = $db->query("SELECT id, name FROM dough_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($types); 
            ?>;
            
            let select = '<select class="edit-input" data-field="dough_type_id">';
            select += '<option value="">Not specified</option>';
            
            doughTypes.forEach(type => {
                const selected = type.name === originalValues[id].doughType ? 'selected' : '';
                select += `<option value="${type.id}" ${selected}>${escapeHtml(type.name)}</option>`;
            });
            
            select += '</select>';
            cells[4].innerHTML = select;
            
            // Clear action buttons in edit-all mode
            cells[5].innerHTML = '';
        }

        function cancelEditAll() {
            if (!isEditingAll) return;
            
            getProductRows().forEach(row => {
                const originalRow = originalTableState[row.dataset.id];
                if (originalRow) row.outerHTML = originalRow;
            });
            isEditingAll = false;
            originalValues = {};
            originalTableState = {};
            
            // Show/hide buttons
            document.getElementById('editAllBtn').style.display = 'inline-block';
            document.getElementById('saveAllBtn').style.display = 'none';
            document.getElementById('cancelEditBtn').style.display = 'none';
            applyProductFilters();
        }

        async function saveAllChanges() {
            if (!isEditingAll) return;
            
            const rows = document.querySelectorAll('tbody tr[data-id]');
            const updates = [];
            let hasErrors = false;
            
            // Validate and collect all updates
            rows.forEach(row => {
                const id = row.getAttribute('data-id');
                if (!id) return;
                
                const nameInput = row.querySelector('input[data-field="name"]');
                const descInput = row.querySelector('textarea[data-field="description"]');
                const priceInput = row.querySelector('input[data-field="price"]');
                const weightInput = row.querySelector('input[data-field="weight_grams"]');
                const doughTypeSelect = row.querySelector('select[data-field="dough_type_id"]');
                
                const name = nameInput ? nameInput.value.trim() : '';
                const priceValue = priceInput ? priceInput.value.trim() : '';
                const price = priceValue === '' ? null : parseFloat(priceValue);
                
                if (!name) {
                    hasErrors = true;
                    nameInput.style.borderColor = 'red';
                    return;
                }
                
                if (price !== null && (!Number.isFinite(price) || price < 0)) {
                    hasErrors = true;
                    priceInput.style.borderColor = 'red';
                    return;
                }
                
                updates.push({
                    id: id,
                    name: name,
                    description: descInput ? descInput.value.trim() : '',
                    price: price,
                    weight_grams: weightInput && weightInput.value.trim() !== '' ? parseFloat(weightInput.value) : null,
                    dough_type_id: doughTypeSelect ? doughTypeSelect.value : ''
                });
            });
            
            if (hasErrors) {
                alert('Please fix the highlighted errors before saving.');
                return;
            }
            
            try {
                const response = await fetch('products.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=batch_update&updates=' + encodeURIComponent(JSON.stringify(updates))
                });
                
                if (response.ok) {
                    window.location.href = 'products.php?success=batch_updated';
                } else {
                    throw new Error('Network response was not ok');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error saving changes. Please try again.');
            }
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const productModal = document.getElementById('productModal');
            const deleteModal = document.getElementById('deleteModal');
            const bulkModal = document.getElementById('bulkAddProductModal');
            
            if (event.target === productModal) {
                hideProductModal();
            }
            if (event.target === deleteModal) {
                hideDeleteModal();
            }
            if (event.target === bulkModal) {
                hideBulkAddProductModal();
            }
        });

        const inlineSaveTimers = new Map();

        function updateProductGroupCounts() {
            document.querySelectorAll('.product-group').forEach(group => {
                const count = group.querySelectorAll('tr[data-id]').length;
                const countLabel = group.querySelector('.group-count');
                if (countLabel) countLabel.textContent = `${count} product${count === 1 ? '' : 's'}`;
            });
        }

        async function saveInlineProduct(row) {
            const controls = Array.from(row.querySelectorAll('.inline-product-control'));
            const status = row.querySelector('.inline-save-status');
            const invalidControl = controls.find(control => !control.checkValidity());
            if (invalidControl) {
                status.textContent = 'Check fields';
                status.className = 'inline-save-status is-error';
                return;
            }

            const field = name => row.querySelector(`[data-field="${name}"]`);
            const payload = new URLSearchParams({
                action: 'update',
                inline: '1',
                id: row.dataset.id,
                name: field('name').value.trim(),
                description: field('description').value.trim(),
                price: field('price').value,
                weight_grams: field('weight_grams').value,
                dough_type_id: field('dough_type_id').value
            });

            status.textContent = 'Saving…';
            status.className = 'inline-save-status is-saving';
            try {
                const response = await fetch('products.php', { method: 'POST', body: payload });
                if (!response.ok) throw new Error('Save failed');

                const doughSelect = field('dough_type_id');
                const selectedOption = doughSelect.options[doughSelect.selectedIndex];
                const targetGroupKey = doughSelect.value || 'unassigned';
                const targetGroup = document.querySelector(`.product-group[data-group="${targetGroupKey}"]`);
                row.dataset.name = field('name').value.trim().toLowerCase();
                row.dataset.description = field('description').value.trim();
                row.dataset.price = field('price').value;
                row.dataset.weight = field('weight_grams').value;
                row.dataset.dough = doughSelect.value ? (selectedOption.dataset.name || selectedOption.textContent.trim().toLowerCase()) : 'unassigned';
                if (targetGroup && row.parentElement !== targetGroup) targetGroup.appendChild(row);
                updateProductGroupCounts();
                applyProductFilters();
                status.textContent = 'Saved';
                status.className = 'inline-save-status is-saved';
            } catch (error) {
                status.textContent = 'Save failed';
                status.className = 'inline-save-status is-error';
            }
        }

        function queueInlineProductSave(row, delay = 0) {
            const rowId = row.dataset.id;
            window.clearTimeout(inlineSaveTimers.get(rowId));
            inlineSaveTimers.set(rowId, window.setTimeout(() => {
                row._saveQueue = (row._saveQueue || Promise.resolve())
                    .then(() => saveInlineProduct(row))
                    .catch(() => saveInlineProduct(row));
            }, delay));
        }

        document.querySelectorAll('.inline-product-control').forEach(control => {
            control.addEventListener('input', () => {
                const row = control.closest('tr[data-id]');
                const status = row.querySelector('.inline-save-status');
                status.textContent = 'Unsaved';
                status.className = 'inline-save-status';
                queueInlineProductSave(row, 700);
            });
            control.addEventListener('change', () => queueInlineProductSave(control.closest('tr[data-id]')));
            control.addEventListener('blur', () => queueInlineProductSave(control.closest('tr[data-id]')));
        });

        const productSearch = document.getElementById('productSearch');
        const doughTypeFilter = document.getElementById('doughTypeFilter');
        const clearProductFilters = document.getElementById('clearProductFilters');
        const productResultCount = document.getElementById('productResultCount');
        const noProductResults = document.getElementById('noProductResults');

        function getProductRows() {
            return Array.from(document.querySelectorAll('.product-group tr[data-id]'));
        }

        function applyProductFilters() {
            const query = productSearch.value.trim().toLowerCase();
            const doughType = doughTypeFilter.value;
            let visibleCount = 0;

            getProductRows().forEach(row => {
                const matchesQuery = !query || row.dataset.name.includes(query) || row.dataset.description.toLowerCase().includes(query);
                const matchesDough = !doughType || row.dataset.dough === doughType;
                const isVisible = matchesQuery && matchesDough;
                row.hidden = !isVisible;
                if (isVisible) visibleCount += 1;
            });

            document.querySelectorAll('.product-group').forEach(group => {
                group.hidden = !Array.from(group.querySelectorAll('tr[data-id]')).some(row => !row.hidden);
            });

            productResultCount.textContent = `${visibleCount} product${visibleCount === 1 ? '' : 's'}`;
            noProductResults.hidden = visibleCount !== 0;
            clearProductFilters.hidden = !query && !doughType;
        }

        productSearch.addEventListener('input', applyProductFilters);
        doughTypeFilter.addEventListener('change', applyProductFilters);
        clearProductFilters.addEventListener('click', () => {
            productSearch.value = '';
            doughTypeFilter.value = '';
            applyProductFilters();
            productSearch.focus();
        });
        applyProductFilters();

        let activeSort = { key: 'name', direction: 1 };
        document.querySelectorAll('.sort-button').forEach(button => {
            button.addEventListener('click', () => {
                const key = button.dataset.sort;
                activeSort.direction = activeSort.key === key ? activeSort.direction * -1 : 1;
                activeSort.key = key;

                document.querySelectorAll('.product-group').forEach(group => {
                    const rows = Array.from(group.querySelectorAll('tr[data-id]'));
                    rows.sort((a, b) => {
                        if (key === 'price' || key === 'weight') {
                            const aValue = a.dataset[key] === '' ? Number.POSITIVE_INFINITY : Number(a.dataset[key]);
                            const bValue = b.dataset[key] === '' ? Number.POSITIVE_INFINITY : Number(b.dataset[key]);
                            return (aValue - bValue) * activeSort.direction;
                        }
                        return a.dataset[key].localeCompare(b.dataset[key], undefined, { numeric: true }) * activeSort.direction;
                    });
                    rows.forEach(row => group.appendChild(row));
                });
                document.querySelectorAll('.sort-button').forEach(sortButton => {
                    const isActive = sortButton === button;
                    sortButton.classList.toggle('is-active', isActive);
                    sortButton.querySelector('span').textContent = isActive ? (activeSort.direction === 1 ? '↑' : '↓') : '↕';
                });
            });
        });

        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            if (document.getElementById('productModal').style.display === 'block') hideProductModal();
            if (document.getElementById('bulkAddProductModal').style.display === 'block') hideBulkAddProductModal();
            if (document.getElementById('deleteModal').style.display === 'block') hideDeleteModal();
        });

        // Show success/error messages
        function showMessage(message, type = 'info') {
            const messageDiv = document.createElement('div');
            messageDiv.className = type === 'error' ? 'error' : 'success-message';
            messageDiv.textContent = message;
            
            const container = document.querySelector('.container');
            container.insertBefore(messageDiv, container.firstChild);
            
            setTimeout(() => {
                messageDiv.remove();
            }, 5000);
        }
    </script>

    <style>
        .edit-input {
            width: 100%;
            padding: 4px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: inherit;
        }
        
        .edit-input {
            width: 100%;
            padding: 0.375rem 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            font-size: 0.9rem;
        }
        
        .edit-input:focus {
            outline: none;
            border-color: #80bdff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        textarea.edit-input {
            min-height: 60px;
            resize: vertical;
        }
        
        .actions {
            white-space: nowrap;
        }
        
        .btn-icon {
            padding: 4px 8px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1.2em;
            transition: transform 0.2s;
        }
        
        .btn-icon:hover {
            transform: scale(1.2);
        }
        
        .save-btn:hover {
            color: #4CAF50;
        }
        
        .cancel-btn:hover {
            color: #f44336;
        }
        
        .error, .success-message {
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease-in;
        }
        
        .error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }
        
        .success-message {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .action-bar {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .batch-actions {
            display: inline-flex;
            gap: 10px;
        }

        .btn-secondary {
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background-color: #e0e0e0;
        }

        .save-all-btn {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .save-all-btn:hover {
            background-color: #45a049;
        }

        .help-text { color: #666; font-size: 0.9em; margin-bottom: 15px; }

        .debug-log {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }
        .debug-log h3 {
            margin-top: 0;
            color: #495057;
        }
        .debug-log pre {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
            color: #212529;
            font-family: monospace;
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 2rem;
        }

        .table-hover {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .table-hover th,
        .table-hover td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }
        
        .weight-cell,
        .dough-type-cell {
            white-space: nowrap;
        }
        
        .edit-input {
            width: 100%;
            padding: 0.375rem 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            font-size: 0.9rem;
        }
        
        .edit-input:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .table-hover th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .table-hover tr:hover {
            background-color: #f8f9fa;
        }

        .table-hover td:last-child {
            text-align: right;
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
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
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

        /* Products workspace */
        .products-page {
            --ink: #18312f;
            --muted: #667674;
            --line: #dce4e1;
            --surface: #ffffff;
            --surface-soft: #f5f7f6;
            --brand: #176b5d;
            --brand-dark: #115348;
            --warning-bg: #fff3d6;
            --warning-text: #895c09;
            max-width: 1320px;
            margin: 0 auto;
            padding: 32px 24px 56px;
            color: var(--ink);
            font-family: "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .products-page *, .products-page *::before, .products-page *::after { box-sizing: border-box; }
        .products-page [hidden] { display: none !important; }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .products-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 24px;
        }

        .products-header h1 {
            margin: 2px 0 6px;
            color: var(--ink);
            font-size: clamp(2rem, 4vw, 2.65rem);
            line-height: 1.05;
            letter-spacing: -0.035em;
        }

        .eyebrow {
            margin: 0;
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 750;
            letter-spacing: 0.11em;
            text-transform: uppercase;
        }

        .page-description { margin: 0; color: var(--muted); font-size: 0.98rem; }

        .products-page .btn-primary,
        .products-page .btn-secondary,
        .products-page .btn-danger {
            min-height: 40px;
            padding: 9px 15px;
            border-radius: 8px;
            font: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: background-color .16s ease, border-color .16s ease, box-shadow .16s ease, transform .16s ease;
        }

        .products-page .btn-primary {
            border: 1px solid var(--brand);
            background: var(--brand);
            color: #fff;
        }

        .products-page .btn-primary:hover { background: var(--brand-dark); border-color: var(--brand-dark); }
        .products-page .btn-secondary { border: 1px solid #cbd6d2; background: #fff; color: var(--ink); }
        .products-page .btn-secondary:hover { border-color: #9fb1ab; background: var(--surface-soft); }
        .products-page .btn-danger { border: 1px solid #b33b3b; background: #b33b3b; color: #fff; }
        .products-page button:focus-visible, .products-page input:focus-visible, .products-page select:focus-visible, .products-page textarea:focus-visible {
            outline: 3px solid rgba(23, 107, 93, .2);
            outline-offset: 2px;
        }

        .primary-action { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; }
        .primary-action span { font-size: 1.25rem; line-height: .7; }

        .catalog-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .summary-card {
            min-height: 92px;
            padding: 18px 20px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--surface);
            box-shadow: 0 1px 2px rgba(24, 49, 47, .04);
        }

        .summary-card strong { display: block; margin-top: 5px; color: var(--ink); font-size: 1.55rem; line-height: 1; }
        .summary-label { color: var(--muted); font-size: 0.79rem; font-weight: 650; }
        .summary-card.needs-attention { border-left: 4px solid #d89a24; }

        .catalog-panel {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--surface);
            box-shadow: 0 5px 18px rgba(24, 49, 47, .06);
        }

        .catalog-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 16px 18px 10px;
        }

        .catalog-filters { display: flex; flex: 1; gap: 10px; min-width: 0; }
        .search-field { position: relative; display: block; flex: 1; max-width: 430px; }
        .search-field svg {
            position: absolute;
            top: 50%;
            left: 12px;
            width: 18px;
            height: 18px;
            transform: translateY(-50%);
            fill: none;
            stroke: #6d7e7a;
            stroke-linecap: round;
            stroke-width: 1.8;
        }

        .search-field input, .select-field select {
            width: 100%;
            height: 42px;
            border: 1px solid #cbd6d2;
            border-radius: 8px;
            background: #fff;
            color: var(--ink);
            font: inherit;
            font-size: .9rem;
        }

        .search-field input { padding: 0 13px 0 39px; }
        .select-field { width: 190px; }
        .select-field select { padding: 0 34px 0 12px; }
        .table-meta { display: flex; align-items: center; gap: 10px; color: var(--muted); font-size: .82rem; white-space: nowrap; }
        .text-button { padding: 3px 0; border: 0; background: transparent; color: var(--brand); font: inherit; font-weight: 700; cursor: pointer; }

        .products-page > .catalog-panel > .action-bar {
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            margin: 0;
            padding: 0 18px 14px;
        }

        .action-bar .action-group { display: flex; gap: 8px; }
        .autosave-note { display: inline-flex; align-items: center; gap: 7px; margin: 0 0 0 auto; color: var(--muted); font-size: .78rem; font-weight: 650; }
        .autosave-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--brand); box-shadow: 0 0 0 3px rgba(23, 107, 93, .12); }
        .products-page .table-responsive { margin: 0; border-top: 1px solid var(--line); overflow: auto; }
        .products-page .table-hover { min-width: 880px; border-radius: 0; box-shadow: none; }
        .products-page .table-hover th, .products-page .table-hover td { padding: 14px 16px; border-bottom: 1px solid #e8eeeb; }
        .products-page .table-hover th {
            background: var(--surface-soft);
            color: #52635f;
            font-size: .72rem;
            font-weight: 750;
            letter-spacing: .055em;
            text-transform: uppercase;
        }

        .products-page .table-hover tbody tr:hover { background: #f8faf9; }
        .products-page .table-hover tbody tr:last-child td { border-bottom: 0; }
        .products-page .product-group-row th {
            padding: 0;
            border-top: 1px solid #cbd8d3;
            border-bottom: 1px solid #dce6e2;
            background: #eaf2ef;
            text-transform: none;
            letter-spacing: 0;
        }
        .products-page .product-group:first-of-type .product-group-row th { border-top: 0; }
        .product-group-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 52px; padding: 0 16px; }
        .formula-link { display: flex; flex: 1; align-items: center; justify-content: space-between; gap: 16px; align-self: stretch; color: var(--ink); text-decoration: none; font-size: 1rem; font-weight: 800; }
        .formula-link:hover .formula-link-action, .formula-link:focus-visible .formula-link-action { color: var(--brand-dark); text-decoration: underline; }
        .formula-link-action { color: var(--brand); font-size: .78rem; font-weight: 750; }
        .group-count { flex: 0 0 auto; color: #60716c; font-size: .75rem; font-weight: 700; }
        .unassigned-group-title { color: var(--warning-text); font-size: 1rem; font-weight: 800; }
        .product-name-cell { width: 21%; color: var(--ink); }
        .description-cell { width: 31%; max-width: 360px; color: #53645f; }
        .empty-value { color: #899692; font-style: italic; }
        .price-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }

        .inline-product-control {
            width: 100%;
            min-width: 0;
            height: 36px;
            padding: 6px 8px;
            border: 1px solid transparent;
            border-radius: 6px;
            background: transparent;
            color: var(--ink);
            font: inherit;
            font-size: .86rem;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
        }
        .inline-product-name { font-weight: 750; }
        .inline-product-control:hover { border-color: #d3ded9; background: #fff; }
        .inline-product-control:focus { border-color: var(--brand); background: #fff; outline: 0; box-shadow: 0 0 0 3px rgba(23, 107, 93, .12); }
        .inline-product-control:invalid { border-color: #c95959; background: #fff7f7; }
        .inline-number-control { display: flex; align-items: center; gap: 2px; color: #687974; }
        .inline-number-control .inline-product-control { width: 78px; text-align: right; font-variant-numeric: tabular-nums; }
        .inline-dough-select { min-width: 145px; }
        .inline-save-status { min-width: 58px; color: #7a8985; font-size: .7rem; font-weight: 700; text-align: right; }
        .inline-save-status.is-saving { color: #8a690f; }
        .inline-save-status.is-saved { color: var(--brand); }
        .inline-save-status.is-error { color: #ad3838; }

        .sort-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: inherit;
            letter-spacing: inherit;
            text-transform: inherit;
            cursor: pointer;
        }

        .sort-button span { color: #9aa7a3; font-size: .85rem; }
        .sort-button.is-active { color: var(--ink); }
        .sort-button.is-active span { color: var(--brand); }

        .status-pill {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 3px 8px;
            border-radius: 999px;
            background: #e8f2ef;
            color: #285f55;
            font-size: .75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pill--warning { background: var(--warning-bg); color: var(--warning-text); }
        .products-page .actions { display: flex; align-items: center; justify-content: flex-end; gap: 5px; }
        .products-page .btn-icon { position: relative; min-width: 50px; padding: 7px 8px; font-size: 0; border-radius: 6px; }
        .products-page .btn-icon::after { font-size: .8rem; font-weight: 700; }
        .products-page .edit-btn::after { content: "Edit"; color: var(--brand); }
        .products-page .delete-btn::after { content: "Delete"; color: #aa3434; }
        .products-page .btn-icon:hover { background: #edf3f1; transform: none; }

        .empty-state { padding: 50px 20px !important; text-align: center !important; color: var(--muted); }
        .empty-state strong, .empty-state span { display: block; }
        .empty-state strong { margin-bottom: 4px; color: var(--ink); font-size: 1rem; }

        .products-page .modal { background: rgba(18, 34, 32, .62); backdrop-filter: blur(2px); }
        .products-page .modal-content { max-height: calc(100vh - 40px); overflow: auto; border: 1px solid rgba(255,255,255,.3); border-radius: 12px; box-shadow: 0 24px 60px rgba(12, 29, 27, .25); }
        .products-page .modal-content h2 { color: var(--ink); font-size: 1.35rem; }
        .products-page .close { width: 34px; height: 34px; padding: 0; border: 0; border-radius: 7px; background: transparent; line-height: 1; }
        .products-page .close:hover { background: var(--surface-soft); color: var(--ink); }
        .products-page .form-group label { color: var(--ink); font-size: .82rem; font-weight: 700; }
        .products-page .form-group input, .products-page .form-group textarea, .products-page .form-group select { border-color: #cbd6d2; border-radius: 8px; font-family: inherit; }
        .products-page .form-group input:focus, .products-page .form-group textarea:focus, .products-page .form-group select:focus { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(23, 107, 93, .14); }

        @media (max-width: 768px) {
            .products-page { padding: 24px 14px 40px; }
            .products-header { align-items: flex-start; }
            .catalog-summary { grid-template-columns: 1fr; }
            .summary-card { min-height: 76px; }
            .catalog-toolbar { align-items: stretch; flex-direction: column; }
            .catalog-filters { flex-direction: column; }
            .search-field, .select-field { max-width: none; width: 100%; }
            .table-meta { justify-content: space-between; }
            .action-bar {
                align-items: stretch;
                flex-direction: row;
                gap: 8px;
            }
            .action-bar .action-group { flex: 1; }
            .action-bar button { width: auto; flex: 1; }
            .table-responsive { margin-top: 0; }
        }

        @media (max-width: 520px) {
            .products-header { flex-direction: column; }
            .primary-action { width: 100%; justify-content: center; }
            .products-page > .catalog-panel > .action-bar { flex-direction: column; }
        }
    </style>
</div>

<?php require_once 'includes/footer.php'; ?>
