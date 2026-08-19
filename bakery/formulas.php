<?php
// Security check
define('ACCESS_ALLOWED', true);

// Load includes
require_once 'includes/config.php';
require_once 'includes/database.php';

// Set page title
$page_title = bakery_t('page.formulas');

function formulas_json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function formulas_redirect(int $dough_type_id, string $success): void
{
    header('Location: formulas.php?dough_type=' . $dough_type_id . '&success=' . rawurlencode($success) . '#formula-' . $dough_type_id);
    exit;
}

function formulas_load_active_ingredients(PDO $db): array
{
    return $db->query('SELECT * FROM ingredients WHERE COALESCE(is_active, 1) = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

function formulas_used_ingredient_ids(PDO $db, int $dough_type_id): array
{
    $stmt = $db->prepare('SELECT ingredient_id FROM formula_ingredients WHERE dough_type_id = ?');
    $stmt->execute([$dough_type_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bakery_require_csrf();
    $wants_json = is_ajax_request() || bakery_wants_json();

    if (isset($_POST['action'])) {
        $dough_type_id = max(0, (int)($_POST['dough_type_id'] ?? 0));
        $redirect = 'formulas.php?dough_type=' . $dough_type_id . '#formula-' . $dough_type_id;

        switch ($_POST['action']) {
            case 'add_ingredient':
                try {
                    $stmt = $db->prepare('INSERT INTO formula_ingredients (dough_type_id, ingredient_id, percentage) VALUES (?, ?, ?)');
                    $stmt->execute([
                        $dough_type_id,
                        (int)$_POST['ingredient_id'],
                        (float)$_POST['percentage'],
                    ]);
                    if ($wants_json) {
                        formulas_json_response(['success' => true, 'redirect' => $redirect . '&success=ingredient_added']);
                    }
                    formulas_redirect($dough_type_id, 'ingredient_added');
                } catch (Exception $e) {
                    if ($wants_json) {
                        formulas_json_response(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                    $error = 'Failed to add ingredient: ' . $e->getMessage();
                }
                break;

            case 'update_percentage':
                try {
                    $stmt = $db->prepare('UPDATE formula_ingredients SET percentage = ? WHERE dough_type_id = ? AND ingredient_id = ?');
                    $stmt->execute([
                        (float)$_POST['percentage'],
                        $dough_type_id,
                        (int)$_POST['ingredient_id'],
                    ]);
                    if ($wants_json) {
                        formulas_json_response(['success' => true]);
                    }
                    formulas_redirect($dough_type_id, 'percentage_updated');
                } catch (Exception $e) {
                    if ($wants_json) {
                        formulas_json_response(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                    $error = 'Failed to update percentage: ' . $e->getMessage();
                }
                break;

            case 'remove_ingredient':
                try {
                    $stmt = $db->prepare('DELETE FROM formula_ingredients WHERE dough_type_id = ? AND ingredient_id = ?');
                    $stmt->execute([
                        $dough_type_id,
                        (int)$_POST['ingredient_id'],
                    ]);
                    if ($wants_json) {
                        formulas_json_response(['success' => true, 'redirect' => $redirect . '&success=ingredient_removed']);
                    }
                    formulas_redirect($dough_type_id, 'ingredient_removed');
                } catch (Exception $e) {
                    if ($wants_json) {
                        formulas_json_response(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                    $error = 'Failed to remove ingredient: ' . $e->getMessage();
                }
                break;

            case 'apply_starter':
                try {
                    $template_key = (string)($_POST['template'] ?? '');
                    $all_ingredients = formulas_load_active_ingredients($db);
                    $used_ids = formulas_used_ingredient_ids($db, $dough_type_id);
                    $resolved = bakery_formula_resolve_starter($all_ingredients, $template_key, $used_ids);
                    if (empty($resolved)) {
                        throw new Exception('No matching ingredients found for this starter. Add flour, water, salt, and starter to your ingredient catalogue first.');
                    }
                    $added = bakery_formula_apply_ingredients($db, $dough_type_id, $resolved);
                    if ($added === 0) {
                        throw new Exception('All starter ingredients are already in this formula.');
                    }
                    if ($wants_json) {
                        formulas_json_response([
                            'success' => true,
                            'redirect' => $redirect . '&success=starter_applied',
                            'added' => $added,
                        ]);
                    }
                    formulas_redirect($dough_type_id, 'starter_applied');
                } catch (Exception $e) {
                    if ($wants_json) {
                        formulas_json_response(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                    $error = $e->getMessage();
                }
                break;

            case 'copy_formula':
                try {
                    $source_id = max(0, (int)($_POST['source_dough_type_id'] ?? 0));
                    if ($source_id <= 0 || $source_id === $dough_type_id) {
                        throw new Exception('Choose a different dough type to copy from.');
                    }
                    $stmt = $db->prepare(
                        'INSERT IGNORE INTO formula_ingredients (dough_type_id, ingredient_id, percentage)
                         SELECT ?, ingredient_id, percentage FROM formula_ingredients WHERE dough_type_id = ?'
                    );
                    $stmt->execute([$dough_type_id, $source_id]);
                    if ($stmt->rowCount() === 0) {
                        throw new Exception('Nothing to copy — the source formula is empty or all ingredients are already present.');
                    }
                    if ($wants_json) {
                        formulas_json_response([
                            'success' => true,
                            'redirect' => $redirect . '&success=formula_copied',
                            'added' => $stmt->rowCount(),
                        ]);
                    }
                    formulas_redirect($dough_type_id, 'formula_copied');
                } catch (Exception $e) {
                    if ($wants_json) {
                        formulas_json_response(['success' => false, 'error' => $e->getMessage()], 400);
                    }
                    $error = $e->getMessage();
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
        case 'starter_applied':
            $success_message = 'Starter formula applied successfully!';
            break;
        case 'formula_copied':
            $success_message = 'Formula copied successfully!';
            break;
    }
}

$starter_templates = bakery_formula_starter_templates();

$selected_dough_type_id = isset($_GET['dough_type']) ? max(0, (int)$_GET['dough_type']) : 0;
$all_ingredients = [];
$dough_types = [];
$formulas_by_dough = [];

try {
    $all_ingredients = formulas_load_active_ingredients($db);
    $dough_types = $db->query("
        SELECT dt.*, COUNT(fi.ingredient_id) AS ingredient_count
        FROM dough_types dt
        LEFT JOIN formula_ingredients fi ON dt.id = fi.dough_type_id
        GROUP BY dt.id
        ORDER BY dt.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $formula_rows = $db->query("
        SELECT fi.*, i.name AS ingredient_name, i.unit
        FROM formula_ingredients fi
        JOIN ingredients i ON fi.ingredient_id = i.id
        ORDER BY fi.dough_type_id, fi.percentage DESC, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($formula_rows as $formula_row) {
        $formulas_by_dough[(int)$formula_row['dough_type_id']][] = $formula_row;
    }
} catch (Exception $e) {
    $error = "Failed to load formulas: " . $e->getMessage();
}
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

<style>
.formulas-page {
    --formula-ink: #18312f;
    --formula-muted: #687874;
    --formula-line: #dbe5e1;
    --formula-soft: #f4f7f6;
    --formula-brand: #176b5d;
    --formula-brand-dark: #115348;
    max-width: 1280px;
    margin: 0 auto;
    padding: 32px 24px 60px;
    color: var(--formula-ink);
    font-family: "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
}

.formulas-page *, .formulas-page *::before, .formulas-page *::after { box-sizing: border-box; }
.formulas-page [hidden] { display: none !important; }
.visually-hidden {
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

.formulas-page-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 24px;
}

.formulas-page-header h1 {
    margin: 2px 0 6px;
    color: var(--formula-ink);
    font-size: clamp(2rem, 4vw, 2.65rem);
    line-height: 1.05;
    letter-spacing: -.035em;
}

.formulas-page-header p:last-child { margin: 0; color: var(--formula-muted); }
.formula-eyebrow { margin: 0; color: var(--formula-brand); font-size: .76rem; font-weight: 800; letter-spacing: .11em; text-transform: uppercase; }
.formula-secondary-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 9px 15px;
    border: 1px solid #c9d6d1;
    border-radius: 8px;
    background: #fff;
    color: var(--formula-ink);
    font-size: .88rem;
    font-weight: 750;
    text-decoration: none;
    white-space: nowrap;
}
.formula-secondary-action:hover { border-color: #96aaa3; background: var(--formula-soft); }

.formulas-page .success-message, .formulas-page .error-message { text-align: left; }
.formula-tools {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 14px;
    padding: 14px 16px;
    border: 1px solid var(--formula-line);
    border-radius: 10px;
    background: #fff;
}
.formula-search { flex: 1; max-width: 420px; }
.formula-search input {
    width: 100%;
    height: 42px;
    padding: 0 13px;
    border: 1px solid #c9d6d1;
    border-radius: 8px;
    color: var(--formula-ink);
    font: inherit;
}
.formula-tools > span { color: var(--formula-muted); font-size: .82rem; font-weight: 650; }
.formulas-page input:focus-visible, .formulas-page select:focus-visible, .formulas-page button:focus-visible, .formulas-page a:focus-visible {
    outline: 3px solid rgba(23, 107, 93, .2);
    outline-offset: 2px;
}

.formula-workspace { display: grid; grid-template-columns: 230px minmax(0, 1fr); gap: 18px; align-items: start; }
.formula-nav {
    position: sticky;
    top: 16px;
    overflow: hidden;
    border: 1px solid var(--formula-line);
    border-radius: 10px;
    background: #fff;
}
.formula-nav a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 46px;
    padding: 9px 12px;
    border-bottom: 1px solid #e7eeeb;
    color: #435550;
    font-size: .84rem;
    font-weight: 700;
    text-decoration: none;
}
.formula-nav a:last-child { border-bottom: 0; }
.formula-nav a:hover { background: var(--formula-soft); color: var(--formula-ink); }
.formula-nav a.is-current { border-left: 4px solid var(--formula-brand); background: #e9f2ef; color: var(--formula-ink); }
.formula-nav small { display: inline-grid; place-items: center; min-width: 24px; height: 24px; padding: 0 6px; border-radius: 999px; background: #edf2f0; color: #61716c; font-size: .7rem; }

.formulas-page .formula-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; margin: 0; padding: 0; }
.formulas-page .formula-card {
    scroll-margin-top: 20px;
    border: 1px solid var(--formula-line);
    border-radius: 11px;
    box-shadow: 0 3px 12px rgba(24, 49, 47, .05);
    transform: none;
}
.formulas-page .formula-card:hover { transform: none; box-shadow: 0 5px 16px rgba(24, 49, 47, .08); }
.formulas-page .formula-card.is-selected { border-color: var(--formula-brand); box-shadow: 0 0 0 3px rgba(23, 107, 93, .12); }
.formulas-page .formula-header { padding: 18px 20px; border-bottom-color: var(--formula-line); background: var(--formula-soft); }
.formulas-page .formula-header h2 { color: var(--formula-ink); font-size: 1.25rem; }
.formulas-page .total-percentage { display: flex; align-items: baseline; gap: 6px; padding: 6px 10px; background: #e5f1ed; color: var(--formula-brand-dark); font-variant-numeric: tabular-nums; font-weight: 800; }
.formulas-page .total-percentage span { color: #60716c; font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.formulas-page .formula-content { padding: 18px 20px 20px; }
.formulas-page .ingredients-list { margin: 0 0 18px; border: 1px solid var(--formula-line); border-radius: 9px; overflow: hidden; }
.formulas-page .ingredient-item { display: grid; grid-template-columns: minmax(170px, 1fr) auto auto; gap: 12px; padding: 11px 12px; }
.ingredient-identity { min-width: 0; }
.ingredient-identity strong, .ingredient-identity small { display: block; }
.ingredient-identity strong { overflow: hidden; color: var(--formula-ink); font-size: .9rem; text-overflow: ellipsis; white-space: nowrap; }
.ingredient-identity small { margin-top: 2px; color: var(--formula-muted); font-size: .72rem; }
.inline-percentage-form { display: flex; align-items: center; gap: 7px; }
.inline-percentage-form label { display: flex; align-items: center; gap: 4px; color: var(--formula-muted); }
.inline-percentage-form input { width: 82px; height: 36px; padding: 0 8px; border: 1px solid #c9d6d1; border-radius: 7px; text-align: right; font: inherit; font-variant-numeric: tabular-nums; }
.formula-save-button, .formula-remove-button {
    min-height: 34px;
    padding: 7px 10px;
    border-radius: 7px;
    background: #fff;
    font: inherit;
    font-size: .76rem;
    font-weight: 750;
    cursor: pointer;
}
.formula-save-button { border: 1px solid #9fb8b0; color: var(--formula-brand-dark); }
.formula-save-button:hover { border-color: var(--formula-brand); background: #eef6f3; }
.formula-save-button:disabled { opacity: .65; cursor: wait; }
.formula-remove-button { border: 1px solid transparent; color: #a63d3d; }
.formula-remove-button:hover { background: #fff0f0; }
.formula-empty-state { padding: 24px 16px; margin-bottom: 18px; border: 1px dashed #c8d5d0; border-radius: 9px; text-align: center; }
.formula-empty-state strong, .formula-empty-state span { display: block; }
.formula-empty-state strong { color: var(--formula-ink); }
.formula-empty-state span { margin-top: 3px; color: var(--formula-muted); font-size: .82rem; }

.formulas-page .quick-add-form { margin: 0; padding: 14px; border: 1px solid #dce6e2; background: var(--formula-soft); }
.formulas-page .quick-add-form .form-row { align-items: flex-end; margin-bottom: 10px; }
.formulas-page .quick-add-form .form-group { position: relative; }
.formulas-page .quick-add-form label { display: block; margin-bottom: 5px; color: #52645f; font-size: .73rem; font-weight: 750; }
.formulas-page .quick-add-form select, .formulas-page .quick-add-form input { height: 40px; border-color: #c9d6d1; color: var(--formula-ink); }
.formulas-page .quick-add-form .percentage-input { display: grid; grid-template-columns: 1fr auto; gap: 6px; max-width: 150px; }
.formulas-page .quick-add-form .percentage-input label { grid-column: 1 / -1; }
.formulas-page .quick-add-form button { background: var(--formula-brand); font-weight: 750; }
.formulas-page .quick-add-form button:hover { background: var(--formula-brand-dark); }
.formula-complete-note { margin: 0; padding: 12px 14px; border-radius: 8px; background: #edf4f1; color: #526b64; font-size: .8rem; font-weight: 650; }
.formula-no-results { padding: 60px 20px; border: 1px dashed #c8d5d0; border-radius: 10px; text-align: center; color: var(--formula-muted); }
.formula-no-results strong, .formula-no-results span { display: block; }
.formula-no-results strong { margin-bottom: 4px; color: var(--formula-ink); }

.formula-starter-panel {
    margin-bottom: 18px;
    padding: 16px;
    border: 1px solid #d4e3dd;
    border-radius: 10px;
    background: linear-gradient(180deg, #f7fbf9 0%, #f0f6f3 100%);
}
.formula-starter-panel > strong { display: block; margin-bottom: 4px; color: var(--formula-ink); font-size: .92rem; }
.formula-starter-panel > span { display: block; margin-bottom: 12px; color: var(--formula-muted); font-size: .8rem; }
.starter-template-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.starter-template-button {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
    padding: 12px 13px;
    border: 1px solid #c5d8d1;
    border-radius: 9px;
    background: #fff;
    color: var(--formula-ink);
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s, background .15s;
}
.starter-template-button:hover { border-color: var(--formula-brand); background: #f3faf7; box-shadow: 0 2px 8px rgba(23, 107, 93, .08); }
.starter-template-button:disabled { opacity: .6; cursor: wait; }
.starter-template-button strong { font-size: .84rem; }
.starter-template-button small { color: var(--formula-muted); font-size: .72rem; line-height: 1.35; }

.formula-suggestions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 14px; }
.formula-suggestions-label { color: var(--formula-muted); font-size: .74rem; font-weight: 750; text-transform: uppercase; letter-spacing: .05em; }
.suggestion-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 11px;
    border: 1px solid #c9d6d1;
    border-radius: 999px;
    background: #fff;
    color: var(--formula-brand-dark);
    font: inherit;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}
.suggestion-chip:hover { border-color: var(--formula-brand); background: #eef6f3; }
.suggestion-chip:disabled { opacity: .55; cursor: wait; }
.suggestion-chip em { color: var(--formula-muted); font-style: normal; font-weight: 650; }

.formula-copy-row {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 10px;
    margin-bottom: 14px;
    padding: 12px 14px;
    border: 1px dashed #c8d5d0;
    border-radius: 9px;
    background: #fafcfb;
}
.formula-copy-row label { display: block; margin-bottom: 5px; color: #52645f; font-size: .73rem; font-weight: 750; }
.formula-copy-row select {
    min-width: 180px;
    height: 40px;
    padding: 0 10px;
    border: 1px solid #c9d6d1;
    border-radius: 7px;
    color: var(--formula-ink);
    font: inherit;
}
.formula-copy-button {
    min-height: 40px;
    padding: 8px 14px;
    border: 1px solid #9fb8b0;
    border-radius: 7px;
    background: #fff;
    color: var(--formula-brand-dark);
    font: inherit;
    font-size: .8rem;
    font-weight: 750;
    cursor: pointer;
}
.formula-copy-button:hover { border-color: var(--formula-brand); background: #eef6f3; }
.formula-copy-button:disabled { opacity: .65; cursor: wait; }

.ingredient-picker { position: relative; }
.ingredient-picker-input {
    width: 100%;
    height: 40px;
    padding: 0 12px;
    border: 1px solid #c9d6d1;
    border-radius: 7px;
    color: var(--formula-ink);
    font: inherit;
}
.ingredient-picker-list {
    position: absolute;
    z-index: 20;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    max-height: 220px;
    overflow-y: auto;
    margin: 0;
    padding: 6px;
    list-style: none;
    border: 1px solid #c9d6d1;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(24, 49, 47, .12);
}
.ingredient-picker-list[hidden] { display: none; }
.ingredient-picker-option {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    padding: 9px 10px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--formula-ink);
    font: inherit;
    font-size: .84rem;
    text-align: left;
    cursor: pointer;
}
.ingredient-picker-option:hover, .ingredient-picker-option.is-highlighted { background: #eef6f3; }
.ingredient-picker-option small { color: var(--formula-muted); font-size: .72rem; }
.ingredient-picker-empty { padding: 10px; color: var(--formula-muted); font-size: .8rem; text-align: center; }
.formula-action-feedback { margin: 0 0 10px; color: var(--formula-brand-dark); font-size: .78rem; font-weight: 650; }
.formula-action-feedback.is-error { color: #a63d3d; }

@media (max-width: 820px) {
    .formulas-page { padding: 24px 14px 44px; }
    .formula-workspace { grid-template-columns: 1fr; }
    .formula-nav { position: static; display: flex; overflow-x: auto; }
    .formula-nav a { flex: 0 0 auto; border-right: 1px solid #e7eeeb; border-bottom: 0; }
    .formula-nav a.is-current { border-left: 0; border-bottom: 3px solid var(--formula-brand); }
}

@media (max-width: 620px) {
    .formulas-page-header { align-items: stretch; flex-direction: column; }
    .formula-secondary-action { width: 100%; }
    .formula-tools { align-items: stretch; flex-direction: column; }
    .formula-search { max-width: none; }
    .formulas-page .ingredient-item { grid-template-columns: 1fr auto; }
    .inline-percentage-form { grid-column: 1 / -1; }
    .remove-ingredient-form { grid-column: 2; grid-row: 1; }
    .formulas-page .quick-add-form .form-row { flex-direction: column; align-items: stretch; }
    .formulas-page .quick-add-form .percentage-input { max-width: none; }
    .formula-copy-row { flex-direction: column; align-items: stretch; }
    .formula-copy-row select { width: 100%; }
    .starter-template-grid { grid-template-columns: 1fr; }
}
</style>

<div class="container formulas-page">
    <header class="formulas-page-header">
        <div>
            <p class="formula-eyebrow">Production standards</p>
            <h1>Dough Formulas</h1>
            <p>Start new formulas from templates, quick-add common ingredients, or fine-tune baker's percentages in place.</p>
        </div>
        <a class="formula-secondary-action" href="ingredients.php">Manage ingredients</a>
    </header>

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

    <div class="formula-tools">
        <label class="formula-search" for="formulaSearch">
            <span class="visually-hidden">Search formulas</span>
            <input id="formulaSearch" type="search" placeholder="Find a dough formula" autocomplete="off">
        </label>
        <span id="formulaCount"><?php echo count($dough_types); ?> formula<?php echo count($dough_types) === 1 ? '' : 's'; ?></span>
    </div>

    <div class="formula-workspace">
        <nav class="formula-nav" aria-label="Dough formulas">
            <?php foreach ($dough_types as $dough_type): ?>
                <a href="?dough_type=<?php echo (int)$dough_type['id']; ?>#formula-<?php echo (int)$dough_type['id']; ?>"
                   data-formula-link="<?php echo (int)$dough_type['id']; ?>"
                   class="<?php echo $selected_dough_type_id === (int)$dough_type['id'] ? 'is-current' : ''; ?>">
                    <span><?php echo htmlspecialchars($dough_type['name']); ?></span>
                    <small><?php echo (int)$dough_type['ingredient_count']; ?></small>
                </a>
            <?php endforeach; ?>
        </nav>

        <main class="formula-grid" id="formulaGrid">
        <?php foreach ($dough_types as $dough_type):
            $dough_type_id = (int)$dough_type['id'];
            $ingredients = $formulas_by_dough[$dough_type_id] ?? [];
            $total_percentage = array_sum(array_column($ingredients, 'percentage'));
            $used_ingredient_ids = array_map('intval', array_column($ingredients, 'ingredient_id'));
            $available_ingredients = array_values(array_filter(
                $all_ingredients,
                static fn($ingredient) => !in_array((int)$ingredient['id'], $used_ingredient_ids, true)
            ));
            $missing_suggestions = bakery_formula_suggest_missing($all_ingredients, $used_ingredient_ids);
            $copy_sources = array_values(array_filter(
                $dough_types,
                static fn($candidate) => (int)$candidate['id'] !== $dough_type_id && (int)$candidate['ingredient_count'] > 0
            ));
            $available_json = bakery_json_for_html(array_map(
                static fn($ingredient) => [
                    'id' => (int)$ingredient['id'],
                    'name' => (string)$ingredient['name'],
                    'unit' => (string)($ingredient['unit'] ?? ''),
                ],
                $available_ingredients
            ));
        ?>
            <article class="formula-card <?php echo $selected_dough_type_id === $dough_type_id ? 'is-selected' : ''; ?>"
                     id="formula-<?php echo $dough_type_id; ?>"
                     data-formula-id="<?php echo $dough_type_id; ?>"
                     data-formula-name="<?php echo htmlspecialchars(strtolower($dough_type['name']), ENT_QUOTES); ?>">
                <div class="formula-header">
                    <h2><?php echo htmlspecialchars($dough_type['name']); ?></h2>
                    <div class="total-percentage"><span>Total</span> <?php echo number_format($total_percentage, 1); ?>%</div>
                </div>
                
                <div class="formula-content">
                    <p class="formula-action-feedback" data-formula-feedback="<?php echo $dough_type_id; ?>" hidden></p>
                    <?php if (empty($ingredients)): ?>
                        <div class="formula-starter-panel">
                            <strong>Start this formula</strong>
                            <span>Pick a starter template — we'll match ingredients from your catalogue by name.</span>
                            <div class="starter-template-grid">
                                <?php foreach ($starter_templates as $template_key => $template): ?>
                                    <button type="button"
                                            class="starter-template-button"
                                            data-starter-template="<?php echo htmlspecialchars($template_key, ENT_QUOTES); ?>"
                                            data-dough-type-id="<?php echo $dough_type_id; ?>">
                                        <strong><?php echo htmlspecialchars($template['label']); ?></strong>
                                        <small><?php echo htmlspecialchars($template['description']); ?></small>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <ul class="ingredients-list">
                            <?php foreach ($ingredients as $ingredient): ?>
                                <li class="ingredient-item">
                                    <div class="ingredient-identity">
                                        <strong><?php echo htmlspecialchars($ingredient['ingredient_name'] ?? ''); ?></strong>
                                        <small><?php echo htmlspecialchars($ingredient['unit'] ?? ''); ?></small>
                                    </div>
                                    <form method="POST" class="inline-percentage-form">
                                        <?php echo bakery_csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_percentage">
                                        <input type="hidden" name="dough_type_id" value="<?php echo $dough_type_id; ?>">
                                        <input type="hidden" name="ingredient_id" value="<?php echo (int)$ingredient['ingredient_id']; ?>">
                                        <label>
                                            <span class="visually-hidden">Percentage for <?php echo htmlspecialchars($ingredient['ingredient_name'] ?? ''); ?></span>
                                            <input type="number" name="percentage" value="<?php echo htmlspecialchars((string)$ingredient['percentage'], ENT_QUOTES); ?>" step="0.1" min="0" max="999.9" required>
                                            <span>%</span>
                                        </label>
                                        <button type="submit" class="formula-save-button">Save</button>
                                    </form>
                                    <form method="POST" class="remove-ingredient-form" data-ingredient-name="<?php echo htmlspecialchars($ingredient['ingredient_name'] ?? '', ENT_QUOTES); ?>">
                                        <?php echo bakery_csrf_field(); ?>
                                        <input type="hidden" name="action" value="remove_ingredient">
                                        <input type="hidden" name="dough_type_id" value="<?php echo $dough_type_id; ?>">
                                        <input type="hidden" name="ingredient_id" value="<?php echo (int)$ingredient['ingredient_id']; ?>">
                                        <button type="submit" class="formula-remove-button">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($copy_sources)): ?>
                        <div class="formula-copy-row">
                            <div class="form-group">
                                <label for="copy-source-<?php echo $dough_type_id; ?>">Copy from another formula</label>
                                <select id="copy-source-<?php echo $dough_type_id; ?>" class="formula-copy-source">
                                    <option value="">Choose a dough type</option>
                                    <?php foreach ($copy_sources as $copy_source): ?>
                                        <option value="<?php echo (int)$copy_source['id']; ?>">
                                            <?php echo htmlspecialchars($copy_source['name']); ?>
                                            (<?php echo (int)$copy_source['ingredient_count']; ?> ingredients)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="button"
                                    class="formula-copy-button"
                                    data-dough-type-id="<?php echo $dough_type_id; ?>">
                                Copy ingredients
                            </button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($missing_suggestions)): ?>
                        <div class="formula-suggestions">
                            <span class="formula-suggestions-label">Quick add</span>
                            <?php foreach ($missing_suggestions as $suggestion): ?>
                                <button type="button"
                                        class="suggestion-chip"
                                        data-dough-type-id="<?php echo $dough_type_id; ?>"
                                        data-ingredient-id="<?php echo (int)$suggestion['ingredient_id']; ?>"
                                        data-percentage="<?php echo htmlspecialchars((string)$suggestion['percentage'], ENT_QUOTES); ?>"
                                        title="Add <?php echo htmlspecialchars($suggestion['name'], ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($suggestion['label']); ?>
                                    <em><?php echo number_format($suggestion['percentage'], 1); ?>%</em>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($available_ingredients)): ?>
                    <form method="POST" class="quick-add-form" data-quick-add-form data-dough-type-id="<?php echo $dough_type_id; ?>">
                        <?php echo bakery_csrf_field(); ?>
                        <input type="hidden" name="action" value="add_ingredient">
                        <input type="hidden" name="dough_type_id" value="<?php echo $dough_type_id; ?>">

                        <div class="form-row">
                            <div class="form-group ingredient-picker" data-ingredient-picker data-options="<?php echo htmlspecialchars($available_json, ENT_QUOTES); ?>">
                                <label for="ingredient-search-<?php echo $dough_type_id; ?>">Ingredient</label>
                                <input id="ingredient-search-<?php echo $dough_type_id; ?>"
                                       class="ingredient-picker-input"
                                       type="search"
                                       placeholder="Search or pick an ingredient"
                                       autocomplete="off"
                                       role="combobox"
                                       aria-expanded="false"
                                       aria-controls="ingredient-list-<?php echo $dough_type_id; ?>">
                                <input type="hidden" name="ingredient_id" value="" required>
                                <ul id="ingredient-list-<?php echo $dough_type_id; ?>" class="ingredient-picker-list" role="listbox" hidden></ul>
                            </div>
                            <div class="form-group percentage-input">
                                <label for="percentage-<?php echo $dough_type_id; ?>">Percentage</label>
                                <input id="percentage-<?php echo $dough_type_id; ?>" type="number" name="percentage" step="0.1" min="0" max="999.9" required placeholder="0.0">
                                <span>%</span>
                            </div>
                        </div>
                        <button type="submit">Add ingredient</button>
                    </form>
                    <?php else: ?>
                        <p class="formula-complete-note">All available ingredients are already in this formula.</p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
            <div id="noFormulaResults" class="formula-no-results" hidden>
                <strong>No formulas found</strong>
                <span>Try another search.</span>
            </div>
        </main>
    </div>
</div>

<script>
const formulaSearch = document.getElementById('formulaSearch');
const formulaCount = document.getElementById('formulaCount');
const noFormulaResults = document.getElementById('noFormulaResults');

function applyFormulaSearch() {
    const query = formulaSearch.value.trim().toLowerCase();
    let visibleCount = 0;
    document.querySelectorAll('.formula-card').forEach(card => {
        const isVisible = !query || card.dataset.formulaName.includes(query);
        card.hidden = !isVisible;
        const navLink = document.querySelector(`[data-formula-link="${card.dataset.formulaId}"]`);
        if (navLink) navLink.hidden = !isVisible;
        if (isVisible) visibleCount += 1;
    });
    formulaCount.textContent = `${visibleCount} formula${visibleCount === 1 ? '' : 's'}`;
    noFormulaResults.hidden = visibleCount !== 0;
}

formulaSearch.addEventListener('input', applyFormulaSearch);
applyFormulaSearch();

document.querySelectorAll('.formula-nav a').forEach(link => {
    link.addEventListener('click', event => {
        const formulaId = link.dataset.formulaLink;
        const card = document.querySelector(`[data-formula-id="${formulaId}"]`);
        if (!card) return;
        event.preventDefault();
        document.querySelectorAll('.formula-nav a').forEach(item => item.classList.toggle('is-current', item === link));
        document.querySelectorAll('.formula-card').forEach(item => item.classList.toggle('is-selected', item === card));
        window.history.replaceState(null, '', `?dough_type=${formulaId}#formula-${formulaId}`);
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
});

async function formulasPost(action, payload) {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const body = new URLSearchParams({ action, csrf_token: csrfToken, ...payload });
    const response = await fetch('formulas.php', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        throw new Error(data.error || 'Request failed');
    }
    return data;
}

function showFormulaFeedback(doughTypeId, message, isError = false) {
    const node = document.querySelector(`[data-formula-feedback="${doughTypeId}"]`);
    if (!node) return;
    node.textContent = message;
    node.classList.toggle('is-error', isError);
    node.hidden = false;
}

function initIngredientPickers() {
    document.querySelectorAll('[data-ingredient-picker]').forEach(picker => {
        const options = JSON.parse(picker.dataset.options || '[]');
        const input = picker.querySelector('.ingredient-picker-input');
        const hidden = picker.querySelector('input[name="ingredient_id"]');
        const list = picker.querySelector('.ingredient-picker-list');
        let highlighted = -1;

        const render = (query = '') => {
            const normalized = query.trim().toLowerCase();
            const filtered = options.filter(option =>
                !normalized || option.name.toLowerCase().includes(normalized)
            );
            list.innerHTML = '';
            highlighted = -1;
            if (filtered.length === 0) {
                list.innerHTML = '<li class="ingredient-picker-empty">No matching ingredients</li>';
            } else {
                filtered.forEach((option, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'ingredient-picker-option';
                    button.dataset.index = String(index);
                    button.innerHTML = `<span>${option.name}</span><small>${option.unit || ''}</small>`;
                    button.addEventListener('mousedown', event => {
                        event.preventDefault();
                        hidden.value = String(option.id);
                        input.value = option.name;
                        list.hidden = true;
                        input.setAttribute('aria-expanded', 'false');
                    });
                    list.appendChild(button);
                });
            }
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        };

        input.addEventListener('focus', () => render(input.value));
        input.addEventListener('input', () => {
            hidden.value = '';
            render(input.value);
        });
        input.addEventListener('keydown', event => {
            const items = list.querySelectorAll('.ingredient-picker-option');
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlighted = Math.min(highlighted + 1, items.length - 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlighted = Math.max(highlighted - 1, 0);
            } else if (event.key === 'Enter' && highlighted >= 0 && items[highlighted]) {
                event.preventDefault();
                items[highlighted].dispatchEvent(new MouseEvent('mousedown'));
                return;
            } else if (event.key === 'Escape') {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                return;
            } else {
                return;
            }
            items.forEach((item, index) => item.classList.toggle('is-highlighted', index === highlighted));
        });
        document.addEventListener('click', event => {
            if (!picker.contains(event.target)) {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
            }
        });
    });
}

initIngredientPickers();

document.querySelectorAll('[data-quick-add-form]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const doughTypeId = form.dataset.doughTypeId;
        const button = form.querySelector('button[type="submit"]');
        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Adding…';
        try {
            const data = await formulasPost('add_ingredient', Object.fromEntries(new FormData(form)));
            window.location.href = data.redirect || `formulas.php?dough_type=${doughTypeId}&success=ingredient_added#formula-${doughTypeId}`;
        } catch (error) {
            showFormulaFeedback(doughTypeId, error.message, true);
            button.textContent = 'Try again';
        } finally {
            button.disabled = false;
            window.setTimeout(() => { button.textContent = originalLabel; }, 1400);
        }
    });
});

document.querySelectorAll('.suggestion-chip').forEach(chip => {
    chip.addEventListener('click', async () => {
        const doughTypeId = chip.dataset.doughTypeId;
        chip.disabled = true;
        try {
            const data = await formulasPost('add_ingredient', {
                dough_type_id: doughTypeId,
                ingredient_id: chip.dataset.ingredientId,
                percentage: chip.dataset.percentage,
            });
            window.location.href = data.redirect || `formulas.php?dough_type=${doughTypeId}&success=ingredient_added#formula-${doughTypeId}`;
        } catch (error) {
            showFormulaFeedback(doughTypeId, error.message, true);
            chip.disabled = false;
        }
    });
});

document.querySelectorAll('[data-starter-template]').forEach(button => {
    button.addEventListener('click', async () => {
        const doughTypeId = button.dataset.doughTypeId;
        const template = button.dataset.starterTemplate;
        button.disabled = true;
        const original = button.innerHTML;
        button.innerHTML = '<strong>Applying…</strong><small>Matching ingredients</small>';
        try {
            const data = await formulasPost('apply_starter', {
                dough_type_id: doughTypeId,
                template,
            });
            window.location.href = data.redirect || `formulas.php?dough_type=${doughTypeId}&success=starter_applied#formula-${doughTypeId}`;
        } catch (error) {
            button.disabled = false;
            button.innerHTML = original;
            showFormulaFeedback(doughTypeId, error.message, true);
        }
    });
});

document.querySelectorAll('.formula-copy-button').forEach(button => {
    button.addEventListener('click', async () => {
        const doughTypeId = button.dataset.doughTypeId;
        const select = button.closest('.formula-copy-row')?.querySelector('.formula-copy-source');
        const sourceId = select?.value || '';
        if (!sourceId) {
            showFormulaFeedback(doughTypeId, 'Choose a dough type to copy from.', true);
            return;
        }
        button.disabled = true;
        const original = button.textContent;
        button.textContent = 'Copying…';
        try {
            const data = await formulasPost('copy_formula', {
                dough_type_id: doughTypeId,
                source_dough_type_id: sourceId,
            });
            window.location.href = data.redirect || `formulas.php?dough_type=${doughTypeId}&success=formula_copied#formula-${doughTypeId}`;
        } catch (error) {
            showFormulaFeedback(doughTypeId, error.message, true);
            button.textContent = original;
            button.disabled = false;
        }
    });
});

document.querySelectorAll('.inline-percentage-form').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('.formula-save-button');
        const originalLabel = button.textContent;
        button.disabled = true;
        button.textContent = 'Saving…';
        try {
            const response = await fetch('formulas.php', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams(new FormData(form)),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.error || 'Save failed');
            }
            const card = form.closest('.formula-card');
            const total = Array.from(card.querySelectorAll('.inline-percentage-form input[name="percentage"]'))
                .reduce((sum, input) => sum + (Number(input.value) || 0), 0);
            card.querySelector('.total-percentage').innerHTML = `<span>Total</span> ${total.toFixed(1)}%`;
            button.textContent = 'Saved';
            window.setTimeout(() => { button.textContent = originalLabel; }, 1400);
        } catch (error) {
            button.textContent = 'Try again';
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('.remove-ingredient-form').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!window.confirm(`Remove ${form.dataset.ingredientName} from this formula?`)) {
            return;
        }
        const button = form.querySelector('.formula-remove-button');
        button.disabled = true;
        try {
            const data = await formulasPost('remove_ingredient', Object.fromEntries(new FormData(form)));
            window.location.href = data.redirect || window.location.pathname + window.location.search;
        } catch (error) {
            button.disabled = false;
            const doughTypeId = form.querySelector('input[name="dough_type_id"]')?.value;
            if (doughTypeId) {
                showFormulaFeedback(doughTypeId, error.message, true);
            }
        }
    });
});

const selectedFormula = document.querySelector('.formula-card.is-selected');
if (selectedFormula) {
    requestAnimationFrame(() => selectedFormula.scrollIntoView({ behavior: 'smooth', block: 'start' }));
}
</script>

<?php require_once 'includes/footer.php'; ?>
