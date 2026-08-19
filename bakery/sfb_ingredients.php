<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$notice = '';
$noticeKind = 'info';
$categories = bakery_sfb_ingredient_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_ingredient':
                $newId = bakery_sfb_create_ingredient(
                    $db,
                    $customerId,
                    $_POST['name'] ?? '',
                    $_POST['category'] ?? 'other'
                );
                header('Location: sfb_ingredients.php?ingredient=' . $newId . '&saved=created');
                exit;

            case 'update_ingredient':
                $ingredientId = bakery_sfb_update_ingredient(
                    $db,
                    $customerId,
                    (int)($_POST['ingredient_id'] ?? 0),
                    $_POST['name'] ?? '',
                    $_POST['category'] ?? 'other'
                );
                header('Location: sfb_ingredients.php?ingredient=' . $ingredientId . '&saved=updated');
                exit;

            case 'toggle_ingredient':
                $ingredientId = bakery_sfb_toggle_ingredient($db, $customerId, (int)($_POST['ingredient_id'] ?? 0));
                header('Location: sfb_ingredients.php?ingredient=' . $ingredientId . '&saved=toggled');
                exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$ingredients = bakery_sfb_custom_ingredients($db, $customerId, false);
$selectedIngredient = null;
$selectedId = (int)($_GET['ingredient'] ?? 0);
if ($selectedId > 0) {
    $selectedIngredient = bakery_sfb_ingredient($db, $customerId, $selectedId);
}
if (!$selectedIngredient && $ingredients) {
    foreach ($ingredients as $candidate) {
        if ((int)$candidate['is_active'] === 1) {
            $selectedIngredient = $candidate;
            break;
        }
    }
    if (!$selectedIngredient) {
        $selectedIngredient = $ingredients[0];
    }
}

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'created' => 'Ingredient added — it is ready to use in your formulas.',
    'updated' => 'Ingredient saved.',
    'toggled' => 'Ingredient updated.',
];

$page_title = 'SF Baker — Ingredients';
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
    <nav class="sfb-tabs" aria-label="SF Baker">
      <a href="sfb_dashboard.php"><?php bakery_te('sfb.tab_dashboard'); ?></a>
      <a href="sfb_starters.php"><?php bakery_te('sfb.tab_starters'); ?></a>
      <a href="sfb_ingredients.php" class="active"><?php bakery_te('sfb.tab_ingredients'); ?></a>
      <a href="sfb_formulas.php"><?php bakery_te('sfb.tab_formulas'); ?></a>
      <a href="sfb_batches.php"><?php bakery_te('sfb.tab_batches'); ?></a>
    </nav>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.my_ingredients'); ?></h2></div>
      <div class="card-body">
        <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.ingredients_intro'); ?></p>
        <?php if (!$ingredients): ?>
          <p class="empty-state"><?php bakery_te('sfb.no_custom_ingredients'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($ingredients as $ingredient): ?>
              <li>
                <span>
                  <a href="sfb_ingredients.php?ingredient=<?php echo (int)$ingredient['id']; ?>" style="color:inherit;">
                    <?php echo htmlspecialchars($ingredient['name']); ?>
                  </a>
                  <?php if ((int)$ingredient['is_active'] !== 1): ?>
                    <span class="badge badge-muted"><?php bakery_te('sfb.retired'); ?></span>
                  <?php endif; ?>
                  <br>
                  <small class="muted"><?php echo htmlspecialchars(bakery_sfb_ingredient_category_label($ingredient['category'])); ?></small>
                </span>
                <form method="post" style="margin:0;">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle_ingredient">
                  <input type="hidden" name="ingredient_id" value="<?php echo (int)$ingredient['id']; ?>">
                  <button type="submit" class="btn-link"><?php echo (int)$ingredient['is_active'] === 1 ? bakery_t('sfb.retire') : bakery_t('sfb.reactivate'); ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="add-row">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="create_ingredient">
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.ingredient_name'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_ingredient_name')); ?></span>
              <input type="text" name="name" required maxlength="100" placeholder="<?php bakery_te('sfb.enter_ingredient_name'); ?>">
            </label>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.category'); ?></span>
              <select name="category">
                <?php foreach ($categories as $key => $label): ?>
                  <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <button type="submit" class="btn btn-block"><?php bakery_te('sfb.add_ingredient'); ?></button>
        </form>
      </div>
    </section>

    <?php if ($selectedIngredient): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.edit_ingredient'); ?></h2></div>
        <div class="card-body">
          <?php if (bakery_sfb_ingredient_in_use($db, (int)$selectedIngredient['id'])): ?>
            <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.ingredient_in_use'); ?></p>
          <?php endif; ?>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="update_ingredient">
            <input type="hidden" name="ingredient_id" value="<?php echo (int)$selectedIngredient['id']; ?>">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.ingredient_name'); ?></span>
                <input type="text" name="name" required maxlength="100" value="<?php echo htmlspecialchars($selectedIngredient['name']); ?>">
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.category'); ?></span>
                <select name="category">
                  <?php foreach ($categories as $key => $label): ?>
                    <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selectedIngredient['category'] === $key ? ' selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_ingredient'); ?></button>
          </form>
        </div>
      </section>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
