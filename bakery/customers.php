<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/zones_catalog.php';
require_once 'includes/customer_portal.php';
require_once 'includes/sf_baker.php';
bakery_ensure_portal_schema($db);
bakery_ensure_sfb_schema($db);

// Set page title
$page_title = bakery_t('page.customers');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    $zoneName = empty($_POST['zone']) ? null : $_POST['zone'];
                    $zoneId = bakery_zone_id_for_name($db, $zoneName);
                    $stmt = $db->prepare("INSERT INTO customers (name, email, phone, address, zone, zone_id, deliver_by, deliver_after, default_pan_dulce_price, portal_phone, portal_code, portal_enabled, sf_baker_enabled, pricing_tier, payment_collection) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $portalCode = bakery_normalize_login_code($_POST['portal_code'] ?? '');
                    $stmt->execute([
                        $_POST['name'],
                        empty($_POST['email']) ? null : $_POST['email'],
                        $_POST['phone'],
                        $_POST['address'],
                        $zoneName,
                        $zoneId,
                        empty($_POST['deliver_by']) ? null : $_POST['deliver_by'],
                        empty($_POST['deliver_after']) ? null : $_POST['deliver_after'],
                        empty($_POST['default_pan_dulce_price']) ? null : $_POST['default_pan_dulce_price'],
                        empty($_POST['portal_phone']) ? null : $_POST['portal_phone'],
                        $portalCode !== '' ? $portalCode : null,
                        !empty($_POST['portal_enabled']) ? 1 : 0,
                        !empty($_POST['sf_baker_enabled']) ? 1 : 0,
                        in_array($_POST['pricing_tier'] ?? '', ['retail', 'wholesale', 'custom'], true) ? $_POST['pricing_tier'] : 'retail',
                        in_array($_POST['payment_collection'] ?? '', ['cod', 'signature'], true) ? $_POST['payment_collection'] : 'cod',
                    ]);
                    $newId = (int)$db->lastInsertId();
                    if ($newId > 0) {
                        header('Location: customer_record.php?customer_id=' . $newId . '&created=1');
                    } else {
                        header('Location: customers.php?success=created');
                    }
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to create customer: " . $e->getMessage();
                }
                break;

            case 'update':
                try {
                    $zoneName = empty($_POST['zone']) ? null : $_POST['zone'];
                    $zoneId = bakery_zone_id_for_name($db, $zoneName);
                    $stmt = $db->prepare("UPDATE customers SET name = ?, email = ?, phone = ?, address = ?, zone = ?, zone_id = ?, deliver_by = ?, deliver_after = ?, default_pan_dulce_price = ?, portal_phone = ?, portal_code = ?, portal_enabled = ?, sf_baker_enabled = ?, pricing_tier = ?, payment_collection = ? WHERE id = ?");
                    $portalCode = bakery_normalize_login_code($_POST['portal_code'] ?? '');
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['email'],
                        $_POST['phone'],
                        $_POST['address'],
                        $zoneName,
                        $zoneId,
                        empty($_POST['deliver_by']) ? null : $_POST['deliver_by'],
                        empty($_POST['deliver_after']) ? null : $_POST['deliver_after'],
                        empty($_POST['default_pan_dulce_price']) ? null : $_POST['default_pan_dulce_price'],
                        empty($_POST['portal_phone']) ? null : $_POST['portal_phone'],
                        $portalCode !== '' ? $portalCode : null,
                        !empty($_POST['portal_enabled']) ? 1 : 0,
                        !empty($_POST['sf_baker_enabled']) ? 1 : 0,
                        in_array($_POST['pricing_tier'] ?? '', ['retail', 'wholesale', 'custom'], true) ? $_POST['pricing_tier'] : 'retail',
                        in_array($_POST['payment_collection'] ?? '', ['cod', 'signature'], true) ? $_POST['payment_collection'] : 'cod',
                        $_POST['id']
                    ]);
                    header("Location: customers.php?success=updated");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to update customer: " . $e->getMessage();
                }
                break;

            case 'delete':
                try {
                    $stmt = $db->prepare("DELETE FROM customers WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    header("Location: customers.php?success=deleted");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to delete customer: " . $e->getMessage();
                }
                break;

            case 'bulk_create':
                try {
                    $names = json_decode($_POST['names'], true);
                    $stmt = $db->prepare("INSERT INTO customers (name, email, phone, address) VALUES (?, ?, ?, ?)");
                    foreach ($names as $name) {
                        $stmt->execute([
                            $name,
                            '',
                            '',
                            ''
                        ]);
                    }
                    header("Location: customers.php?success=bulk_created");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to create customers: " . $e->getMessage();
                }
                break;

            case 'quick_edit':
                try {
                    $field = $_POST['field'];
                    $value = $_POST['value'];
                    $id = $_POST['id'];
                    
                    // Validate field name for security
                    $allowed_fields = ['name', 'email', 'phone', 'address', 'zone', 'deliver_by', 'deliver_after', 'default_pan_dulce_price', 'portal_phone', 'portal_code', 'portal_enabled', 'sf_baker_enabled', 'pricing_tier', 'payment_collection'];
                    if (!in_array($field, $allowed_fields)) {
                        throw new Exception("Invalid field");
                    }
                    
                    if ($field === 'zone') {
                        $zoneName = empty($value) ? null : $value;
                        $zoneId = bakery_zone_id_for_name($db, $zoneName);
                        $stmt = $db->prepare("UPDATE customers SET zone = ?, zone_id = ? WHERE id = ?");
                        $stmt->execute([$zoneName, $zoneId, $id]);
                    } else {
                        $stmt = $db->prepare("UPDATE customers SET $field = ? WHERE id = ?");
                        $stmt->execute([empty($value) ? null : $value, $id]);
                    }
                    
                    echo json_encode(['success' => true]);
                    exit;
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
            $success_message = 'Customer created successfully!';
            break;
        case 'updated':
            $success_message = 'Customer updated successfully!';
            break;
        case 'deleted':
            $success_message = 'Customer deleted successfully!';
            break;
        case 'bulk_created':
            $success_message = 'Customers created successfully!';
            break;
    }
}

// Load zones from DB (fallback to legacy hardcoded list if empty)
$zonesCatalog = bakery_zones_catalog($db);
$zones = array_column($zonesCatalog, 'name');
?>

<div class="container container--wide">
    <h1>👥 Customers Management</h1>
    
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

    <!-- Action Bar -->
    <div class="action-bar">
        <button class="btn-primary" onclick="showCustomerForm()">
            <i class="icon">➕</i> Add New Customer
        </button>
        <button class="btn-secondary" onclick="showBulkAddModal()">
            <i class="icon">📋</i> Add Multiple Customers
        </button>
        <button class="btn-info" onclick="toggleEditMode()">
            <i class="icon">✏️</i> <span id="editModeText">Enable Quick Edit</span>
        </button>
        <input type="search" id="customerSearch" class="customer-search"
               placeholder="Search by name, phone, email, zone, or address…"
               autocomplete="off"
               autofocus
               value="<?php echo htmlspecialchars(trim((string)($_GET['q'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"
               aria-label="Search customers">
        <span id="customerSearchHint" class="customer-search-hint">Type to filter · Enter opens the first match</span>
    </div>

    <!-- Add/Edit Customer Form (Hidden by default) -->
    <div id="customerForm" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="hideCustomerForm()">&times;</span>
            <h2 id="formTitle">Add New Customer</h2>
            <form id="customerFormElement" method="POST" onsubmit="return validateForm()">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="id" value="">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone">
                    </div>

                    <div class="form-group">
                        <label for="zone">🗺️ Zone</label>
                        <select id="zone" name="zone">
                            <option value="">Select Zone</option>
                            <?php foreach ($zones as $zone): ?>
                                <option value="<?php echo htmlspecialchars($zone); ?>"><?php echo htmlspecialchars($zone); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" rows="2"></textarea>
                    </div>
                </div>

                <div class="delivery-constraints">
                    <h3>⏰ Delivery Time Constraints</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="deliver_by">📅 Must Deliver By</label>
                            <input type="time" id="deliver_by" name="deliver_by">
                            <small class="help-text">Latest time for delivery (e.g., farmer's market deadline)</small>
                        </div>

                        <div class="form-group">
                            <label for="deliver_after">🕐 Can Deliver After</label>
                            <input type="time" id="deliver_after" name="deliver_after">
                            <small class="help-text">Earliest time for delivery (e.g., restaurant opens at 3 PM)</small>
                        </div>
                    </div>
                </div>

                <div class="pricing-constraints">
                    <h3>Customer Portal</h3>
                    <p class="help-text">Customers sign in at <a href="<?php echo htmlspecialchars(BASE_URL); ?>customer_login.php" target="_blank">customer portal</a> with their 4-digit passcode.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="portal_phone">Portal phone (login)</label>
                            <input type="tel" id="portal_phone" name="portal_phone" placeholder="Store admin phone">
                            <small class="help-text">Leave blank to use the customer phone above.</small>
                        </div>
                        <div class="form-group">
                            <label for="portal_code">4-digit passcode</label>
                            <input type="tel" id="portal_code" name="portal_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="1234">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="pricing_tier">Catalog pricing tier</label>
                            <select id="pricing_tier" name="pricing_tier">
                                <option value="retail">Retail (standard product price)</option>
                                <option value="wholesale">Wholesale (wholesale price when set)</option>
                                <option value="custom">Custom (per-product overrides)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="payment_collection">Delivery payment</label>
                            <select id="payment_collection" name="payment_collection">
                                <option value="cod" selected>Cash on delivery (COD)</option>
                                <option value="signature">Signature receipt (no cash)</option>
                            </select>
                            <small class="help-text">COD stops count toward the driver&apos;s cash turn-in total in Route Manager.</small>
                        </div>
                        <div class="form-group" style="display:flex;align-items:flex-end;">
                            <label style="display:flex;align-items:center;gap:8px;margin:0;">
                                <input type="checkbox" id="portal_enabled" name="portal_enabled" value="1">
                                Enable customer portal access
                            </label>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="display:flex;align-items:flex-end;">
                            <label style="display:flex;align-items:center;gap:8px;margin:0;">
                                <input type="checkbox" id="sf_baker_enabled" name="sf_baker_enabled" value="1">
                                Enable SF Baker module (baking journal in the portal)
                            </label>
                            <small class="help-text">Adds the SF Baker section (starters, formulas, batches) to this customer's portal.</small>
                        </div>
                    </div>
                </div>

                <div class="pricing-constraints">
                    <h3>💰 Pan Dulce Pricing</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="default_pan_dulce_price">🍞 Default Pan Dulce Price</label>
                            <input type="number" id="default_pan_dulce_price" name="default_pan_dulce_price" step="0.01" min="0" max="99.99">
                            <small class="help-text">Custom price for all pan dulce products (leave empty for standard pricing)</small>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="hideCustomerForm()">Cancel</button>
                    <button type="submit" class="btn-primary">Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Add Modal -->
    <div id="bulkAddModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="hideBulkAddModal()">&times;</span>
            <h2>Add Multiple Customers</h2>
            <p class="help-text">Enter one customer name per line. Other details can be added later.</p>
            <form id="bulkAddForm" method="POST" onsubmit="return submitBulkAdd(event)">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="bulk_create">
                
                <div class="form-group">
                    <label for="bulkNames">Customer Names *</label>
                    <textarea id="bulkNames" name="bulkNames" rows="10" required 
                        placeholder="John Doe&#13;&#10;Jane Smith&#13;&#10;Robert Johnson"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="hideBulkAddModal()">Cancel</button>
                    <button type="submit" class="btn-primary">Add Customers</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="table-responsive">
        <div class="mobile-table-header">
            <div class="mobile-scroll-hint">← Scroll to see all columns →</div>
        </div>
        <table class="table-hover" id="customersTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>🗺️ Zone</th>
                    <th>📅 Deliver By</th>
                    <th>🕐 Deliver After</th>
                    <th>💰 Pan Dulce Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                try {
                    $customers = $db->query("SELECT * FROM customers ORDER BY zone, name")->fetchAll();
                    $highlightId = max(0, (int)($_GET['highlight'] ?? 0));
                    foreach ($customers as $customer):
                        $searchBlob = strtolower(trim(implode(' ', array_filter([
                            (string)($customer['name'] ?? ''),
                            (string)($customer['email'] ?? ''),
                            (string)($customer['phone'] ?? ''),
                            (string)($customer['portal_phone'] ?? ''),
                            (string)($customer['address'] ?? ''),
                            (string)($customer['zone'] ?? ''),
                        ]))));
                        $rowClass = ((int)$customer['id'] === $highlightId) ? ' is-highlighted' : '';
                ?>
                    <tr class="<?php echo trim($rowClass); ?>" data-customer-id="<?php echo (int)$customer['id']; ?>" data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="editable" data-field="name"><a class="customer-name-link" href="customer_record.php?customer_id=<?php echo (int)$customer['id']; ?>"><?php echo htmlspecialchars($customer['name']); ?></a></td>
                        <td class="editable" data-field="email"><?php echo htmlspecialchars($customer['email'] ?? ''); ?></td>
                        <td class="editable" data-field="phone"><?php echo htmlspecialchars($customer['phone'] ?? ''); ?></td>
                        <td class="editable" data-field="address"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></td>
                        <td class="editable zone-field" data-field="zone">
                            <?php if ($customer['zone']): ?>
                                <span class="zone-badge zone-<?php echo strtolower(str_replace([' ', '/'], ['-', '-'], $customer['zone'])); ?>">
                                    <?php echo htmlspecialchars($customer['zone']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">No zone</span>
                            <?php endif; ?>
                        </td>
                        <td class="editable time-field" data-field="deliver_by">
                            <?php 
                            if ($customer['deliver_by']) {
                                $time = new DateTime($customer['deliver_by']);
                                echo '<span class="time-display">' . $time->format('g:i A') . '</span>';
                                echo '<input type="time" class="time-input" style="display:none;" value="' . $customer['deliver_by'] . '">';
                            } else {
                                echo '<span class="time-display text-muted">No constraint</span>';
                                echo '<input type="time" class="time-input" style="display:none;" value="">';
                            }
                            ?>
                        </td>
                        <td class="editable time-field" data-field="deliver_after">
                            <?php 
                            if ($customer['deliver_after']) {
                                $time = new DateTime($customer['deliver_after']);
                                echo '<span class="time-display">' . $time->format('g:i A') . '</span>';
                                echo '<input type="time" class="time-input" style="display:none;" value="' . $customer['deliver_after'] . '">';
                            } else {
                                echo '<span class="time-display text-muted">No constraint</span>';
                                echo '<input type="time" class="time-input" style="display:none;" value="">';
                            }
                            ?>
                        </td>
                        <td class="editable price-field" data-field="default_pan_dulce_price">
                            <?php 
                            if ($customer['default_pan_dulce_price']) {
                                echo '<span class="price-display">$' . number_format($customer['default_pan_dulce_price'], 2) . '</span>';
                                echo '<input type="number" class="price-input" style="display:none;" step="0.01" min="0" max="99.99" value="' . $customer['default_pan_dulce_price'] . '">';
                            } else {
                                echo '<span class="price-display text-muted">Standard pricing</span>';
                                echo '<input type="number" class="price-input" style="display:none;" step="0.01" min="0" max="99.99" value="">';
                            }
                            ?>
                        </td>
                        <td class="actions">
                            <a class="btn-icon" href="customer_record.php?customer_id=<?php echo (int)$customer['id']; ?>" title="Open customer hub">👤</a>
                            <button class="btn-icon" onclick="editCustomer(<?php echo htmlspecialchars(json_encode($customer)); ?>)" title="Edit Customer">
                                ✏️
                            </button>
                            <button class="btn-icon" onclick="copyCustomer(<?php echo htmlspecialchars(json_encode($customer)); ?>)" title="Copy Customer">
                                📋
                            </button>
                            <button class="btn-icon" onclick="deleteCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['name'], ENT_QUOTES); ?>')" title="Delete Customer">
                                🗑️
                            </button>
                        </td>
                    </tr>
                <?php
                    endforeach;
                } catch (Exception $e) {
                    echo '<tr><td colspan="8" class="error">Error loading customers: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal" style="display: none;">
        <div class="modal-content">
            <h2>Confirm Deletion</h2>
            <p>Are you sure you want to delete customer: <strong id="deleteCustomerName"></strong>?</p>
            <form method="POST">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteCustomerId">
                <div class="form-actions">
                    <button type="button" class="btn-secondary" onclick="hideDeleteModal()">Cancel</button>
                    <button type="submit" class="btn-danger">Delete Customer</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let editMode = false;
        const zones = <?php echo json_encode($zones); ?>;

        (function () {
            const search = document.getElementById('customerSearch');
            if (!search) return;
            const rows = Array.prototype.slice.call(document.querySelectorAll('#customersTable tbody tr[data-customer-id]'));
            const hint = document.getElementById('customerSearchHint');

            function visibleRows() {
                return rows.filter(function (row) {
                    return row.style.display !== 'none';
                });
            }

            function applyFilter() {
                const q = search.value.trim().toLowerCase();
                let shown = 0;
                rows.forEach(function (row) {
                    const blob = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                    const match = q === '' || blob.indexOf(q) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) shown++;
                });
                if (hint) {
                    if (q === '') {
                        hint.textContent = 'Type to filter · Enter opens the first match';
                    } else if (shown === 0) {
                        hint.textContent = 'No customers match';
                    } else if (shown === 1) {
                        hint.textContent = '1 match · Enter opens their hub';
                    } else {
                        hint.textContent = shown + ' matches · Enter opens the first';
                    }
                }
            }

            search.addEventListener('input', applyFilter);
            search.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                const first = visibleRows()[0];
                if (!first) return;
                const link = first.querySelector('a.customer-name-link');
                if (link && link.href) {
                    window.location.href = link.href;
                }
            });

            if (search.value.trim() !== '') {
                applyFilter();
            }

            const highlighted = document.querySelector('#customersTable tbody tr.is-highlighted');
            if (highlighted) {
                highlighted.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        })();

        function showCustomerForm() {
            document.getElementById('customerForm').style.display = 'block';
            document.getElementById('formTitle').textContent = 'Add New Customer';
            document.getElementById('customerFormElement').reset();
            document.getElementById('customerFormElement').action.value = 'create';
            document.getElementById('name').focus();
        }

        function hideCustomerForm() {
            document.getElementById('customerForm').style.display = 'none';
        }

        function editCustomer(customer) {
            document.getElementById('formTitle').textContent = 'Edit Customer';
            document.getElementById('customerFormElement').action.value = 'update';
            document.getElementById('customerFormElement').id.value = customer.id;
            document.getElementById('name').value = customer.name || '';
            document.getElementById('email').value = customer.email || '';
            document.getElementById('phone').value = customer.phone || '';
            document.getElementById('address').value = customer.address || '';
            document.getElementById('zone').value = customer.zone || '';
            document.getElementById('deliver_by').value = customer.deliver_by || '';
            document.getElementById('deliver_after').value = customer.deliver_after || '';
            document.getElementById('default_pan_dulce_price').value = customer.default_pan_dulce_price || '';
            document.getElementById('portal_phone').value = customer.portal_phone || '';
            document.getElementById('portal_code').value = customer.portal_code || '';
            document.getElementById('portal_enabled').checked = customer.portal_enabled == 1;
            document.getElementById('sf_baker_enabled').checked = customer.sf_baker_enabled == 1;
            document.getElementById('pricing_tier').value = customer.pricing_tier || 'retail';
            document.getElementById('payment_collection').value = customer.payment_collection || 'cod';
            document.getElementById('customerForm').style.display = 'block';
            document.getElementById('name').focus();
        }

        function copyCustomer(customer) {
            document.getElementById('formTitle').textContent = 'Copy Customer (New)';
            document.getElementById('customerFormElement').action.value = 'create';
            document.getElementById('customerFormElement').id.value = '';
            document.getElementById('name').value = customer.name + ' (Copy)';
            document.getElementById('email').value = customer.email || '';
            document.getElementById('phone').value = customer.phone || '';
            document.getElementById('address').value = customer.address || '';
            document.getElementById('zone').value = customer.zone || '';
            document.getElementById('deliver_by').value = customer.deliver_by || '';
            document.getElementById('deliver_after').value = customer.deliver_after || '';
            document.getElementById('default_pan_dulce_price').value = customer.default_pan_dulce_price || '';
            document.getElementById('portal_phone').value = '';
            document.getElementById('portal_code').value = '';
            document.getElementById('portal_enabled').checked = false;
            document.getElementById('sf_baker_enabled').checked = false;
            document.getElementById('pricing_tier').value = customer.pricing_tier || 'retail';
            document.getElementById('payment_collection').value = customer.payment_collection || 'cod';
            document.getElementById('customerForm').style.display = 'block';
            document.getElementById('name').focus();
            document.getElementById('name').select();
        }

        function deleteCustomer(id, name) {
            document.getElementById('deleteCustomerId').value = id;
            document.getElementById('deleteCustomerName').textContent = name;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        function validateForm() {
            const name = document.getElementById('name').value.trim();
            const deliverBy = document.getElementById('deliver_by').value;
            const deliverAfter = document.getElementById('deliver_after').value;
            
            if (!name) {
                showMessage('Please enter a customer name', 'error');
                return false;
            }

            // Validate time constraints
            if (deliverBy && deliverAfter) {
                if (deliverAfter >= deliverBy) {
                    showMessage('Delivery "after" time must be earlier than "by" time', 'error');
                    return false;
                }
            }
            
            return true;
        }

        function toggleEditMode() {
            editMode = !editMode;
            const editModeText = document.getElementById('editModeText');
            const table = document.getElementById('customersTable');
            
            if (editMode) {
                editModeText.textContent = 'Disable Quick Edit';
                table.classList.add('edit-mode');
                showMessage('Quick edit mode enabled. Click on any field to edit directly.', 'success');
                enableQuickEdit();
            } else {
                editModeText.textContent = 'Enable Quick Edit';
                table.classList.remove('edit-mode');
                showMessage('Quick edit mode disabled.', 'info');
                disableQuickEdit();
            }
        }

        function enableQuickEdit() {
            const editableCells = document.querySelectorAll('.editable');
            editableCells.forEach(cell => {
                cell.addEventListener('click', handleCellClick);
                cell.style.cursor = 'pointer';
            });
        }

        function disableQuickEdit() {
            const editableCells = document.querySelectorAll('.editable');
            editableCells.forEach(cell => {
                cell.removeEventListener('click', handleCellClick);
                cell.style.cursor = 'default';
                // Reset any active editing
                resetCellEdit(cell);
            });
        }

        function handleCellClick(event) {
            if (!editMode) return;
            
            const cell = event.target.closest('.editable');
            if (!cell) return;
            
            const field = cell.dataset.field;
            const customerId = cell.closest('tr').dataset.customerId;
            
            // Don't edit if already editing this cell
            if (cell.classList.contains('editing')) return;
            
            startCellEdit(cell, customerId, field);
        }

        function startCellEdit(cell, customerId, field) {
            cell.classList.add('editing');
            
            if (field === 'zone') {
                // Handle zone field with dropdown
                const currentValue = cell.textContent.trim();
                const select = document.createElement('select');
                select.style.width = '100%';
                select.style.border = '2px solid #007bff';
                select.style.borderRadius = '4px';
                select.style.padding = '4px';
                select.style.fontFamily = 'inherit';
                select.style.fontSize = 'inherit';
                
                // Add empty option
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'No zone';
                select.appendChild(emptyOption);
                
                // Add zone options
                zones.forEach(zone => {
                    const option = document.createElement('option');
                    option.value = zone;
                    option.textContent = zone;
                    if (zone === currentValue || (currentValue === 'No zone' && zone === '')) {
                        option.selected = true;
                    }
                    select.appendChild(option);
                });
                
                cell.innerHTML = '';
                cell.appendChild(select);
                select.focus();
                
                select.addEventListener('blur', function() {
                    finishZoneEdit(cell, customerId, field, select.value, currentValue);
                });
                
                select.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        select.blur();
                    } else if (e.key === 'Escape') {
                        cancelCellEdit(cell, currentValue);
                    }
                });
                
            } else if (field === 'deliver_by' || field === 'deliver_after') {
                // Handle time fields
                const timeInput = cell.querySelector('.time-input');
                const timeDisplay = cell.querySelector('.time-display');
                
                if (timeInput && timeDisplay) {
                    timeDisplay.style.display = 'none';
                    timeInput.style.display = 'block';
                    timeInput.style.border = '2px solid #007bff';
                    timeInput.focus();
                    
                    const originalValue = timeInput.value;
                    
                    timeInput.addEventListener('blur', function() {
                        finishTimeEdit(cell, customerId, field, timeInput.value, originalValue);
                    });
                    
                    timeInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            timeInput.blur();
                        } else if (e.key === 'Escape') {
                            cancelTimeEdit(cell, originalValue);
                        }
                    });
                }
            } else if (field === 'default_pan_dulce_price') {
                // Handle price field
                const priceInput = cell.querySelector('.price-input');
                const priceDisplay = cell.querySelector('.price-display');
                
                if (priceInput && priceDisplay) {
                    priceDisplay.style.display = 'none';
                    priceInput.style.display = 'block';
                    priceInput.style.border = '2px solid #007bff';
                    priceInput.focus();
                    priceInput.select();
                    
                    const originalValue = priceInput.value;
                    
                    priceInput.addEventListener('blur', function() {
                        finishPriceEdit(cell, customerId, field, priceInput.value, originalValue);
                    });
                    
                    priceInput.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            priceInput.blur();
                        } else if (e.key === 'Escape') {
                            cancelPriceEdit(cell, originalValue);
                        }
                    });
                }
            } else {
                // Handle text fields
                const originalText = cell.textContent.trim();
                
                if (field === 'address') {
                    const textarea = document.createElement('textarea');
                    textarea.value = originalText;
                    textarea.style.width = '100%';
                    textarea.style.height = '60px';
                    textarea.style.border = '2px solid #007bff';
                    textarea.style.borderRadius = '4px';
                    textarea.style.padding = '4px';
                    textarea.style.fontFamily = 'inherit';
                    textarea.style.fontSize = 'inherit';
                    
                    cell.innerHTML = '';
                    cell.appendChild(textarea);
                    textarea.focus();
                    
                    textarea.addEventListener('blur', function() {
                        finishTextEdit(cell, customerId, field, textarea.value, originalText);
                    });
                    
                    textarea.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && e.ctrlKey) {
                            textarea.blur();
                        } else if (e.key === 'Escape') {
                            cancelCellEdit(cell, originalText);
                        }
                    });
                } else {
                    const input = document.createElement('input');
                    input.type = field === 'email' ? 'email' : 'text';
                    input.value = originalText;
                    input.style.width = '100%';
                    input.style.border = '2px solid #007bff';
                    input.style.borderRadius = '4px';
                    input.style.padding = '4px';
                    input.style.fontFamily = 'inherit';
                    input.style.fontSize = 'inherit';
                    
                    cell.innerHTML = '';
                    cell.appendChild(input);
                    input.focus();
                    input.select();
                    
                    input.addEventListener('blur', function() {
                        finishTextEdit(cell, customerId, field, input.value, originalText);
                    });
                    
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter') {
                            input.blur();
                        } else if (e.key === 'Escape') {
                            cancelCellEdit(cell, originalText);
                        }
                    });
                }
            }
        }

        function finishZoneEdit(cell, customerId, field, newValue, originalValue) {
            if (newValue !== originalValue && newValue !== (originalValue === 'No zone' ? '' : originalValue)) {
                // Save to database
                saveFieldValue(customerId, field, newValue)
                    .then(success => {
                        if (success) {
                            updateZoneDisplay(cell, newValue);
                            showMessage('Zone updated successfully', 'success');
                        } else {
                            cancelCellEdit(cell, originalValue);
                            showMessage('Failed to update zone', 'error');
                        }
                    });
            } else {
                updateZoneDisplay(cell, newValue);
            }
            
            cell.classList.remove('editing');
        }

        function updateZoneDisplay(cell, value) {
            if (value) {
                const zoneClass = 'zone-' + value.toLowerCase().replace(/[\s\/]/g, '-');
                cell.innerHTML = `<span class="zone-badge ${zoneClass}">${value}</span>`;
            } else {
                cell.innerHTML = '<span class="text-muted">No zone</span>';
            }
        }

        function finishTimeEdit(cell, customerId, field, newValue, originalValue) {
            const timeDisplay = cell.querySelector('.time-display');
            const timeInput = cell.querySelector('.time-input');
            
            if (newValue !== originalValue) {
                // Save to database
                saveFieldValue(customerId, field, newValue)
                    .then(success => {
                        if (success) {
                            timeInput.value = newValue;
                            updateTimeDisplay(timeDisplay, newValue);
                            showMessage('Time constraint updated successfully', 'success');
                        } else {
                            timeInput.value = originalValue;
                            updateTimeDisplay(timeDisplay, originalValue);
                            showMessage('Failed to update time constraint', 'error');
                        }
                    });
            } else {
                updateTimeDisplay(timeDisplay, newValue);
            }
            
            timeDisplay.style.display = 'block';
            timeInput.style.display = 'none';
            cell.classList.remove('editing');
        }

        function updateTimeDisplay(timeDisplay, value) {
            if (value) {
                const time = new Date('1970-01-01T' + value);
                timeDisplay.textContent = time.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
                timeDisplay.className = 'time-display';
            } else {
                timeDisplay.textContent = 'No constraint';
                timeDisplay.className = 'time-display text-muted';
            }
        }

        function finishTextEdit(cell, customerId, field, newValue, originalValue) {
            if (newValue !== originalValue) {
                // Save to database
                saveFieldValue(customerId, field, newValue)
                    .then(success => {
                        if (success) {
                            cell.textContent = newValue || (field === 'email' ? '' : '');
                            showMessage('Field updated successfully', 'success');
                        } else {
                            cell.textContent = originalValue;
                            showMessage('Failed to update field', 'error');
                        }
                    });
            } else {
                cell.textContent = newValue;
            }
            
            cell.classList.remove('editing');
        }

        function cancelTimeEdit(cell, originalValue) {
            const timeDisplay = cell.querySelector('.time-display');
            const timeInput = cell.querySelector('.time-input');
            
            timeInput.value = originalValue;
            updateTimeDisplay(timeDisplay, originalValue);
            timeDisplay.style.display = 'block';
            timeInput.style.display = 'none';
            cell.classList.remove('editing');
        }

        function finishPriceEdit(cell, customerId, field, newValue, originalValue) {
            const priceDisplay = cell.querySelector('.price-display');
            const priceInput = cell.querySelector('.price-input');
            
            if (newValue !== originalValue) {
                // Save to database
                saveFieldValue(customerId, field, newValue)
                    .then(success => {
                        if (success) {
                            priceInput.value = newValue;
                            updatePriceDisplay(priceDisplay, newValue);
                            showMessage('Pan dulce price updated successfully', 'success');
                        } else {
                            priceInput.value = originalValue;
                            updatePriceDisplay(priceDisplay, originalValue);
                            showMessage('Failed to update pan dulce price', 'error');
                        }
                    });
            } else {
                updatePriceDisplay(priceDisplay, newValue);
            }
            
            priceDisplay.style.display = 'block';
            priceInput.style.display = 'none';
            cell.classList.remove('editing');
        }

        function updatePriceDisplay(priceDisplay, value) {
            if (value && value !== '') {
                const price = parseFloat(value);
                if (!isNaN(price)) {
                    priceDisplay.textContent = '$' + price.toFixed(2);
                    priceDisplay.className = 'price-display';
                } else {
                    priceDisplay.textContent = 'Standard pricing';
                    priceDisplay.className = 'price-display text-muted';
                }
            } else {
                priceDisplay.textContent = 'Standard pricing';
                priceDisplay.className = 'price-display text-muted';
            }
        }

        function cancelPriceEdit(cell, originalValue) {
            const priceDisplay = cell.querySelector('.price-display');
            const priceInput = cell.querySelector('.price-input');
            
            priceInput.value = originalValue;
            updatePriceDisplay(priceDisplay, originalValue);
            priceDisplay.style.display = 'block';
            priceInput.style.display = 'none';
            cell.classList.remove('editing');
        }

        function cancelCellEdit(cell, originalValue) {
            cell.textContent = originalValue;
            cell.classList.remove('editing');
        }

        function resetCellEdit(cell) {
            cell.classList.remove('editing');
            const timeDisplay = cell.querySelector('.time-display');
            const timeInput = cell.querySelector('.time-input');
            const priceDisplay = cell.querySelector('.price-display');
            const priceInput = cell.querySelector('.price-input');
            
            if (timeDisplay && timeInput) {
                timeDisplay.style.display = 'block';
                timeInput.style.display = 'none';
            }
            
            if (priceDisplay && priceInput) {
                priceDisplay.style.display = 'block';
                priceInput.style.display = 'none';
            }
        }

        async function saveFieldValue(customerId, field, value) {
            try {
                const formData = new FormData();
                formData.append('action', 'quick_edit');
                formData.append('id', customerId);
                formData.append('field', field);
                formData.append('value', value);
                
                const response = await fetch('customers.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                return result.success;
            } catch (error) {
                console.error('Error saving field:', error);
                return false;
            }
        }

        function showBulkAddModal() {
            document.getElementById('bulkAddModal').style.display = 'block';
            document.getElementById('bulkNames').focus();
        }

        function hideBulkAddModal() {
            document.getElementById('bulkAddModal').style.display = 'none';
        }

        function submitBulkAdd(event) {
            event.preventDefault();
            const names = document.getElementById('bulkNames').value.trim().split('\n').filter(name => name.trim());
            
            if (names.length === 0) {
                showMessage('Please enter at least one customer name', 'error');
                return false;
            }
            
            const formData = new FormData();
            formData.append('action', 'bulk_create');
            formData.append('names', JSON.stringify(names));
            
            fetch('customers.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                window.location.reload();
            }).catch(error => {
                showMessage('Error creating customers: ' + error.message, 'error');
            });
            
            return false;
        }

        function showMessage(message, type) {
            // Create or update message display
            let messageDiv = document.querySelector('.temp-message');
            if (!messageDiv) {
                messageDiv = document.createElement('div');
                messageDiv.className = 'temp-message';
                document.querySelector('.container').insertBefore(messageDiv, document.querySelector('.action-bar'));
            }
            
            messageDiv.className = `temp-message ${type}`;
            messageDiv.textContent = message;
            messageDiv.style.display = 'block';
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 3000);
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const customerForm = document.getElementById('customerForm');
            const deleteModal = document.getElementById('deleteModal');
            const bulkAddModal = document.getElementById('bulkAddModal');
            
            if (event.target === customerForm) {
                hideCustomerForm();
            } else if (event.target === deleteModal) {
                hideDeleteModal();
            } else if (event.target === bulkAddModal) {
                hideBulkAddModal();
            }
        }

        // Hide mobile scroll hint after user starts scrolling
        document.addEventListener('DOMContentLoaded', function() {
            const tableContainer = document.querySelector('.table-responsive');
            const scrollHint = document.querySelector('.mobile-scroll-hint');
            
            if (tableContainer && scrollHint) {
                let hasScrolled = false;
                
                tableContainer.addEventListener('scroll', function() {
                    if (!hasScrolled) {
                        hasScrolled = true;
                        scrollHint.style.opacity = '0';
                        setTimeout(() => {
                            scrollHint.parentElement.style.display = 'none';
                        }, 300);
                    }
                });
            }
        });
    </script>

    <style>
        /* Shared layout, buttons, alerts: css/base.css */

        /* Action Bar */
        .action-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            align-items: center;
        }

        .customer-search {
            flex: 1;
            min-width: 220px;
            max-width: 380px;
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 15px;
        }

        .customer-search-hint {
            font-size: 0.82rem;
            color: #64748b;
            white-space: nowrap;
        }

        .customer-name-link {
            color: inherit;
            font-weight: 600;
            text-decoration: none;
        }

        .customer-name-link:hover {
            color: #2c5aa0;
            text-decoration: underline;
        }

        #customersTable tbody tr.is-highlighted {
            outline: 2px solid #3182ce;
            outline-offset: -2px;
            background: #ebf8ff;
        }

        .btn-icon {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            padding: 8px;
            margin: 2px;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }

        .btn-icon:hover {
            background-color: #f8f9fa;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(3px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            position: absolute;
            right: 20px;
            top: 15px;
        }

        .close:hover {
            color: #000;
        }

        /* Form Styles */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #495057;
            font-size: 14px;
        }

        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: inherit;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }

        .delivery-constraints, .pricing-constraints {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .delivery-constraints h3, .pricing-constraints h3 {
            margin: 0 0 15px 0;
            color: #495057;
            font-size: 18px;
        }

        .help-text {
            color: #6c757d;
            font-size: 0.85em;
            margin-top: 5px;
            line-height: 1.4;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }

        /* Table Styles */
        .table-responsive {
            margin-top: 20px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        #customersTable {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        #customersTable th {
            background: #007bff;
            color: white;
            padding: 15px 10px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #0056b3;
        }

        #customersTable td {
            padding: 12px 10px;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        #customersTable tr:hover {
            background-color: #f8f9fa;
        }

        /* Zone Badge Styles */
        .zone-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .zone-centro {
            background: #007bff;
            color: white;
        }

        .zone-mission {
            background: #dc3545;
            color: white;
        }

        .zone-ruta-sour-flour {
            background: #28a745;
            color: white;
        }

        .zone-daly-city-san-mateo {
            background: #fd7e14;
            color: white;
        }

        .zone-north-bay {
            background: #6f42c1;
            color: white;
        }

        .zone-east-bay {
            background: #20c997;
            color: white;
        }

        /* Quick Edit Mode */
        #customersTable.edit-mode .editable {
            background-color: #fff3cd;
            border: 1px dashed #ffc107;
            position: relative;
        }

        #customersTable.edit-mode .editable:hover {
            background-color: #ffeaa7;
            border-color: #ffb347;
        }

        .text-muted {
            color: #6c757d !important;
            font-style: italic;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn-primary, .btn-secondary, .btn-info {
                justify-content: center;
            }
            
            .modal-content {
                margin: 2% auto;
                width: 95%;
                padding: 20px;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }

        @media (max-width: 768px) {
            /* Mobile table layout */
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            #customersTable {
                min-width: 800px; /* Ensure all columns are visible */
            }
            
            #customersTable th,
            #customersTable td {
                padding: 8px 6px;
                font-size: 14px;
                white-space: nowrap;
            }
            
            /* Make actions column wider for touch */
            #customersTable .actions {
                min-width: 120px;
            }
            
            /* Adjust zone badges for mobile */
            .zone-badge {
                padding: 3px 8px;
                font-size: 11px;
                white-space: nowrap;
            }
            
            /* Make price display more compact */
            .price-display {
                font-size: 13px;
                white-space: nowrap;
            }
            
            /* Adjust time display for mobile */
            .time-display {
                font-size: 13px;
                white-space: nowrap;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }
            
            h1 {
                font-size: 1.8rem;
            }
            
            /* Even more compact for very small screens */
            #customersTable th,
            #customersTable td {
                padding: 6px 4px;
                font-size: 12px;
            }
            
            .zone-badge {
                padding: 2px 6px;
                font-size: 10px;
            }
            
            .price-display, .time-display {
                font-size: 12px;
            }
            
            /* Make buttons more touch-friendly */
            .btn-icon {
                padding: 10px;
                font-size: 16px;
                min-width: 44px;
                min-height: 44px;
            }
            
            /* Adjust modal for very small screens */
            .modal-content {
                margin: 1% auto;
                width: 98%;
                padding: 15px;
            }
        }

        /* Add horizontal scroll indicator */
        .table-responsive::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            width: 20px;
            background: linear-gradient(to right, transparent, rgba(255,255,255,0.8));
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .table-responsive:hover::after {
            opacity: 1;
        }

        /* Mobile table header */
        .mobile-table-header {
            display: none;
            background: #f8f9fa;
            padding: 10px;
            text-align: center;
            border-bottom: 1px solid #dee2e6;
        }

        .mobile-scroll-hint {
            color: #6c757d;
            font-size: 14px;
            font-weight: 500;
        }

        /* Improve touch targets */
        @media (max-width: 768px) {
            .mobile-table-header {
                display: block;
            }
            
            .editable {
                min-height: 44px;
                display: flex;
                align-items: center;
            }
            
            /* Make sure text doesn't wrap in cells */
            #customersTable td {
                vertical-align: middle;
            }
        }
    </style>
</div>

<?php require_once 'includes/footer.php'; ?> 
