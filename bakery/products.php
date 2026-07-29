<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Set page title
$page_title = 'Products';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    $stmt = $db->prepare("INSERT INTO products (name, description, price, weight_grams, dough_type_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'],
                        $_POST['price'],
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
                    $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, price = ?, weight_grams = ?, dough_type_id = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['description'],
                        $_POST['price'],
                        $_POST['weight_grams'] ?: null,
                        $_POST['dough_type_id'] ?: null, // Convert empty string to null
                        $_POST['id']
                    ]);
                    header("Location: products.php?success=updated");
                    exit;
                } catch (Exception $e) {
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
                    
                    $stmt = $db->prepare("UPDATE products SET name = ?, description = ?, price = ?, dough_type_id = ? WHERE id = ?");
                    foreach ($updates as $update) {
                        $stmt->execute([
                            $update['name'],
                            $update['description'],
                            $update['price'],
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
?>

<div class="container">
    <h1>Products</h1>
    
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

    <!-- Add New Product Button -->
    <div class="action-bar">
        <div class="action-group">
            <button class="btn-primary" onclick="showProductModal()">
                <i class="icon">➕</i> Add New Product
            </button>
            <button class="btn-secondary" onclick="showBulkAddProductModal()">
                <i class="icon">📋</i> Add Multiple
            </button>
            <button class="btn-secondary edit-dough-types-btn" onclick="toggleDoughTypeEditMode()">
                <i class="icon">🥖</i> Dough Types
            </button>
        </div>
        <div class="action-group">
            <button class="btn-secondary" id="editAllBtn" onclick="startEditAll()">
                <i class="icon">✏️</i> Edit All
            </button>
            <button class="btn-primary" id="saveAllBtn" style="display: none;" onclick="saveAllChanges()">
                <i class="icon">💾</i> Save All
            </button>
            <button class="btn-secondary" id="cancelEditBtn" style="display: none;" onclick="cancelEditAll()">
                Cancel
            </button>
        </div>
    </div>

    <!-- Dough Types Legend (Hidden by default) -->
    <div id="doughTypesLegend" class="dough-types-legend" style="display: none;">
        <h3>Dough Types</h3>
        <div class="dough-type-buttons">
            <?php
            $dough_types = $db->query("SELECT id, name FROM dough_types ORDER BY name")->fetchAll();
            foreach ($dough_types as $dough_type) {
                echo '<button class="dough-type-btn" data-dough-type-id="' . $dough_type['id'] . 
                     '" onclick="setDoughTypeForProduct(null, ' . $dough_type['id'] . ')">' . 
                     htmlspecialchars($dough_type['name']) . '</button>';
            }
            ?>
            <button class="dough-type-btn clear-btn" data-dough-type-id="" onclick="setDoughTypeForProduct(null, null)">Clear Type</button>
        </div>
    </div>

    <!-- Products Table -->
    <div class="table-responsive">
        <table class="table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Price</th>
                    <th>Weight (g)</th>
                    <th>Dough Type</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                try {
                    $products = $db->query("
                        SELECT p.*, dt.name as dough_type_name 
                        FROM products p 
                        LEFT JOIN dough_types dt ON p.dough_type_id = dt.id 
                        ORDER BY p.name
                    ")->fetchAll();
                    foreach ($products as $product):
                ?>
                    <tr id="product-<?php echo $product['id']; ?>" data-id="<?php echo $product['id']; ?>">
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo htmlspecialchars($product['description']); ?></td>
                        <td>$<?php echo number_format($product['price'] ?? 0, 2); ?></td>
                        <td class="weight-cell"><?php echo number_format($product['weight_grams'] ?? 0); ?></td>
                        <td class="dough-type-cell"><?php echo htmlspecialchars($product['dough_type_name'] ?? 'Not specified'); ?></td>
                        <td class="actions">
                            <button class="btn-icon edit-btn" onclick="editProduct(<?php echo $product['id']; ?>)" title="Edit">✏️</button>
                            <button class="btn-icon delete-btn" onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>')" title="Delete">🗑️</button>
                        </td>
                    </tr>
                <?php
                    endforeach;
                } catch (Exception $e) {
                    echo '<tr><td colspan="5" class="error">Error loading products: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- Product Modal (Add/Edit) -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideProductModal()">&times;</span>
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
                    <label for="price">Price *</label>
                    <input type="number" id="price" name="price" step="0.01" min="0" required>
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
    <div id="deleteModal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2>Confirm Deletion</h2>
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
    <div id="bulkAddProductModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="hideBulkAddProductModal()">&times;</span>
            <h2>Add Multiple Products</h2>
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
        let isDoughTypeEditMode = false;
        let originalTableState = '';

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
                    form.elements['name'].value = row.cells[0].textContent.trim();
                    form.elements['description'].value = row.cells[1].textContent.trim();
                    const priceText = row.cells[2].textContent.replace('$', '').replace(',', '').trim();
                    form.elements['price'].value = parseFloat(priceText) || 0;
                    const weightText = row.cells[3].textContent.replace(',', '').trim();
                    form.elements['weight_grams'].value = parseFloat(weightText) || 0;
                    
                    // Set dough type if exists
                    const doughTypeName = row.cells[4].textContent.trim();
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

        function deleteProduct(id, name) {
            const modal = document.getElementById('deleteModal');
            const nameSpan = document.getElementById('deleteProductName');
            const idInput = document.getElementById('deleteProductId');
            
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
            originalTableState = document.querySelector('tbody').innerHTML;
            
            // Show/hide buttons
            document.getElementById('editAllBtn').style.display = 'none';
            document.getElementById('saveAllBtn').style.display = 'inline-block';
            document.getElementById('cancelEditBtn').style.display = 'inline-block';
            
            // Make all rows editable
            const rows = document.querySelectorAll('tbody tr[data-id]');
            rows.forEach(row => {
                const id = row.getAttribute('data-id');
                if (id) {
                    makeRowEditable(id);
                }
            });
        }

        function makeRowEditable(id) {
            const row = document.getElementById('product-' + id);
            if (!row) return;
            
            const cells = row.getElementsByTagName('td');
            
            // Store original values
            originalValues[id] = {
                name: cells[0].textContent.trim(),
                description: cells[1].textContent.trim(),
                price: cells[2].textContent.replace('$', '').replace(',', '').trim(),
                weight: cells[3].textContent.replace(',', '').trim(),
                doughType: cells[4].textContent.trim()
            };
            
            // Create edit inputs
            cells[0].innerHTML = `<input type="text" class="edit-input" value="${originalValues[id].name}" data-field="name">`;
            cells[1].innerHTML = `<textarea class="edit-input" data-field="description" rows="2">${originalValues[id].description}</textarea>`;
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
                select += `<option value="${type.id}" ${selected}>${type.name}</option>`;
            });
            
            select += '</select>';
            cells[4].innerHTML = select;
            
            // Clear action buttons in edit-all mode
            cells[5].innerHTML = '';
        }

        function cancelEditAll() {
            if (!isEditingAll) return;
            
            // Restore original table state
            document.querySelector('tbody').innerHTML = originalTableState;
            isEditingAll = false;
            originalValues = {};
            
            // Show/hide buttons
            document.getElementById('editAllBtn').style.display = 'inline-block';
            document.getElementById('saveAllBtn').style.display = 'none';
            document.getElementById('cancelEditBtn').style.display = 'none';
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
                const price = priceInput ? parseFloat(priceInput.value) || 0 : 0;
                
                if (!name) {
                    hasErrors = true;
                    nameInput.style.borderColor = 'red';
                    return;
                }
                
                if (price < 0) {
                    hasErrors = true;
                    priceInput.style.borderColor = 'red';
                    return;
                }
                
                updates.push({
                    id: id,
                    name: name,
                    description: descInput ? descInput.value.trim() : '',
                    price: price,
                    weight_grams: weightInput ? parseInt(weightInput.value) || 0 : 0,
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

        // Dough Type editing mode
        function toggleDoughTypeEditMode() {
            isDoughTypeEditMode = !isDoughTypeEditMode;
            const legend = document.getElementById('doughTypesLegend');
            const btn = document.querySelector('.edit-dough-types-btn');
            
            if (isDoughTypeEditMode) {
                legend.style.display = 'block';
                btn.textContent = '🥖 Hide Dough Types';
                btn.classList.add('active');
                
                // Add click handlers to dough type cells
                document.querySelectorAll('.dough-type-cell').forEach(cell => {
                    cell.style.cursor = 'pointer';
                    cell.title = 'Click to change dough type';
                });
            } else {
                legend.style.display = 'none';
                btn.textContent = '🥖 Dough Types';
                btn.classList.remove('active');
                
                // Remove click handlers
                document.querySelectorAll('.dough-type-cell').forEach(cell => {
                    cell.style.cursor = 'default';
                    cell.title = '';
                });
            }
        }

        function setDoughTypeForProduct(productId, doughTypeId) {
            if (!isDoughTypeEditMode) return;
            
            // If no productId specified, we're in batch mode - do nothing for now
            if (!productId) return;
            
            // Update the product's dough type
            updateProductDoughType(productId, doughTypeId)
                .then(() => {
                    // Refresh the page to show updated data
                    window.location.reload();
                })
                .catch(error => {
                    alert('Error updating dough type: ' + error.message);
                });
        }

        function updateProductDoughType(productId, doughTypeId) {
            return new Promise((resolve, reject) => {
                    const formData = new FormData();
                    formData.append('action', 'update');
                    formData.append('id', productId);
                    
                // Get current product data
                const row = document.getElementById('product-' + productId);
                if (!row) {
                    reject(new Error('Product row not found'));
                    return;
                }
                
                formData.append('name', row.cells[0].textContent.trim());
                formData.append('description', row.cells[1].textContent.trim());
                formData.append('price', row.cells[2].textContent.replace('$', '').replace(',', '').trim());
                formData.append('weight_grams', row.cells[3].textContent.replace(',', '').trim());
                formData.append('dough_type_id', doughTypeId || '');

                    fetch('products.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                            if (response.ok) {
                        resolve();
                            } else {
                        throw new Error('Network response was not ok');
                        }
                    })
                    .catch(error => {
                        reject(error);
                    });
            });
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

        // Add click handler for dough type cells when in edit mode
        document.addEventListener('click', function(event) {
            if (!isDoughTypeEditMode) return;
            
            const cell = event.target.closest('.dough-type-cell');
            if (cell) {
                const row = cell.closest('tr');
                const productId = row.getAttribute('data-id');
                if (productId) {
                    // Show a simple prompt for now, or implement a better UI
                    const doughTypes = <?php echo json_encode($dough_types); ?>;
                    let options = 'Available dough types:\n';
                    doughTypes.forEach((type, index) => {
                        options += `${index + 1}. ${type.name}\n`;
                    });
                    options += `${doughTypes.length + 1}. Clear (no dough type)\n`;
                    
                    const choice = prompt(options + '\nEnter the number of your choice:');
                    const choiceNum = parseInt(choice);
                    
                    if (choiceNum >= 1 && choiceNum <= doughTypes.length) {
                        setDoughTypeForProduct(productId, doughTypes[choiceNum - 1].id);
                    } else if (choiceNum === doughTypes.length + 1) {
                        setDoughTypeForProduct(productId, null);
                    }
                }
            }
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

        .dough-types-legend {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }

        .dough-types-legend h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #495057;
        }

        .dough-type-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .dough-type-btn {
            padding: 4px 12px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            font-size: 0.9em;
            transition: all 0.2s;
        }

        .dough-type-btn:hover {
            background: #e9ecef;
        }

        .dough-type-btn.active {
            background: #007bff;
            color: white;
            border-color: #0056b3;
        }

        .dough-type-btn.clear-btn {
            background: #f8f9fa;
            border-color: #ddd;
        }

        .dough-type-btn.clear-btn.active {
            background: #6c757d;
            color: white;
            border-color: #545b62;
        }

        .edit-dough-types-btn {
            margin-left: 10px;
        }

        .dough-type-cell .dough-type-buttons {
            padding: 8px 0;
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

        @media (max-width: 768px) {
            .action-bar {
                flex-direction: column;
                gap: 1rem;
            }
            
            .action-bar button {
                width: 100%;
            }
            
            .table-responsive {
                margin-top: 1rem;
            }
        }
    </style>
</div>

<?php require_once 'includes/footer.php'; ?> 