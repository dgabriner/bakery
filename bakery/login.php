<?php
/**
 * Login page: 4-digit code sign-in (public exception to auth gate).
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → home
if ($existingUser = bakery_current_user()) {
    header('Location: ' . BASE_URL . bakery_role_home($existingUser['role_slug'] ?? ''));
    exit;
}

$error = '';
$nextInput = $_GET['next'] ?? (BASE_URL . 'index.php');
$next = is_string($nextInput) ? $nextInput : (BASE_URL . 'index.php');
// Prevent open redirects
if (strpos($next, '/') !== 0) {
    $next = BASE_URL . 'index.php';
}
if (function_exists('bakery_served_at_app_root') && bakery_served_at_app_root() && strpos($next, '/bakery/') === 0) {
    $next = substr($next, 7) ?: '/';
}

$postedCode = $_POST['code'] ?? '';
$code = is_string($postedCode) ? $postedCode : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = bakery_t('common.error_csrf');
    } else {
        try {
            $db = check_mysql_connection();
            if (bakery_login($db, $code)) {
                $destInput = $_POST['next'] ?? $next;
                $dest = is_string($destInput) ? $destInput : (BASE_URL . 'index.php');
                if (strpos($dest, '/') !== 0) {
                    $dest = BASE_URL . 'index.php';
                }
                if (function_exists('bakery_served_at_app_root') && bakery_served_at_app_root() && strpos($dest, '/bakery/') === 0) {
                    $dest = substr($dest, 7) ?: '/';
                }
                // Focused workspaces land on their dedicated home, not the ops dashboard.
                $user = bakery_current_user();
                $role = $user['role_slug'] ?? '';
                if ($user && bakery_role_uses_dedicated_home($role)) {
                    $dest = BASE_URL . bakery_role_home($role);
                }
                header('Location: ' . $dest);
                exit;
            }
            $error = bakery_t('login.error_wrong');
            bakery_login_audit_record_failure($db, 'staff', 'Staff login', $code);
            // Slow down brute force slightly
            usleep(300000);
        } catch (Exception $e) {
            $error = bakery_t('login.error_unavailable');
            error_log('Login error: ' . $e->getMessage());
        }
    }
}

$page_title = bakery_t('login.title');
$currentLocale = bakery_locale();
$loginUi = [];
foreach (['product', 'show', 'hide', 'show_label', 'hide_label', 'sign_in'] as $loginUiKey) {
    $loginUi[$loginUiKey] = bakery_t('login.' . $loginUiKey);
}
$codeDescribedBy = $error !== '' ? ' aria-invalid="true" aria-describedby="login-error"' : '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($loginUi['product'] . ': ' . $page_title, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php require __DIR__ . '/includes/client_refresh.php'; ?>
  <?php require_once __DIR__ . '/includes/google_analytics.php'; ?>
  <link rel="stylesheet" href="<?php echo bakery_asset_href('css/tokens.css'); ?>">
  <link rel="stylesheet" href="<?php echo bakery_asset_href('css/login.css'); ?>">
</head>
<body class="login-body">
<?php if (defined('IS_STAGING') && IS_STAGING): ?>
  <div class="local-env-banner staging-env-banner" role="alert">
    <?php echo htmlspecialchars(bakery_t('env.staging', ['db' => defined('DB_NAME') ? DB_NAME : 'unknown', 'host' => defined('DB_HOST') ? DB_HOST : 'unknown'])); ?>
  </div>
<?php endif; ?>
  <main class="login-shell">
    <header class="login-brand">
      <div class="login-brand__logos">
        <img class="login-brand__partner" src="<?php echo bakery_asset_href('assets/logos/la-victoria.png'); ?>" alt="La Victoria San Francisco" width="456" height="178" fetchpriority="high" decoding="async">
        <img class="login-brand__mark" src="<?php echo bakery_asset_href('assets/logos/sour-flour-full.png'); ?>" alt="Sour Flour" width="900" height="622" decoding="async">
      </div>
      <h1 class="login-brand__name"><?php echo htmlspecialchars($loginUi['product'], ENT_QUOTES, 'UTF-8'); ?></h1>
    </header>
    <div class="login-card">
      <?php if ($error !== ''): ?>
        <p class="login-error" id="login-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
      <?php endif; ?>
      <form method="post" action="">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="next" value="<?php echo htmlspecialchars($next, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="login-field">
          <label for="code"><?php bakery_te('login.label'); ?></label>
          <div class="login-control">
            <input class="login-code" type="password" id="code" name="code" required
                   inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4"
                   autocomplete="current-password" autocapitalize="off" autocorrect="off" spellcheck="false"
                   enterkeyhint="done"<?php echo $codeDescribedBy; ?>
                   value="<?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="button" class="login-reveal" id="login-reveal" aria-controls="code" aria-pressed="false"
                    aria-label="<?php echo htmlspecialchars($loginUi['show_label'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-show-label="<?php echo htmlspecialchars($loginUi['show_label'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-hide-label="<?php echo htmlspecialchars($loginUi['hide_label'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-show-text="<?php echo htmlspecialchars($loginUi['show'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-hide-text="<?php echo htmlspecialchars($loginUi['hide'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($loginUi['show'], ENT_QUOTES, 'UTF-8'); ?></button>
          </div>
        </div>
        <button type="submit" class="login-submit"><?php echo htmlspecialchars($loginUi['sign_in'], ENT_QUOTES, 'UTF-8'); ?></button>
      </form>
      <div class="login-lang"><?php $langSwitchVariant = 'inline'; require __DIR__ . '/includes/language_switch.php'; ?></div>
      <p class="login-portal"><a href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>customer_login.php"><?php bakery_te('login.customer_portal_link'); ?></a></p>
    </div>
  </main>
  <script>
    (function () {
      var input = document.getElementById('code');
      var reveal = document.getElementById('login-reveal');
      var form = input ? input.form : null;
      if (!input || !form) return;

      if (reveal) {
        reveal.addEventListener('click', function () {
          var show = input.type === 'password';
          var value = input.value;
          input.type = show ? 'text' : 'password';
          if (input.value !== value) {
            input.value = value;
          }
          reveal.setAttribute('aria-pressed', show ? 'true' : 'false');
          reveal.setAttribute('aria-label', show ? reveal.getAttribute('data-hide-label') : reveal.getAttribute('data-show-label'));
          reveal.textContent = show ? reveal.getAttribute('data-hide-text') : reveal.getAttribute('data-show-text');
          try {
            input.focus({ preventScroll: true });
          } catch (focusError) {
            input.focus();
          }
        });
      }

      try {
        input.focus({ preventScroll: true });
      } catch (focusError) {
        input.focus();
      }

      var submitting = false;
      input.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 4);
        if (this.value.length === 4 && !submitting && form.checkValidity()) {
          submitting = true;
          if (form.requestSubmit) {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }
      });
    })();
  </script>
</body>
</html>
