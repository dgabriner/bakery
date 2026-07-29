<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = 'Zones Management';

// Handle AJAX requests for zone operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create':
                $name = trim($_POST['name']);
                $description = trim($_POST['description'] ?? '');
                $color = $_POST['color'] ?? '#007bff';
                
                if (empty($name)) {
                    throw new Exception('Zone name is required');
                }
                
                // Create zones table if it doesn't exist
                $db->exec("CREATE TABLE IF NOT EXISTS zones (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(100) NOT NULL UNIQUE,
                    description TEXT,
                    color VARCHAR(7) DEFAULT '#007bff',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )");
                
                $stmt = $db->prepare("INSERT INTO zones (name, description, color) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $color]);
                
                echo json_encode(['success' => true, 'message' => 'Zone created successfully']);
                exit;
                
            case 'update':
                $id = $_POST['id'];
                $name = trim($_POST['name']);
                $description = trim($_POST['description'] ?? '');
                $color = $_POST['color'] ?? '#007bff';
                
                if (empty($name)) {
                    throw new Exception('Zone name is required');
                }
                
                $stmt = $db->prepare("UPDATE zones SET name = ?, description = ?, color = ? WHERE id = ?");
                $stmt->execute([$name, $description, $color, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Zone updated successfully']);
                exit;
                
            case 'delete':
                $id = $_POST['id'];
                
                // Check if zone is being used by customers
                $stmt = $db->prepare(
                    "SELECT COUNT(*) as count FROM customers
                     WHERE zone_id = ? OR zone = (SELECT name FROM zones WHERE id = ? LIMIT 1)"
                );
                $stmt->execute([$id, $id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    throw new Exception('Cannot delete zone: it is currently assigned to ' . $result['count'] . ' customer(s)');
                }
                
                $stmt = $db->prepare("DELETE FROM zones WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Zone deleted successfully']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Create zones table if it doesn't exist and migrate existing data
try {
    $db->exec("CREATE TABLE IF NOT EXISTS zones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description TEXT,
        color VARCHAR(7) DEFAULT '#007bff',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Check if we need to migrate hardcoded zones
    $stmt = $db->query("SELECT COUNT(*) as count FROM zones");
    $count = $stmt->fetch()['count'];
    
    if ($count == 0) {
        // Migrate hardcoded zones with colors
        $hardcodedZones = [
            ['Centro', 'Downtown core area', '#007bff'],
            ['Mission', 'Mission district area', '#dc3545'],
            ['Ruta Sour Flour', 'Sour flour distribution route', '#28a745'],
            ['Daly City/San Mateo', 'South bay area coverage', '#fd7e14'],
            ['North Bay', 'North bay area coverage', '#6f42c1'],
            ['East Bay', 'East bay area coverage', '#20c997']
        ];
        
        $stmt = $db->prepare("INSERT INTO zones (name, description, color) VALUES (?, ?, ?)");
        foreach ($hardcodedZones as $zone) {
            $stmt->execute($zone);
        }
    }
} catch (Exception $e) {
    $error = 'Error setting up zones table: ' . htmlspecialchars($e->getMessage());
}

// Get all zones
$zones = [];
try {
    $stmt = $db->query("SELECT * FROM zones ORDER BY name");
    $zones = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error loading zones: ' . htmlspecialchars($e->getMessage());
}

// Get zone usage statistics
$zoneStats = [];
try {
    $stmt = $db->query("
        SELECT 
            z.id,
            z.name,
            COUNT(c.id) as customer_count
        FROM zones z
        LEFT JOIN customers c ON c.zone = z.name
        GROUP BY z.id, z.name
        ORDER BY z.name
    ");
    $zoneStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    // If customers table doesn't exist yet, just continue
    $zoneStats = [];
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<style>
.zones-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: center;
}

.page-header h1 {
    margin: 0 0 10px 0;
    color: #2c3e50;
    font-size: 1.8rem;
}

.page-header p {
    margin: 0;
    color: #6c757d;
    font-size: 1rem;
}

.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}

.zones-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.zone-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border: 1px solid #dee2e6;
    overflow: hidden;
    transition: transform 0.2s ease;
}

.zone-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.zone-header {
    padding: 15px 20px;
    color: white;
    font-weight: 600;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.zone-actions {
    display: flex;
    gap: 8px;
}

.zone-content {
    padding: 20px;
}

.zone-name {
    font-size: 1.2rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 8px;
}

.zone-description {
    color: #6c757d;
    margin-bottom: 15px;
    line-height: 1.4;
}

.zone-stats {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 15px;
    border-top: 1px solid #f1f3f4;
}

.customer-count {
    background: #e3f2fd;
    color: #1976d2;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.85rem;
    font-weight: 600;
}

.color-indicator {
    width: 20px;
    height: 20px;
    border-radius: 4px;
    border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
    transform: translateY(-1px);
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #545b62;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
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
    max-width: 500px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    position: relative;
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

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #495057;
}

.form-control {
    width: 100%;
    padding: 12px;
    border: 2px solid #ddd;
    border-radius: 6px;
    font-size: 1rem;
    transition: border-color 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
}

.color-picker-container {
    display: flex;
    align-items: center;
    gap: 10px;
}

.color-picker {
    width: 50px;
    height: 40px;
    padding: 0;
    border: none;
    border-radius: 6px;
    cursor: pointer;
}

.modal-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

/* Message Styles */
.message-bar {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 20px;
    border-radius: 8px;
    font-weight: 600;
    z-index: 1001;
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    transform: translateX(100%);
    animation: slideIn 0.3s ease forwards;
}

.message-bar.success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.message-bar.error {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

@keyframes slideIn {
    to {
        transform: translateX(0);
    }
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.empty-state h3 {
    margin-bottom: 10px;
    color: #495057;
}

/* Responsive */
@media (max-width: 768px) {
    .zones-container {
        padding: 10px;
    }
    
    .zones-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .action-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .modal-content {
        margin: 10% auto;
        padding: 20px;
    }
}
</style>

<div class="zones-container">
    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <h1>🗺️ Delivery Zones Management</h1>
        <p>Create and manage delivery zones for customer assignment</p>
    </div>
    
    <!-- Action Bar -->
    <div class="action-bar">
        <div>
            <h2>Zones (<?php echo count($zones); ?>)</h2>
        </div>
        <button type="button" class="btn btn-primary" onclick="openCreateModal()">
            ➕ Add New Zone
        </button>
    </div>
    
    <!-- Zones Grid -->
    <?php if (empty($zones)): ?>
        <div class="empty-state">
            <h3>No zones found</h3>
            <p>Get started by creating your first delivery zone.</p>
            <button type="button" class="btn btn-primary" onclick="openCreateModal()">
                ➕ Create First Zone
            </button>
        </div>
    <?php else: ?>
        <div class="zones-grid">
            <?php foreach ($zones as $zone): ?>
                <div class="zone-card" data-zone-id="<?php echo $zone['id']; ?>">
                    <div class="zone-header" style="background-color: <?php echo htmlspecialchars($zone['color']); ?>">
                        <span><?php echo htmlspecialchars($zone['name']); ?></span>
                        <div class="zone-actions">
                            <button type="button" class="btn btn-sm btn-secondary" onclick="openEditModal(<?php echo $zone['id']; ?>, '<?php echo htmlspecialchars($zone['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($zone['description'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($zone['color']); ?>')">
                                ✏️ Edit
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="deleteZone(<?php echo $zone['id']; ?>, '<?php echo htmlspecialchars($zone['name'], ENT_QUOTES); ?>')">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                    <div class="zone-content">
                        <div class="zone-description">
                            <?php echo htmlspecialchars($zone['description'] ?: 'No description provided'); ?>
                        </div>
                        <div class="zone-stats">
                            <div class="customer-count">
                                <?php 
                                $count = isset($zoneStats[$zone['id']]) ? $zoneStats[$zone['id']] : 0;
                                echo $count . ' customer' . ($count != 1 ? 's' : '');
                                ?>
                            </div>
                            <div class="color-indicator" style="background-color: <?php echo htmlspecialchars($zone['color']); ?>"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Create/Edit Zone Modal -->
<div id="zoneModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h2 id="modalTitle">Create New Zone</h2>
        
        <form id="zoneForm">
            <input type="hidden" id="zoneId" name="id">
            
            <div class="form-group">
                <label for="zoneName">Zone Name *</label>
                <input type="text" id="zoneName" name="name" class="form-control" required placeholder="e.g. Downtown, North Bay, etc.">
            </div>
            
            <div class="form-group">
                <label for="zoneDescription">Description</label>
                <textarea id="zoneDescription" name="description" class="form-control" rows="3" placeholder="Optional description of the zone coverage area"></textarea>
            </div>
            
            <div class="form-group">
                <label for="zoneColor">Zone Color</label>
                <div class="color-picker-container">
                    <input type="color" id="zoneColor" name="color" class="color-picker" value="#007bff">
                    <span>Used for visual identification in schedules</span>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Create Zone</button>
            </div>
        </form>
    </div>
</div>

<!-- Message Bar -->
<div id="messageBar" class="message-bar" style="display: none;">
    <span id="messageText"></span>
</div>

<script>
let isEditMode = false;

function openCreateModal() {
    isEditMode = false;
    document.getElementById('modalTitle').textContent = 'Create New Zone';
    document.getElementById('submitBtn').textContent = 'Create Zone';
    document.getElementById('zoneForm').reset();
    document.getElementById('zoneId').value = '';
    document.getElementById('zoneColor').value = '#007bff';
    document.getElementById('zoneModal').style.display = 'block';
}

function openEditModal(id, name, description, color) {
    isEditMode = true;
    document.getElementById('modalTitle').textContent = 'Edit Zone';
    document.getElementById('submitBtn').textContent = 'Update Zone';
    document.getElementById('zoneId').value = id;
    document.getElementById('zoneName').value = name;
    document.getElementById('zoneDescription').value = description;
    document.getElementById('zoneColor').value = color;
    document.getElementById('zoneModal').style.display = 'block';
}

function closeModal() {
    document.getElementById('zoneModal').style.display = 'none';
}

document.getElementById('zoneForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const action = isEditMode ? 'update' : 'create';
    formData.append('action', action);
    
    try {
        const response = await fetch('zones.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showMessage(result.message, 'success');
            closeModal();
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
});

async function deleteZone(id, name) {
    if (!confirm(`Are you sure you want to delete the zone "${name}"?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('zones.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showMessage(result.message, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showMessage('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        showMessage('Error: ' + error.message, 'error');
    }
}

function showMessage(message, type) {
    const messageBar = document.getElementById('messageBar');
    const messageText = document.getElementById('messageText');
    
    messageText.textContent = message;
    messageBar.className = 'message-bar ' + type;
    messageBar.style.display = 'block';
    
    setTimeout(() => {
        messageBar.style.display = 'none';
    }, 3000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('zoneModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>

<?php require_once 'includes/footer.php'; ?> 