<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

// Get current page name to determine if tracking should be enabled
$current_page = basename($_SERVER['PHP_SELF'], '.php');
$authUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$authRoleSlug = $authUser['role_slug'] ?? '';
$isBakerUser = $authUser && $authRoleSlug === 'baker';
$isDriverUser = $authUser && bakery_is_driver_route_role($authRoleSlug);
$isFocusedWorkspaceUser = $isBakerUser || $isDriverUser;
$showLocalDebugBanner = defined('IS_LOCAL') && IS_LOCAL && !$isBakerUser;
$showStagingBanner = defined('IS_STAGING') && IS_STAGING;
$workspaceBodyClass = $isDriverUser
    ? 'workspace-driver'
    : ($isBakerUser ? 'workspace-baker' : ($authUser ? 'workspace-ops' : ''));
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
$currentLocale = function_exists('bakery_locale') ? bakery_locale() : 'en';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="app-base-url" content="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
    <?php require __DIR__ . '/client_refresh.php'; ?>
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <?php if (!defined('IS_LOCAL') || !IS_LOCAL): ?>
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <?php endif; ?>
    <?php if ($authUser && function_exists('bakery_csrf_token')): ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="login-audit-session" content="<?php echo (int)bakery_login_audit_current_id(); ?>">
    <script src="<?php echo bakery_asset_href('includes/csrf.js'); ?>"></script>
    <?php endif; ?>
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo bakery_asset_href('css/tokens.css'); ?>">
    <link rel="stylesheet" href="<?php echo bakery_asset_href('css/base.css'); ?>">
    <link rel="stylesheet" href="<?php echo bakery_asset_href('css/nav.css'); ?>">
    <link rel="stylesheet" href="<?php echo bakery_asset_href('css/styles.css'); ?>">
    <script defer src="<?php echo bakery_asset_href('includes/shell.js'); ?>"></script>
    <?php if ($authUser): ?>
    <script>
    (function () {
      function removeLegacyLocationPrompt() {
        document.querySelectorAll('[data-login-location-choice]').forEach(function (node) { node.remove(); });
      }
      removeLegacyLocationPrompt();
      document.addEventListener('DOMContentLoaded', removeLegacyLocationPrompt);
      if (typeof MutationObserver === 'function') {
        document.addEventListener('DOMContentLoaded', function () {
          if (document.body) {
            new MutationObserver(removeLegacyLocationPrompt).observe(document.body, { childList: true, subtree: true });
          }
        });
      }
      window.setTimeout(removeLegacyLocationPrompt, 900);
      window.setTimeout(removeLegacyLocationPrompt, 1500);
    }());
    </script>
    <script defer src="<?php echo bakery_asset_href('includes/login_audit.js'); ?>"></script>
    <?php endif; ?>
    <script>window.__BAKERY_LOCALE__ = <?php echo json_encode($currentLocale); ?>;     window.__BAKERY_I18N__ = <?php echo json_encode([
        'saving' => bakery_t('common.saving'),
        'loading' => bakery_t('common.loading'),
        'cancel' => bakery_t('common.cancel'),
        'save' => bakery_t('common.save'),
        'today' => bakery_t('common.today'),
        'previous' => bakery_t('ui.previous'),
        'next' => bakery_t('ui.next'),
        'loading_order' => bakery_t('driver.loading_order'),
        'loading_order_details' => bakery_t('driver.loading_order_details'),
        'loading_photos' => bakery_t('driver.loading_photos'),
    ], JSON_UNESCAPED_UNICODE); ?>;</script>

    <?php if ($current_page === 'driver'): ?>
    <!-- GPS Tracking Script (Driver Page Only) -->
    <script src="<?php echo bakery_asset_href('includes/global_tracking.js'); ?>"></script>
    <?php endif; ?>
</head>
<body<?php echo $workspaceBodyClass !== '' ? ' class="' . htmlspecialchars($workspaceBodyClass, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
<?php if ($showStagingBanner): ?>
<div class="local-env-banner staging-env-banner" role="alert">
  <div class="local-env-banner-row">
    <span><?php echo htmlspecialchars(bakery_t('env.staging', ['db' => defined('DB_NAME') ? DB_NAME : 'unknown', 'host' => defined('DB_HOST') ? DB_HOST : 'unknown'])); ?></span>
  </div>
</div>
<?php endif; ?>
<?php if ($showLocalDebugBanner): ?>
<?php if (defined('USE_PROD_DB') && USE_PROD_DB): ?>
<div class="local-env-banner prod-db-banner" role="alert">
  <div class="local-env-banner-row">
    <span><?php echo htmlspecialchars(bakery_t('env.prod_db', ['db' => defined('DB_NAME') ? DB_NAME : 'unknown', 'host' => defined('DB_HOST') ? DB_HOST : 'unknown'])); ?></span>
    <button type="button" class="local-env-banner-dismiss" data-dismiss-prod-db-banner aria-label="<?php bakery_te('common.hide_warning'); ?>"><?php bakery_te('common.hide_warning'); ?></button>
    <?php if ($canControlAutoPush): ?>
    <div id="auto-push-controls" class="auto-push-controls <?php echo $autoPushEnabled ? 'auto-push-on' : 'auto-push-off'; ?>" data-base-url="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
      <span class="auto-push-label"><?php bakery_te('env.auto_push'); ?></span>
      <label class="auto-push-switch" title="<?php bakery_te('env.auto_push_title'); ?>">
        <input type="checkbox" id="auto-push-toggle" <?php echo $autoPushEnabled ? 'checked' : ''; ?> aria-checked="<?php echo $autoPushEnabled ? 'true' : 'false'; ?>">
        <span class="auto-push-slider"></span>
      </label>
      <button type="button" class="auto-push-sync<?php echo $autoPushEnabled ? '' : ' auto-push-sync-emphasis'; ?>" id="auto-push-sync"><?php bakery_te('env.sync_live'); ?></button>
      <button type="button" class="auto-push-sync" id="auto-push-promote">Promote approved to Live</button>
      <button type="button" class="auto-push-sync" id="auto-push-direct">Local directly to Live</button>
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
    <span><?php echo htmlspecialchars(bakery_t('env.local', ['db' => defined('DB_NAME') ? DB_NAME : 'unknown', 'host' => defined('DB_HOST') ? DB_HOST : 'unknown'])); ?></span>
    <?php if (isset($db) && $db instanceof PDO && function_exists('bakery_local_using_demo_fixtures') && bakery_local_using_demo_fixtures($db)): ?>
    <span><?php bakery_te('env.demo_fixtures'); ?></span>
    <?php endif; ?>
    <?php if ($canControlAutoPush): ?>
    <div id="auto-push-controls" class="auto-push-controls <?php echo $autoPushEnabled ? 'auto-push-on' : 'auto-push-off'; ?>" data-base-url="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
      <span class="auto-push-label"><?php bakery_te('env.auto_push'); ?></span>
      <label class="auto-push-switch" title="<?php bakery_te('env.auto_push_title'); ?>">
        <input type="checkbox" id="auto-push-toggle" <?php echo $autoPushEnabled ? 'checked' : ''; ?> aria-checked="<?php echo $autoPushEnabled ? 'true' : 'false'; ?>">
        <span class="auto-push-slider"></span>
      </label>
      <button type="button" class="auto-push-sync<?php echo $autoPushEnabled ? '' : ' auto-push-sync-emphasis'; ?>" id="auto-push-sync"><?php bakery_te('env.sync_live'); ?></button>
      <button type="button" class="auto-push-sync" id="auto-push-promote-mobile">Promote approved to Live</button>
      <button type="button" class="auto-push-sync" id="auto-push-direct-mobile">Local directly to Live</button>
      <span class="auto-push-status auto-push-status--muted" id="auto-push-status"></span>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php if ($canControlAutoPush): ?>
<script src="<?php echo bakery_asset_href('includes/auto_push_control.js'); ?>"></script>
<?php endif; ?>
<?php endif; ?>
<?php if ($authUser && !$isFocusedWorkspaceUser): ?>
<?php
  $authSelectedDriverName = function_exists('bakery_get_selected_driver_name') ? bakery_get_selected_driver_name() : '';
  $authSelectedDriverId = function_exists('bakery_get_selected_driver_id') ? bakery_get_selected_driver_id() : 0;
?>
<div class="auth-bar">
    <span><?php echo htmlspecialchars($authUser['display_name'] . ' (' . $authUser['role_slug'] . ')'); ?></span>
  <?php if ($authSelectedDriverId > 0): ?>
  <span class="auth-bar-driver">
    <?php echo htmlspecialchars(bakery_t('role.driving_as')); ?> <strong><?php echo htmlspecialchars($authSelectedDriverName !== '' ? $authSelectedDriverName : ('#' . $authSelectedDriverId)); ?></strong>
    · <a href="<?php echo htmlspecialchars(BASE_URL); ?>driver.php?change_driver=1" style="color:#7fdbff;"><?php bakery_te('common.change'); ?></a>
  </span>
  <?php endif; ?>
  <?php $langSwitchVariant = 'inline'; require __DIR__ . '/language_switch.php'; ?>
  <form method="post" action="<?php echo htmlspecialchars(BASE_URL); ?>logout.php">
    <?php echo bakery_csrf_field(); ?>
    <button type="submit"><?php bakery_te('common.log_out'); ?></button>
  </form>
</div>
<?php endif; ?>
