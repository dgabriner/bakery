<?php
/** Staff page for generating a customer-specific QR portal invitation. */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_qr_login.php';

bakery_require_role(['administrator', 'driver', 'driver_assistant']);
$user = bakery_current_user();
$isDriver = bakery_is_driver_route_role($user['role_slug'] ?? '');
$customers = [];
$selectedCustomer = null;
$invite = null;
$error = '';

if ($isDriver) {
    $selectedCustomer = bakery_customer_qr_current_stop($db, bakery_route_worker_driver_id($db, $user, date('Y-m-d')));
} else {
    $customers = $db->query('SELECT id, name, address, portal_enabled, portal_code FROM customers WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerId = $isDriver ? (int)($selectedCustomer['id'] ?? 0) : (int)($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        $error = $isDriver ? 'There is no active stop to create a login for.' : 'Choose a customer first.';
    } else {
        try {
            if (!$isDriver) {
                foreach ($customers as $customer) {
                    if ((int)$customer['id'] === $customerId) { $selectedCustomer = $customer; break; }
                }
                if (!$selectedCustomer) throw new RuntimeException('Customer not found.');
            }
            $invite = bakery_customer_qr_create_invite($db, $customerId, $user);
        } catch (Throwable $e) {
            error_log('Generate customer QR error: ' . $e->getMessage());
            $error = $e->getMessage() === 'Customer QR Login is not installed yet.'
                ? 'Customer QR Login needs the latest database update before it can be used.'
                : 'We could not create the QR login. Please try again.';
        }
    }
}

$page_title = 'Customer QR Login';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/qr_login.css'); ?>">
<main class="qr-staff-page">
  <header class="qr-page-header">
    <p class="qr-eyebrow"><?php echo $isDriver ? 'Current stop' : 'Customer access'; ?></p>
    <h1>Generate QR Login</h1>
    <p>Let a customer securely create or use their 4-digit portal code.</p>
  </header>
  <?php if ($error): ?><div class="qr-staff-alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

  <?php if ($invite): $invitePath = BASE_URL . 'customer_qr_login.php?token=' . rawurlencode($invite['token']); ?>
    <section class="qr-result-card" aria-labelledby="qrResultTitle">
      <div class="qr-result-copy">
        <p class="qr-eyebrow">Ready to scan</p>
        <h2 id="qrResultTitle"><?php echo htmlspecialchars($invite['customer_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <p>Ask the customer to scan this with their phone camera. It expires in <?php echo (int)$invite['expires_minutes']; ?> minutes and can be used once.</p>
      </div>
      <div class="qr-code-shell">
        <div id="customerQrCode" class="qr-code-image" data-invite-path="<?php echo htmlspecialchars($invitePath, ENT_QUOTES, 'UTF-8'); ?>" aria-label="QR login for <?php echo htmlspecialchars($invite['customer_name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
        <p id="qrLoadMessage" class="qr-load-message">Preparing QR code...</p>
      </div>
      <div class="qr-result-actions">
        <button type="button" class="qr-secondary-button" data-copy-qr-link>Copy login link</button>
        <a class="qr-secondary-button" data-open-qr-link href="<?php echo htmlspecialchars($invitePath, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Open login</a>
      </div>
      <p class="qr-copy-status" role="status" aria-live="polite"></p>
    </section>
  <?php else: ?>
    <section class="qr-generator-card">
      <?php if ($isDriver): ?>
        <?php if ($selectedCustomer): ?>
          <div class="qr-customer-summary">
            <span class="qr-customer-avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(substr($selectedCustomer['name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
            <div><strong><?php echo htmlspecialchars($selectedCustomer['name'], ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars($selectedCustomer['address'] ?: 'Current assigned customer', ENT_QUOTES, 'UTF-8'); ?></span></div>
          </div>
          <form method="post"><?php echo bakery_csrf_field(); ?><button type="submit" class="qr-primary-button">Generate QR for this customer</button></form>
        <?php else: ?>
          <div class="qr-empty-state"><strong>No active stop right now</strong><p>Open this page when you are working at your next assigned customer.</p></div>
        <?php endif; ?>
      <?php else: ?>
        <form method="post" class="qr-admin-form">
          <?php echo bakery_csrf_field(); ?>
          <label for="customerSearch">Select customer</label>
          <input type="search" id="customerSearch" placeholder="Search by name or address" autocomplete="off" aria-controls="customerPicker">
          <select id="customerPicker" name="customer_id" required size="8" aria-label="Customer">
            <?php foreach ($customers as $customer): ?>
              <option value="<?php echo (int)$customer['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower($customer['name'] . ' ' . ($customer['address'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($customer['name'] . (!empty($customer['portal_code']) ? ' - Has login' : ' - New login'), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
          <p class="qr-picker-count"><span data-customer-count><?php echo count($customers); ?></span> customers</p>
          <button type="submit" class="qr-primary-button">Generate QR Login</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<?php if ($invite): ?>
<script src="<?php echo bakery_asset_href('assets/js/qrcode.min.js'); ?>" integrity="sha384-3zSEDfvllQohrq0PHL1fOXJuC/jSOO34H46t6UQfobFOmxE5BpjjaIJY5F2/bMnU"></script>
<script>
(function () {
  var holder = document.getElementById('customerQrCode');
  var message = document.getElementById('qrLoadMessage');
  if (!holder) return;
  var absoluteUrl = new URL(holder.dataset.invitePath, window.location.href).href;
  var openLink = document.querySelector('[data-open-qr-link]');
  if (openLink) openLink.href = absoluteUrl;
  if (window.QRCode) {
    new QRCode(holder, { text: absoluteUrl, width: 240, height: 240, colorDark: '#183c38', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    if (message) message.hidden = true;
  } else if (message) message.textContent = 'QR code could not load. Use Copy login link instead.';
  var copyButton = document.querySelector('[data-copy-qr-link]');
  var status = document.querySelector('.qr-copy-status');
  if (copyButton) copyButton.addEventListener('click', function () {
    navigator.clipboard.writeText(absoluteUrl).then(function () {
      if (status) status.textContent = 'Login link copied.';
    }, function () { if (status) status.textContent = absoluteUrl; });
  });
}());
</script>
<?php else: ?>
<script>
(function () {
  var search = document.getElementById('customerSearch');
  var picker = document.getElementById('customerPicker');
  var count = document.querySelector('[data-customer-count]');
  if (!search || !picker) return;
  var options = Array.prototype.slice.call(picker.options);
  search.addEventListener('input', function () {
    var query = search.value.trim().toLowerCase();
    var visible = 0;
    options.forEach(function (option) {
      var match = !query || option.dataset.search.indexOf(query) !== -1;
      option.hidden = !match;
      if (match) visible++;
    });
    if (count) count.textContent = visible;
  });
}());
</script>
<?php endif; ?>
