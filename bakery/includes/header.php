<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// Get current page name to determine if tracking should be enabled
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$authUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <?php if ($authUser && function_exists('bakery_csrf_token')): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars(BASE_URL); ?>includes/csrf.js"></script>
    <?php endif; ?>
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="/bakery/css/styles.css">
    <?php if (defined('IS_LOCAL') && IS_LOCAL): ?>
    <style>
      .local-env-banner {
        position: sticky;
        top: 0;
        z-index: 10000;
        background: #856404;
        color: #fff3cd;
        text-align: center;
        padding: 8px 12px;
        font-family: Segoe UI, sans-serif;
        font-size: 14px;
        font-weight: 600;
        border-bottom: 2px solid #533f03;
      }
      .auth-bar {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        align-items: center;
        padding: 6px 16px;
        background: #2c3e50;
        color: #ecf0f1;
        font-size: 13px;
        font-family: Segoe UI, sans-serif;
      }
      .auth-bar form { margin: 0; }
      .auth-bar button {
        background: #e74c3c;
        color: #fff;
        border: 0;
        padding: 4px 10px;
        border-radius: 3px;
        cursor: pointer;
      }
    </style>
    <?php else: ?>
    <style>
      .auth-bar {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        align-items: center;
        padding: 6px 16px;
        background: #2c3e50;
        color: #ecf0f1;
        font-size: 13px;
        font-family: Segoe UI, sans-serif;
      }
      .auth-bar form { margin: 0; }
      .auth-bar button {
        background: #e74c3c;
        color: #fff;
        border: 0;
        padding: 4px 10px;
        border-radius: 3px;
        cursor: pointer;
      }
    </style>
    <?php endif; ?>

    <?php if ($current_page === 'driver'): ?>
    <!-- GPS Tracking Script (Driver Page Only) -->
    <script src="/bakery/includes/global_tracking.js"></script>
    <?php endif; ?>
</head>
<body>
<?php if (defined('IS_LOCAL') && IS_LOCAL): ?>
<div class="local-env-banner" role="status">
  LOCAL ENVIRONMENT — database <?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'unknown'); ?> @ <?php echo htmlspecialchars(defined('DB_HOST') ? DB_HOST : 'unknown'); ?> — not production
</div>
<?php endif; ?>
<?php if ($authUser): ?>
<div class="auth-bar">
  <span><?php echo htmlspecialchars($authUser['display_name']); ?> (<?php echo htmlspecialchars($authUser['role_slug']); ?>)</span>
  <form method="post" action="<?php echo htmlspecialchars(BASE_URL); ?>logout.php">
    <?php echo bakery_csrf_field(); ?>
    <button type="submit">Log out</button>
  </form>
</div>
<?php endif; ?>
