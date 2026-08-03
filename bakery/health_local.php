<?php
/**
 * Local health check page — no DB required.
 * Confirms APP_ENV, safety rails, and local banner constants.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Local Health — <?php echo htmlspecialchars(SITE_NAME); ?></title>
  <style>
    body { font-family: Segoe UI, sans-serif; margin: 2rem; background: #f8f9fa; color: #222; }
    .ok { color: #155724; background: #d4edda; padding: 1rem; border-radius: 6px; }
    .banner { background: #856404; color: #fff3cd; padding: 0.75rem; font-weight: 700; margin-bottom: 1rem; }
    code { background: #eee; padding: 0.1rem 0.3rem; }
  </style>
</head>
<body>
<?php if (IS_LOCAL && defined('USE_PROD_DB') && USE_PROD_DB): ?>
<div class="banner" style="background:#721c24;color:#f8d7da;">LOCAL APP → LIVE PRODUCTION DB — <?php echo htmlspecialchars(DB_NAME); ?> @ <?php echo htmlspecialchars(DB_HOST); ?></div>
<?php elseif (IS_LOCAL): ?>
<div class="banner">LOCAL ENVIRONMENT — <?php echo htmlspecialchars(DB_NAME); ?> @ <?php echo htmlspecialchars(DB_HOST); ?></div>
<?php endif; ?>
<div class="ok">
  <p><strong>Config loaded successfully.</strong></p>
  <ul>
    <li>APP_ENV: <code><?php echo htmlspecialchars(APP_ENV); ?></code></li>
    <li>IS_LOCAL: <code><?php echo IS_LOCAL ? 'true' : 'false'; ?></code></li>
    <li>USE_PROD_DB: <code><?php echo (defined('USE_PROD_DB') && USE_PROD_DB) ? 'true' : 'false'; ?></code></li>
    <li>DB_NAME: <code><?php echo htmlspecialchars(DB_NAME); ?></code></li>
    <li>DB_HOST: <code><?php echo htmlspecialchars(DB_HOST); ?></code></li>
    <li>MAIL_DRIVER: <code><?php echo htmlspecialchars(MAIL_DRIVER); ?></code></li>
    <li>MAPS_ENABLED: <code><?php echo MAPS_ENABLED ? 'true' : 'false'; ?></code></li>
  </ul>
  <p>Switch DB: <code>php scripts/switch_db.php local|prod</code>. Local MariaDB setup: <code>scripts/setup_local_db.php</code>.</p>
</div>
</body>
</html>
