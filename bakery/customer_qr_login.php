<?php
/** Customer-facing QR activation and sign-in. */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_qr_login.php';

if (function_exists('bakery_set_locale')) bakery_set_locale('en', false);
if (bakery_portal_customer_id() > 0) {
    header('Location: ' . BASE_URL . 'customer_portal.php');
    exit;
}

$token = strtolower(trim((string)($_GET['token'] ?? $_POST['token'] ?? '')));
$invite = bakery_customer_qr_find_invite($db, $token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = 'Please refresh the page and try again.';
    } elseif (!$invite) {
        $error = 'This QR login has expired. Ask for a new one.';
    } else {
        try {
            $result = bakery_customer_qr_complete($db, $token, (string)($_POST['code'] ?? ''), (string)($_POST['code_confirmation'] ?? ''));
            if (!empty($result['success'])) {
                header('Location: ' . BASE_URL . 'customer_portal.php');
                exit;
            }
            $error = (string)($result['error'] ?? 'We could not sign you in.');
            usleep(300000);
            $invite = bakery_customer_qr_find_invite($db, $token);
        } catch (Throwable $e) {
            error_log('Customer QR login error: ' . $e->getMessage());
            $error = 'Login is temporarily unavailable. Please try again.';
        }
    }
}

$hasExistingCode = $invite && bakery_normalize_login_code($invite['portal_code'] ?? '') !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Login - Sour Flour</title>
  <?php require __DIR__ . '/includes/client_refresh.php'; ?>
  <?php require_once __DIR__ . '/includes/google_analytics.php'; ?>
  <link rel="stylesheet" href="<?php echo bakery_asset_href('css/qr_login.css'); ?>">
</head>
<body class="qr-customer-body">
  <main class="qr-customer-card">
    <?php echo bakery_sour_flour_logo_img('qr-customer-logo'); ?>
    <?php if (!$invite): ?>
      <div class="qr-status-icon qr-status-icon--muted" aria-hidden="true">!</div>
      <p class="qr-eyebrow">Customer login</p>
      <h1>This link is no longer active</h1>
      <p class="qr-lede">Ask your driver or Sour Flour administrator to generate a new QR login.</p>
    <?php else: ?>
      <div class="qr-status-icon" aria-hidden="true">&#10003;</div>
      <p class="qr-eyebrow">Welcome, <?php echo htmlspecialchars($invite['name'], ENT_QUOTES, 'UTF-8'); ?></p>
      <h1><?php echo $hasExistingCode ? 'Enter your 4-digit code' : 'Create your 4-digit code'; ?></h1>
      <p class="qr-lede"><?php echo $hasExistingCode ? 'Use the code you already chose for your Sour Flour account.' : 'This will be your quick login whenever you return.'; ?></p>
      <?php if ($error): ?><div class="qr-alert" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <form method="post" class="qr-code-form">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
        <label for="code"><?php echo $hasExistingCode ? 'Your code' : 'New code'; ?></label>
        <input type="tel" id="code" name="code" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" autofocus aria-describedby="codeHint">
        <span id="codeHint" class="qr-field-hint">4 numbers</span>
        <?php if (!$hasExistingCode): ?>
          <label for="code_confirmation">Enter it again</label>
          <input type="tel" id="code_confirmation" name="code_confirmation" required inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
        <?php endif; ?>
        <button type="submit"><?php echo $hasExistingCode ? 'Log me in' : 'Create code & log me in'; ?></button>
      </form>
      <p class="qr-privacy-note">Keep this code private. Sour Flour staff will never ask you to share it.</p>
    <?php endif; ?>
  </main>
  <script>
  document.querySelectorAll('.qr-code-form input[type="tel"]').forEach(function (input) {
    input.addEventListener('input', function () { input.value = input.value.replace(/\D/g, '').slice(0, 4); });
  });
  </script>
</body>
</html>
