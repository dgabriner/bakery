<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/counter_orders.php';

bakery_require_role(['cashier', 'baker', 'manager', 'administrator']);

$user = bakery_current_user();
$role = (string)($user['role_slug'] ?? '');
$userId = (int)($user['id'] ?? 0);
$canComplete = bakery_counter_order_can_complete($role);
$ordersEnabled = table_exists($db, 'counter_orders');

$requestedView = strtolower(trim((string)($_GET['view'] ?? '')));
if (!in_array($requestedView, ['take', 'pending'], true)) {
    $requestedView = in_array($role, ['manager', 'baker'], true) ? 'pending' : 'take';
}

$filterDate = trim((string)($_GET['date'] ?? ''));
if ($filterDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}
$filterPlace = strtolower(trim((string)($_GET['place'] ?? '')));
if (!in_array($filterPlace, bakery_counter_order_places(), true)) {
    $filterPlace = '';
}
$filterStatus = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($filterStatus, ['pending', 'done'], true)) {
    $filterStatus = 'pending';
}

$notice = '';
$error = '';
$form = [
    'pickup_date' => date('Y-m-d'),
    'pickup_place' => '',
    'order_text' => '',
    'pay_status' => 'unpaid',
    'pay_note' => '',
    'customer_name' => '',
    'customer_phone' => '',
    'customer_email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = 'csrf';
    } elseif (!$ordersEnabled) {
        $error = 'not_enabled';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            foreach ($form as $key => $unused) {
                if (isset($_POST[$key])) {
                    $form[$key] = (string)$_POST[$key];
                }
            }
            $file = isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : null;
            $created = bakery_counter_order_create($db, $userId, $form, $file);
            if (!empty($created['ok'])) {
                $savedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$form['pickup_date'])
                    ? (string)$form['pickup_date']
                    : date('Y-m-d');
                header('Location: ' . BASE_URL . 'counter_orders.php?view=pending&saved=1&date=' . rawurlencode($savedDate));
                exit;
            }
            $error = (string)($created['error'] ?? 'save_failed');
            $requestedView = 'take';
        } elseif ($action === 'done') {
            $done = bakery_counter_order_mark_done($db, (int)($_POST['order_id'] ?? 0), $userId, $role);
            if (!empty($done['ok'])) {
                $back = 'view=pending&done=1&status=' . rawurlencode($filterStatus);
                if ($filterDate !== '') {
                    $back .= '&date=' . rawurlencode($filterDate);
                }
                if ($filterPlace !== '') {
                    $back .= '&place=' . rawurlencode($filterPlace);
                }
                header('Location: ' . BASE_URL . 'counter_orders.php?' . $back);
                exit;
            }
            $error = (string)($done['error'] ?? 'missing');
            $requestedView = 'pending';
        }
    }
}

if ($notice === '' && isset($_GET['saved'])) {
    $notice = 'saved';
} elseif ($notice === '' && isset($_GET['done'])) {
    $notice = 'marked_done';
}

$rows = [];
if ($ordersEnabled && $requestedView === 'pending') {
    $rows = bakery_counter_order_list(
        $db,
        $filterStatus,
        $filterDate !== '' ? $filterDate : null,
        $filterPlace !== '' ? $filterPlace : null
    );
}

$page_title = bakery_t($requestedView === 'pending' ? 'counter_orders.title_pending' : 'counter_orders.title_take');

$counterLink = static function (array $overrides) use ($requestedView, $filterDate, $filterPlace, $filterStatus): string {
    $query = [
        'view' => $overrides['view'] ?? $requestedView,
        'date' => array_key_exists('date', $overrides) ? $overrides['date'] : $filterDate,
        'place' => array_key_exists('place', $overrides) ? $overrides['place'] : $filterPlace,
        'status' => $overrides['status'] ?? $filterStatus,
    ];
    $query = array_filter($query, static function ($value) {
        return $value !== null && $value !== '';
    });
    return BASE_URL . 'counter_orders.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/counter_orders.css'); ?>">

<main class="counter-orders">
  <header class="counter-orders__hero">
    <p class="counter-orders__eyebrow"><?php bakery_te('counter_orders.eyebrow'); ?></p>
    <h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="counter-orders__lead"><?php bakery_te('counter_orders.lead'); ?></p>
  </header>

  <nav class="counter-orders__tabs" aria-label="<?php bakery_te('counter_orders.tabs_aria'); ?>">
    <a class="counter-orders__tab<?php echo $requestedView === 'take' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($counterLink(['view' => 'take', 'status' => '', 'date' => '', 'place' => '']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.tab_take'); ?></a>
    <a class="counter-orders__tab<?php echo $requestedView === 'pending' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($counterLink(['view' => 'pending', 'status' => 'pending']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.tab_pending'); ?></a>
  </nav>

  <?php if ($notice !== ''): ?>
    <p class="counter-orders__banner counter-orders__banner--ok" role="status"><?php bakery_te('counter_orders.' . $notice); ?></p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <p class="counter-orders__banner counter-orders__banner--bad" role="alert"><?php bakery_te('counter_orders.' . $error); ?></p>
  <?php endif; ?>

  <?php if (!$ordersEnabled): ?>
    <section class="counter-orders__empty">
      <h2><?php bakery_te('counter_orders.not_enabled'); ?></h2>
    </section>
  <?php elseif ($requestedView === 'take'): ?>
    <form class="counter-orders__form" method="post" action="<?php echo htmlspecialchars(BASE_URL . 'counter_orders.php?view=take', ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="action" value="create">

      <label class="counter-orders__field">
        <span><?php bakery_te('counter_orders.when'); ?></span>
        <input type="date" name="pickup_date" required value="<?php echo htmlspecialchars((string)$form['pickup_date'], ENT_QUOTES, 'UTF-8'); ?>">
      </label>

      <fieldset class="counter-orders__field">
        <legend><?php bakery_te('counter_orders.where'); ?></legend>
        <div class="counter-orders__choices">
          <?php foreach (bakery_counter_order_places() as $place): ?>
            <label class="counter-orders__choice">
              <input type="radio" name="pickup_place" value="<?php echo htmlspecialchars($place, ENT_QUOTES, 'UTF-8'); ?>" required<?php echo $form['pickup_place'] === $place ? ' checked' : ''; ?>>
              <span><?php bakery_te('counter_orders.place_' . $place); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <label class="counter-orders__field">
        <span><?php bakery_te('counter_orders.what'); ?></span>
        <textarea name="order_text" rows="4" maxlength="2000" placeholder="<?php bakery_te('counter_orders.what_placeholder'); ?>"><?php echo htmlspecialchars((string)$form['order_text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
      </label>

      <fieldset class="counter-orders__field">
        <legend><?php bakery_te('counter_orders.pay'); ?></legend>
        <div class="counter-orders__choices">
          <?php foreach (bakery_counter_order_pay_statuses() as $pay): ?>
            <label class="counter-orders__choice">
              <input type="radio" name="pay_status" value="<?php echo htmlspecialchars($pay, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $form['pay_status'] === $pay ? ' checked' : ''; ?>>
              <span><?php bakery_te('counter_orders.pay_' . $pay); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <input class="counter-orders__note" type="text" name="pay_note" maxlength="160" value="<?php echo htmlspecialchars((string)$form['pay_note'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php bakery_te('counter_orders.pay_note_placeholder'); ?>">
      </fieldset>

      <fieldset class="counter-orders__field">
        <legend><?php bakery_te('counter_orders.who'); ?> <em><?php bakery_te('counter_orders.who_optional'); ?></em></legend>
        <label class="counter-orders__sub">
          <span><?php bakery_te('counter_orders.name'); ?></span>
          <input type="text" name="customer_name" maxlength="120" autocomplete="name" value="<?php echo htmlspecialchars((string)$form['customer_name'], ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label class="counter-orders__sub">
          <span><?php bakery_te('counter_orders.phone'); ?></span>
          <input type="tel" name="customer_phone" maxlength="40" autocomplete="tel" value="<?php echo htmlspecialchars((string)$form['customer_phone'], ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label class="counter-orders__sub">
          <span><?php bakery_te('counter_orders.email'); ?></span>
          <input type="email" name="customer_email" maxlength="160" autocomplete="email" value="<?php echo htmlspecialchars((string)$form['customer_email'], ENT_QUOTES, 'UTF-8'); ?>">
        </label>
      </fieldset>

      <label class="counter-orders__photo">
        <span><?php bakery_te('counter_orders.photo_button'); ?></span>
        <input type="file" id="counterOrderPhoto" name="photo" accept="image/*" capture="environment">
      </label>
      <img class="counter-orders__preview" id="counterOrderPreview" alt="" hidden>

      <button class="counter-orders__save" type="submit"><?php bakery_te('counter_orders.save'); ?></button>
    </form>
  <?php else: ?>
    <div class="counter-orders__filters">
      <a class="counter-orders__chip<?php echo $filterStatus === 'pending' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($counterLink(['status' => 'pending']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.show_open'); ?></a>
      <a class="counter-orders__chip<?php echo $filterStatus === 'done' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($counterLink(['status' => 'done']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.show_done'); ?></a>
      <a class="counter-orders__chip<?php echo $filterPlace === '' ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($counterLink(['place' => '']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.all_places'); ?></a>
      <?php foreach (bakery_counter_order_places() as $place): ?>
        <a class="counter-orders__chip<?php echo $filterPlace === $place ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($counterLink(['place' => $place]), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.place_' . $place); ?></a>
      <?php endforeach; ?>
      <form method="get" action="<?php echo htmlspecialchars(BASE_URL . 'counter_orders.php', ENT_QUOTES, 'UTF-8'); ?>" class="counter-orders__date-filter">
        <input type="hidden" name="view" value="pending">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($filterPlace !== ''): ?>
          <input type="hidden" name="place" value="<?php echo htmlspecialchars($filterPlace, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
        <label>
          <span><?php bakery_te('counter_orders.when'); ?></span>
          <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <button type="submit"><?php bakery_te('common.view'); ?></button>
        <?php if ($filterDate !== ''): ?>
          <a href="<?php echo htmlspecialchars($counterLink(['date' => '']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('counter_orders.all_dates'); ?></a>
        <?php endif; ?>
      </form>
    </div>

    <?php if ($rows === []): ?>
      <section class="counter-orders__empty">
        <h2><?php bakery_te($filterStatus === 'done' ? 'counter_orders.empty_done' : 'counter_orders.empty'); ?></h2>
      </section>
    <?php else: ?>
      <ul class="counter-orders__list">
        <?php foreach ($rows as $row): ?>
          <?php
            $photoHref = bakery_counter_order_photo_href((string)($row['photo_path'] ?? ''));
            $placeKey = 'counter_orders.place_' . (string)$row['pickup_place'];
            $payKey = 'counter_orders.pay_' . (string)$row['pay_status'];
          ?>
          <li class="counter-orders__card">
            <div class="counter-orders__card-top">
              <strong><?php echo htmlspecialchars((string)$row['pickup_date'], ENT_QUOTES, 'UTF-8'); ?></strong>
              <span class="counter-orders__pill"><?php bakery_te($placeKey); ?></span>
              <span class="counter-orders__pill counter-orders__pill--<?php echo htmlspecialchars((string)$row['pay_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($payKey); ?></span>
            </div>
            <?php if ((string)$row['pay_note'] !== ''): ?>
              <p class="counter-orders__note-line"><?php echo htmlspecialchars((string)$row['pay_note'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ((string)$row['order_text'] !== ''): ?>
              <p class="counter-orders__what"><?php echo htmlspecialchars((string)$row['order_text'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($photoHref !== ''): ?>
              <a class="counter-orders__photo-link" href="<?php echo htmlspecialchars($photoHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                <img src="<?php echo htmlspecialchars($photoHref, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php bakery_te('counter_orders.photo'); ?>">
              </a>
            <?php endif; ?>
            <p class="counter-orders__who-line">
              <?php
                $whoBits = array_filter([
                    (string)$row['customer_name'],
                    (string)$row['customer_phone'],
                    (string)$row['customer_email'],
                ], static function ($bit) {
                    return $bit !== '';
                });
                echo $whoBits === []
                    ? htmlspecialchars(bakery_t('counter_orders.no_customer'), ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(implode(' · ', $whoBits), ENT_QUOTES, 'UTF-8');
              ?>
            </p>
            <p class="counter-orders__meta"><?php echo htmlspecialchars(bakery_t('counter_orders.by', ['name' => (string)$row['created_name']]), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($canComplete && (string)$row['status'] === 'pending'): ?>
              <form method="post" action="<?php echo htmlspecialchars($counterLink([]), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="done">
                <input type="hidden" name="order_id" value="<?php echo (int)$row['id']; ?>">
                <button class="counter-orders__done" type="submit"><?php bakery_te('counter_orders.done'); ?></button>
              </form>
            <?php elseif ((string)$row['status'] === 'done'): ?>
              <p class="counter-orders__meta"><?php bakery_te('counter_orders.status_done'); ?><?php if (!empty($row['done_name'])): ?> · <?php echo htmlspecialchars((string)$row['done_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</main>
<script>
(function () {
  var input = document.getElementById('counterOrderPhoto');
  var preview = document.getElementById('counterOrderPreview');
  if (!input || !preview) { return; }
  input.addEventListener('change', function () {
    var file = input.files && input.files[0];
    if (!file) {
      preview.hidden = true;
      preview.removeAttribute('src');
      return;
    }
    preview.src = URL.createObjectURL(file);
    preview.hidden = false;
  });
}());
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
