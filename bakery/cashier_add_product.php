<?php
/**
 * Cashier: add a bakery or store product, then take its photo.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/cashier_catalog.php';

bakery_require_role(['administrator', 'manager', 'cashier']);

$page_title = bakery_t('page.cashier_add_product');
$error = '';
$kind = 'retail';
$name = '';
$price = '';
$doughTypeId = 0;
$description = '';

$doughOptions = bakery_cashier_dough_options($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bakery_require_csrf();
    $kind = strtolower(trim((string)($_POST['kind'] ?? 'retail')));
    $name = trim((string)($_POST['name'] ?? ''));
    $price = trim((string)($_POST['price'] ?? ''));
    $doughTypeId = (int)($_POST['dough_type_id'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));

    $result = bakery_cashier_create_product($db, [
        'kind' => $kind,
        'name' => $name,
        'price' => $price,
        'dough_type_id' => $doughTypeId,
        'description' => $description,
    ]);

    if (empty($result['ok'])) {
        $map = [
            'name_required' => bakery_t('cashier_add.error_name'),
            'name_taken' => bakery_t('cashier_add.error_name_taken'),
            'price_invalid' => bakery_t('cashier_add.error_price'),
            'dough_required' => bakery_t('cashier_add.error_dough'),
            'save_failed' => bakery_t('cashier_add.error_save'),
            'products_missing' => bakery_t('cashier_add.error_save'),
        ];
        $error = $map[$result['error'] ?? ''] ?? bakery_t('cashier_add.error_save');
    } else {
        $newId = (int)$result['id'];
        $photoNote = '';
        if (!empty($_FILES['photo']['tmp_name']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
            if (table_exists($db, 'product_images')) {
                require_once __DIR__ . '/includes/product_photo_handler.php';
                $handler = new ProductPhotoHandler();
                $upload = $handler->processUpload($db, $_FILES['photo'], $newId, true);
                if (empty($upload['success'])) {
                    $photoNote = '&photo_error=1';
                }
            }
        }
        header('Location: ' . BASE_URL . 'product_photos.php?product_id=' . $newId . $photoNote);
        exit;
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<style>
.cashier-add {
  max-width: 32rem;
  margin: 0 auto;
  padding: 1rem 1rem 6rem;
}
.cashier-add h1 {
  font-size: 1.45rem;
  margin: 0 0 .35rem;
}
.cashier-add .lead {
  color: #5c6b66;
  margin: 0 0 1.25rem;
  font-size: .95rem;
}
.cashier-add .error {
  background: #fde8e6;
  color: #8a2b22;
  border-radius: 10px;
  padding: .75rem 1rem;
  margin-bottom: 1rem;
}
.kind-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: .5rem;
  margin-bottom: 1.25rem;
}
.kind-toggle label {
  display: block;
  border: 2px solid #d5ddd9;
  border-radius: 14px;
  padding: .85rem .75rem;
  text-align: center;
  cursor: pointer;
  background: #fff;
  font-weight: 600;
}
.kind-toggle input {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.kind-toggle input:checked + span,
.kind-toggle label:has(input:checked) {
  border-color: #2f6f5e;
  background: #e8f5f0;
  color: #1d4a3e;
}
.kind-toggle .sub {
  display: block;
  font-weight: 400;
  font-size: .78rem;
  color: #6b7d78;
  margin-top: .25rem;
}
.cashier-add label.field {
  display: block;
  font-weight: 600;
  margin: 0 0 .35rem;
}
.cashier-add .field-wrap {
  margin-bottom: 1rem;
}
.cashier-add input[type=text],
.cashier-add input[type=number],
.cashier-add select,
.cashier-add textarea {
  width: 100%;
  box-sizing: border-box;
  font-size: 1.05rem;
  padding: .75rem .85rem;
  border: 1px solid #c9d2cd;
  border-radius: 12px;
  background: #fff;
}
.cashier-add textarea { min-height: 4rem; resize: vertical; }
.cashier-add .hint {
  font-size: .8rem;
  color: #6b7d78;
  margin: .3rem 0 0;
}
.photo-box {
  border: 2px dashed #b7c4be;
  border-radius: 14px;
  padding: 1rem;
  text-align: center;
  background: #f7faf8;
  margin-bottom: 1.25rem;
}
.photo-box input[type=file] {
  width: 100%;
  margin-top: .5rem;
}
.cashier-add .actions {
  display: grid;
  gap: .65rem;
}
.cashier-add .btn-primary {
  display: block;
  width: 100%;
  border: 0;
  border-radius: 14px;
  background: #2f6f5e;
  color: #fff;
  font-size: 1.1rem;
  font-weight: 700;
  padding: 1rem;
  cursor: pointer;
}
.cashier-add .btn-secondary {
  display: block;
  text-align: center;
  color: #2f6f5e;
  font-weight: 600;
  padding: .5rem;
  text-decoration: none;
}
#doughWrap[hidden] { display: none !important; }
</style>

<div class="cashier-add">
  <h1><?php echo htmlspecialchars(bakery_t('cashier_add.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
  <p class="lead"><?php echo htmlspecialchars(bakery_t('cashier_add.lead'), ENT_QUOTES, 'UTF-8'); ?></p>

  <?php if ($error !== ''): ?>
    <div class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="cashierAddForm">
    <?php echo bakery_csrf_field(); ?>

    <div class="kind-toggle" role="radiogroup" aria-label="<?php echo htmlspecialchars(bakery_t('cashier_add.kind_label'), ENT_QUOTES, 'UTF-8'); ?>">
      <label>
        <input type="radio" name="kind" value="retail" <?php echo $kind === 'retail' ? 'checked' : ''; ?>>
        <span><?php echo htmlspecialchars(bakery_t('cashier_add.kind_retail'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="sub"><?php echo htmlspecialchars(bakery_t('cashier_add.kind_retail_hint'), ENT_QUOTES, 'UTF-8'); ?></span>
      </label>
      <label>
        <input type="radio" name="kind" value="bakery" <?php echo $kind === 'bakery' ? 'checked' : ''; ?>>
        <span><?php echo htmlspecialchars(bakery_t('cashier_add.kind_bakery'), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="sub"><?php echo htmlspecialchars(bakery_t('cashier_add.kind_bakery_hint'), ENT_QUOTES, 'UTF-8'); ?></span>
      </label>
    </div>

    <div class="field-wrap">
      <label class="field" for="name"><?php echo htmlspecialchars(bakery_t('cashier_add.name'), ENT_QUOTES, 'UTF-8'); ?></label>
      <input type="text" id="name" name="name" required maxlength="100" autocomplete="off"
             value="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
             placeholder="<?php echo htmlspecialchars(bakery_t('cashier_add.name_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
    </div>

    <div class="field-wrap">
      <label class="field" for="price"><?php echo htmlspecialchars(bakery_t('cashier_add.price'), ENT_QUOTES, 'UTF-8'); ?></label>
      <input type="number" id="price" name="price" min="0" step="0.01" inputmode="decimal"
             value="<?php echo htmlspecialchars($price, ENT_QUOTES, 'UTF-8'); ?>"
             placeholder="0.00">
    </div>

    <div class="field-wrap" id="doughWrap" <?php echo $kind === 'bakery' ? '' : 'hidden'; ?>>
      <label class="field" for="dough_type_id"><?php echo htmlspecialchars(bakery_t('cashier_add.dough'), ENT_QUOTES, 'UTF-8'); ?></label>
      <select id="dough_type_id" name="dough_type_id">
        <option value=""><?php echo htmlspecialchars(bakery_t('cashier_add.dough_placeholder'), ENT_QUOTES, 'UTF-8'); ?></option>
        <?php foreach ($doughOptions as $opt): ?>
          <option value="<?php echo (int)$opt['id']; ?>" <?php echo $doughTypeId === (int)$opt['id'] ? 'selected' : ''; ?>>
            <?php
              $label = (string)$opt['name'];
              if (!empty($opt['line_name'])) {
                  $label = $opt['line_name'] . ' — ' . $label;
              }
              echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="hint"><?php echo htmlspecialchars(bakery_t('cashier_add.dough_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <div class="photo-box">
      <strong><?php echo htmlspecialchars(bakery_t('cashier_add.photo'), ENT_QUOTES, 'UTF-8'); ?></strong>
      <p class="hint"><?php echo htmlspecialchars(bakery_t('cashier_add.photo_hint'), ENT_QUOTES, 'UTF-8'); ?></p>
      <input type="file" name="photo" accept="image/*" capture="environment">
    </div>

    <div class="actions">
      <button type="submit" class="btn-primary"><?php echo htmlspecialchars(bakery_t('cashier_add.submit'), ENT_QUOTES, 'UTF-8'); ?></button>
      <a class="btn-secondary" href="<?php echo htmlspecialchars(BASE_URL . 'product_photos.php', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bakery_t('cashier_add.back_photos'), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
  </form>
</div>

<script>
(function () {
  var doughWrap = document.getElementById('doughWrap');
  var doughSelect = document.getElementById('dough_type_id');
  function syncKind() {
    var bakery = document.querySelector('input[name="kind"][value="bakery"]');
    var isBakery = bakery && bakery.checked;
    if (doughWrap) {
      doughWrap.hidden = !isBakery;
    }
    if (doughSelect) {
      doughSelect.required = !!isBakery;
      if (!isBakery) {
        doughSelect.value = '';
      }
    }
  }
  document.querySelectorAll('input[name="kind"]').forEach(function (el) {
    el.addEventListener('change', syncKind);
  });
  syncKind();
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
