<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// Get current page name to determine if tracking should be enabled
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$authUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$isBakerUser = $authUser && ($authUser['role_slug'] ?? '') === 'baker';
$showLocalDebugBanner = defined('IS_LOCAL') && IS_LOCAL && !$isBakerUser;
if (!function_exists('bakery_user_can_control_auto_push')) {
    $autoPushControlPath = __DIR__ . '/auto_push_control.php';
    if (is_file($autoPushControlPath)) {
        require_once $autoPushControlPath;
    }
}
$canControlAutoPush = function_exists('bakery_user_can_control_auto_push')
    && bakery_user_can_control_auto_push($authUser);
$autoPushEnabled = $canControlAutoPush && function_exists('bakery_auto_push_is_enabled')
    ? bakery_auto_push_is_enabled()
    : false;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(function_exists('bakery_current_lang') ? bakery_current_lang() : 'en', ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="app-base-url" content="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <?php if (!defined('IS_LOCAL') || !IS_LOCAL): ?>
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <?php endif; ?>
    <?php if ($authUser && function_exists('bakery_csrf_token')): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="<?php echo htmlspecialchars(BASE_URL); ?>includes/csrf.js"></script>
    <?php endif; ?>
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>css/styles.css">
    <?php if ($showLocalDebugBanner): ?>
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
      .local-env-banner.prod-db-banner {
        background: #721c24;
        color: #f8d7da;
        border-bottom-color: #491217;
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
      .auth-bar-driver { margin-left: 4px; }
      .auth-bar-driver a { color: #7fdbff; }
      .local-env-banner-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 10px 16px;
      }
      .local-env-banner-dismiss {
        appearance: none;
        border: 1px solid currentColor;
        border-radius: 4px;
        padding: 3px 8px;
        background: transparent;
        color: inherit;
        font: inherit;
        font-size: 12px;
        cursor: pointer;
      }
      .local-env-banner-dismiss:hover,
      .local-env-banner-dismiss:focus-visible {
        background: rgba(255, 255, 255, 0.16);
      }
      .auto-push-controls {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: 4px 10px;
        border-radius: 6px;
        background: rgba(0, 0, 0, 0.18);
        font-weight: 500;
      }
      .auto-push-controls.auto-push-on { box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25); }
      .auto-push-controls.auto-push-off { box-shadow: inset 0 0 0 1px rgba(0,0,0,0.25); }
      .auto-push-label { white-space: nowrap; }
      .auto-push-switch {
        position: relative;
        display: inline-block;
        width: 42px;
        height: 24px;
        flex: 0 0 auto;
      }
      .auto-push-switch input {
        opacity: 0;
        width: 0;
        height: 0;
      }
      .auto-push-slider {
        position: absolute;
        cursor: pointer;
        inset: 0;
        background: #6c757d;
        border-radius: 24px;
        transition: 0.15s;
      }
      .auto-push-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        top: 3px;
        background: #fff;
        border-radius: 50%;
        transition: 0.15s;
      }
      .auto-push-switch input:checked + .auto-push-slider { background: #28a745; }
      .auto-push-switch input:checked + .auto-push-slider:before { transform: translateX(18px); }
      .auto-push-sync {
        border: 0;
        border-radius: 4px;
        padding: 4px 10px;
        cursor: pointer;
        font-weight: 600;
        font-size: 12px;
        background: #f8f9fa;
        color: #212529;
      }
      .auto-push-sync:disabled { opacity: 0.6; cursor: wait; }
      .auto-push-sync-emphasis {
        background: #ffc107;
        color: #212529;
      }
      .auto-push-status {
        font-size: 12px;
        font-weight: 500;
        opacity: 0.95;
        min-width: 8rem;
        text-align: left;
      }
      .auto-push-status--ok { color: #d4edda; }
      .auto-push-status--warn { color: #fff3cd; }
      .auto-push-status--error { color: #f8d7da; }
      .auto-push-status--busy { color: #ffe8a1; }
      .auto-push-status--muted { color: rgba(255,255,255,0.85); }
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
      .auth-bar-driver { margin-left: 4px; }
      .auth-bar-driver a { color: #7fdbff; }
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
    <script src="<?php echo htmlspecialchars(BASE_URL); ?>includes/global_tracking.js"></script>
    <?php endif; ?>
</head>
<body>
<?php if ($showLocalDebugBanner): ?>
<?php if (defined('USE_PROD_DB') && USE_PROD_DB): ?>
<div class="local-env-banner prod-db-banner" role="alert">
  <div class="local-env-banner-row">
    <span>LOCAL APP → LIVE PRODUCTION DB — <?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'unknown'); ?> @ <?php echo htmlspecialchars(defined('DB_HOST') ? DB_HOST : 'unknown'); ?> — writes affect the live site (php scripts/switch_db.php local)</span>
    <button type="button" class="local-env-banner-dismiss" data-dismiss-prod-db-banner aria-label="Hide the live production database warning for this tab">Hide warning</button>
    <?php if ($canControlAutoPush): ?>
    <div id="auto-push-controls" class="auto-push-controls <?php echo $autoPushEnabled ? 'auto-push-on' : 'auto-push-off'; ?>" data-base-url="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
      <span class="auto-push-label">Live auto-push</span>
      <label class="auto-push-switch" title="When on, local file changes auto-upload to bakery.sourflour.org/bake/">
        <input type="checkbox" id="auto-push-toggle" <?php echo $autoPushEnabled ? 'checked' : ''; ?> aria-checked="<?php echo $autoPushEnabled ? 'true' : 'false'; ?>">
        <span class="auto-push-slider"></span>
      </label>
      <button type="button" class="auto-push-sync<?php echo $autoPushEnabled ? '' : ' auto-push-sync-emphasis'; ?>" id="auto-push-sync">Sync to live</button>
      <span class="auto-push-status auto-push-status--muted" id="auto-push-status"></span>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
  (function () {
    var storageKey = 'bakery-hide-prod-db-banner';
    var banner = document.querySelector('.prod-db-banner');
    var dismissButton = document.querySelector('[data-dismiss-prod-db-banner]');
    if (!banner || !dismissButton) {
      return;
    }

    try {
      if (window.sessionStorage.getItem(storageKey) === '1') {
        banner.hidden = true;
      }
    } catch (error) {
      // The dismiss action still works when browser storage is unavailable.
    }

    dismissButton.addEventListener('click', function () {
      banner.hidden = true;
      try {
        window.sessionStorage.setItem(storageKey, '1');
      } catch (error) {
        // The banner remains hidden until this page is reloaded.
      }
    });
  }());
</script>
<?php else: ?>
<div class="local-env-banner" role="status">
  <div class="local-env-banner-row">
    <span>LOCAL ENVIRONMENT — database <?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'unknown'); ?> @ <?php echo htmlspecialchars(defined('DB_HOST') ? DB_HOST : 'unknown'); ?> — not production</span>
    <?php if ($canControlAutoPush): ?>
    <div id="auto-push-controls" class="auto-push-controls <?php echo $autoPushEnabled ? 'auto-push-on' : 'auto-push-off'; ?>" data-base-url="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
      <span class="auto-push-label">Live auto-push</span>
      <label class="auto-push-switch" title="When on, local file changes auto-upload to bakery.sourflour.org/bake/">
        <input type="checkbox" id="auto-push-toggle" <?php echo $autoPushEnabled ? 'checked' : ''; ?> aria-checked="<?php echo $autoPushEnabled ? 'true' : 'false'; ?>">
        <span class="auto-push-slider"></span>
      </label>
      <button type="button" class="auto-push-sync<?php echo $autoPushEnabled ? '' : ' auto-push-sync-emphasis'; ?>" id="auto-push-sync">Sync to live</button>
      <span class="auto-push-status auto-push-status--muted" id="auto-push-status"></span>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php if ($canControlAutoPush): ?>
<script src="<?php echo htmlspecialchars(BASE_URL); ?>includes/auto_push_control.js"></script>
<?php endif; ?>
<?php endif; ?>
<?php if ($authUser): ?>
<?php
  $authSelectedDriverName = function_exists('bakery_get_selected_driver_name') ? bakery_get_selected_driver_name() : '';
  $authSelectedDriverId = function_exists('bakery_get_selected_driver_id') ? bakery_get_selected_driver_id() : 0;
?>
<div class="auth-bar">
  <?php if (!$isBakerUser): ?>
  <span><?php echo htmlspecialchars($authUser['display_name'] . ' (' . $authUser['role_slug'] . ')'); ?></span>
  <?php if ($authSelectedDriverId > 0): ?>
  <span class="auth-bar-driver">
    Driving as <strong><?php echo htmlspecialchars($authSelectedDriverName !== '' ? $authSelectedDriverName : ('#' . $authSelectedDriverId)); ?></strong>
    <?php if (($authUser['role_slug'] ?? '') !== 'driver'): ?>
    · <a href="<?php echo htmlspecialchars(BASE_URL); ?>driver.php?change_driver=1" style="color:#7fdbff;">Change</a>
    <?php endif; ?>
  </span>
  <?php endif; ?>
  <?php endif; ?>
  <form method="post" action="<?php echo htmlspecialchars(BASE_URL); ?>logout.php">
    <?php echo bakery_csrf_field(); ?>
    <button type="submit">Log out</button>
  </form>
</div>
<?php endif; ?>
