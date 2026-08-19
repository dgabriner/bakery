<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'copy_template':
                $newId = bakery_sfb_copy_template($db, $customerId, (int)($_POST['template_id'] ?? 0));
                header('Location: sfb_formulas.php?formula=' . $newId . '&saved=copied');
                exit;

            case 'create_formula':
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Formula name is required');
                }
                $target = (float)($_POST['target_dough_g'] ?? 0);
                $stmt = $db->prepare(
                    'INSERT INTO sfb_formulas (customer_id, name, description, target_dough_g, is_template)
                     VALUES (?, ?, ?, ?, 0)'
                );
                $stmt->execute([
                    $customerId,
                    $name,
                    trim((string)($_POST['description'] ?? '')) !== '' ? trim((string)$_POST['description']) : null,
                    $target > 0 ? $target : null,
                ]);
                header('Location: sfb_formulas.php?formula=' . (int)$db->lastInsertId() . '&saved=created');
                exit;

            case 'update_formula':
                $formula = bakery_sfb_formula($db, $customerId, (int)($_POST['formula_id'] ?? 0));
                if (!$formula || (int)$formula['customer_id'] !== $customerId) {
                    throw new InvalidArgumentException('Formula not found');
                }
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Formula name is required');
                }
                $target = (float)($_POST['target_dough_g'] ?? 0);
                $stmt = $db->prepare(
                    'UPDATE sfb_formulas SET name = ?, description = ?, target_dough_g = ?, notes = ? WHERE id = ?'
                );
                $stmt->execute([
                    $name,
                    trim((string)($_POST['description'] ?? '')) !== '' ? trim((string)$_POST['description']) : null,
                    $target > 0 ? $target : null,
                    trim((string)($_POST['notes'] ?? '')) !== '' ? trim((string)$_POST['notes']) : null,
                    (int)$formula['id'],
                ]);
                header('Location: sfb_formulas.php?formula=' . (int)$formula['id'] . '&saved=updated');
                exit;

            case 'delete_formula':
                $formula = bakery_sfb_formula($db, $customerId, (int)($_POST['formula_id'] ?? 0));
                if (!$formula || (int)$formula['customer_id'] !== $customerId) {
                    throw new InvalidArgumentException('Formula not found');
                }
                $db->prepare('UPDATE sfb_batches SET formula_id = NULL WHERE formula_id = ?')->execute([(int)$formula['id']]);
                $db->prepare('DELETE FROM sfb_formulas WHERE id = ? AND customer_id = ?')->execute([(int)$formula['id'], $customerId]);
                header('Location: sfb_formulas.php?saved=deleted');
                exit;

            case 'create_ingredient':
                $newId = bakery_sfb_create_ingredient(
                    $db,
                    $customerId,
                    $_POST['name'] ?? '',
                    $_POST['category'] ?? 'other'
                );
                $formulaId = (int)($_POST['formula_id'] ?? 0);
                $redirect = 'sfb_formulas.php?new_ingredient=' . $newId;
                if ($formulaId > 0) {
                    $redirect .= '&formula=' . $formulaId;
                }
                header('Location: ' . $redirect . '&saved=ingredient_created');
                exit;

            case 'add_line':
                $formula = bakery_sfb_formula($db, $customerId, (int)($_POST['formula_id'] ?? 0));
                if (!$formula || (int)$formula['customer_id'] !== $customerId) {
                    throw new InvalidArgumentException('Formula not found');
                }
                $lineType = (string)($_POST['line_type'] ?? 'ingredient');
                $percentage = (float)($_POST['percentage'] ?? 0);
                if ($percentage <= 0 || $percentage > 500) {
                    throw new InvalidArgumentException('Percentage must be between 0 and 500');
                }
                $ingredientId = null;
                $starterId = null;
                if ($lineType === 'starter') {
                    $starter = bakery_sfb_starter($db, $customerId, (int)($_POST['starter_id'] ?? 0));
                    if (!$starter) {
                        throw new InvalidArgumentException('Choose one of your starters');
                    }
                    $starterId = (int)$starter['id'];
                } else {
                    $ingredientId = (int)($_POST['ingredient_id'] ?? 0);
                    $valid = false;
                    foreach (bakery_sfb_ingredient_options($db, $customerId) as $opt) {
                        if ((int)$opt['id'] === $ingredientId) {
                            $valid = true;
                            break;
                        }
                    }
                    if (!$valid) {
                        throw new InvalidArgumentException('Choose an ingredient');
                    }
                }
                $sort = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sfb_formula_ingredients WHERE formula_id = ?');
                $sort->execute([(int)$formula['id']]);
                $stmt = $db->prepare(
                    'INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, starter_id, percentage, sort_order)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([(int)$formula['id'], $ingredientId, $starterId, $percentage, (int)$sort->fetchColumn()]);
                header('Location: sfb_formulas.php?formula=' . (int)$formula['id'] . '&saved=line_added');
                exit;

            case 'update_line':
            case 'delete_line':
                $lineId = (int)($_POST['line_id'] ?? 0);
                $check = $db->prepare(
                    'SELECT fi.id, fi.formula_id FROM sfb_formula_ingredients fi
                     JOIN sfb_formulas f ON f.id = fi.formula_id
                     WHERE fi.id = ? AND f.customer_id = ? LIMIT 1'
                );
                $check->execute([$lineId, $customerId]);
                $line = $check->fetch();
                if (!$line) {
                    throw new InvalidArgumentException('Formula line not found');
                }
                if ($_POST['action'] === 'delete_line') {
                    $db->prepare('DELETE FROM sfb_formula_ingredients WHERE id = ?')->execute([$lineId]);
                } else {
                    $percentage = (float)($_POST['percentage'] ?? 0);
                    if ($percentage <= 0 || $percentage > 500) {
                        throw new InvalidArgumentException('Percentage must be between 0 and 500');
                    }
                    $db->prepare('UPDATE sfb_formula_ingredients SET percentage = ? WHERE id = ?')
                        ->execute([$percentage, $lineId]);
                }
                header('Location: sfb_formulas.php?formula=' . (int)$line['formula_id'] . '&saved=updated');
                exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$formulas = bakery_sfb_formulas($db, $customerId);
$templates = bakery_sfb_templates($db);
$ingredientOptions = bakery_sfb_ingredient_options($db, $customerId);
$ingredientCategories = bakery_sfb_ingredient_categories();
$starterOptions = bakery_sfb_starters($db, $customerId);
$preselectIngredientId = (int)($_GET['new_ingredient'] ?? 0);

$selectedFormula = null;
$selectedLines = [];
$grams = null;
$selectedId = (int)($_GET['formula'] ?? 0);
if ($selectedId > 0) {
    $selectedFormula = bakery_sfb_formula($db, $customerId, $selectedId);
}
if (!$selectedFormula && $formulas) {
    $selectedFormula = bakery_sfb_formula($db, $customerId, (int)$formulas[0]['id']);
}
if ($selectedFormula) {
    $selectedLines = bakery_sfb_formula_lines($db, (int)$selectedFormula['id']);
    $target = (float)($_GET['dough_g'] ?? 0);
    if ($target <= 0) {
        $target = (float)($selectedFormula['target_dough_g'] ?? 0);
    }
    if ($target > 0 && $selectedLines) {
        $grams = bakery_sfb_formula_grams($selectedLines, $target);
        $grams['target'] = $target;
    }
}
$ownsSelected = $selectedFormula && (int)$selectedFormula['customer_id'] === $customerId;
$totalPct = bakery_sfb_formula_total_pct($selectedLines);

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'copied' => 'Standard formula copied — it is yours to adjust now.',
    'created' => 'Formula created. Add ingredient percentages below.',
    'updated' => 'Formula saved.',
    'deleted' => 'Formula deleted.',
    'line_added' => 'Ingredient added to the formula.',
    'ingredient_created' => 'Custom ingredient added — select it below to add to your formula.',
];

$page_title = 'SF Baker — Formulas';
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
</head>
<body class="sfb-body">
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container sfb-app">
    <?php $sfbActiveTab = 'formulas'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.my_formulas'); ?></h2></div>
      <div class="card-body">
        <?php if (!$formulas): ?>
          <p class="empty-state"><?php bakery_te('sfb.no_formulas'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($formulas as $formula): ?>
              <li>
                <span>
                  <a href="sfb_formulas.php?formula=<?php echo (int)$formula['id']; ?>" style="color:inherit;">
                    <?php echo htmlspecialchars($formula['name']); ?>
                  </a>
                  <?php if ($selectedFormula && (int)$selectedFormula['id'] === (int)$formula['id']): ?>
                    <span class="badge badge-ok"><?php bakery_te('sfb.selected'); ?></span>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="add-row">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="create_formula">
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.formula_name'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_formula_name')); ?></span>
              <input type="text" name="name" required maxlength="100" placeholder="<?php bakery_te('sfb.enter_formula_name'); ?>">
            </label>
          </div>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.new_blank_formula'); ?></button>
        </form>
      </div>
    </section>

    <?php if ($templates): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.standard_formulas'); ?></h2></div>
        <div class="card-body">
          <?php foreach ($templates as $template): ?>
            <div class="delivery-item" style="margin-bottom:10px;">
              <div>
                <span class="delivery-item__date"><?php echo htmlspecialchars($template['name']); ?></span>
                <div class="delivery-item__meta"><?php echo htmlspecialchars($template['description'] ?? ''); ?></div>
              </div>
              <form method="post" style="margin:0;">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="copy_template">
                <input type="hidden" name="template_id" value="<?php echo (int)$template['id']; ?>">
                <button type="submit" class="btn btn-sm"><?php bakery_te('sfb.use_template'); ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($selectedFormula): ?>
      <section class="card">
        <div class="card-header">
          <h2><?php echo htmlspecialchars($selectedFormula['name']); ?></h2>
        </div>
        <div class="card-body">
          <?php if (!$ownsSelected): ?>
            <p class="muted"><?php bakery_te('sfb.template_readonly'); ?></p>
          <?php endif; ?>

          <ul class="line-list">
            <?php foreach ($selectedLines as $line): ?>
              <li>
                <span>
                  <?php echo htmlspecialchars($line['line_name']); ?>
                  <?php if ($line['line_kind'] === 'starter'): ?>
                    <span class="badge badge-info"><?php bakery_te('sfb.starter'); ?></span>
                  <?php endif; ?>
                </span>
                <span class="sfb-line-forms">
                  <?php if ($grams): ?>
                    <span class="sfb-grams"><?php echo number_format((float)$line['grams'], 1); ?>g</span>
                  <?php endif; ?>
                  <?php if ($ownsSelected): ?>
                    <form method="post" style="margin:0;display:flex;gap:6px;align-items:center;">
                      <?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="update_line">
                      <input type="hidden" name="line_id" value="<?php echo (int)$line['id']; ?>">
                      <input type="number" class="sfb-pct-input" name="percentage" value="<?php echo (float)$line['percentage']; ?>" min="0.1" max="500" step="0.1" aria-label="<?php echo htmlspecialchars($line['line_name']); ?> %">
                      <button type="submit" class="btn-link">%</button>
                    </form>
                    <form method="post" style="margin:0;">
                      <?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="delete_line">
                      <input type="hidden" name="line_id" value="<?php echo (int)$line['id']; ?>">
                      <button type="submit" class="btn-link" aria-label="Remove">✕</button>
                    </form>
                  <?php else: ?>
                    <span class="line-qty"><?php echo (float)$line['percentage']; ?>%</span>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
            <li>
              <span><strong><?php bakery_te('sfb.total'); ?></strong></span>
              <span class="line-qty"><?php echo number_format($totalPct, 1); ?>%</span>
            </li>
          </ul>

          <form method="get" action="sfb_formulas.php" class="inline-form" style="grid-template-columns:1fr auto;">
            <input type="hidden" name="formula" value="<?php echo (int)$selectedFormula['id']; ?>">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.target_dough_g'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_dough_weight')); ?></span>
                <input type="number" name="dough_g" min="1" step="1" value="<?php echo $grams ? (int)$grams['target'] : ''; ?>" placeholder="<?php bakery_te('sfb.enter_dough_weight'); ?>">
              </label>
            </div>
            <button type="submit" class="btn btn-secondary"><?php bakery_te('sfb.calc_grams'); ?></button>
          </form>
        </div>

        <?php if ($ownsSelected): ?>
          <div class="add-row">
            <form method="post" class="inline-form" style="grid-template-columns:1fr;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="add_line">
              <input type="hidden" name="formula_id" value="<?php echo (int)$selectedFormula['id']; ?>">
              <div class="sfb-grid2">
                <div class="sfb-field">
                  <label><span><?php bakery_te('sfb.line_type'); ?></span>
                  <select name="line_type" id="sfbLineType">
                    <option value="ingredient"><?php bakery_te('sfb.ingredient'); ?></option>
                    <option value="starter"<?php echo $starterOptions ? '' : ' disabled'; ?>><?php bakery_te('sfb.my_starter'); ?></option>
                  </select>
                </div>
                <div class="sfb-field">
                  <label><span><?php bakery_te('sfb.percentage'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_percentage')); ?></span>
                  <input type="number" name="percentage" min="0.1" max="500" step="0.1" required placeholder="<?php bakery_te('sfb.enter_percentage'); ?>">
                </label>
              </div>
              <div class="sfb-field" id="sfbIngredientPick">
                <label><span><?php bakery_te('sfb.ingredient'); ?></span>
                <select name="ingredient_id" id="sfbIngredientSelect">
                  <?php foreach ($ingredientOptions as $opt): ?>
                    <option value="<?php echo (int)$opt['id']; ?>"<?php echo $preselectIngredientId === (int)$opt['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($opt['name']); ?><?php echo $opt['customer_id'] !== null ? ' (' . bakery_t('sfb.mine') . ')' : ''; ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <p style="margin:0;">
                <button type="button" class="btn-link" id="sfbToggleNewIngredient"><?php bakery_te('sfb.add_custom_ingredient'); ?></button>
                <span class="muted"> · </span>
                <a href="sfb_ingredients.php" class="btn-link"><?php bakery_te('sfb.manage_ingredients'); ?></a>
              </p>
              <div class="sfb-field" id="sfbStarterPick" hidden>
                <label><span><?php bakery_te('sfb.my_starter'); ?></span>
                <select name="starter_id">
                  <?php foreach ($starterOptions as $starter): ?>
                    <option value="<?php echo (int)$starter['id']; ?>"><?php echo htmlspecialchars($starter['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-block"><?php bakery_te('sfb.add_line'); ?></button>
            </form>
            <form method="post" class="inline-form sfb-new-ingredient" id="sfbNewIngredientForm" style="grid-template-columns:1fr;margin-top:10px;"<?php echo $preselectIngredientId > 0 ? '' : ' hidden'; ?>>
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="create_ingredient">
              <input type="hidden" name="formula_id" value="<?php echo (int)$selectedFormula['id']; ?>">
              <div class="sfb-grid2">
                <div class="sfb-field">
                  <label><span><?php bakery_te('sfb.ingredient_name'); ?></span>
                    <input type="text" name="name" required maxlength="100" placeholder="<?php bakery_te('sfb.enter_ingredient_name'); ?>">
                  </label>
                </div>
                <div class="sfb-field">
                  <label><span><?php bakery_te('sfb.category'); ?></span>
                    <select name="category">
                      <?php foreach ($ingredientCategories as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>
              </div>
              <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.add_ingredient'); ?></button>
            </form>
          </div>
          <div class="add-row">
            <form method="post" class="inline-form" style="grid-template-columns:1fr;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="update_formula">
              <input type="hidden" name="formula_id" value="<?php echo (int)$selectedFormula['id']; ?>">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.formula_name'); ?></span>
                <input type="text" name="name" required maxlength="100" value="<?php echo htmlspecialchars($selectedFormula['name']); ?>">
              </label>
              </div>
              <div class="sfb-grid2">
                <div class="sfb-field">
                  <label><span><?php bakery_te('sfb.target_dough_g'); ?></span>
                  <input type="number" name="target_dough_g" min="1" step="1" value="<?php echo $selectedFormula['target_dough_g'] !== null ? (int)$selectedFormula['target_dough_g'] : ''; ?>">
                </label>
                <div class="sfb-field">
                  <label><span><?php bakery_te('sfb.description'); ?></span>
                  <input type="text" name="description" maxlength="1000" value="<?php echo htmlspecialchars($selectedFormula['description'] ?? ''); ?>">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.notes'); ?></span>
                <textarea name="notes" rows="2"><?php echo htmlspecialchars($selectedFormula['notes'] ?? ''); ?></textarea>
              </label>
              </div>
              <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_formula'); ?></button>
            </form>
            <form method="post" style="margin-top:10px;" onsubmit="return confirm('Delete this formula? Batches that used it keep their name.');">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="delete_formula">
              <input type="hidden" name="formula_id" value="<?php echo (int)$selectedFormula['id']; ?>">
              <button type="submit" class="btn-link" style="color:#9b332c;"><?php bakery_te('sfb.delete_formula'); ?></button>
            </form>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
  <script>
    (function () {
      var type = document.getElementById('sfbLineType');
      if (!type) return;
      var ing = document.getElementById('sfbIngredientPick');
      var str = document.getElementById('sfbStarterPick');
      function sync() {
        var isStarter = type.value === 'starter';
        ing.hidden = isStarter;
        str.hidden = !isStarter;
      }
      type.addEventListener('change', sync);
      sync();

      var toggle = document.getElementById('sfbToggleNewIngredient');
      var newForm = document.getElementById('sfbNewIngredientForm');
      if (toggle && newForm) {
        toggle.addEventListener('click', function () {
          newForm.hidden = !newForm.hidden;
          if (!newForm.hidden) {
            var nameInput = newForm.querySelector('input[name="name"]');
            if (nameInput) nameInput.focus();
          }
        });
      }
    })();
  </script>
</body>
</html>
