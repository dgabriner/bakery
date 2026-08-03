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
    $existingHome = $existingRole === 'driver'
        ? 'driver.php'
        : ($existingRole === 'baker'
            ? ('production.php?date=' . urlencode(date('Y-m-d', strtotime('+1 day'))))
            : 'index.php');
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
        $error = 'Invalid security token. Please try again.';
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
                if ($user && $user['role_slug'] === 'driver') {
                    $dest = BASE_URL . 'driver.php';
                } elseif ($user && $user['role_slug'] === 'baker') {
                    $dest = BASE_URL . 'production.php?date=' . urlencode(date('Y-m-d', strtotime('+1 day')));
                }
                header('Location: ' . $dest);
                exit;
            }
            $error = 'Código incorrecto. Inténtalo de nuevo.';
            // Slow down brute force slightly
            usleep(300000);
        } catch (Exception $e) {
            $error = 'No se puede iniciar sesión en este momento.';
            error_log('Login error: ' . $e->getMessage());
        }
    }
}

$page_title = 'Código para entrar';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Código para entrar</title>
  <style>
    :root { color-scheme: light; --ink: #33251f; --cream: #fffdf8; --terracotta: #b75c3f; }
    * { box-sizing: border-box; }
    body { align-items: center; background: var(--cream); color: var(--ink); display: flex; font-family: Georgia, 'Times New Roman', serif; justify-content: center; margin: 0; min-height: 100vh; min-height: 100svh; padding: clamp(20px, 5vh, 56px) clamp(20px, 5vw, 72px); }
    .wrap { text-align: center; width: min(100%, 760px); }
    .brands { align-items: center; display: flex; flex-direction: column; gap: clamp(22px, 4vh, 34px); justify-content: center; margin: 0 auto clamp(36px, 7vh, 58px); }
    .la-victoria-logo { display: block; height: auto; max-width: 400px; width: min(78vw, 400px); }
    .sour-flour-logo { display: block; height: auto; max-width: 250px; width: min(46vw, 250px); mix-blend-mode: multiply; }
    label { display: block; font-size: .94rem; margin-bottom: 14px; }
    input[type=tel] { background: transparent; border: 0; border-bottom: 2px solid #c9b9a8; border-radius: 0; color: var(--ink); display: block; font-family: inherit; font-size: 2.5rem; letter-spacing: .55em; margin: 0 auto; max-width: 240px; outline: none; padding: 4px 0 9px 18px; text-align: center; width: 100%; }
    input[type=tel]:focus { border-bottom-color: var(--terracotta); }
    .error { color: #9b332c; font-size: .9rem; margin: 0 0 18px; }
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
  <div class="wrap">
    <div class="brands" aria-label="La Victoria y Sour Flour">
      <img class="la-victoria-logo" src="assets/logos/la-victoria.png" alt="La Victoria San Francisco">
      <img class="sour-flour-logo" src="assets/logos/sour-flour-full.png?v=20260802" alt="Sour Flour">
    </div>
    <?php if ($error): ?>
      <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post" action="">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
      <label for="code">Código para entrar</label>
      <input type="tel" id="code" name="code" required
             inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4"
             autocomplete="one-time-code" autofocus
             value="<?php echo htmlspecialchars($_POST['code'] ?? ''); ?>">
    </form>
  </div>
  <script>
    (function () {
      var input = document.getElementById('code');
      var form = input ? input.form : null;
      if (!input || !form) return;

      var isMobile = window.matchMedia('(max-width: 560px)').matches;
      var keepMobileAtTop = function () {
        if (!isMobile) return;
        window.scrollTo(0, 0);
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;
      };

      input.focus({ preventScroll: true });
      keepMobileAtTop();
      requestAnimationFrame(keepMobileAtTop);
      setTimeout(keepMobileAtTop, 100);
      setTimeout(keepMobileAtTop, 350);
      if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', keepMobileAtTop);
        window.visualViewport.addEventListener('scroll', keepMobileAtTop);
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
