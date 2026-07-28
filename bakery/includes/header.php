<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// Get current page name to determine if tracking should be enabled
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
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
