<?php
// Security check
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'add_lead':
                $stmt = $db->prepare("INSERT INTO leads (customer_name, contact_name, phone, email, address, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $success = $stmt->execute([
                    $_POST['customer_name'],
                    $_POST['contact_name'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['address'],
                    $_POST['notes'],
                    $_POST['status'] ?? 'new'
                ]);
                echo json_encode(['success' => $success, 'id' => $db->lastInsertId()]);
                break;
                
            case 'update_lead':
                $allowedStatuses = ['new', 'contacted', 'interested', 'qualified', 'converted', 'closed'];
                if (!in_array($_POST['status'] ?? '', $allowedStatuses, true)) {
                    throw new InvalidArgumentException('Invalid pipeline stage.');
                }
                $stmt = $db->prepare("UPDATE leads SET customer_name = ?, contact_name = ?, phone = ?, email = ?, address = ?, notes = ?, status = ? WHERE id = ?");
                $success = $stmt->execute([
                    $_POST['customer_name'],
                    $_POST['contact_name'],
                    $_POST['phone'],
                    $_POST['email'],
                    $_POST['address'],
                    $_POST['notes'],
                    $_POST['status'],
                    $_POST['id']
                ]);
                echo json_encode(['success' => $success]);
                break;

            case 'convert_lead':
                $leadId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$leadId) {
                    throw new InvalidArgumentException('A valid lead is required.');
                }
                $db->beginTransaction();
                $stmt = $db->prepare('SELECT * FROM leads WHERE id = ? FOR UPDATE');
                $stmt->execute([$leadId]);
                $lead = $stmt->fetch();
                if (!$lead) {
                    throw new RuntimeException('Lead not found.');
                }
                if (!empty($lead['customer_id'])) {
                    $customerId = (int)$lead['customer_id'];
                    $stmt = $db->prepare("UPDATE leads SET status = 'converted' WHERE id = ?");
                    $stmt->execute([$leadId]);
                } else {
                    $existingCustomer = $db->prepare('SELECT id FROM customers WHERE name = ? LIMIT 1');
                    $existingCustomer->execute([$lead['customer_name']]);
                    if ($existingCustomer->fetchColumn()) {
                        throw new RuntimeException('A customer with this name already exists. Rename the lead or manage the existing customer.');
                    }
                    $stmt = $db->prepare('INSERT INTO customers (name, address, phone, email, is_active) VALUES (?, ?, ?, ?, 1)');
                    $stmt->execute([$lead['customer_name'], $lead['address'], $lead['phone'], $lead['email']]);
                    $customerId = (int)$db->lastInsertId();
                    $stmt = $db->prepare("UPDATE leads SET status = 'converted', customer_id = ? WHERE id = ?");
                    $stmt->execute([$customerId, $leadId]);
                }
                $db->commit();
                echo json_encode(['success' => true, 'customer_id' => $customerId]);
                break;
                
            case 'delete_lead':
                $stmt = $db->prepare("DELETE FROM leads WHERE id = ?");
                $success = $stmt->execute([$_POST['id']]);
                echo json_encode(['success' => $success]);
                break;
                
            case 'add_contact':
                $stmt = $db->prepare("INSERT INTO lead_contacts (lead_id, contact_date, contact_mode, comment, follow_up_needed, follow_up_date) VALUES (?, ?, ?, ?, ?, ?)");
                $success = $stmt->execute([
                    $_POST['lead_id'],
                    $_POST['contact_date'],
                    $_POST['contact_mode'],
                    $_POST['comment'],
                    isset($_POST['follow_up_needed']) ? 1 : 0,
                    $_POST['follow_up_date'] ?: null
                ]);
                echo json_encode(['success' => $success, 'id' => $db->lastInsertId()]);
                break;
                
            case 'update_contact':
                $stmt = $db->prepare("UPDATE lead_contacts SET contact_date = ?, contact_mode = ?, comment = ?, follow_up_needed = ?, follow_up_date = ? WHERE id = ?");
                $success = $stmt->execute([
                    $_POST['contact_date'],
                    $_POST['contact_mode'],
                    $_POST['comment'],
                    isset($_POST['follow_up_needed']) ? 1 : 0,
                    $_POST['follow_up_date'] ?: null,
                    $_POST['id']
                ]);
                echo json_encode(['success' => $success]);
                break;
                
            case 'delete_contact':
                $stmt = $db->prepare("DELETE FROM lead_contacts WHERE id = ?");
                $success = $stmt->execute([$_POST['id']]);
                echo json_encode(['success' => $success]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$page_title = bakery_t('page.leads');
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Fetch all leads with contact count
$leads = $db->query("
    SELECT l.*,
           MAX(c.is_active) AS linked_customer_active,
           COUNT(lc.id) as contact_count,
           MAX(lc.contact_date) as last_contact_date,
           SUM(CASE WHEN lc.follow_up_needed = 1 AND lc.follow_up_date <= CURDATE() THEN 1 ELSE 0 END) as overdue_followups
    FROM leads l
    LEFT JOIN lead_contacts lc ON l.id = lc.lead_id
    LEFT JOIN customers c ON l.customer_id = c.id
    GROUP BY l.id
    ORDER BY l.created_at DESC
")->fetchAll();

$pipelineCounts = array_fill_keys(['new', 'contacted', 'interested', 'qualified', 'converted', 'closed'], 0);
foreach ($leads as $pipelineLead) {
    if (isset($pipelineCounts[$pipelineLead['status']])) {
        $pipelineCounts[$pipelineLead['status']]++;
    }
}
$clientCounts = $db->query(
    'SELECT SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_clients,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_clients
     FROM customers'
)->fetch();
$activeClients = (int)($clientCounts['active_clients'] ?? 0);
$inactiveClients = (int)($clientCounts['inactive_clients'] ?? 0);

// Get status options
$status_options = ['new', 'contacted', 'interested', 'qualified', 'converted', 'closed'];
$contact_modes = ['phone', 'email', 'in_person', 'text', 'social_media'];
?>

<div class="container">
    <div class="page-header">
        <h1>🎯 Leads Management</h1>
        <p class="subtitle">Track prospects and manage contact history</p>
        <button id="add-lead-btn" class="btn btn-primary">+ Add New Lead</button>
    </div>

    <section class="pipeline-section" aria-label="Customer lifecycle pipeline">
        <div class="pipeline-heading">
            <div><h2>Customer pipeline</h2><p>From first conversation through active and inactive client relationships.</p></div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="filterPipeline('all')">Show all leads</button>
        </div>
        <div class="pipeline-track">
            <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'qualified' => 'Qualified'] as $stage => $label): ?>
                <button type="button" class="pipeline-stage" onclick="filterPipeline('<?php echo $stage; ?>')">
                    <span class="pipeline-count"><?php echo $pipelineCounts[$stage]; ?></span><span><?php echo $label; ?></span>
                </button>
            <?php endforeach; ?>
            <div class="pipeline-stage client-stage active-stage">
                <span class="pipeline-count"><?php echo $activeClients; ?></span><span>Active clients</span>
                <a href="customer_overview.php?client_status=active">View clients</a>
            </div>
            <div class="pipeline-stage client-stage inactive-stage">
                <span class="pipeline-count"><?php echo $inactiveClients; ?></span><span>Inactive clients</span>
                <a href="customer_overview.php?client_status=inactive">Manage clients</a>
            </div>
        </div>
        <div class="pipeline-exits">
            <button type="button" onclick="filterPipeline('converted')">Converted lead records: <?php echo $pipelineCounts['converted']; ?></button>
            <button type="button" onclick="filterPipeline('closed')">Closed/lost: <?php echo $pipelineCounts['closed']; ?></button>
        </div>
    </section>

    <!-- Add Lead Form (Hidden by default) -->
    <div id="add-lead-form" class="form-container" style="display: none;">
        <div class="form-header">
            <h3>Add New Lead</h3>
            <button class="close-btn" onclick="hideAddForm()">×</button>
        </div>
        <form id="leadForm" class="form-grid">
            <div class="form-group">
                <label for="customer_name">Customer/Company Name *</label>
                <input type="text" id="customer_name" name="customer_name" required>
            </div>
            <div class="form-group">
                <label for="contact_name">Contact Person *</label>
                <input type="text" id="contact_name" name="contact_name" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email">
            </div>
            <div class="form-group full-width">
                <label for="address">Address</label>
                <textarea id="address" name="address" rows="2"></textarea>
            </div>
            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php foreach ($status_options as $status): ?>
                        <option value="<?php echo $status; ?>"><?php echo ucfirst($status); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group full-width">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3"></textarea>
            </div>
            <div class="form-actions full-width">
                <button type="submit" class="btn btn-primary">Save Lead</button>
                <button type="button" class="btn btn-secondary" onclick="hideAddForm()">Cancel</button>
            </div>
        </form>
    </div>

    <!-- Leads List -->
    <div class="leads-container">
        <?php if (empty($leads)): ?>
            <div class="empty-state">
                <h3>No leads yet</h3>
                <p>Click "Add New Lead" to get started tracking your prospects.</p>
            </div>
        <?php else: ?>
            <?php foreach ($leads as $lead): ?>
                <div class="lead-card" data-lead-id="<?php echo $lead['id']; ?>" data-pipeline-status="<?php echo htmlspecialchars($lead['status']); ?>">
                    <div class="lead-header">
                        <div class="lead-info">
                            <h3 class="lead-name editable" data-field="customer_name" data-id="<?php echo $lead['id']; ?>">
                                <?php echo htmlspecialchars($lead['customer_name']); ?>
                            </h3>
                            <p class="contact-name">
                                Contact: <span class="editable" data-field="contact_name" data-id="<?php echo $lead['id']; ?>">
                                    <?php echo htmlspecialchars($lead['contact_name']); ?>
                                </span>
                            </p>
                        </div>
                        <div class="lead-status">
                            <select class="status-select" data-field="status" data-id="<?php echo $lead['id']; ?>">
                                <?php foreach ($status_options as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $lead['status'] === $status ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="lead-actions">
                                <?php if (empty($lead['customer_id']) && $lead['status'] !== 'closed'): ?>
                                    <button class="btn btn-sm btn-convert" onclick="convertLead(<?php echo (int)$lead['id']; ?>, <?php echo htmlspecialchars(json_encode($lead['customer_name']), ENT_QUOTES, 'UTF-8'); ?>)" title="Create an active customer">Convert to client</button>
                                <?php elseif (!empty($lead['customer_id'])): ?>
                                    <a class="btn btn-sm btn-client-link" href="customer_record.php?customer_id=<?php echo (int)$lead['customer_id']; ?>"><?php echo $lead['linked_customer_active'] ? 'Active client' : 'Inactive client'; ?></a>
                                <?php endif; ?>
                                <button class="btn-icon" onclick="deleteLead(<?php echo $lead['id']; ?>)" title="Delete Lead">🗑️</button>
                            </div>
                        </div>
                    </div>

                    <div class="lead-details">
                        <div class="contact-details">
                            <div class="detail-item">
                                <label>Phone:</label>
                                <span class="editable" data-field="phone" data-id="<?php echo $lead['id']; ?>">
                                    <?php echo htmlspecialchars($lead['phone'] ?: 'Not provided'); ?>
                                </span>
                            </div>
                            <div class="detail-item">
                                <label>Email:</label>
                                <span class="editable" data-field="email" data-id="<?php echo $lead['id']; ?>">
                                    <?php echo htmlspecialchars($lead['email'] ?: 'Not provided'); ?>
                                </span>
                            </div>
                            <div class="detail-item full-width">
                                <label>Address:</label>
                                <span class="editable" data-field="address" data-id="<?php echo $lead['id']; ?>">
                                    <?php echo htmlspecialchars($lead['address'] ?: 'Not provided'); ?>
                                </span>
                            </div>
                            <div class="detail-item full-width">
                                <label>Notes:</label>
                                <span class="editable" data-field="notes" data-id="<?php echo $lead['id']; ?>">
                                    <?php echo htmlspecialchars($lead['notes'] ?: 'No notes'); ?>
                                </span>
                            </div>
                        </div>

                        <div class="lead-stats">
                            <div class="stat">
                                <span class="stat-label">Contacts:</span>
                                <span class="stat-value"><?php echo $lead['contact_count']; ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Last Contact:</span>
                                <span class="stat-value">
                                    <?php echo $lead['last_contact_date'] ? date('M j, Y', strtotime($lead['last_contact_date'])) : 'Never'; ?>
                                </span>
                            </div>
                            <?php if ($lead['overdue_followups'] > 0): ?>
                                <div class="stat alert">
                                    <span class="stat-label">Overdue Follow-ups:</span>
                                    <span class="stat-value"><?php echo $lead['overdue_followups']; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Contact History -->
                    <div class="contact-history">
                        <div class="section-header">
                            <h4>Contact History</h4>
                            <button class="btn btn-sm btn-primary" onclick="showAddContactForm(<?php echo $lead['id']; ?>)">+ Add Contact</button>
                        </div>
                        
                        <!-- Add Contact Form (Hidden) -->
                        <div id="add-contact-form-<?php echo $lead['id']; ?>" class="contact-form" style="display: none;">
                            <form class="contact-form-grid" onsubmit="addContact(event, <?php echo $lead['id']; ?>)">
                                <div class="form-group">
                                    <label>Date</label>
                                    <input type="date" name="contact_date" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Mode</label>
                                    <select name="contact_mode" required>
                                        <?php foreach ($contact_modes as $mode): ?>
                                            <option value="<?php echo $mode; ?>"><?php echo ucfirst(str_replace('_', ' ', $mode)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label>Comment</label>
                                    <textarea name="comment" rows="2" required></textarea>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="follow_up_needed"> Follow-up needed
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label>Follow-up Date</label>
                                    <input type="date" name="follow_up_date">
                                </div>
                                <div class="form-actions full-width">
                                    <button type="submit" class="btn btn-sm btn-primary">Save Contact</button>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="hideAddContactForm(<?php echo $lead['id']; ?>)">Cancel</button>
                                </div>
                            </form>
                        </div>

                        <!-- Contact List -->
                        <div class="contacts-list" id="contacts-list-<?php echo $lead['id']; ?>">
                            <?php
                            $contacts = $db->prepare("SELECT * FROM lead_contacts WHERE lead_id = ? ORDER BY contact_date DESC");
                            $contacts->execute([$lead['id']]);
                            $contact_list = $contacts->fetchAll();
                            ?>
                            
                            <?php if (empty($contact_list)): ?>
                                <p class="no-contacts">No contact history yet. Add the first contact above.</p>
                            <?php else: ?>
                                <?php foreach ($contact_list as $contact): ?>
                                    <div class="contact-item" data-contact-id="<?php echo $contact['id']; ?>">
                                        <div class="contact-header">
                                            <div class="contact-date">
                                                <span class="editable-date" data-field="contact_date" data-id="<?php echo $contact['id']; ?>">
                                                    <?php echo date('M j, Y', strtotime($contact['contact_date'])); ?>
                                                </span>
                                            </div>
                                            <div class="contact-mode">
                                                <select class="mode-select" data-field="contact_mode" data-id="<?php echo $contact['id']; ?>">
                                                    <?php foreach ($contact_modes as $mode): ?>
                                                        <option value="<?php echo $mode; ?>" <?php echo $contact['contact_mode'] === $mode ? 'selected' : ''; ?>>
                                                            <?php echo ucfirst(str_replace('_', ' ', $mode)); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="contact-actions">
                                                <button class="btn-icon" onclick="deleteContact(<?php echo $contact['id']; ?>)" title="Delete Contact">🗑️</button>
                                            </div>
                                        </div>
                                        <div class="contact-comment">
                                            <span class="editable" data-field="comment" data-id="<?php echo $contact['id']; ?>" data-type="contact">
                                                <?php echo htmlspecialchars($contact['comment']); ?>
                                            </span>
                                        </div>
                                        <?php if ($contact['follow_up_needed']): ?>
                                            <div class="follow-up-info <?php echo ($contact['follow_up_date'] && strtotime($contact['follow_up_date']) < time()) ? 'overdue' : ''; ?>">
                                                Follow-up needed: 
                                                <span class="editable-date" data-field="follow_up_date" data-id="<?php echo $contact['id']; ?>" data-type="contact">
                                                    <?php echo $contact['follow_up_date'] ? date('M j, Y', strtotime($contact['follow_up_date'])) : 'No date set'; ?>
                                                </span>
                                                <label class="follow-up-toggle">
                                                    <input type="checkbox" checked onchange="toggleFollowUp(<?php echo $contact['id']; ?>, this.checked)"> Follow-up needed
                                                </label>
                                            </div>
                                        <?php else: ?>
                                            <div class="follow-up-info">
                                                <label class="follow-up-toggle">
                                                    <input type="checkbox" onchange="toggleFollowUp(<?php echo $contact['id']; ?>, this.checked)"> Follow-up needed
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Show/hide add lead form
document.getElementById('add-lead-btn').addEventListener('click', function() {
    document.getElementById('add-lead-form').style.display = 'block';
    document.getElementById('customer_name').focus();
});

function hideAddForm() {
    document.getElementById('add-lead-form').style.display = 'none';
    document.getElementById('leadForm').reset();
}

// Add new lead
document.getElementById('leadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'add_lead');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Reload to show new lead
        } else {
            alert('Error adding lead: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error adding lead');
    });
});

// Inline editing for leads
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('editable') && !e.target.querySelector('input') && !e.target.querySelector('textarea')) {
        makeEditable(e.target);
    }
});

function makeEditable(element) {
    const currentValue = element.textContent.trim();
    const field = element.dataset.field;
    const id = element.dataset.id;
    const type = element.dataset.type || 'lead';
    
    let input;
    if (field === 'notes' || field === 'address' || field === 'comment') {
        input = document.createElement('textarea');
        input.rows = 2;
    } else {
        input = document.createElement('input');
        input.type = 'text';
    }
    
    input.value = currentValue === 'Not provided' || currentValue === 'No notes' ? '' : currentValue;
    input.style.width = '100%';
    input.style.padding = '4px';
    input.style.border = '1px solid #ddd';
    input.style.borderRadius = '4px';
    
    element.innerHTML = '';
    element.appendChild(input);
    input.focus();
    input.select();
    
    function saveEdit() {
        const newValue = input.value.trim();
        const formData = new FormData();
        formData.append('action', type === 'contact' ? 'update_contact' : 'update_lead');
        formData.append('id', id);
        
        if (type === 'contact') {
            // For contacts, we need to get current values and update the specific field
            const contactItem = element.closest('.contact-item');
            formData.append('contact_date', contactItem.querySelector('[data-field="contact_date"]').textContent);
            formData.append('contact_mode', contactItem.querySelector('[data-field="contact_mode"]').value);
            formData.append('comment', field === 'comment' ? newValue : contactItem.querySelector('[data-field="comment"]').textContent);
            formData.append('follow_up_needed', contactItem.querySelector('.follow-up-toggle input').checked ? '1' : '0');
            formData.append('follow_up_date', contactItem.querySelector('[data-field="follow_up_date"]') ? contactItem.querySelector('[data-field="follow_up_date"]').textContent : '');
        } else {
            // For leads, get all current values
            const leadCard = element.closest('.lead-card');
            formData.append('customer_name', field === 'customer_name' ? newValue : leadCard.querySelector('[data-field="customer_name"]').textContent);
            formData.append('contact_name', field === 'contact_name' ? newValue : leadCard.querySelector('[data-field="contact_name"]').textContent);
            formData.append('phone', field === 'phone' ? newValue : leadCard.querySelector('[data-field="phone"]').textContent);
            formData.append('email', field === 'email' ? newValue : leadCard.querySelector('[data-field="email"]').textContent);
            formData.append('address', field === 'address' ? newValue : leadCard.querySelector('[data-field="address"]').textContent);
            formData.append('notes', field === 'notes' ? newValue : leadCard.querySelector('[data-field="notes"]').textContent);
            formData.append('status', leadCard.querySelector('[data-field="status"]').value);
        }
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                element.textContent = newValue || (field === 'notes' ? 'No notes' : 'Not provided');
            } else {
                alert('Error updating: ' + (data.error || 'Unknown error'));
                element.textContent = currentValue;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            element.textContent = currentValue;
        });
    }
    
    function cancelEdit() {
        element.textContent = currentValue;
    }
    
    input.addEventListener('blur', saveEdit);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            cancelEdit();
        }
    });
}

// Status change handler
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('status-select')) {
        const id = e.target.dataset.id;
        const newStatus = e.target.value;
        
        const formData = new FormData();
        formData.append('action', 'update_lead');
        formData.append('id', id);
        
        // Get all current values
        const leadCard = e.target.closest('.lead-card');
        formData.append('customer_name', leadCard.querySelector('[data-field="customer_name"]').textContent);
        formData.append('contact_name', leadCard.querySelector('[data-field="contact_name"]').textContent);
        formData.append('phone', leadCard.querySelector('[data-field="phone"]').textContent);
        formData.append('email', leadCard.querySelector('[data-field="email"]').textContent);
        formData.append('address', leadCard.querySelector('[data-field="address"]').textContent);
        formData.append('notes', leadCard.querySelector('[data-field="notes"]').textContent);
        formData.append('status', newStatus);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert('Error updating status: ' + (data.error || 'Unknown error'));
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating status');
        });
    }
});

// Contact mode change handler
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('mode-select')) {
        const id = e.target.dataset.id;
        const newMode = e.target.value;
        
        const formData = new FormData();
        formData.append('action', 'update_contact');
        formData.append('id', id);
        
        // Get current values
        const contactItem = e.target.closest('.contact-item');
        formData.append('contact_date', contactItem.querySelector('[data-field="contact_date"]').textContent);
        formData.append('contact_mode', newMode);
        formData.append('comment', contactItem.querySelector('[data-field="comment"]').textContent);
        formData.append('follow_up_needed', contactItem.querySelector('.follow-up-toggle input').checked ? '1' : '0');
        formData.append('follow_up_date', contactItem.querySelector('[data-field="follow_up_date"]') ? contactItem.querySelector('[data-field="follow_up_date"]').textContent : '');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                alert('Error updating contact mode: ' + (data.error || 'Unknown error'));
                location.reload();
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating contact mode');
        });
    }
});

// Show/hide add contact form
function showAddContactForm(leadId) {
    document.getElementById('add-contact-form-' + leadId).style.display = 'block';
}

function hideAddContactForm(leadId) {
    document.getElementById('add-contact-form-' + leadId).style.display = 'none';
}

// Add contact
function addContact(e, leadId) {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', 'add_contact');
    formData.append('lead_id', leadId);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload(); // Reload to show new contact
        } else {
            alert('Error adding contact: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error adding contact');
    });
}

// Delete lead
function deleteLead(id) {
    if (confirm('Are you sure you want to delete this lead? This will also delete all associated contact history.')) {
        const formData = new FormData();
        formData.append('action', 'delete_lead');
        formData.append('id', id);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelector('[data-lead-id="' + id + '"]').remove();
            } else {
                alert('Error deleting lead: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting lead');
        });
    }
}

// Delete contact
function deleteContact(id) {
    if (confirm('Are you sure you want to delete this contact entry?')) {
        const formData = new FormData();
        formData.append('action', 'delete_contact');
        formData.append('id', id);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelector('[data-contact-id="' + id + '"]').remove();
            } else {
                alert('Error deleting contact: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error deleting contact');
        });
    }
}

// Toggle follow-up
function toggleFollowUp(contactId, needed) {
    const formData = new FormData();
    formData.append('action', 'update_contact');
    formData.append('id', contactId);
    
    // Get current values
    const contactItem = document.querySelector('[data-contact-id="' + contactId + '"]');
    formData.append('contact_date', contactItem.querySelector('[data-field="contact_date"]').textContent);
    formData.append('contact_mode', contactItem.querySelector('[data-field="contact_mode"]').value);
    formData.append('comment', contactItem.querySelector('[data-field="comment"]').textContent);
    formData.append('follow_up_needed', needed ? '1' : '0');
    formData.append('follow_up_date', contactItem.querySelector('[data-field="follow_up_date"]') ? contactItem.querySelector('[data-field="follow_up_date"]').textContent : '');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI to reflect change
            location.reload();
        } else {
            alert('Error updating follow-up: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error updating follow-up');
    });
}

function filterPipeline(status) {
    document.querySelectorAll('.lead-card').forEach(function(card) {
        card.style.display = status === 'all' || card.dataset.pipelineStatus === status ? '' : 'none';
    });
}

function convertLead(leadId, leadName) {
    if (!confirm('Convert ' + leadName + ' into an active client?')) return;
    const formData = new FormData();
    formData.append('action', 'convert_lead');
    formData.append('id', leadId);
    fetch('', { method: 'POST', body: formData })
        .then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok || !data.success) throw new Error(data.error || 'Unable to convert lead.');
                return data;
            });
        })
        .then(function() { location.reload(); })
        .catch(function(error) { alert('Error converting lead: ' + error.message); });
}
</script>

<style>
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 20px;
}

.page-header h1 {
    margin: 0;
    color: #333;
}

.subtitle {
    color: #666;
    margin: 5px 0 0 0;
}

.pipeline-section { background: #fff; border: 1px solid #dee2e6; border-radius: 12px; padding: 20px; margin-bottom: 28px; }
.pipeline-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.pipeline-heading h2 { margin: 0 0 4px; color: #2c3e50; }
.pipeline-heading p { margin: 0; color: #6c757d; }
.pipeline-track { display: grid; grid-template-columns: repeat(6, minmax(120px, 1fr)); gap: 10px; }
.pipeline-stage { position: relative; min-height: 92px; border: 0; border-radius: 9px; padding: 12px; background: #eef3f8; color: #34495e; cursor: pointer; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; text-align: left; }
.pipeline-stage:not(:last-child)::after { content: '›'; position: absolute; right: -9px; z-index: 2; font-size: 25px; color: #7f8c8d; }
.pipeline-count { font-size: 1.7rem; line-height: 1; font-weight: 800; margin-bottom: 7px; }
.client-stage { cursor: default; }
.client-stage a { font-size: .78rem; margin-top: 5px; color: inherit; }
.active-stage { background: #d4edda; color: #155724; }
.inactive-stage { background: #e2e3e5; color: #383d41; }
.pipeline-exits { display: flex; gap: 10px; margin-top: 12px; }
.pipeline-exits button { background: transparent; border: 0; color: #6c757d; text-decoration: underline; cursor: pointer; }
.btn-convert { background: #28a745; color: #fff; }
.btn-client-link { background: #d4edda; color: #155724; }

@media (max-width: 900px) { .pipeline-track { grid-template-columns: repeat(2, 1fr); } .pipeline-stage::after { display: none; } }

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
}

.btn-primary {
    background-color: #007bff;
    color: white;
}

.btn-primary:hover {
    background-color: #0056b3;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #5a6268;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 14px;
}

.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: background-color 0.3s;
}

.btn-icon:hover {
    background-color: #f8f9fa;
}

/* Form Styles */
.form-container {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.form-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #dee2e6;
}

.form-header h3 {
    margin: 0;
    color: #333;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.close-btn:hover {
    background-color: #f8f9fa;
    color: #333;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    margin-bottom: 5px;
    font-weight: bold;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 10px;
}

/* Leads Container */
.leads-container {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #666;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: #333;
}

/* Lead Cards */
.lead-card {
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 25px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: box-shadow 0.3s ease;
}

.lead-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.lead-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    gap: 20px;
}

.lead-info h3 {
    margin: 0 0 5px 0;
    color: #333;
    font-size: 20px;
}

.contact-name {
    margin: 0;
    color: #666;
}

.lead-status {
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.status-select {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: white;
    font-size: 14px;
}

.lead-actions {
    display: flex;
    gap: 5px;
}

.lead-details {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 30px;
    margin-bottom: 30px;
}

.contact-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.detail-item {
    display: flex;
    flex-direction: column;
}

.detail-item.full-width {
    grid-column: 1 / -1;
}

.detail-item label {
    font-weight: bold;
    color: #333;
    margin-bottom: 3px;
    font-size: 14px;
}

.detail-item span {
    color: #666;
    padding: 4px 0;
    min-height: 20px;
}

.editable {
    cursor: pointer;
    padding: 4px 6px;
    border-radius: 4px;
    transition: background-color 0.3s;
}

.editable:hover {
    background-color: #f8f9fa;
}

.lead-stats {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 200px;
}

.stat {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 4px;
    font-size: 14px;
}

.stat.alert {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

.stat-label {
    font-weight: bold;
}

.stat-value {
    color: #007bff;
    font-weight: bold;
}

.stat.alert .stat-value {
    color: #856404;
}

/* Contact History */
.contact-history {
    border-top: 1px solid #dee2e6;
    padding-top: 20px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.section-header h4 {
    margin: 0;
    color: #333;
}

.contact-form {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 15px;
}

.contact-form-grid {
    display: grid;
    grid-template-columns: auto auto 1fr;
    gap: 15px;
    align-items: end;
}

.contact-form-grid .form-group.full-width {
    grid-column: 1 / -1;
}

.contact-form-grid .form-actions {
    grid-column: 1 / -1;
    margin-top: 10px;
}

.contacts-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.no-contacts {
    text-align: center;
    color: #666;
    font-style: italic;
    padding: 20px;
}

.contact-item {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
}

.contact-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
    gap: 15px;
}

.contact-date {
    font-weight: bold;
    color: #333;
}

.contact-mode select {
    padding: 4px 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.contact-comment {
    margin-bottom: 10px;
    color: #666;
    line-height: 1.4;
}

.follow-up-info {
    font-size: 14px;
    color: #666;
    display: flex;
    align-items: center;
    gap: 15px;
}

.follow-up-info.overdue {
    color: #dc3545;
    font-weight: bold;
}

.follow-up-toggle {
    display: flex;
    align-items: center;
    gap: 5px;
    cursor: pointer;
}

.follow-up-toggle input {
    margin: 0;
}

/* Responsive Design */
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .lead-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .lead-status {
        flex-direction: row;
        justify-content: space-between;
    }
    
    .lead-details {
        grid-template-columns: 1fr;
    }
    
    .contact-details {
        grid-template-columns: 1fr;
    }
    
    .contact-form-grid {
        grid-template-columns: 1fr;
    }
    
    .contact-header {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    
    .follow-up-info {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<?php require_once 'includes/footer.php'; ?>
