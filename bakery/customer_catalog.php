<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$customer = bakery_portal_customer($db);
$customerId = (int)$customer['id'];
$tierLabel = bakery_pricing_tier_label($customer['pricing_tier'] ?? 'retail');

$catalogProducts = bakery_portal_catalog_products($db, $customer);
$upcomingDeliveries = bakery_portal_upcoming_deliveries($db, $customerId, $customer);
$standingDays = bakery_portal_standing_order_days($db, $customerId);

$productLines = [];
$doughTypes = [];
foreach ($catalogProducts as $product) {
    $line = $product['product_line_name'] ?: 'Other';
    $productLines[$line] = true;
    $dough = $product['dough_type_name'] ?: 'Other';
    $doughTypes[$dough] = true;
}
ksort($productLines);
ksort($doughTypes);

$page_title = bakery_t('page.customer_catalog');
$currentLocale = bakery_locale();
$portalActivePage = 'catalog';
$portalCustomerName = $customer['name'];

$catalogI18n = [
    'search_products' => bakery_t('portal.search_products'),
    'filter_all' => bakery_t('portal.filter_all'),
    'filter_current' => bakery_t('portal.filter_current'),
    'filter_ordered_before' => bakery_t('portal.filter_ordered_before'),
    'filter_never_ordered' => bakery_t('portal.filter_never_ordered'),
    'available_to_order' => bakery_t('portal.available_to_order'),
    'price_contact' => bakery_t('portal.price_contact'),
    'unit_piece' => bakery_t('portal.unit_piece'),
    'unit_each' => bakery_t('portal.unit_each'),
    'view_details' => bakery_t('portal.view_details'),
    'add_to_delivery' => bakery_t('portal.add_to_delivery'),
    'add_to_standing' => bakery_t('portal.add_to_standing'),
    'add_to_delivery_heading' => bakery_t('portal.add_to_delivery_heading'),
    'add_to_standing_heading' => bakery_t('portal.add_to_standing_heading'),
    'add_to_standing_hint' => bakery_t('portal.add_to_standing_hint'),
    'select_delivery' => bakery_t('portal.select_delivery'),
    'select_standing_day' => bakery_t('portal.select_standing_day'),
    'quantity' => bakery_t('portal.quantity'),
    'no_deliveries' => bakery_t('portal.no_deliveries'),
    'no_standing_days' => bakery_t('portal.no_standing_days'),
    'added_to_delivery' => bakery_t('portal.added_to_delivery'),
    'added_to_standing' => bakery_t('portal.added_to_standing'),
    'add_failed' => bakery_t('portal.add_failed'),
    'back_to_orders' => bakery_t('portal.back_to_orders'),
    'continue_browsing' => bakery_t('portal.continue_browsing'),
    'detail_close' => bakery_t('portal.detail_close'),
    'no_results' => bakery_t('portal.no_results'),
    'no_photo' => bakery_t('portal.no_photo'),
    'network_error' => bakery_t('portal.network_error'),
    'section_current' => bakery_t('portal.section_current'),
    'section_ordered_before' => bakery_t('portal.section_ordered_before'),
    'section_never_ordered' => bakery_t('portal.section_never_ordered'),
    'section_all' => bakery_t('portal.section_all'),
    'catalog_group_by' => bakery_t('portal.catalog_group_by'),
    'catalog_group_class' => bakery_t('portal.catalog_group_class'),
    'catalog_group_dough' => bakery_t('portal.catalog_group_dough'),
    'catalog_group_history' => bakery_t('portal.catalog_group_history'),
    'catalog_group_flat' => bakery_t('portal.catalog_group_flat'),
    'catalog_filter_class' => bakery_t('portal.catalog_filter_class'),
    'catalog_filter_dough' => bakery_t('portal.catalog_filter_dough'),
    'catalog_all_classes' => bakery_t('portal.catalog_all_classes'),
    'catalog_all_doughs' => bakery_t('portal.catalog_all_doughs'),
    'catalog_results' => bakery_t('portal.catalog_results'),
    'catalog_clear_filters' => bakery_t('portal.catalog_clear_filters'),
    'catalog_history' => bakery_t('portal.catalog_history'),
    'catalog_no_history' => bakery_t('portal.catalog_no_history'),
    'catalog_order_count' => bakery_t('portal.catalog_order_count'),
    'catalog_lifetime_quantity' => bakery_t('portal.catalog_lifetime_quantity'),
    'catalog_first_ordered' => bakery_t('portal.catalog_first_ordered'),
    'catalog_last_ordered' => bakery_t('portal.catalog_last_ordered'),
    'catalog_recent_deliveries' => bakery_t('portal.catalog_recent_deliveries'),
    'catalog_regular_schedule' => bakery_t('portal.catalog_regular_schedule'),
    'catalog_no_regular_schedule' => bakery_t('portal.catalog_no_regular_schedule'),
    'catalog_complete_history' => bakery_t('portal.catalog_complete_history'),
    'catalog_products' => bakery_t('portal.catalog_products'),
    'catalog_regulars' => bakery_t('portal.catalog_regulars'),
    'catalog_tried' => bakery_t('portal.catalog_tried'),
    'catalog_to_try' => bakery_t('portal.catalog_to_try'),
    'catalog_regular_badge' => bakery_t('portal.catalog_regular_badge'),
    'catalog_ordered_badge' => bakery_t('portal.catalog_ordered_badge'),
    'catalog_new_badge' => bakery_t('portal.catalog_new_badge'),
    'catalog_last_ordered_short' => bakery_t('portal.catalog_last_ordered_short'),
    'catalog_no_products_in_group' => bakery_t('portal.catalog_no_products_in_group'),
];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <style>
    .catalog-hero { align-items: flex-end; display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; margin-bottom: 18px; }
    .catalog-hero h1 { font-family: Georgia, serif; font-size: clamp(1.65rem, 5vw, 2.3rem); margin: 2px 0 6px; }
    .catalog-kicker { color: var(--terracotta); font-size: .72rem; font-weight: 700; letter-spacing: .12em; margin: 0; text-transform: uppercase; }
    .catalog-hero .page-intro { margin: 0; max-width: 650px; }
    .pricing-note { background: var(--sand); border: 1px solid var(--border); border-radius: 10px; color: var(--muted); font-size: .88rem; margin-bottom: 16px; padding: 12px 14px; }
    .catalog-stats { display: grid; gap: 8px; grid-template-columns: repeat(2, 1fr); margin-bottom: 18px; }
    .catalog-stat { background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 12px 14px; }
    .catalog-stat strong { display: block; font-family: Georgia, serif; font-size: 1.35rem; line-height: 1.1; }
    .catalog-stat span { color: var(--muted); display: block; font-size: .72rem; margin-top: 4px; }
    .catalog-toolbar { background: #fff; border: 1px solid var(--border); border-radius: 14px; margin-bottom: 20px; padding: 12px; position: sticky; top: 8px; z-index: 4; }
    .catalog-toolbar-top { align-items: center; display: flex; gap: 8px; }
    .search-input { background: #fff; border: 1px solid var(--border); border-radius: 10px; flex: 1; font-size: 1rem; min-height: 48px; padding: 12px 14px; width: 100%; }
    .clear-search { background: transparent; border: 0; color: var(--muted); cursor: pointer; min-height: 44px; padding: 8px; }
    .catalog-controls { display: grid; gap: 8px; grid-template-columns: 1fr; margin-top: 10px; }
    .catalog-control { color: var(--muted); display: grid; font-size: .72rem; gap: 4px; letter-spacing: .02em; text-transform: uppercase; }
    .catalog-control select { background: #fff; border: 1px solid var(--border); border-radius: 9px; color: var(--ink); font-size: .9rem; min-height: 42px; padding: 8px 10px; text-transform: none; }
    .filter-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
    .filter-chip { background: #fff; border: 1px solid var(--border); border-radius: 999px; color: var(--ink); cursor: pointer; font-size: .82rem; min-height: 40px; padding: 8px 14px; touch-action: manipulation; }
    .filter-chip.active { background: var(--ink); border-color: var(--ink); color: #fff; }
    .catalog-result-line { align-items: baseline; color: var(--muted); display: flex; font-size: .82rem; justify-content: space-between; margin: 0 0 12px; }
    .catalog-result-line strong { color: var(--ink); }
    .catalog-jump { display: flex; gap: 8px; margin: 0 0 18px; overflow-x: auto; padding-bottom: 2px; scrollbar-width: thin; }
    .catalog-jump a { background: var(--sand); border: 1px solid var(--border); border-radius: 999px; color: var(--ink); flex: 0 0 auto; font-size: .78rem; padding: 7px 11px; text-decoration: none; }
    .catalog-section { scroll-margin-top: 150px; }
    .catalog-section-head { align-items: baseline; display: flex; gap: 8px; justify-content: space-between; margin: 22px 0 10px; }
    .catalog-section-title { font-family: Georgia, serif; font-size: 1.3rem; margin: 0; }
    .catalog-section-count { color: var(--muted); font-size: .78rem; }
    .catalog-grid { display: grid; gap: 14px; grid-template-columns: 1fr; }
    @media (min-width: 480px) { .catalog-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 768px) { .catalog-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); } }
    .product-card { background: #fff; border: 1px solid var(--border); border-radius: 12px; cursor: pointer; display: flex; flex-direction: column; overflow: hidden; text-align: left; transition: box-shadow .15s, transform .15s; width: 100%; }
    .product-card:hover { box-shadow: 0 8px 22px rgba(51, 37, 31, .09); transform: translateY(-1px); }
    .product-card:active { background: var(--sand); }
    .product-card:focus-visible { box-shadow: 0 6px 18px rgba(51, 37, 31, .08); outline: 2px solid var(--terracotta); outline-offset: 2px; }
    .product-image { aspect-ratio: 4/3; background: #f3ece4; object-fit: cover; width: 100%; }
    .product-image.placeholder { align-items: center; color: var(--muted); display: flex; font-size: .85rem; justify-content: center; }
    .product-body { display: flex; flex: 1; flex-direction: column; padding: 14px; }
    .product-line { color: var(--muted); font-size: .75rem; letter-spacing: .04em; margin-bottom: 4px; text-transform: uppercase; }
    .product-card .product-name { font-family: Georgia, serif; font-size: 1.05rem; margin: 0 0 6px; }
    .product-desc { -webkit-box-orient: vertical; -webkit-line-clamp: 2; color: var(--muted); display: -webkit-box; font-size: .85rem; line-height: 1.4; margin: 0 0 10px; overflow: hidden; }
    .product-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 10px; }
    .product-tag { background: var(--sand); border-radius: 999px; color: var(--muted); font-size: .68rem; padding: 4px 7px; }
    .product-tag--current { background: #eef6f1; color: var(--green); }
    .product-tag--new { background: #fff5df; color: #8a5a00; }
    .product-history { border-top: 1px solid var(--border); color: var(--muted); font-size: .76rem; line-height: 1.45; margin: 2px 0 12px; padding-top: 10px; }
    .product-history strong { color: var(--ink); font-weight: 600; }
    .product-meta { align-items: baseline; display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; margin-top: auto; }
    .product-price { color: var(--terracotta); font-size: 1.05rem; font-weight: 600; }
    .product-price.muted-price { color: var(--muted); font-size: .88rem; font-weight: 500; }
    .product-meta .badge { background: #eef6f1; color: var(--green); font-size: .72rem; }
    .overlay { align-items: flex-end; background: rgba(51, 37, 31, .45); display: none; inset: 0; justify-content: center; padding: 0; position: fixed; z-index: 60; }
    .overlay.open { display: flex; }
    .detail-panel { background: #fff; border-radius: 16px 16px 0 0; max-height: 92vh; overflow: auto; padding: 18px; width: 100%; }
    .detail-image { aspect-ratio: 16/10; background: #f3ece4; border-radius: 12px; margin-bottom: 14px; object-fit: cover; width: 100%; }
    .detail-image.placeholder { align-items: center; color: var(--muted); display: flex; justify-content: center; }
    .detail-name { font-family: Georgia, serif; font-size: 1.35rem; margin: 0 0 8px; }
    .detail-desc { color: var(--muted); font-size: .92rem; line-height: 1.5; margin: 0 0 16px; white-space: pre-wrap; }
    .detail-price { color: var(--terracotta); font-size: 1.2rem; font-weight: 600; margin-bottom: 18px; }
    .detail-tags { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 12px; }
    .detail-tag { background: var(--sand); border-radius: 999px; color: var(--muted); font-size: .75rem; padding: 5px 8px; }
    .history-panel { background: #fbf8f4; border: 1px solid var(--border); border-radius: 12px; margin: 0 0 14px; padding: 14px; }
    .history-panel h3 { font-size: .95rem; margin: 0 0 10px; }
    .history-stats { display: grid; gap: 8px; grid-template-columns: repeat(2, 1fr); margin-bottom: 12px; }
    .history-stat { background: #fff; border-radius: 8px; padding: 9px; }
    .history-stat strong { display: block; font-size: 1rem; }
    .history-stat span { color: var(--muted); display: block; font-size: .68rem; margin-top: 2px; }
    .history-list { list-style: none; margin: 0 0 10px; padding: 0; }
    .history-list li { align-items: center; border-top: 1px solid var(--border); display: flex; font-size: .8rem; gap: 8px; justify-content: space-between; padding: 8px 0; }
    .history-list li span:last-child { color: var(--muted); }
    .history-link { color: var(--terracotta); font-size: .82rem; font-weight: 600; text-decoration: none; }
    .action-block { background: var(--sand); border: 1px solid var(--border); border-radius: 12px; margin-bottom: 14px; padding: 14px; }
    .action-block.standing { border-color: #d4c4b8; }
    .action-block h3 { font-size: .95rem; margin: 0 0 8px; }
    .action-hint { color: var(--muted); font-size: .82rem; line-height: 1.4; margin: 0 0 12px; }
    .field { margin-bottom: 12px; }
    .field label { display: block; font-size: .82rem; font-weight: 600; margin-bottom: 6px; }
    .field select, .field input { background: #fff; border: 1px solid var(--border); border-radius: 8px; font-size: 1rem; min-height: 48px; padding: 10px 12px; width: 100%; }
    .qty-row { align-items: center; display: flex; gap: 10px; }
    .btn-standing { background: var(--ink); }
    .detail-close { background: transparent; border: 0; color: var(--muted); cursor: pointer; float: right; font-size: .88rem; min-height: 44px; padding: 0; }
    .confirm-actions { display: grid; gap: 8px; margin-top: 12px; }
    .catalog-toast { z-index: 80; }
    @media (min-width: 640px) {
      .catalog-stats { grid-template-columns: repeat(4, 1fr); }
      .catalog-controls { grid-template-columns: repeat(3, 1fr); }
      .overlay { align-items: center; padding: 24px; }
      .detail-panel { border-radius: 16px; max-width: 520px; }
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container container--wide">
    <div class="catalog-hero">
      <div>
        <p class="catalog-kicker"><?php bakery_te('portal.catalog'); ?></p>
        <h1><?php bakery_te('portal.product_catalog'); ?></h1>
        <p class="page-intro"><?php bakery_te('portal.catalog_subtitle'); ?></p>
      </div>
      <a class="btn btn-secondary btn-sm" href="customer_portal_history.php"><?php bakery_te('portal.catalog_history'); ?></a>
    </div>
    <p class="pricing-note"><?php echo htmlspecialchars(bakery_t('portal.pricing_tier_note', ['tier' => $tierLabel])); ?></p>

    <div class="catalog-stats" id="catalogStats" aria-label="<?php echo htmlspecialchars(bakery_t('portal.product_catalog')); ?>">
      <div class="catalog-stat"><strong id="statProducts">0</strong><span><?php bakery_te('portal.catalog_products'); ?></span></div>
      <div class="catalog-stat"><strong id="statRegulars">0</strong><span><?php bakery_te('portal.catalog_regulars'); ?></span></div>
      <div class="catalog-stat"><strong id="statTried">0</strong><span><?php bakery_te('portal.catalog_tried'); ?></span></div>
      <div class="catalog-stat"><strong id="statToTry">0</strong><span><?php bakery_te('portal.catalog_to_try'); ?></span></div>
    </div>

    <div class="catalog-toolbar">
      <div class="catalog-toolbar-top">
        <input type="search" class="search-input" id="catalogSearch" placeholder="<?php echo htmlspecialchars(bakery_t('portal.search_products')); ?>" autocomplete="off">
        <button type="button" class="clear-search" id="clearSearch" hidden><?php echo htmlspecialchars(bakery_t('portal.catalog_clear_filters')); ?></button>
      </div>
      <div class="catalog-controls">
        <label class="catalog-control" for="groupBy"><?php echo htmlspecialchars(bakery_t('portal.catalog_group_by')); ?>
          <select id="groupBy">
            <option value="class"><?php echo htmlspecialchars(bakery_t('portal.catalog_group_class')); ?></option>
            <option value="dough"><?php echo htmlspecialchars(bakery_t('portal.catalog_group_dough')); ?></option>
            <option value="history"><?php echo htmlspecialchars(bakery_t('portal.catalog_group_history')); ?></option>
            <option value="flat"><?php echo htmlspecialchars(bakery_t('portal.catalog_group_flat')); ?></option>
          </select>
        </label>
        <label class="catalog-control" for="classFilter"><?php echo htmlspecialchars(bakery_t('portal.catalog_filter_class')); ?>
          <select id="classFilter">
            <option value=""><?php echo htmlspecialchars(bakery_t('portal.catalog_all_classes')); ?></option>
            <?php foreach (array_keys($productLines) as $lineName): ?>
              <option value="<?php echo htmlspecialchars($lineName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lineName); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="catalog-control" for="doughFilter"><?php echo htmlspecialchars(bakery_t('portal.catalog_filter_dough')); ?>
          <select id="doughFilter">
            <option value=""><?php echo htmlspecialchars(bakery_t('portal.catalog_all_doughs')); ?></option>
            <?php foreach (array_keys($doughTypes) as $doughName): ?>
              <option value="<?php echo htmlspecialchars($doughName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($doughName); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="filter-row" id="discoveryFilters">
        <button type="button" class="filter-chip active" data-discovery=""><?php bakery_te('portal.section_all'); ?></button>
        <button type="button" class="filter-chip" data-discovery="current"><?php bakery_te('portal.filter_current'); ?></button>
        <button type="button" class="filter-chip" data-discovery="ordered_before"><?php bakery_te('portal.filter_ordered_before'); ?></button>
        <button type="button" class="filter-chip" data-discovery="never_ordered"><?php bakery_te('portal.filter_never_ordered'); ?></button>
      </div>
    </div>

    <div class="catalog-result-line"><span id="catalogResultText"></span><strong id="catalogResultCount"></strong></div>
    <nav class="catalog-jump" id="catalogJump" aria-label="Catalog sections" hidden></nav>
    <div id="catalogSections"></div>
    <div class="empty-state" id="emptyState" hidden><?php bakery_te('portal.no_results'); ?></div>
  </main>

  <div class="overlay" id="detailOverlay" aria-hidden="true">
    <div class="detail-panel" role="dialog" aria-modal="true" aria-labelledby="detailName">
      <button type="button" class="detail-close" id="detailClose"><?php bakery_te('portal.detail_close'); ?></button>
      <div id="detailContent"></div>
    </div>
  </div>

  <div class="toast" id="toast" role="status"></div>

  <script>
    window.__CATALOG__ = <?php echo json_encode([
        'products' => $catalogProducts,
        'deliveries' => $upcomingDeliveries,
        'standingDays' => $standingDays,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.__BAKERY_I18N__ = <?php echo json_encode($catalogI18n, JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script>
    (function () {
      var data = window.__CATALOG__ || {};
      var i18n = window.__BAKERY_I18N__ || {};
      var products = data.products || [];
      var deliveries = data.deliveries || [];
      var standingDays = data.standingDays || [];
      var sectionsEl = document.getElementById('catalogSections');
      var emptyState = document.getElementById('emptyState');
      var searchEl = document.getElementById('catalogSearch');
      var clearSearch = document.getElementById('clearSearch');
      var groupByEl = document.getElementById('groupBy');
      var classFilterEl = document.getElementById('classFilter');
      var doughFilterEl = document.getElementById('doughFilter');
      var jumpEl = document.getElementById('catalogJump');
      var resultTextEl = document.getElementById('catalogResultText');
      var resultCountEl = document.getElementById('catalogResultCount');
      var overlay = document.getElementById('detailOverlay');
      var detailContent = document.getElementById('detailContent');
      var toast = document.getElementById('toast');
      var toastTimer;
      var activeDiscovery = '';
      var activeClass = '';
      var activeDough = '';
      var selectedProduct = null;

      function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
      }

      function showToast(msg, isError) {
        toast.textContent = msg;
        toast.className = 'toast' + (isError ? ' error' : '');
        toast.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.style.display = 'none'; }, 3200);
      }

      function formatPrice(product) {
        if (!product.price_reliable) {
          return i18n.price_contact;
        }
        var unit = product.ordering_unit === 'piece' ? i18n.unit_piece : i18n.unit_each;
        return '$' + product.unit_price.toFixed(2) + ' / ' + unit;
      }

      function unitLabel(product) {
        return product.ordering_unit === 'piece' ? i18n.unit_piece : i18n.unit_each;
      }

      function formatDate(dateValue) {
        if (!dateValue) return '';
        var date = new Date(String(dateValue) + 'T12:00:00');
        if (isNaN(date.getTime())) return String(dateValue);
        return new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
          month: 'short', day: 'numeric', year: 'numeric'
        }).format(date);
      }

      function discoveryLabel(product) {
        if (product.discovery === 'current') return i18n.catalog_regular_badge;
        if (product.discovery === 'ordered_before') return i18n.catalog_ordered_badge;
        return i18n.catalog_new_badge;
      }

      function discoveryClass(product) {
        return product.discovery === 'current' ? 'product-tag--current' : (product.discovery === 'never_ordered' ? 'product-tag--new' : '');
      }

      function historySummary(product) {
        if (!product.history_order_count) return '<span>' + escapeHtml(i18n.catalog_no_history) + '</span>';
        var count = escapeHtml(String(product.history_order_count));
        var quantity = escapeHtml(String(product.history_lifetime_quantity));
        var last = formatDate(product.history_last_ordered);
        return '<strong>' + count + '</strong> ' + escapeHtml(i18n.catalog_order_count) + ' · <strong>' + quantity + '</strong> ' + escapeHtml(i18n.catalog_lifetime_quantity) +
          (last ? '<br>' + escapeHtml(i18n.catalog_last_ordered_short) + ' ' + escapeHtml(last) : '');
      }

      function groupName(product) {
        if (groupByEl.value === 'dough') return product.dough_type_name || 'Other';
        if (groupByEl.value === 'history') return discoveryLabel(product);
        return product.product_line_name || 'Other';
      }

      function slug(value) {
        return String(value || 'group').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'group';
      }

      function matchesFilters(product) {
        var q = (searchEl.value || '').trim().toLowerCase();
        if (q) {
          var hay = (product.name + ' ' + product.description + ' ' + product.product_line_name + ' ' + product.dough_type_name + ' ' + product.product_line_description + ' ' + product.dough_type_description).toLowerCase();
          if (hay.indexOf(q) === -1) return false;
        }
        if (activeClass && (product.product_line_name || 'Other') !== activeClass) return false;
        if (activeDough && (product.dough_type_name || 'Other') !== activeDough) return false;
        if (activeDiscovery && product.discovery !== activeDiscovery) return false;
        return true;
      }

      function renderCard(product) {
        var img = product.image_url
          ? '<img class="product-image" src="' + escapeAttr(product.image_url) + '" alt="">'
          : '<div class="product-image placeholder">' + escapeHtml(i18n.no_photo) + '</div>';
        var desc = product.description
          ? '<p class="product-desc">' + escapeHtml(product.description) + '</p>'
          : '';
        var line = '<div class="product-line">' + escapeHtml(product.product_line_name || 'Other') + '</div>';
        var tags = '<div class="product-tags"><span class="product-tag ' + discoveryClass(product) + '">' + escapeHtml(discoveryLabel(product)) + '</span>' +
          (product.dough_type_name ? '<span class="product-tag">' + escapeHtml(product.dough_type_name) + '</span>' : '') + '</div>';
        var priceClass = product.price_reliable ? 'product-price' : 'product-price muted-price';
        return '<button type="button" class="product-card" data-product-id="' + product.id + '">' +
          img + '<div class="product-body">' + line +
          '<h2 class="product-name">' + escapeHtml(product.name) + '</h2>' + tags + desc +
          '<div class="product-history">' + historySummary(product) + '</div>' +
          '<div class="product-meta"><span class="' + priceClass + '">' + escapeHtml(formatPrice(product)) + '</span>' +
          '<span class="badge">' + escapeHtml(i18n.available_to_order) + '</span></div></div></button>';
      }

      function renderCatalog() {
        var filtered = products.filter(matchesFilters);
        sectionsEl.innerHTML = '';
        jumpEl.innerHTML = '';
        jumpEl.hidden = true;
        emptyState.hidden = filtered.length > 0;
        clearSearch.hidden = !(searchEl.value || '').trim();
        resultTextEl.textContent = i18n.catalog_results;
        resultCountEl.textContent = filtered.length + ' / ' + products.length;

        document.getElementById('statProducts').textContent = products.length;
        document.getElementById('statRegulars').textContent = products.filter(function (p) { return p.discovery === 'current'; }).length;
        document.getElementById('statTried').textContent = products.filter(function (p) { return p.discovery === 'ordered_before' || p.discovery === 'current'; }).length;
        document.getElementById('statToTry').textContent = products.filter(function (p) { return p.discovery === 'never_ordered'; }).length;

        if (!filtered.length) {
          return;
        }

        var groups = [];
        if (groupByEl.value === 'flat') {
          groups.push({ name: '', items: filtered });
        } else {
          var byGroup = {};
          filtered.forEach(function (product) {
            var name = groupName(product);
            if (!byGroup[name]) byGroup[name] = [];
            byGroup[name].push(product);
          });
          groups = Object.keys(byGroup).sort(function (a, b) { return a.localeCompare(b); }).map(function (name) {
            return { name: name, items: byGroup[name] };
          });
        }

        groups.forEach(function (group, index) {
          var sectionId = 'catalog-group-' + slug(group.name || 'all-' + index);
          if (groupByEl.value !== 'flat') {
            jumpEl.insertAdjacentHTML('beforeend', '<a href="#' + escapeAttr(sectionId) + '">' + escapeHtml(group.name) + ' <span>(' + group.items.length + ')</span></a>');
          }
          sectionsEl.insertAdjacentHTML('beforeend',
            '<section class="catalog-section" id="' + escapeAttr(sectionId) + '">' +
            (group.name ? '<div class="catalog-section-head"><h2 class="catalog-section-title">' + escapeHtml(group.name) + '</h2><span class="catalog-section-count">' + group.items.length + '</span></div>' : '') +
            '<div class="catalog-grid">' + group.items.map(renderCard).join('') + '</div></section>');
        });
        jumpEl.hidden = groupByEl.value === 'flat' || groups.length < 2;
      }

      function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      }

      function escapeAttr(str) {
        return escapeHtml(str).replace(/'/g, '&#39;');
      }

      function openDetail(productId) {
        selectedProduct = products.find(function (p) { return p.id === productId; });
        if (!selectedProduct) return;

        var p = selectedProduct;
        var img = p.image_url
          ? '<img class="detail-image" src="' + escapeAttr(p.image_url) + '" alt="">'
          : '<div class="detail-image placeholder">' + escapeHtml(i18n.no_photo) + '</div>';
        var desc = p.description ? '<p class="detail-desc">' + escapeHtml(p.description) + '</p>' : '';
        var meta = [];
        if (p.product_line_name) meta.push(p.product_line_name);
        if (p.dough_type_name) meta.push(p.dough_type_name);
        if (p.weight_grams) meta.push(p.weight_grams + 'g');
        var metaHtml = meta.length ? '<p class="action-hint">' + escapeHtml(meta.join(' · ')) + '</p>' : '';
        var detailTags = '<div class="detail-tags"><span class="detail-tag">' + escapeHtml(discoveryLabel(p)) + '</span>' +
          (p.dough_type_name ? '<span class="detail-tag">' + escapeHtml(p.dough_type_name) + '</span>' : '') + '</div>';

        var standingSchedule = p.standing_orders || [];
        var standingScheduleHtml = standingSchedule.length
          ? '<p class="action-hint">' + escapeHtml(i18n.catalog_regular_schedule) + ': ' + standingSchedule.map(function (row) {
              return escapeHtml(row.day_label) + ' (' + escapeHtml(String(row.quantity)) + ')';
            }).join(' · ') + '</p>'
          : '<p class="action-hint">' + escapeHtml(i18n.catalog_no_regular_schedule) + '</p>';
        var recentDeliveries = (p.history_recent_deliveries || []).map(function (row) {
          return '<li><span>' + escapeHtml(formatDate(row.date)) + '</span><span>' + escapeHtml(String(row.quantity)) + ' ' + escapeHtml(unitLabel(p)) + '</span></li>';
        }).join('');
        var historyPanel = '<section class="history-panel"><h3>' + escapeHtml(i18n.catalog_history) + '</h3>' +
          '<div class="history-stats"><div class="history-stat"><strong>' + escapeHtml(String(p.history_order_count || 0)) + '</strong><span>' + escapeHtml(i18n.catalog_order_count) + '</span></div>' +
          '<div class="history-stat"><strong>' + escapeHtml(String(p.history_lifetime_quantity || 0)) + '</strong><span>' + escapeHtml(i18n.catalog_lifetime_quantity) + '</span></div></div>' +
          (p.history_order_count
            ? '<p class="action-hint">' + escapeHtml(i18n.catalog_first_ordered) + ': ' + escapeHtml(formatDate(p.history_first_ordered)) + '<br>' + escapeHtml(i18n.catalog_last_ordered) + ': ' + escapeHtml(formatDate(p.history_last_ordered)) + '</p>'
            : '<p class="action-hint">' + escapeHtml(i18n.catalog_no_history) + '</p>') +
          (recentDeliveries ? '<h4 class="action-hint">' + escapeHtml(i18n.catalog_recent_deliveries) + '</h4><ul class="history-list">' + recentDeliveries + '</ul>' : '') +
          standingScheduleHtml +
          '<a class="history-link" href="customer_portal_history.php?product_id=' + encodeURIComponent(p.id) + '">' + escapeHtml(i18n.catalog_complete_history) + '</a></section>';

        var deliveryOptions = deliveries.map(function (d) {
          return '<option value="' + escapeAttr(d.date) + '">' + escapeHtml(d.label) + '</option>';
        }).join('');
        var deliveryDisabled = deliveries.length === 0;
        var deliveryBlock = '<section class="action-block"><h3>' + escapeHtml(i18n.add_to_delivery_heading) + '</h3>' +
          (deliveryDisabled
            ? '<p class="action-hint">' + escapeHtml(i18n.no_deliveries) + '</p>'
            : '<div class="field"><label for="deliveryDate">' + escapeHtml(i18n.select_delivery) + '</label>' +
              '<select id="deliveryDate">' + deliveryOptions + '</select></div>' +
              '<div class="field"><label for="deliveryQty">' + escapeHtml(i18n.quantity) + '</label>' +
              '<div class="qty-row">' +
              '<button type="button" class="qty-btn" data-target="deliveryQty" data-delta="-1">−</button>' +
              '<input class="qty-input field" id="deliveryQty" type="number" min="1" step="1" value="' + p.default_quantity + '">' +
              '<button type="button" class="qty-btn" data-target="deliveryQty" data-delta="1">+</button></div></div>' +
              '<button type="button" class="btn" id="addDeliveryBtn"' + (deliveryDisabled ? ' disabled' : '') + '>' +
              escapeHtml(i18n.add_to_delivery) + '</button>') + '</section>';

        var standingOptions = standingDays.map(function (d) {
          return '<option value="' + d.day_of_week + '">' + escapeHtml(d.day_label) + '</option>';
        }).join('');
        var standingDisabled = standingDays.length === 0;
        var standingHint = standingDays.length
          ? i18n.add_to_standing_hint.replace(':day', standingDays[0].day_label)
          : i18n.no_standing_days;
        var standingBlock = '<section class="action-block standing"><h3>' + escapeHtml(i18n.add_to_standing_heading) + '</h3>' +
          '<p class="action-hint">' + escapeHtml(standingHint) + '</p>' +
          (standingDisabled
            ? ''
            : '<div class="field"><label for="standingDay">' + escapeHtml(i18n.select_standing_day) + '</label>' +
              '<select id="standingDay">' + standingOptions + '</select></div>' +
              '<div class="field"><label for="standingQty">' + escapeHtml(i18n.quantity) + '</label>' +
              '<div class="qty-row">' +
              '<button type="button" class="qty-btn" data-target="standingQty" data-delta="-1">−</button>' +
              '<input class="qty-input field" id="standingQty" type="number" min="1" step="1" value="' + p.default_quantity + '">' +
              '<button type="button" class="qty-btn" data-target="standingQty" data-delta="1">+</button></div></div>' +
              '<button type="button" class="btn btn-standing" id="addStandingBtn">' +
              escapeHtml(i18n.add_to_standing.replace(':day', standingDays[0].day_label)) + '</button>') +
          (standingDisabled ? '<p class="action-hint">' + escapeHtml(i18n.no_standing_days) + '</p>' : '') +
          '</section>';

        detailContent.innerHTML = img +
          '<h2 class="detail-name" id="detailName">' + escapeHtml(p.name) + '</h2>' +
          detailTags + metaHtml + desc + historyPanel +
          '<div class="detail-price">' + escapeHtml(formatPrice(p)) + '</div>' +
          deliveryBlock + standingBlock;

        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        bindDetailEvents();
      }

      function closeDetail() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        selectedProduct = null;
      }

      function postAction(action, extra) {
        var body = new URLSearchParams({ action: action, csrf_token: csrfToken() });
        Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
        return fetch('customer_portal_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: body.toString()
        }).then(function (r) { return r.json(); });
      }

      function bindDetailEvents() {
        detailContent.querySelectorAll('.qty-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-target'));
            var delta = parseInt(btn.getAttribute('data-delta'), 10);
            input.value = Math.max(1, parseInt(input.value, 10) + delta);
          });
        });

        var standingDay = document.getElementById('standingDay');
        var standingBtn = document.getElementById('addStandingBtn');
        if (standingDay && standingBtn) {
          standingDay.addEventListener('change', function () {
            var label = standingDay.options[standingDay.selectedIndex].text;
            standingBtn.textContent = i18n.add_to_standing.replace(':day', label);
          });
        }

        var addDeliveryBtn = document.getElementById('addDeliveryBtn');
        if (addDeliveryBtn) {
          addDeliveryBtn.addEventListener('click', function () {
            if (!selectedProduct) return;
            addDeliveryBtn.disabled = true;
            postAction('save_daily_item', {
              date: document.getElementById('deliveryDate').value,
              product_id: selectedProduct.id,
              quantity: document.getElementById('deliveryQty').value
            }).then(function (res) {
              if (res.success) {
                showConfirm(res.result, 'delivery');
                closeDetail();
              } else {
                showToast(res.error || i18n.add_failed, true);
                addDeliveryBtn.disabled = false;
              }
            }).catch(function () {
              showToast(i18n.network_error, true);
              addDeliveryBtn.disabled = false;
            });
          });
        }

        if (standingBtn) {
          standingBtn.addEventListener('click', function () {
            if (!selectedProduct) return;
            var selectedProductId = selectedProduct.id;
            standingBtn.disabled = true;
            var daySelect = document.getElementById('standingDay');
            postAction('save_standing', {
              day_of_week: daySelect.value,
              product_id: selectedProduct.id,
              quantity: document.getElementById('standingQty').value
            }).then(function (res) {
              if (res.success) {
                showConfirm(res.result, 'standing');
                closeDetail();
                var prod = products.find(function (p) { return p.id === selectedProductId; });
                if (prod && prod.discovery === 'never_ordered') prod.discovery = 'current';
                if (prod) {
                  var existing = (prod.standing_orders || []).find(function (row) { return String(row.day_of_week) === String(res.day_of_week); });
                  if (existing) existing.quantity = res.new_quantity;
                  else prod.standing_orders = (prod.standing_orders || []).concat([{ day_of_week: parseInt(res.day_of_week, 10), day_label: res.day_label, quantity: res.new_quantity }]);
                  prod.discovery = 'current';
                }
                renderCatalog();
              } else {
                showToast(res.error || i18n.add_failed, true);
                standingBtn.disabled = false;
              }
            }).catch(function () {
              showToast(i18n.network_error, true);
              standingBtn.disabled = false;
            });
          });
        }
      }

      function showConfirm(res, kind) {
        var msg = kind === 'delivery'
          ? i18n.catalog_saved_delivery.replace(':product', res.product_name).replace(':quantity', res.new_quantity).replace(':date', formatDate(res.date))
          : i18n.catalog_saved_standing.replace(':product', res.product_name).replace(':quantity', res.new_quantity).replace(':day', res.day_label);
        showToast(msg, false);
      }

      sectionsEl.addEventListener('click', function (e) {
        var card = e.target.closest('.product-card');
        if (!card) return;
        openDetail(parseInt(card.getAttribute('data-product-id'), 10));
      });

      searchEl.addEventListener('input', renderCatalog);
      clearSearch.addEventListener('click', function () {
        searchEl.value = '';
        activeClass = '';
        activeDough = '';
        activeDiscovery = '';
        classFilterEl.value = '';
        doughFilterEl.value = '';
        groupByEl.value = 'class';
        document.querySelectorAll('#discoveryFilters .filter-chip').forEach(function (chip) { chip.classList.toggle('active', chip.getAttribute('data-discovery') === ''); });
        renderCatalog();
        searchEl.focus();
      });
      groupByEl.addEventListener('change', renderCatalog);
      classFilterEl.addEventListener('change', function () {
        activeClass = classFilterEl.value;
        renderCatalog();
      });
      doughFilterEl.addEventListener('change', function () {
        activeDough = doughFilterEl.value;
        renderCatalog();
      });

      document.getElementById('discoveryFilters').addEventListener('click', function (e) {
        var chip = e.target.closest('.filter-chip');
        if (!chip) return;
        activeDiscovery = chip.getAttribute('data-discovery') || '';
        chip.parentElement.querySelectorAll('.filter-chip').forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        renderCatalog();
      });

      document.getElementById('detailClose').addEventListener('click', closeDetail);
      overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeDetail();
      });

      renderCatalog();
    })();
  </script>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
