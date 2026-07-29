<?php
/**
 * Login page — public exception to auth gate (Checkpoint 0D).
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → home
if (bakery_current_user()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
$next = $_GET['next'] ?? (BASE_URL . 'index.php');
// Prevent open redirects
if (strpos($next, '/') !== 0) {
    $next = BASE_URL . 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!bakery_verify_csrf()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        try {
            $db = check_mysql_connection();
            if (bakery_login($db, $email, $password)) {
                $dest = $_POST['next'] ?? $next;
                if (strpos($dest, '/') !== 0) {
                    $dest = BASE_URL . 'index.php';
                }
                // Drivers land on driver list
                $user = bakery_current_user();
                if ($user && $user['role_slug'] === 'driver') {
                    $dest = BASE_URL . 'driver_list.php';
                }
                header('Location: ' . $dest);
                exit;
            }
            $error = 'Invalid email or password.';
            // Slow down brute force slightly
            usleep(300000);
        } catch (Exception $e) {
            $error = 'Login unavailable. Check local database.';
            error_log('Login error: ' . $e->getMessage());
        }
    }
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - <?php echo htmlspecialchars(SITE_NAME); ?></title>
  <style>
    body { font-family: Segoe UI, sans-serif; background: #f0f2f5; margin: 0; }
    .wrap { max-width: 420px; margin: 10vh auto; background: #fff; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    h1 { margin-top: 0; color: #2c3e50; font-size: 1.4rem; }
    label { display: block; margin: 0.75rem 0 0.25rem; font-weight: 600; }
    input[type=email], input[type=password] { width: 100%; padding: 0.65rem; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
    button { margin-top: 1.25rem; width: 100%; padding: 0.75rem; background: #2c3e50; color: #fff; border: 0; border-radius: 4px; font-weight: 600; cursor: pointer; }
    .error { background: #f8d7da; color: #721c24; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem; }
    .local { background: #856404; color: #fff3cd; padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 1rem; }
    .hint { color: #666; font-size: 0.85rem; margin-top: 1rem; }
  </style>
</head>
<body>
  <div class="wrap">
    <?php if (IS_LOCAL): ?>
      <div class="local">LOCAL ENVIRONMENT — <?php echo htmlspecialchars(DB_NAME); ?></div>
    <?php endif; ?>
    <h1>Sign in</h1>
    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post" action="">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autocomplete="username"
             value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
      <button type="submit">Sign in</button>
    </form>
    <?php if (IS_LOCAL): ?>
      <p class="hint">Local seed users are documented in docs/LOCAL_SETUP.md. Emergency reset: scripts/reset_local_admin.php</p>
    <?php endif; ?>
  </div>
</body>
</html>
