<?php
/**
 * Customer portal sign-in and instant account creation (English).
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_portal.php';

if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}

$error = '';
$next = $_GET['next'] ?? (BASE_URL . 'customer_portal.php');
if (strpos($next, '/') !== 0) {
    $next = BASE_URL . 'customer_portal.php';
}

$nextPath = (string)(parse_url($next, PHP_URL_PATH) ?: '');
$nextBase = basename($nextPath);
$starterReturn = $nextBase === 'starter.php';
$keepNextBases = ['starter.php', 'sfb_offerings.php'];

if (bakery_portal_customer_id() > 0) {
    header('Location: ' . $next);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = bakery_t('common.error_csrf');
    } else {
        $mode = ($_POST['mode'] ?? 'signin') === 'create' ? 'create' : 'signin';
        $phone = $_POST['phone'] ?? '';
        $code = $_POST['code'] ?? '';
        try {
            $result = $mode === 'create'
                ? bakery_portal_sign_in_or_register($db, $phone, $code)
                : ['success' => bakery_portal_login_by_code($db, $code), 'first_batch' => false];
            if (!empty($result['success'])) {
                // An education invite claims exactly one new baker.
                if (!empty($result['first_batch']) && function_exists('bakery_sfb_invite_lookup')) {
                    require_once __DIR__ . '/includes/sf_baker.php';
                    $claimedInvite = bakery_sfb_invite_lookup($db, (string)($_POST['invite'] ?? ''));
                    if ($claimedInvite) {
                        bakery_sfb_mark_invite_used($db, (int)$claimedInvite['id'], (int)$result['customer']['id']);
                    }
                }
                $dest = (string)($_POST['next'] ?? $next);
                if (strpos($dest, '/') !== 0) {
                    $dest = BASE_URL . 'sfb_batches.php?welcome=1';
                }
                // A new account starts at first batch unless returning to a
                // paid funnel (starter jar / workshops) that already holds details.
                if (!empty($result['first_batch'])) {
                    $destPath = (string)(parse_url($dest, PHP_URL_PATH) ?: '');
                    $destBase = basename($destPath);
                    if (!in_array($destBase, $keepNextBases, true)) {
                        $dest = BASE_URL . 'sfb_batches.php?welcome=1';
                    }
                }
                header('Location: ' . $dest);
                exit;
            }
            $error = (string)($result['error'] ?? 'That 4-digit code does not match an account.');
            $failurePrincipal = $mode === 'create' ? 'Customer portal signup' : 'Customer portal login';
            bakery_login_audit_record_failure($db, 'customer', $failurePrincipal, (string)$code);
            usleep(300000);
        } catch (Exception $e) {
            $error = bakery_t('portal.error_unavailable');
            error_log('Customer login error: ' . $e->getMessage());
        }
    }
}

$page_title = bakery_t('portal.title_sign_in');
$createMode = (($_POST['mode'] ?? ($_GET['create'] ?? '')) === 'create');
$inviteQ = (string)($_GET['invite'] ?? '');
$toggleHref = '?' . http_build_query(array_filter([
    'create' => $createMode ? null : '1',
    'next' => $next,
    'invite' => $inviteQ !== '' ? $inviteQ : null,
]));

if ($starterReturn && $createMode) {
    $heading = bakery_t('sfb.starter_jar_login_heading');
    $subtitleCreate = bakery_t('sfb.starter_jar_login_create_copy');
    $subtitleSignin = bakery_t('sfb.starter_jar_login_signin_copy');
    $submitCreate = bakery_t('sfb.starter_jar_login_create_cta');
    $submitSignin = bakery_t('sfb.starter_jar_login_signin_cta');
} elseif ($starterReturn) {
    $heading = bakery_t('sfb.starter_jar_login_heading');
    $subtitleCreate = bakery_t('sfb.starter_jar_login_create_copy');
    $subtitleSignin = bakery_t('sfb.starter_jar_login_signin_copy');
    $submitCreate = bakery_t('sfb.starter_jar_login_create_cta');
    $submitSignin = bakery_t('sfb.starter_jar_login_signin_cta');
} else {
    $heading = bakery_t('portal.heading');
    $subtitleCreate = 'Use your mobile number and choose a unique 4-digit code. You can add your name and email later.';
    $subtitleSignin = 'Enter your 4-digit code to continue.';
    $submitCreate = 'Create account & start my first batch';
    $submitSignin = 'Sign in';
}
$subtitle = $createMode ? $subtitleCreate : $subtitleSignin;
$submitLabel = $createMode ? $submitCreate : $submitSignin;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <?php require __DIR__ . '/includes/client_refresh.php'; ?>
  <script src="<?php echo bakery_asset_href('includes/csrf.js'); ?>"></script>
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <style>
    :root { color-scheme: light; --ink: #1c2a26; --cream: #fffaf2; --terracotta: #c7783a; --muted: #6b7d78; }
    * { box-sizing: border-box; }
    body { align-items: center; background: var(--cream); color: var(--ink); display: flex; font-family: Georgia, 'Times New Roman', serif; justify-content: center; margin: 0; min-height: 100vh; min-height: 100svh; padding: 24px; }
    .wrap { max-width: 420px; text-align: center; width: 100%; }
    .logo { display: block; height: auto; margin: 0 auto 28px; max-width: 220px; mix-blend-mode: multiply; width: 60vw; }
    h1 { font-size: 1.35rem; font-weight: normal; margin: 0 0 8px; }
    .subtitle { color: var(--muted); font-size: .92rem; margin: 0 0 28px; }
    label { display: block; font-size: .88rem; margin-bottom: 8px; }
    input[type=tel] { background: transparent; border: 0; border-bottom: 2px solid #c9b9a8; border-radius: 0; color: var(--ink); display: block; font-family: inherit; margin: 0 auto 22px; max-width: 300px; outline: none; padding: 10px 8px; text-align: center; width: 100%; }
    input[type=tel]:focus { border-bottom-color: var(--terracotta); }
    #phone { font-size: 1.25rem; letter-spacing: .05em; }
    #code { font-size: 2rem; letter-spacing: .45em; max-width: 220px; padding-left: 12px; }
    button { background: var(--terracotta); border: 0; border-radius: 8px; color: #fff; cursor: pointer; font: inherit; font-size: 1rem; padding: 12px 18px; width: 100%; }
    .privacy { color: var(--muted); font-size: .78rem; line-height: 1.4; margin: 18px auto 0; max-width: 320px; }
    .error { color: #9b332c; font-size: .9rem; margin: 0 0 18px; }
    .staff-link { color: var(--muted); display: block; font-size: .85rem; margin-top: 32px; }
    .staff-link a { color: var(--terracotta); }
  </style>
</head>
<body>
  <div class="wrap">
    <?php echo bakery_sour_flour_logo_img('logo'); ?>
    <h1><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="subtitle" id="signinCopy"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($error): ?>
      <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post" action="" id="customerLoginForm">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
      <input type="hidden" name="mode" id="mode" value="<?php echo $createMode ? 'create' : 'signin'; ?>">
      <input type="hidden" name="invite" value="<?php echo htmlspecialchars($inviteQ, ENT_QUOTES, 'UTF-8'); ?>">
      <div id="phoneField"<?php echo $createMode ? '' : ' hidden'; ?>>
      <label for="phone">Mobile phone number</label>
      <input type="tel" id="phone" name="phone"<?php echo $createMode ? ' required' : ''; ?>
             inputmode="tel" autocomplete="tel-national" placeholder="(555) 555-5555"
             value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
      </div>
      <label for="code" id="codeLabel"><?php echo $createMode ? 'Choose a unique 4-digit code' : 'Your 4-digit code'; ?></label>
      <input type="tel" id="code" name="code" required
             inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4"
             autocomplete="current-password"
             value="<?php echo htmlspecialchars($_POST['code'] ?? ''); ?>">
      <button type="submit" id="submitButton"><?php echo htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8'); ?></button>
    </form>
    <p class="privacy"><a href="<?php echo htmlspecialchars($toggleHref, ENT_QUOTES, 'UTF-8'); ?>" id="createLink"><?php echo $createMode ? 'Already have an account? Sign in with your code.' : 'First time here? Create an account.'; ?></a></p>
    <a class="staff-link" href="<?php echo htmlspecialchars(BASE_URL); ?>login.php"><?php bakery_te('portal.staff_link'); ?></a>
  </div>
  <script>
    (function () {
      var phoneInput = document.getElementById('phone');
      var codeInput = document.getElementById('code');
      var form = codeInput ? codeInput.form : null;
      var modeInput = document.getElementById('mode');
      var phoneField = document.getElementById('phoneField');
      var createLink = document.getElementById('createLink');
      var submitButton = document.getElementById('submitButton');
      var codeLabel = document.getElementById('codeLabel');
      var signinCopy = document.getElementById('signinCopy');
      var copyCreate = <?php echo json_encode($subtitleCreate, JSON_UNESCAPED_UNICODE); ?>;
      var copySignin = <?php echo json_encode($subtitleSignin, JSON_UNESCAPED_UNICODE); ?>;
      var ctaCreate = <?php echo json_encode($submitCreate, JSON_UNESCAPED_UNICODE); ?>;
      var ctaSignin = <?php echo json_encode($submitSignin, JSON_UNESCAPED_UNICODE); ?>;
      var nextParam = <?php echo json_encode($next, JSON_UNESCAPED_UNICODE); ?>;
      var inviteParam = <?php echo json_encode($inviteQ, JSON_UNESCAPED_UNICODE); ?>;
      if (!codeInput || !form) return;
      codeInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 4);
      });
      if (phoneInput) phoneInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9+()\-\s]/g, '');
      });
      if (createLink) createLink.addEventListener('click', function (event) {
        event.preventDefault();
        var creating = modeInput.value !== 'create';
        modeInput.value = creating ? 'create' : 'signin';
        phoneField.hidden = !creating;
        phoneInput.required = creating;
        codeLabel.textContent = creating ? 'Choose a unique 4-digit code' : 'Your 4-digit code';
        submitButton.textContent = creating ? ctaCreate : ctaSignin;
        signinCopy.textContent = creating ? copyCreate : copySignin;
        createLink.textContent = creating ? 'Already have an account? Sign in with your code.' : 'First time here? Create an account.';
        var q = [];
        if (creating) q.push('create=1');
        if (nextParam) q.push('next=' + encodeURIComponent(nextParam));
        if (inviteParam) q.push('invite=' + encodeURIComponent(inviteParam));
        createLink.setAttribute('href', '?' + q.join('&'));
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', createLink.getAttribute('href'));
        }
        (creating ? phoneInput : codeInput).focus();
      });
    })();
  </script>
</body>
</html>
