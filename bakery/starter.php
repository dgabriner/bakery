<?php
/**
 * Public door: buy a living Sour Flour starter jar.
 * Pickup $5 (Tue/Fri at the bakery) or ship $25.
 * Non-customers create a portal account, pay via Square, leave fulfillment details.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/sf_baker.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$customerId = bakery_portal_customer_id();
$notice = '';
$noticeKind = 'info';
$orderSummary = null;

$draft = $_SESSION['starter_jar_draft'] ?? null;
if (!is_array($draft)) {
    $draft = null;
}

$purchasedId = (int)($_GET['purchased'] ?? 0);
if ($purchasedId > 0 && bakery_sfb_starter_jar_ready($db)) {
    $orderSummary = bakery_sfb_starter_jar_for_purchase($db, $purchasedId);
    if ($orderSummary && $customerId > 0 && (int)$orderSummary['customer_id'] !== $customerId) {
        $orderSummary = null;
    }
}

$saved = (string)($_GET['saved'] ?? '');
$flash = isset($_SESSION['starter_jar_flash']) ? trim((string)$_SESSION['starter_jar_flash']) : '';
unset($_SESSION['starter_jar_flash']);
if ($saved === 'intent') {
    $notice = bakery_t('sfb.pay_intent_saved');
}
if ($saved === 'failed') {
    $noticeKind = 'warn';
    $notice = bakery_t('sfb.pay_failed_notice', ['reason' => $flash !== '' ? $flash : '-']);
}

/**
 * Finish checkout from a normalized draft for a signed-in baker.
 * @return never|void redirects on success paths
 */
$finishStarterCheckout = static function (PDO $db, int $customerId, array $normalized) use (&$notice, &$noticeKind): void {
    if (!bakery_sfb_starter_jar_ready($db)) {
        throw new RuntimeException(bakery_t('sfb.starter_jar_unavailable'));
    }
    $result = bakery_sfb_buy_starter_jar($db, $customerId, $normalized);
    unset($_SESSION['starter_jar_draft']);
    if (!empty($result['configured']) && !empty($result['url'])) {
        header('Location: ' . $result['url']);
        exit;
    }
    if (!empty($result['error'])) {
        $_SESSION['starter_jar_flash'] = (string)$result['error'];
        header('Location: ' . bakery_sfb_starter_jar_return_path('saved=failed&p=' . (int)$result['purchase_id']));
        exit;
    }
    header('Location: ' . bakery_sfb_starter_jar_return_path('saved=intent&p=' . (int)$result['purchase_id']));
    exit;
};

// After account create/sign-in: continue=1 means finish pay without a second form submit.
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && $customerId > 0
    && is_array($draft)
    && $purchasedId <= 0
    && $orderSummary === null
    && (string)($_GET['continue'] ?? '') === '1'
    && $saved === ''
) {
    try {
        $finishStarterCheckout($db, $customerId, bakery_sfb_starter_jar_normalize_draft($draft));
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? 'request');
        if ($action === 'request' || $action === 'resume') {
            if ($action === 'resume') {
                if (!is_array($draft)) {
                    throw new InvalidArgumentException(bakery_t('sfb.starter_jar_draft_missing'));
                }
                $normalized = bakery_sfb_starter_jar_normalize_draft($draft);
            } else {
                $normalized = bakery_sfb_starter_jar_normalize_draft($_POST);
                $_SESSION['starter_jar_draft'] = $normalized;
                $draft = $normalized;
            }

            if ($customerId <= 0) {
                $login = BASE_URL . 'customer_login.php?create=1&next='
                    . rawurlencode(bakery_sfb_starter_jar_return_path('continue=1'));
                header('Location: ' . $login);
                exit;
            }

            $finishStarterCheckout($db, $customerId, $normalized);
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$resumeDraft = $customerId > 0 && is_array($draft) && $purchasedId <= 0 && $orderSummary === null;

$form = is_array($draft) ? $draft : [
    'fulfillment' => (string)($_POST['fulfillment'] ?? 'pickup'),
    'pickup_day' => (string)($_POST['pickup_day'] ?? 'tuesday'),
    'contact_name' => (string)($_POST['contact_name'] ?? ''),
    'ship_line1' => (string)($_POST['ship_line1'] ?? ''),
    'ship_line2' => (string)($_POST['ship_line2'] ?? ''),
    'ship_city' => (string)($_POST['ship_city'] ?? ''),
    'ship_state' => (string)($_POST['ship_state'] ?? ''),
    'ship_zip' => (string)($_POST['ship_zip'] ?? ''),
    'notes' => (string)($_POST['notes'] ?? ''),
];
if (!is_array($draft) && $_SERVER['REQUEST_METHOD'] !== 'POST' && (string)($_GET['kit'] ?? '') === '1') {
    $form['fulfillment'] = 'kit';
}
$choice = (string)($form['fulfillment'] ?? 'pickup');
if (($form['pack_kind'] ?? '') === 'first_loaf_kit') {
    $choice = 'kit';
}
$kitReady = bakery_sfb_first_loaf_kit_ready($db);

$page_title = bakery_t('sfb.starter_jar_page_title');
$currentLocale = bakery_locale();
$ready = bakery_sfb_starter_jar_ready($db);
$signedIn = $customerId > 0;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once __DIR__ . '/includes/google_analytics.php'; ?>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <style>
    :root { color-scheme: light; --ink: #1c2a26; --cream: #fffaf2; --terracotta: #c7783a; --muted: #6b7d78; --line: #e8ddcf; }
    * { box-sizing: border-box; }
    body { background: var(--cream); color: var(--ink); font-family: Georgia, 'Times New Roman', serif; margin: 0; padding: 32px 20px 48px; }
    .wrap { margin: 0 auto; max-width: 520px; }
    .logo { display: block; height: auto; margin: 0 auto 20px; max-width: 180px; mix-blend-mode: multiply; width: 46vw; }
    h1 { font-size: 1.45rem; font-weight: normal; margin: 0 0 8px; text-align: center; }
    .lede { color: var(--muted); font-size: .95rem; line-height: 1.5; margin: 0 auto 22px; max-width: 440px; text-align: center; }
    .steps { color: var(--muted); display: flex; font-size: .78rem; gap: 8px; justify-content: center; list-style: none; margin: 0 0 18px; padding: 0; }
    .steps li { white-space: nowrap; }
    .steps .on { color: var(--terracotta); font-weight: bold; }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; margin: 0 0 14px; overflow: hidden; }
    .card-body { padding: 18px; }
    .prices { display: grid; gap: 10px; grid-template-columns: 1fr; margin: 0 0 16px; }
    .price-opt { border: 1px solid var(--line); border-radius: 10px; cursor: pointer; display: block; padding: 12px; }
    .price-opt:has(input:checked) { border-color: var(--terracotta); box-shadow: inset 0 0 0 1px var(--terracotta); }
    .price-opt strong { display: block; font-size: 1.05rem; }
    .price-opt span { color: var(--muted); font-size: .85rem; }
    .price-opt input { margin-right: 6px; }
    label.field { display: block; font-size: .88rem; margin: 0 0 12px; }
    label.field span { display: block; margin-bottom: 4px; }
    input[type=text], select, textarea { background: #fff; border: 1px solid var(--line); border-radius: 8px; color: var(--ink); font: inherit; padding: 10px 12px; width: 100%; }
    .ship-fields[hidden], .pickup-fields[hidden] { display: none !important; }
    .grid2 { display: grid; gap: 10px; grid-template-columns: 1fr 1fr; }
    button, a.btn { background: var(--terracotta); border: 0; border-radius: 8px; color: #fff; cursor: pointer; display: block; font: inherit; padding: 12px 16px; text-align: center; text-decoration: none; width: 100%; }
    a.quiet { background: transparent; border: 1px solid var(--line); color: var(--ink); margin-top: 10px; }
    .notice { border-radius: 8px; margin: 0 0 14px; padding: 10px 12px; }
    .notice--info { background: #eef6f1; }
    .notice--warn { background: #f8ebe6; color: #7a2e24; }
    .muted { color: var(--muted); }
    .staff { color: var(--muted); display: block; font-size: .85rem; margin-top: 22px; text-align: center; }
    .staff a { color: var(--terracotta); }
    @media (max-width: 480px) { .grid2 { grid-template-columns: 1fr; } .steps { flex-wrap: wrap; } }
  </style>
</head>
<body>
  <div class="wrap">
    <?php echo bakery_sour_flour_logo_img('logo'); ?>
    <h1><?php bakery_te('sfb.starter_jar_heading'); ?></h1>
    <p class="lede"><?php
      echo $choice === 'kit'
          ? bakery_t('sfb.starter_jar_kit_lede')
          : bakery_t('sfb.starter_jar_lede');
    ?></p>
    <?php if (!$orderSummary): ?>
      <ol class="steps" aria-label="<?php bakery_te('sfb.starter_jar_steps_label'); ?>">
        <li class="on"><?php bakery_te('sfb.starter_jar_step_details'); ?></li>
        <li<?php echo !$signedIn ? ' class="on"' : ''; ?>><?php bakery_te('sfb.starter_jar_step_account'); ?></li>
        <li><?php bakery_te('sfb.starter_jar_step_pay'); ?></li>
      </ol>
    <?php endif; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>

    <?php if ($orderSummary): ?>
      <div class="card">
        <div class="card-body">
          <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.starter_jar_thanks'); ?></p>
          <p><strong><?php echo htmlspecialchars((string)$orderSummary['offering_title_snapshot'], ENT_QUOTES, 'UTF-8'); ?></strong>
            · $<?php echo number_format(((int)$orderSummary['price_cents_snapshot']) / 100, 2); ?></p>
          <p><?php
            if ((string)$orderSummary['fulfillment'] === 'pickup') {
                echo htmlspecialchars(bakery_t('sfb.starter_jar_summary_pickup', [
                    'day' => bakery_t('sfb.starter_jar_day_' . (string)$orderSummary['pickup_day']),
                    'name' => (string)$orderSummary['contact_name'],
                ]), ENT_QUOTES, 'UTF-8');
            } else {
                $addr = trim(implode(', ', array_filter([
                    (string)$orderSummary['ship_line1'],
                    (string)($orderSummary['ship_line2'] ?? ''),
                    trim((string)$orderSummary['ship_city'] . ', ' . (string)$orderSummary['ship_state'] . ' ' . (string)$orderSummary['ship_zip']),
                ])));
                echo htmlspecialchars(bakery_t('sfb.starter_jar_summary_ship', [
                    'name' => (string)$orderSummary['contact_name'],
                    'address' => $addr,
                ]), ENT_QUOTES, 'UTF-8');
            }
          ?></p>
          <p class="muted"><?php
            $status = (string)$orderSummary['purchase_status'];
            bakery_te('sfb.purchase_status_' . $status);
            if ($status === 'pending') {
                echo ' — ';
                bakery_te('sfb.starter_jar_pending_copy');
            } elseif ($status === 'paid') {
                echo ' — ';
                $pack = (string)($orderSummary['pack_kind'] ?? 'jar');
                bakery_te($pack === 'first_loaf_kit' ? 'sfb.starter_jar_kit_paid_copy' : 'sfb.starter_jar_paid_copy');
            } elseif ($status === 'intent') {
                echo ' — ';
                bakery_te('sfb.pay_intent_saved');
            }
          ?></p>
          <?php if ($customerId > 0): ?>
            <a class="btn quiet" href="<?php echo htmlspecialchars(BASE_URL . 'sfb_dashboard.php', ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.starter_jar_go_home'); ?></a>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <?php if ($resumeDraft && $ready): ?>
      <div class="card">
        <div class="card-body">
          <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.starter_jar_resume_copy'); ?></p>
          <form method="post">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="resume">
            <button type="submit"><?php bakery_te('sfb.starter_jar_cta_pay'); ?></button>
          </form>
        </div>
      </div>
      <?php endif; ?>
      <div class="card">
        <div class="card-body">
          <?php if (!$ready): ?>
            <p class="muted"><?php bakery_te('sfb.starter_jar_unavailable'); ?></p>
          <?php else: ?>
            <form method="post" id="starterJarForm">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="request">

              <div class="prices" role="radiogroup" aria-label="<?php bakery_te('sfb.starter_jar_how'); ?>">
                <label class="price-opt">
                  <input type="radio" name="fulfillment" value="pickup"<?php echo $choice === 'pickup' ? ' checked' : ''; ?>>
                  <strong><?php bakery_te('sfb.starter_jar_pickup_price'); ?></strong>
                  <span><?php bakery_te('sfb.starter_jar_pickup_hint'); ?></span>
                </label>
                <label class="price-opt">
                  <input type="radio" name="fulfillment" value="ship"<?php echo $choice === 'ship' ? ' checked' : ''; ?>>
                  <strong><?php bakery_te('sfb.starter_jar_ship_price'); ?></strong>
                  <span><?php bakery_te('sfb.starter_jar_ship_hint'); ?></span>
                </label>
                <?php if ($kitReady): ?>
                <label class="price-opt">
                  <input type="radio" name="fulfillment" value="kit"<?php echo $choice === 'kit' ? ' checked' : ''; ?>>
                  <strong><?php bakery_te('sfb.starter_jar_kit_price'); ?></strong>
                  <span><?php bakery_te('sfb.starter_jar_kit_hint'); ?></span>
                </label>
                <?php endif; ?>
              </div>

              <label class="field"><span><?php bakery_te('sfb.starter_jar_name'); ?></span>
                <input type="text" name="contact_name" required maxlength="120" value="<?php echo htmlspecialchars((string)($form['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              </label>

              <div class="pickup-fields" id="pickupFields"<?php echo $choice === 'ship' ? ' hidden' : ''; ?>>
                <label class="field"><span><?php bakery_te('sfb.starter_jar_pickup_day'); ?></span>
                  <select name="pickup_day">
                    <option value="tuesday"<?php echo ($form['pickup_day'] ?? '') === 'tuesday' ? ' selected' : ''; ?>><?php bakery_te('sfb.starter_jar_day_tuesday'); ?></option>
                    <option value="friday"<?php echo ($form['pickup_day'] ?? '') === 'friday' ? ' selected' : ''; ?>><?php bakery_te('sfb.starter_jar_day_friday'); ?></option>
                  </select>
                </label>
              </div>

              <div class="ship-fields" id="shipFields"<?php echo $choice === 'ship' ? '' : ' hidden'; ?>>
                <label class="field"><span><?php bakery_te('sfb.starter_jar_address1'); ?></span>
                  <input type="text" name="ship_line1" maxlength="150" value="<?php echo htmlspecialchars((string)($form['ship_line1'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label class="field"><span><?php bakery_te('sfb.starter_jar_address2'); ?></span>
                  <input type="text" name="ship_line2" maxlength="150" value="<?php echo htmlspecialchars((string)($form['ship_line2'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <div class="grid2">
                  <label class="field"><span><?php bakery_te('sfb.starter_jar_city'); ?></span>
                    <input type="text" name="ship_city" maxlength="80" value="<?php echo htmlspecialchars((string)($form['ship_city'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                  </label>
                  <label class="field"><span><?php bakery_te('sfb.starter_jar_state'); ?></span>
                    <input type="text" name="ship_state" maxlength="40" value="<?php echo htmlspecialchars((string)($form['ship_state'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                  </label>
                </div>
                <label class="field"><span><?php bakery_te('sfb.starter_jar_zip'); ?></span>
                  <input type="text" name="ship_zip" maxlength="20" value="<?php echo htmlspecialchars((string)($form['ship_zip'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </label>
              </div>

              <label class="field"><span><?php bakery_te('sfb.starter_jar_notes'); ?></span>
                <textarea name="notes" rows="2" maxlength="255"><?php echo htmlspecialchars((string)($form['notes'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
              </label>

              <button type="submit"><?php
                echo $signedIn
                    ? bakery_t('sfb.starter_jar_cta_pay')
                    : bakery_t('sfb.starter_jar_cta_account');
              ?></button>
              <p class="muted" style="margin:12px 0 0;font-size:.85rem;"><?php
                echo $signedIn
                    ? bakery_t('sfb.starter_jar_pay_copy')
                    : bakery_t('sfb.starter_jar_account_copy');
              ?></p>
              <p class="muted" style="margin:10px 0 0;font-size:.85rem;"><a href="https://bakery.sourflour.org/breadeducation/start/first-loaf-shopping.html" target="_blank" rel="noopener"><?php bakery_te('sfb.starter_jar_gear_list'); ?></a></p>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$signedIn): ?>
        <a class="btn quiet" href="<?php echo htmlspecialchars(BASE_URL . 'customer_login.php?next=' . rawurlencode(bakery_sfb_starter_jar_return_path('continue=1')), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.starter_jar_signin'); ?></a>
      <?php endif; ?>
    <?php endif; ?>

    <a class="staff" href="<?php echo htmlspecialchars(BASE_URL . ($signedIn ? 'sfb_dashboard.php' : 'sfb_join.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php
      echo $signedIn ? bakery_t('sfb.starter_jar_go_home') : bakery_t('sfb.starter_jar_back_join');
    ?></a>
  </div>
  <script>
    (function () {
      var form = document.getElementById('starterJarForm');
      if (!form) return;
      var pickup = document.getElementById('pickupFields');
      var ship = document.getElementById('shipFields');
      function sync() {
        var mode = (form.querySelector('input[name="fulfillment"]:checked') || {}).value || 'pickup';
        var shipping = mode === 'ship';
        if (pickup) pickup.hidden = shipping;
        if (ship) ship.hidden = !shipping;
        form.querySelectorAll('#shipFields input').forEach(function (el) {
          if (el.name === 'ship_line2') return;
          el.required = shipping;
        });
        var day = form.querySelector('select[name="pickup_day"]');
        if (day) day.required = !shipping;
      }
      form.querySelectorAll('input[name="fulfillment"]').forEach(function (el) {
        el.addEventListener('change', sync);
      });
      sync();
    })();
  </script>
</body>
</html>
