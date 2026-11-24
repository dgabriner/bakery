<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/special_orders.php';

bakery_require_role(['administrator', 'manager']);

$date = trim((string)($_GET['date'] ?? $_POST['delivery_date'] ?? ''));
$dateObject = DateTime::createFromFormat('!Y-m-d', $date);
if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}
$customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$editId = (int)($_GET['id'] ?? 0);
$notice = '';
$error = '';
$ready = bakery_special_orders_ready($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = 'csrf';
    } elseif (!$ready) {
        $error = 'missing_schema';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'cancel') {
            $result = bakery_special_order_cancel($db, (int)($_POST['id'] ?? 0));
            if (!empty($result['ok'])) {
                header('Location: ' . BASE_URL . 'special_orders.php?date=' . rawurlencode($date) . '&cancelled=1');
                exit;
            }
            $error = (string)($result['error'] ?? 'not_found');
        } elseif ($action === 'save') {
            $result = bakery_special_order_save($db, [
                'id' => (int)($_POST['id'] ?? 0),
                'customer_id' => (int)($_POST['customer_id'] ?? 0),
                'delivery_date' => (string)($_POST['delivery_date'] ?? ''),
                'note' => (string)($_POST['note'] ?? ''),
                'lines' => is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [],
            ]);
            if (!empty($result['ok'])) {
                $savedDate = (string)($_POST['delivery_date'] ?? $date);
                $savedCustomer = (int)($_POST['customer_id'] ?? 0);
                header('Location: ' . BASE_URL . 'special_orders.php?date=' . rawurlencode($savedDate)
                    . '&customer_id=' . $savedCustomer . '&saved=1');
                exit;
            }
            $error = (string)($result['error'] ?? 'need_line');
            $date = (string)($_POST['delivery_date'] ?? $date);
            $customerId = (int)($_POST['customer_id'] ?? $customerId);
            $editId = (int)($_POST['id'] ?? 0);
        }
    }
}

if ($notice === '' && isset($_GET['saved'])) {
    $notice = 'saved';
} elseif ($notice === '' && isset($_GET['cancelled'])) {
    $notice = 'cancelled';
}

$editing = ($ready && $editId > 0) ? bakery_special_order_load($db, $editId) : null;
if ($editing && (string)($editing['status'] ?? '') === 'open') {
    $customerId = (int)$editing['customer_id'];
    $date = (string)$editing['delivery_date'];
} else {
    $editing = null;
    $editId = 0;
}

$postedLines = (($_SERVER['REQUEST_METHOD'] === 'POST') && is_array($_POST['lines'] ?? null))
    ? array_values($_POST['lines'])
    : [];
$formLines = $postedLines !== [] ? $postedLines : ($editing['lines'] ?? []);
$noteValue = (($_SERVER['REQUEST_METHOD'] === 'POST') && $error !== '')
    ? (string)($_POST['note'] ?? '')
    : (string)($editing['note'] ?? '');

$customers = $ready ? bakery_special_order_customer_choices($db) : [];
$products = $ready ? bakery_special_order_product_choices($db) : [];
$openOrders = $ready ? bakery_special_order_list_for_date($db, $date) : [];
$reasons = function_exists('bakery_no_charge_reasons') ? bakery_no_charge_reasons() : [];
$rowCount = max(4, count($formLines));

$page_title = bakery_t('special_order.title');
$errorText = '';
if ($error === 'csrf') {
    $errorText = bakery_t('common.error_csrf');
} elseif ($error !== '') {
    $errorKey = 'special_order.' . $error;
    $errorText = bakery_t($errorKey);
    if ($errorText === $errorKey) {
        $errorText = bakery_t('special_order.need_line');
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/special_orders.css'); ?>">
<main class="special-orders">
  <header class="special-orders__hero">
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p><?php bakery_te('special_order.lead'); ?></p>
  </header>

  <?php if ($notice !== ''): ?>
    <p class="special-orders__banner special-orders__banner--ok" role="status"><?php bakery_te('special_order.' . $notice); ?></p>
  <?php endif; ?>
  <?php if ($errorText !== ''): ?>
    <p class="special-orders__banner special-orders__banner--bad" role="alert"><?php echo htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <section class="special-orders__card">
      <p><?php bakery_te('special_order.schema_missing_page'); ?></p>
    </section>
  <?php else: ?>
    <form class="special-orders__card" method="post" action="<?php echo htmlspecialchars(BASE_URL . 'special_orders.php', ENT_QUOTES, 'UTF-8'); ?>">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?php echo (int)$editId; ?>">
      <div class="special-orders__fields">
        <label><?php bakery_te('special_order.date'); ?>
          <input type="date" name="delivery_date" required value="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label><?php bakery_te('special_order.customer'); ?>
          <select name="customer_id" required>
            <option value=""><?php bakery_te('special_order.choose_customer'); ?></option>
            <?php foreach ($customers as $customer): ?>
              <option value="<?php echo (int)$customer['id']; ?>"<?php echo (int)$customer['id'] === $customerId ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$customer['name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="special-orders__note"><?php bakery_te('special_order.note'); ?>
          <input type="text" name="note" maxlength="500" value="<?php echo htmlspecialchars($noteValue, ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo htmlspecialchars(bakery_t('special_order.note_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
        </label>
      </div>
      <h2><?php bakery_te('special_order.lines_heading'); ?></h2>
      <p class="special-orders__help"><?php bakery_te('special_order.add_help'); ?></p>
      <div class="special-orders__lines">
        <?php for ($i = 0; $i < $rowCount; $i++): ?>
          <?php
            $line = $formLines[$i] ?? [];
            $kind = (string)($line['line_kind'] ?? 'text');
            if ($kind !== 'catalog' && $kind !== 'text') {
                $kind = 'text';
            }
            $lineProduct = (int)($line['product_id'] ?? 0);
            $lineQty = (string)($line['quantity'] ?? '');
            $linePrice = array_key_exists('unit_price', $line) ? (string)$line['unit_price'] : '';
            $lineNoCharge = !empty($line['is_no_charge']);
            $lineReason = (string)($line['no_charge_reason'] ?? '');
          ?>
          <fieldset class="special-orders__line">
            <legend><?php echo (int)($i + 1); ?></legend>
            <label><?php bakery_te('special_order.line_kind'); ?>
              <select name="lines[<?php echo $i; ?>][line_kind]" data-line-kind>
                <option value="catalog"<?php echo $kind === 'catalog' ? ' selected' : ''; ?>><?php bakery_te('special_order.kind_catalog'); ?></option>
                <option value="text"<?php echo $kind === 'text' ? ' selected' : ''; ?>><?php bakery_te('special_order.kind_text'); ?></option>
              </select>
            </label>
            <label data-catalog-field <?php echo $kind === 'catalog' ? '' : 'hidden'; ?>><?php bakery_te('special_order.product'); ?>
              <select name="lines[<?php echo $i; ?>][product_id]">
                <option value=""><?php bakery_te('special_order.choose_product'); ?></option>
                <?php foreach ($products as $product): ?>
                  <option value="<?php echo (int)$product['id']; ?>"<?php echo (int)$product['id'] === $lineProduct ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$product['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><?php bakery_te('special_order.description'); ?>
              <input type="text" name="lines[<?php echo $i; ?>][description]" maxlength="200" value="<?php echo htmlspecialchars((string)($line['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label><?php bakery_te('special_order.quantity'); ?>
              <input type="number" name="lines[<?php echo $i; ?>][quantity]" min="0" max="9999" step="1" inputmode="numeric" value="<?php echo htmlspecialchars($lineQty, ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label><?php bakery_te('special_order.unit_price'); ?>
              <input type="number" name="lines[<?php echo $i; ?>][unit_price]" min="0" max="9999" step="0.01" inputmode="decimal" value="<?php echo htmlspecialchars($linePrice, ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label class="special-orders__check">
              <input type="checkbox" name="lines[<?php echo $i; ?>][is_no_charge]" value="1"<?php echo $lineNoCharge ? ' checked' : ''; ?>>
              <?php bakery_te('special_order.no_charge'); ?>
            </label>
            <label><?php bakery_te('special_order.reason'); ?>
              <select name="lines[<?php echo $i; ?>][no_charge_reason]">
                <option value=""><?php bakery_te('special_order.choose_reason'); ?></option>
                <?php foreach ($reasons as $reason): ?>
                  <?php
                    $reasonKey = 'no_charge.reason.' . $reason;
                    $reasonLabel = bakery_t($reasonKey);
                    if ($reasonLabel === $reasonKey) {
                        $reasonLabel = $reason;
                    }
                  ?>
                  <option value="<?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $lineReason === $reason ? ' selected' : ''; ?>><?php echo htmlspecialchars($reasonLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </fieldset>
        <?php endfor; ?>
      </div>
      <button type="submit" class="special-orders__save"><?php bakery_te('special_order.save'); ?></button>
    </form>

    <section class="special-orders__card">
      <h2><?php bakery_te('special_order.list_heading'); ?></h2>
      <?php if ($openOrders === []): ?>
        <p><?php bakery_te('special_order.list_empty'); ?></p>
      <?php else: ?>
        <ul class="special-orders__list">
          <?php foreach ($openOrders as $order): ?>
            <li>
              <div>
                <strong><?php echo htmlspecialchars((string)$order['customer_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars(bakery_special_order_plain_summary($order['lines'] ?? []), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if (trim((string)($order['note'] ?? '')) !== ''): ?>
                  <span><?php echo htmlspecialchars((string)$order['note'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
              </div>
              <div class="special-orders__row-actions">
                <a href="<?php echo htmlspecialchars(BASE_URL . 'special_orders.php?date=' . rawurlencode($date) . '&id=' . (int)$order['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('special_order.edit'); ?></a>
                <form method="post" action="<?php echo htmlspecialchars(BASE_URL . 'special_orders.php?date=' . rawurlencode($date), ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="id" value="<?php echo (int)$order['id']; ?>">
                  <button type="submit"><?php bakery_te('special_order.cancel'); ?></button>
                </form>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<script>
(function () {
  document.querySelectorAll('[data-line-kind]').forEach(function (select) {
    var sync = function () {
      var field = select.closest('fieldset');
      if (!field) return;
      var catalog = field.querySelector('[data-catalog-field]');
      if (catalog) catalog.hidden = select.value !== 'catalog';
    };
    select.addEventListener('change', sync);
    sync();
  });
}());
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
