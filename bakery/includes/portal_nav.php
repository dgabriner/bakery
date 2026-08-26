<?php
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$portalActivePage = $portalActivePage ?? 'home';
$portalPrimaryTab = function_exists('bakery_portal_primary_tab')
    ? bakery_portal_primary_tab($portalActivePage)
    : 'home';
$portalMoreActive = in_array($portalActivePage, ['history', 'catalog', 'notifications', 'account', 'sfb', 'sfb_purchase'], true);

$portalSfbEnabled = false;
if (isset($db) && $db instanceof PDO) {
    require_once __DIR__ . '/sf_baker.php';
    $portalSfbEnabled = bakery_sfb_enabled($db);
}

function bakery_portal_tab_active($tab, $primary) {
    return $tab === $primary ? ' active' : '';
}
?>
<nav class="portal-tabs" aria-label="<?php bakery_te('portal.main_navigation'); ?>">
  <a href="customer_portal.php" class="<?php echo trim(bakery_portal_tab_active('home', $portalPrimaryTab)); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg>
    <?php bakery_te('portal.nav_home'); ?>
  </a>
  <a href="customer_portal_calendar.php" class="<?php echo trim(bakery_portal_tab_active('deliveries', $portalPrimaryTab)); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
    <?php bakery_te('portal.nav_deliveries'); ?>
  </a>
  <a href="customer_portal_regular.php" class="<?php echo trim(bakery_portal_tab_active('regular', $portalPrimaryTab)); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
    <?php bakery_te('portal.nav_my_order'); ?>
  </a>
  <a href="customer_portal_billing.php" class="<?php echo trim(bakery_portal_tab_active('billing', $portalPrimaryTab)); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>
    <?php bakery_te('portal.nav_billing'); ?>
  </a>
</nav>

<div class="portal-more-desktop" aria-label="<?php bakery_te('portal.more_options'); ?>">
  <?php if ($portalSfbEnabled): ?>
    <a href="sfb_dashboard.php"<?php echo $portalActivePage === 'sfb' ? ' class="active"' : ''; ?>><?php bakery_te('sfb.nav'); ?></a>
    <a href="sfb_offerings.php"<?php echo $portalActivePage === 'sfb_purchase' ? ' class="active"' : ''; ?>><?php bakery_te('sfb.tab_purchase'); ?></a>
  <?php endif; ?>
  <a href="customer_portal_history.php"<?php echo $portalActivePage === 'history' ? ' class="active"' : ''; ?>><?php bakery_te('portal.history'); ?></a>
  <a href="customer_catalog.php"<?php echo $portalActivePage === 'catalog' ? ' class="active"' : ''; ?>><?php bakery_te('portal.catalog'); ?></a>
  <a href="customer_portal_notifications.php"<?php echo $portalActivePage === 'notifications' ? ' class="active"' : ''; ?>><?php bakery_te('portal.notifications_link'); ?></a>
  <a href="customer_portal_account.php"<?php echo $portalActivePage === 'account' ? ' class="active"' : ''; ?>><?php bakery_te('portal.account_nav'); ?></a>
  <?php $langSwitchVariant = 'portal'; require __DIR__ . '/language_switch.php'; ?>
  <a href="customer_portal_logout.php"><?php bakery_te('portal.sign_out'); ?></a>
</div>

<div class="portal-sheet-backdrop" id="portalSheetBackdrop" hidden></div>
<div class="portal-sheet" id="portalMoreSheet" role="dialog" aria-modal="true" aria-label="<?php bakery_te('portal.more_options'); ?>" hidden>
  <div class="portal-sheet__handle" aria-hidden="true"></div>
  <p class="portal-sheet__title"><?php bakery_te('portal.more_options'); ?></p>
  <?php if ($portalSfbEnabled): ?>
    <a class="portal-sheet__link" href="sfb_dashboard.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 3c-4 0-7 2.5-7 6 0 2 1 3.5 2 4.5V19a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-5.5c1-1 2-2.5 2-4.5 0-3.5-3-6-7-6z"/><path d="M9 8c1-1 2-1.5 3-1.5"/></svg>
      <?php bakery_te('sfb.nav'); ?>
    </a>
    <a class="portal-sheet__link" href="sfb_offerings.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
      <?php bakery_te('sfb.tab_purchase'); ?>
    </a>
  <?php endif; ?>
  <a class="portal-sheet__link" href="customer_portal_history.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
    <?php bakery_te('portal.history'); ?>
  </a>
  <a class="portal-sheet__link" href="customer_portal_notifications.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    <?php bakery_te('portal.notifications_link'); ?>
  </a>
  <a class="portal-sheet__link" href="customer_catalog.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>
    <?php bakery_te('portal.catalog'); ?>
  </a>
  <a class="portal-sheet__link" href="customer_portal_account.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 4-6 8-6s8 2 8 6"/></svg>
    <?php bakery_te('portal.account_nav'); ?>
  </a>
  <div class="portal-sheet__link portal-sheet__lang">
    <span><?php bakery_te('portal.language'); ?></span>
    <?php $langSwitchVariant = 'portal'; require __DIR__ . '/language_switch.php'; ?>
  </div>
  <a class="portal-sheet__link portal-sheet__link--danger" href="customer_portal_logout.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
    <?php bakery_te('portal.sign_out'); ?>
  </a>
</div>
<script src="<?php echo bakery_asset_href('includes/portal_nav.js'); ?>" defer></script>
