<?php

if (!defined('ACCESS_ALLOWED')) {

    die('Direct access not permitted');

}



$portalActivePage = $portalActivePage ?? 'home';

$portalCustomerName = $portalCustomerName ?? '';



/** Map sub-pages to primary tab for bottom nav highlighting. */

function bakery_portal_primary_tab($page) {

    $map = [

        'home' => 'home',

        'calendar' => 'deliveries',

        'deliveries' => 'deliveries',

        'delivery' => 'deliveries',

        'upcoming' => 'deliveries',

        'regular' => 'regular',

        'billing' => 'billing',

        'history' => 'more',

        'catalog' => 'more',

        'notifications' => 'more',

        'account' => 'more',

        'sfb' => 'more',

    ];

    return $map[$page] ?? 'home';

}



$portalPrimaryTab = bakery_portal_primary_tab($portalActivePage);

$portalMoreActive = in_array($portalActivePage, ['history', 'catalog', 'notifications', 'account'], true);



$portalUnreadCount = 0;

if (isset($db) && function_exists('bakery_portal_customer_id')) {

    $portalCustomerIdForNotify = bakery_portal_customer_id();

    if ($portalCustomerIdForNotify > 0) {

        require_once __DIR__ . '/customer_notifications.php';

        $portalUnreadCount = bakery_customer_notifications_unread_count($db, $portalCustomerIdForNotify);

    }

}

?>

<header class="portal-top">

  <a class="portal-top__brand" href="customer_portal.php" aria-label="Sour Flour">
    <?php echo bakery_sour_flour_logo_img('portal-top__brand-logo'); ?>
  </a>

  <a class="portal-top__notify" href="customer_portal_notifications.php" aria-label="<?php bakery_te('portal.notifications_link'); ?>">

    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>

    <?php if ($portalUnreadCount > 0): ?>

      <span class="portal-top__notify-badge"><?php echo $portalUnreadCount > 99 ? '99+' : (int)$portalUnreadCount; ?></span>

    <?php else: ?>

      <span class="portal-top__notify-badge" hidden>0</span>

    <?php endif; ?>

  </a>

  <h1 class="portal-top__name"><?php echo htmlspecialchars($portalCustomerName); ?></h1>

  <button type="button" class="portal-top__more" id="portalMoreBtn" aria-expanded="false" aria-controls="portalMoreSheet" aria-label="<?php bakery_te('portal.nav_more'); ?>">

    <?php bakery_te('portal.nav_more'); ?>

  </button>

</header>

<?php
$sfbImpersonation = !empty($_SESSION['sfb_impersonator_user_id']) ? [
    'admin_name' => (string)($_SESSION['sfb_impersonator_name'] ?? 'SFAdmin'),
    'customer_name' => (string)($portalCustomerName !== '' ? $portalCustomerName : ($_SESSION['portal_customer_name'] ?? '')),
] : null;
if ($sfbImpersonation):
?>
<div class="portal-impersonation" role="status">
  <span><?php echo htmlspecialchars(bakery_t('sfb.admin_impersonation_banner', ['name' => $sfbImpersonation['customer_name']]), ENT_QUOTES, 'UTF-8'); ?></span>
  <form method="post" action="sfb_admin_impersonate.php">
    <?php echo bakery_csrf_field(); ?>
    <input type="hidden" name="action" value="stop">
    <button type="submit"><?php bakery_te('sfb.admin_stop_impersonation'); ?></button>
  </form>
</div>
<style>
  .portal-impersonation { align-items: center; background: #3a241a; color: #fffaf3; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; padding: 10px 16px; }
  .portal-impersonation form { margin: 0; }
  .portal-impersonation button { background: #fffaf3; border: 0; border-radius: 8px; color: #3a241a; cursor: pointer; font: inherit; font-size: .85rem; font-weight: 700; padding: 7px 12px; }
</style>
<?php endif; ?>

