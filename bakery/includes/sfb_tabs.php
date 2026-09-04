<?php
/**
 * SF Baker bottom navigation: Home / Learn / Bake / Community + More sheet.
 * Reuses portal More sheet JS (includes/portal_nav.js).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$sfbActiveTab = $sfbActiveTab ?? 'dashboard';
$sfbShellNav = true;

$sfbPrimaryTabs = [
    'dashboard' => ['href' => 'sfb_dashboard.php', 'label' => 'sfb.nav_home', 'icon' => 'home'],
    'resources' => ['href' => 'sfb_resources.php', 'label' => 'sfb.nav_learn', 'icon' => 'learn'],
    'batches' => ['href' => 'sfb_batches.php', 'label' => 'sfb.nav_bake', 'icon' => 'bake'],
    'community' => ['href' => 'sfb_community.php', 'label' => 'sfb.nav_community', 'icon' => 'community'],
];

$sfbMoreLinks = [
    ['href' => 'sfb_starters.php', 'label' => 'sfb.tab_starters', 'keys' => ['starters']],
    ['href' => 'sfb_ingredients.php', 'label' => 'sfb.tab_ingredients', 'keys' => ['ingredients']],
    ['href' => 'sfb_formulas.php', 'label' => 'sfb.tab_formulas', 'keys' => ['formulas']],
    ['href' => 'sfb_resources.php', 'label' => 'sfb.tab_resources', 'keys' => ['resources']],
    ['href' => 'sfb_offerings.php', 'label' => 'sfb.tab_purchase', 'keys' => ['purchase']],
    ['href' => 'customer_portal_account.php', 'label' => 'portal.account_nav', 'keys' => ['account']],
];

$sfbMoreActive = !isset($sfbPrimaryTabs[$sfbActiveTab]);

$sfbIcons = [
    'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1v-9.5z"/></svg>',
    'learn' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
    'bake' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M12 3c-4 0-7 2.5-7 6 0 2 1 3.5 2 4.5V19a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-5.5c1-1 2-2.5 2-4.5 0-3.5-3-6-7-6z"/><path d="M9 8c1-1 2-1.5 3-1.5"/></svg>',
    'community' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'more' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="5" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="19" cy="12" r="1.5" fill="currentColor"/></svg>',
];
?>
<nav class="sfb-tabs portal-tabs" aria-label="<?php bakery_te('sfb.nav_aria'); ?>">
  <?php foreach ($sfbPrimaryTabs as $key => $tab): ?>
    <?php $isActive = $key === $sfbActiveTab; ?>
    <a href="<?php echo htmlspecialchars($tab['href'], ENT_QUOTES, 'UTF-8'); ?>"
       class="<?php echo $isActive ? 'active' : ''; ?>"
       <?php echo $isActive ? 'aria-current="page"' : ''; ?>>
      <?php echo $sfbIcons[$tab['icon']]; ?>
      <?php bakery_te($tab['label']); ?>
    </a>
  <?php endforeach; ?>
  <button type="button"
          class="sfb-tabs__more<?php echo $sfbMoreActive ? ' active' : ''; ?>"
          id="sfbMoreBtn"
          data-more-btn
          data-more-sheet="sfbMoreSheet"
          data-more-backdrop="sfbSheetBackdrop"
          aria-expanded="false"
          aria-controls="sfbMoreSheet"
          aria-label="<?php bakery_te('portal.nav_more'); ?>">
    <?php echo $sfbIcons['more']; ?>
    <?php bakery_te('portal.nav_more'); ?>
  </button>
</nav>

<div class="portal-sheet-backdrop" id="sfbSheetBackdrop" data-more-backdrop-el hidden></div>
<div class="portal-sheet" id="sfbMoreSheet" role="dialog" aria-modal="true" aria-label="<?php bakery_te('portal.more_options'); ?>" data-more-sheet-el hidden>
  <div class="portal-sheet__handle" aria-hidden="true"></div>
  <p class="portal-sheet__title"><?php bakery_te('sfb.more_title'); ?></p>
  <?php foreach ($sfbMoreLinks as $link): ?>
    <?php $linkActive = in_array($sfbActiveTab, $link['keys'], true); ?>
    <a class="portal-sheet__link<?php echo $linkActive ? ' is-active' : ''; ?>"
       href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>"
       <?php echo $linkActive ? 'aria-current="page"' : ''; ?>>
      <?php bakery_te($link['label']); ?>
    </a>
  <?php endforeach; ?>
  <div class="portal-sheet__link portal-sheet__lang">
    <span><?php bakery_te('portal.language'); ?></span>
    <?php $langSwitchVariant = 'portal'; require __DIR__ . '/language_switch.php'; ?>
  </div>
</div>
