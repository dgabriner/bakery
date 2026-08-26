<?php
/**
 * Login page — 4-digit code sign-in (public exception to auth gate).
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → home
if ($existingUser = bakery_current_user()) {
    $existingRole = $existingUser['role_slug'] ?? '';
    $existingHome = bakery_is_driver_route_role($existingRole)
        ? 'driver.php'
        : ($existingRole === 'baker'
            ? ('production.php?date=' . urlencode(date('Y-m-d', strtotime('+1 day'))))
            : ($existingRole === 'manager' ? 'manager.php' : 'index.php'));
    header('Location: ' . BASE_URL . $existingHome);
    exit;
}

$error = '';
$next = $_GET['next'] ?? (BASE_URL . 'index.php');
// Prevent open redirects
if (strpos($next, '/') !== 0) {
    $next = BASE_URL . 'index.php';
}
if (function_exists('bakery_served_at_app_root') && bakery_served_at_app_root() && strpos($next, '/bakery/') === 0) {
    $next = substr($next, 7) ?: '/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = bakery_t('common.error_csrf');
    } else {
        $code = $_POST['code'] ?? '';
        try {
            $db = check_mysql_connection();
            if (bakery_login($db, $code)) {
                $dest = $_POST['next'] ?? $next;
                if (strpos($dest, '/') !== 0) {
                    $dest = BASE_URL . 'index.php';
                }
                if (function_exists('bakery_served_at_app_root') && bakery_served_at_app_root() && strpos($dest, '/bakery/') === 0) {
                    $dest = substr($dest, 7) ?: '/';
                }
                // Drivers land on their route; bakers on their daily production work.
                $user = bakery_current_user();
                if ($user && bakery_is_driver_route_role($user['role_slug'] ?? '')) {
                    $dest = BASE_URL . 'driver.php';
                } elseif ($user && $user['role_slug'] === 'baker') {
                    $dest = BASE_URL . 'production.php?date=' . urlencode(date('Y-m-d', strtotime('+1 day')));
                } elseif ($user && ($user['role_slug'] ?? '') === 'manager') {
                    $dest = BASE_URL . 'manager.php';
                }
                header('Location: ' . $dest);
                exit;
            }
            $error = bakery_t('login.error_wrong');
            bakery_login_audit_record_failure($db, 'staff', 'Staff login', (string)($_POST['code'] ?? ''));
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
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title); ?> — Sour Flour OS</title>
  <?php require __DIR__ . '/includes/client_refresh.php'; ?>
  <link rel="stylesheet" href="<?php echo bakery_asset_href('css/tokens.css'); ?>">
  <style>
    :root {
      color-scheme: light;
      --ink: var(--sf-text, #1c2a26);
      --cream: var(--sf-bg-elevated, #fffaf2);
      --terracotta: var(--sf-accent, #c7783a);
      --muted: var(--sf-text-muted, #6b7d78);
      --border: var(--sf-border, #ddd4c6);
    }
    * { box-sizing: border-box; }
    body { align-items: center; background-color: var(--cream); background-image: var(--sf-paper-wash, none); color: var(--ink); display: flex; flex-direction: column; font-family: Georgia, 'Times New Roman', serif; justify-content: center; margin: 0; min-height: 100vh; min-height: 100svh; padding: clamp(20px, 5vh, 56px) clamp(20px, 5vw, 72px); }
    .wrap { text-align: center; width: min(100%, 760px); }
    .brands { align-items: center; display: flex; flex-direction: column; gap: clamp(22px, 4vh, 34px); justify-content: center; margin: 0 auto clamp(36px, 7vh, 58px); }
    .la-victoria-logo { display: block; height: auto; max-width: 400px; width: min(78vw, 400px); }
    .sour-flour-logo { display: block; height: auto; max-width: 250px; width: min(46vw, 250px); mix-blend-mode: multiply; }
    label { display: block; font-size: .94rem; margin-bottom: 14px; }
    input[type=tel] { background: transparent; border: 0; border-bottom: 2px solid #c9b9a8; border-radius: 0; color: var(--ink); display: block; font-family: inherit; font-size: 2.5rem; letter-spacing: .55em; margin: 0 auto; max-width: 240px; outline: none; padding: 4px 0 9px 18px; text-align: center; width: 100%; }
    input[type=tel]:focus { border-bottom-color: var(--terracotta); }
    .error { color: #9b332c; font-size: .9rem; margin: 0 0 18px; }
    .lang-row { display: flex; justify-content: center; margin-bottom: 20px; }
    .bakery-lang-switch--inline { background: rgba(0,0,0,.04); border-radius: 999px; display: inline-flex; gap: 2px; padding: 3px; }
    .bakery-lang-switch--inline .bakery-lang-switch__btn { border-radius: 999px; color: var(--muted); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; font-size: .82rem; padding: 6px 14px; text-decoration: none; }
    .bakery-lang-switch--inline .bakery-lang-switch__btn--active { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.12); color: var(--ink); font-weight: 600; }
    .portal-link { margin: 28px 0 0; font-size: .85rem; }
    .portal-link a { color: var(--muted); }
    @media (max-width: 560px) {
      html { height: 100%; overflow: hidden; }
      body { align-items: flex-start; height: 100%; inset: 0; min-height: 0; overflow: hidden; padding: max(10px, env(safe-area-inset-top)) 18px 14px; position: fixed; width: 100%; }
      .wrap { width: 100%; }
      .brands { gap: 8px; margin-bottom: 16px; }
      .la-victoria-logo { width: min(80vw, 290px); }
      .sour-flour-logo { width: min(54vw, 210px); }
      label { margin-bottom: 6px; }
      input[type=tel] { font-size: 2rem; padding: 0 0 4px 12px; }
    }
    /* Keep the complete brand stack visible when the mobile keypad reduces the viewport. */
    @media (max-width: 560px) and (max-height: 600px) {
      body { align-items: flex-start; padding: 10px 18px 14px; }
      .brands { gap: 8px; margin-bottom: 16px; }
      .la-victoria-logo { width: min(80vw, 290px); }
      .sour-flour-logo { width: min(54vw, 210px); }
      label { margin-bottom: 6px; }
      input[type=tel] { font-size: 2rem; padding: 0 0 4px 12px; }
    }
  </style>
</head>
<body>
<?php if (defined('IS_STAGING') && IS_STAGING): ?>
  <div class="local-env-banner staging-env-banner" role="alert" style="position:fixed;top:0;left:0;right:0;z-index:10000;background:#8a1c3c;color:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:0.85rem;font-weight:700;padding:8px 12px;text-align:center;">
    <?php echo htmlspecialchars(bakery_t('env.staging', ['db' => defined('DB_NAME') ? DB_NAME : 'unknown', 'host' => defined('DB_HOST') ? DB_HOST : 'unknown'])); ?>
  </div>
<?php endif; ?>
  <div class="wrap">
    <div class="brands" aria-label="La Victoria y Sour Flour">
      <img class="la-victoria-logo" src="<?php echo bakery_asset_href('assets/logos/la-victoria.png'); ?>" alt="La Victoria San Francisco">
      <img class="sour-flour-logo" src="<?php echo bakery_asset_href('assets/logos/sour-flour-full.png'); ?>" alt="Sour Flour">
    </div>
    <?php if ($error): ?>
      <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <div class="lang-row"><?php $langSwitchVariant = 'inline'; require __DIR__ . '/includes/language_switch.php'; ?></div>
    <form method="post" action="">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
      <label for="code"><?php bakery_te('login.label'); ?></label>
      <input type="tel" id="code" name="code" required
             inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4"
             autocomplete="one-time-code" autofocus
             value="<?php echo htmlspecialchars($_POST['code'] ?? ''); ?>">
    </form>
    <p class="portal-link"><a href="<?php echo htmlspecialchars(BASE_URL); ?>customer_login.php"><?php bakery_te('login.customer_portal_link'); ?></a></p>
  </div>
  <script>
    (function () {
      var input = document.getElementById('code');
      var form = input ? input.form : null;
      if (!input || !form) return;

      // Mobile layout is already position:fixed + overflow:hidden. Do not force
      // document scroll on soft-keyboard resize — that shakes the screen.
      try {
        input.focus({ preventScroll: true });
      } catch (error) {
        input.focus();
      }
      var submitting = false;
      input.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 4);
        if (this.value.length === 4 && !submitting) {
          submitting = true;
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }
      });
    })();
  </script>
</body>
</html>
