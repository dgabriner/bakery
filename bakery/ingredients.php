<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Set page title
$page_title = 'Ingredients';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_ingredient':
                try {
                    $stmt = $db->prepare("INSERT INTO ingredients (name, unit) VALUES (?, ?)");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['unit']
                    ]);
                    header("Location: ingredients.php?success=added");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to add ingredient: " . $e->getMessage();
                }
                break;

            case 'edit_ingredient':
                try {
                    $stmt = $db->prepare("UPDATE ingredients SET name = ?, unit = ? WHERE id = ?");
                    $stmt->execute([
                        $_POST['name'],
                        $_POST['unit'],
                        $_POST['id']
                    ]);
                    header("Location: ingredients.php?success=updated");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to update ingredient: " . $e->getMessage();
                }
                break;

            case 'delete_ingredient':
                try {
                    // First check if the ingredient is used in any formulas
                    $check = $db->prepare("SELECT COUNT(*) FROM formula_ingredients WHERE ingredient_id = ?");
                    $check->execute([$_POST['id']]);
                    if ($check->fetchColumn() > 0) {
                        throw new Exception("Cannot delete ingredient as it is used in one or more formulas.");
                    }
                    
                    $stmt = $db->prepare("DELETE FROM ingredients WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    header("Location: ingredients.php?success=deleted");
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to delete ingredient: " . $e->getMessage();
                }
                break;
        }
    }
}

// Include header and navigation
require_once 'includes/header.php';
require_once 'includes/nav.php';

// Get success message if any
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
    }
}
?>

<style>
.ingredients-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 2rem;
}

.ingredients-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-top: 2rem;
}

.ingredient-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transition: transform 0.2s, box-shadow 0.2s;
    overflow: hidden;
}

.ingredient-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

.ingredient-content {
    padding: 1.5rem;
}

.ingredient-name {
    font-size: 1.25rem;
    color: #2c3e50;
    margin: 0 0 0.5rem 0;
}

.ingredient-unit {
    color: #6c757d;
    font-size: 0.95rem;
}

.ingredient-actions {
    margin-top: 1rem;
    display: flex;
    gap: 0.5rem;
}

.btn-action {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background-color 0.2s;
}

.btn-edit {
    background-color: #e3f2fd;
    color: #1e88e5;
}

.btn-edit:hover {
    background-color: #bbdefb;
}

.btn-delete {
    background-color: #ffebee;
    color: #e53935;
}

.btn-delete:hover {
    background-color: #ffcdd2;
}

.add-ingredient-card {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f8f9fa;
    border: 2px dashed #dee2e6;
    cursor: pointer;
    transition: all 0.2s;
    min-height: 160px;
}

.add-ingredient-card:hover {
    border-color: #1e88e5;
    background: #f1f8fe;
}

.add-ingredient-content {
    text-align: center;
    color: #6c757d;
}

.add-ingredient-icon {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.success-message {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    text-align: center;
}

.error-message {
    background: #ffebee;
    color: #c62828;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    text-align: center;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
}

.modal-content {
    background: white;
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    margin: 2rem auto;
    padding: 2rem;
    position: relative;
}

.modal-header {
    margin-bottom: 1.5rem;
}

.modal-header h2 {
    margin: 0;
    color: #2c3e50;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    color: #2c3e50;
    font-weight: 500;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    font-size: 1rem;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #1e88e5;
    box-shadow: 0 0 0 3px rgba(30, 136, 229, 0.1);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    margin-top: 2rem;
}

.btn-secondary {
    background-color: #e9ecef;
    color: #495057;
}

.btn-secondary:hover {
    background-color: #dee2e6;
}

.btn-primary {
    background-color: #1e88e5;
    color: white;
}

.btn-primary:hover {
    background-color: #1976d2;
}
</style>

<div class="ingredients-container">
    <h1>Ingredients</h1>

    <?php if (isset($error)): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <div class="ingredients-grid">
        <!-- Add New Ingredient Card -->
        <div class="add-ingredient-card" onclick="showAddModal()">
            <div class="add-ingredient-content">
                <div class="add-ingredient-icon">➕</div>
                <div>Add New Ingredient</div>
            </div>
        </div>

        <?php
        try {
            $ingredients = $db->query("SELECT * FROM ingredients ORDER BY name")->fetchAll();
            foreach ($ingredients as $ingredient):
        ?>
            <div class="ingredient-card">
                <div class="ingredient-content">
                    <h3 class="ingredient-name"><?php echo htmlspecialchars($ingredient['name'] ?? ''); ?></h3>
                    <div class="ingredient-unit">Unit: <?php echo htmlspecialchars($ingredient['unit'] ?? 'Not specified'); ?></div>
                    <div class="ingredient-actions">
                        <button class="btn-action btn-edit" onclick="showEditModal(<?php 
                            echo htmlspecialchars(json_encode([
                                'id' => $ingredient['id'],
                                'name' => $ingredient['name'] ?? '',
                                'unit' => $ingredient['unit'] ?? ''
                            ])); 
                        ?>)">Edit</button>
                        <button class="btn-action btn-delete" onclick="confirmDelete(<?php 
                            echo htmlspecialchars(json_encode([
                                'id' => $ingredient['id'],
                                'name' => $ingredient['name']
                            ])); 
                        ?>)">Delete</button>
                    </div>
                </div>
            </div>
        <?php 
            endforeach;
        } catch (Exception $e) {
            echo '<div class="error-message">Error loading ingredients: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>
    </div>
</div>

<!-- Add Ingredient Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Ingredient</h2>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_ingredient">
            
            <div class="form-group">
                <label for="name">Ingredient Name</label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-group">
                <label for="unit">Unit of Measurement</label>
                <select id="unit" name="unit" required>
                    <option value="g">Grams (g)</option>
                    <option value="kg">Kilograms (kg)</option>
                    <option value="ml">Milliliters (ml)</option>
                    <option value="L">Liters (L)</option>
                    <option value="oz">Ounces (oz)</option>
                    <option value="lb">Pounds (lb)</option>
                    <option value="tsp">Teaspoons (tsp)</option>
                    <option value="tbsp">Tablespoons (tbsp)</option>
                    <option value="cup">Cups</option>
                    <option value="pc">Pieces (pc)</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-action btn-secondary" onclick="hideModal('addModal')">Cancel</button>
                <button type="submit" class="btn-action btn-primary">Add Ingredient</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Ingredient Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit Ingredient</h2>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_ingredient">
            <input type="hidden" name="id" id="edit_id">
            
            <div class="form-group">
                <label for="edit_name">Ingredient Name</label>
                <input type="text" id="edit_name" name="name" required>
            </div>

            <div class="form-group">
                <label for="edit_unit">Unit of Measurement</label>
                <select id="edit_unit" name="unit" required>
                    <option value="g">Grams (g)</option>
                    <option value="kg">Kilograms (kg)</option>
                    <option value="ml">Milliliters (ml)</option>
                    <option value="L">Liters (L)</option>
                    <option value="oz">Ounces (oz)</option>
                    <option value="lb">Pounds (lb)</option>
                    <option value="tsp">Teaspoons (tsp)</option>
                    <option value="tbsp">Tablespoons (tbsp)</option>
                    <option value="cup">Cups</option>
                    <option value="pc">Pieces (pc)</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-action btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn-action btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('addModal').style.display = 'block';
}

function showEditModal(ingredient) {
    const modal = document.getElementById('editModal');
    document.getElementById('edit_id').value = ingredient.id;
    document.getElementById('edit_name').value = ingredient.name;
    document.getElementById('edit_unit').value = ingredient.unit;
    modal.style.display = 'block';
}

function hideModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

function confirmDelete(ingredient) {
    if (confirm(`Are you sure you want to delete ${ingredient.name}? This cannot be undone if the ingredient is not used in any formulas.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_ingredient">
            <input type="hidden" name="id" value="${ingredient.id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php require_once 'includes/footer.php'; ?> 