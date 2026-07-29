<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Set page title
$page_title = 'Dough Formulas';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_ingredient':
                try {
                    $stmt = $db->prepare("INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?)");
                    $stmt->execute([
                        $_POST['dough_type_id'],
                        $_POST['ingredient_id'],
                        $_POST['percentage']
                    ]);
                    header("Location: formulas.php?success=ingredient_added#formula-" . $_POST['dough_type_id']);
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to add ingredient: " . $e->getMessage();
                }
                break;

            case 'update_percentage':
                try {
                    $stmt = $db->prepare("UPDATE formula_ingredients SET percentage = ? WHERE dough_type_id = ? AND ingredient_id = ?");
                    $stmt->execute([
                        $_POST['percentage'],
                        $_POST['dough_type_id'],
                        $_POST['ingredient_id']
                    ]);
                    header("Location: formulas.php?success=percentage_updated#formula-" . $_POST['dough_type_id']);
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to update percentage: " . $e->getMessage();
                }
                break;

            case 'remove_ingredient':
                try {
                    $stmt = $db->prepare("DELETE FROM formula_ingredients WHERE dough_type_id = ? AND ingredient_id = ?");
                    $stmt->execute([
                        $_POST['dough_type_id'],
                        $_POST['ingredient_id']
                    ]);
                    header("Location: formulas.php?success=ingredient_removed#formula-" . $_POST['dough_type_id']);
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to remove ingredient: " . $e->getMessage();
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
        case 'ingredient_added':
            $success_message = 'Ingredient added successfully!';
            break;
        case 'percentage_updated':
            $success_message = 'Percentage updated successfully!';
            break;
        case 'ingredient_removed':
            $success_message = 'Ingredient removed successfully!';
            break;
    }
}

// Get all available ingredients once
$all_ingredients = $db->query("SELECT * FROM ingredients ORDER BY name")->fetchAll();
?>

<style>
.formula-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
    padding: 1rem;
}

.formula-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}

.formula-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.formula-header {
    background-color: #f8f9fa;
    padding: 1.5rem;
    border-bottom: 1px solid #e9ecef;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.formula-header h2 {
    margin: 0;
    color: #2c3e50;
    font-size: 1.4rem;
}

.total-percentage {
    background: #e3f2fd;
    color: #1976d2;
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-weight: 500;
}

.formula-content {
    padding: 1.5rem;
}

.ingredients-list {
    list-style: none;
    padding: 0;
    margin: 0 0 1.5rem 0;
}

.ingredient-item {
    display: flex;
    align-items: center;
    padding: 1rem;
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s;
}

.ingredient-item:hover {
    background-color: #f8f9fa;
}

.ingredient-item:last-child {
    border-bottom: none;
}

.ingredient-name {
    flex: 1;
    font-weight: 500;
    color: #2c3e50;
}

.ingredient-percentage {
    width: 100px;
    text-align: right;
    margin-right: 1rem;
    font-family: monospace;
    font-size: 1.1em;
    color: #1976d2;
}

.ingredient-actions {
    display: flex;
    gap: 0.5rem;
}

.quick-add-form {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    margin-top: 1rem;
}

.quick-add-form .form-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
}

.quick-add-form .form-group {
    flex: 1;
    margin: 0;
}

.quick-add-form select,
.quick-add-form input {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    font-size: 0.95rem;
}

.quick-add-form select:focus,
.quick-add-form input:focus {
    outline: none;
    border-color: #1976d2;
    box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.1);
}

.quick-add-form button {
    width: 100%;
    padding: 0.75rem;
    background: #1976d2;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.quick-add-form button:hover {
    background: #1565c0;
}

.btn-icon {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.5rem;
    border-radius: 50%;
    transition: all 0.2s;
    color: #666;
}

.btn-icon:hover {
    background-color: rgba(0, 0, 0, 0.05);
    transform: scale(1.1);
}

.btn-edit:hover {
    color: #1976d2;
}

.btn-delete:hover {
    color: #d32f2f;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: #666;
    font-style: italic;
}

.percentage-input {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.percentage-input input {
    width: 80px;
    text-align: right;
}

.percentage-input span {
    color: #666;
}

/* Enhanced Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    animation: fadeIn 0.2s ease-out;
}

.modal-content {
    background: white;
    border-radius: 12px;
    max-width: 500px;
    width: 90%;
    margin: 2rem auto;
    padding: 2rem;
    position: relative;
    animation: slideIn 0.2s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.success-message {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    text-align: center;
    animation: slideIn 0.2s ease-out;
}

.error-message {
    background: #ffebee;
    color: #c62828;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    text-align: center;
    animation: slideIn 0.2s ease-out;
}
</style>

<div class="container">
    <h1>Dough Formulas</h1>
    <p><a href="ingredients.php">Manage ingredient inventory</a></p>

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

    <div class="formula-grid">
        <?php
        try {
            $dough_types = $db->query("
                SELECT dt.*, COUNT(fi.ingredient_id) as ingredient_count
                FROM dough_types dt
                LEFT JOIN formula_ingredients fi ON dt.id = fi.dough_type_id
                GROUP BY dt.id
                ORDER BY dt.name
            ")->fetchAll();

            foreach ($dough_types as $dough_type):
                // Get ingredients for this dough type
                $ingredients = $db->prepare("
                    SELECT fi.*, i.name as ingredient_name, i.unit
                    FROM formula_ingredients fi
                    JOIN ingredients i ON fi.ingredient_id = i.id
                    WHERE fi.dough_type_id = ?
                    ORDER BY fi.percentage DESC
                ");
                $ingredients->execute([$dough_type['id']]);
                $ingredients = $ingredients->fetchAll();
                
                // Calculate total percentage
                $total_percentage = array_sum(array_column($ingredients, 'percentage'));
        ?>
            <div class="formula-card" id="formula-<?php echo $dough_type['id']; ?>">
                <div class="formula-header">
                    <h2><?php echo htmlspecialchars($dough_type['name']); ?></h2>
                    <div class="total-percentage">Total: <?php echo number_format($total_percentage, 1); ?>%</div>
                </div>
                
                <div class="formula-content">
                    <?php if (empty($ingredients)): ?>
                        <p class="empty-state">No ingredients added yet</p>
                    <?php else: ?>
                        <ul class="ingredients-list">
                            <?php foreach ($ingredients as $ingredient): ?>
                                <li class="ingredient-item">
                                    <span class="ingredient-name">
                                        <?php echo htmlspecialchars($ingredient['ingredient_name'] ?? ''); ?>
                                        <small style="color: #666;">(<?php echo htmlspecialchars($ingredient['unit'] ?? ''); ?>)</small>
                                    </span>
                                    <span class="ingredient-percentage"><?php echo number_format($ingredient['percentage'], 1); ?>%</span>
                                    <div class="ingredient-actions">
                                        <button class="btn-icon btn-edit" onclick="editPercentage(<?php echo $dough_type['id']; ?>, <?php echo $ingredient['ingredient_id']; ?>, '<?php echo htmlspecialchars($ingredient['ingredient_name'] ?? ''); ?>', <?php echo $ingredient['percentage']; ?>)" title="Edit">✏️</button>
                                        <button class="btn-icon btn-delete" onclick="removeIngredient(<?php echo $dough_type['id']; ?>, <?php echo $ingredient['ingredient_id']; ?>, '<?php echo htmlspecialchars($ingredient['ingredient_name'] ?? ''); ?>')" title="Remove">🗑️</button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <!-- Quick Add Form -->
                    <form method="POST" class="quick-add-form">
                        <input type="hidden" name="action" value="add_ingredient">
                        <input type="hidden" name="dough_type_id" value="<?php echo $dough_type['id']; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <select name="ingredient_id" required>
                                    <option value="">Select ingredient...</option>
                                    <?php foreach ($all_ingredients as $ingredient): ?>
                                        <option value="<?php echo $ingredient['id']; ?>">
                                            <?php echo htmlspecialchars($ingredient['name']); ?> 
                                            (<?php echo htmlspecialchars($ingredient['unit']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group percentage-input">
                                <input type="number" name="percentage" step="0.1" min="0" max="999.9" required placeholder="0.0">
                                <span>%</span>
                            </div>
                        </div>
                        <button type="submit">Add Ingredient</button>
                    </form>
                </div>
            </div>
        <?php 
            endforeach;
        } catch (Exception $e) {
            echo '<div class="error-message">Error loading formulas: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        ?>
    </div>
</div>

<!-- Edit Percentage Modal -->
<div id="editPercentageModal" class="modal">
    <div class="modal-content">
        <h2>Edit Percentage for <span id="ingredientName"></span></h2>
        <form id="editPercentageForm" method="POST">
            <input type="hidden" name="action" value="update_percentage">
            <input type="hidden" name="dough_type_id" value="">
            <input type="hidden" name="ingredient_id" value="">

            <div class="form-group">
                <label for="percentage">Percentage</label>
                <div class="percentage-input">
                    <input type="number" name="percentage" step="0.1" min="0" max="999.9" required>
                    <span>%</span>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn-secondary" onclick="hideModal('editPercentageModal')">Cancel</button>
                <button type="submit" class="btn-primary">Update</button>
            </div>
        </form>
    </div>
</div>

<script>
function editPercentage(doughTypeId, ingredientId, ingredientName, currentPercentage) {
    const modal = document.getElementById('editPercentageModal');
    document.getElementById('ingredientName').textContent = ingredientName;
    modal.querySelector('[name="dough_type_id"]').value = doughTypeId;
    modal.querySelector('[name="ingredient_id"]').value = ingredientId;
    modal.querySelector('[name="percentage"]').value = currentPercentage;
    modal.style.display = 'block';
}

function removeIngredient(doughTypeId, ingredientId, ingredientName) {
    if (confirm(`Are you sure you want to remove ${ingredientName} from this formula?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="remove_ingredient">
            <input type="hidden" name="dough_type_id" value="${doughTypeId}">
            <input type="hidden" name="ingredient_id" value="${ingredientId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function hideModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Highlight the formula card that was just updated
if (window.location.hash) {
    const element = document.querySelector(window.location.hash);
    if (element) {
        element.scrollIntoView({ behavior: 'smooth' });
        element.style.animation = 'highlight 2s ease-out';
    }
}
</script>

<style>
@keyframes highlight {
    0% { background-color: #e3f2fd; }
    100% { background-color: white; }
}
</style>

<?php require_once 'includes/footer.php'; ?>