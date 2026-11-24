<?php
/**
 * Universal 1-tap Store Photo launcher & sheet.
 * Rendered from includes/nav.php so staff can take a store photo from ANY screen.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$sfQuickPhotoUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$sfQuickPhotoRole = (string)($sfQuickPhotoUser['role_slug'] ?? '');
$sfQuickPhotoEligibleRoles = ['administrator', 'manager', 'driver', 'driver_assistant', 'cashier'];

if ($sfQuickPhotoUser && in_array($sfQuickPhotoRole, $sfQuickPhotoEligibleRoles, true)):
    $sfQuickPhotoShopOnly = ($sfQuickPhotoRole === 'cashier');
    $sfQuickPhotoDateRaw = $_GET['date'] ?? date('Y-m-d');
    $sfQuickPhotoDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$sfQuickPhotoDateRaw);
    $sfQuickPhotoDate = ($sfQuickPhotoDateObj && $sfQuickPhotoDateObj->format('Y-m-d') === (string)$sfQuickPhotoDateRaw)
        ? $sfQuickPhotoDateObj->format('Y-m-d')
        : date('Y-m-d');

    $sfQuickPhotoDefaultDriverId = 0;
    $sfQuickPhotoStores = [];

    if (isset($db) && $db instanceof PDO) {
        try {
            if (function_exists('bakery_staff_own_driver_id')) {
                $sfQuickPhotoDefaultDriverId = bakery_staff_own_driver_id($db, $sfQuickPhotoUser);
            }
            if ($sfQuickPhotoDefaultDriverId <= 0 && function_exists('bakery_is_driver_route_role') && bakery_is_driver_route_role($sfQuickPhotoRole) && function_exists('bakery_route_worker_driver_id')) {
                $sfQuickPhotoDefaultDriverId = bakery_route_worker_driver_id($db, $sfQuickPhotoUser, $sfQuickPhotoDate);
            }
            if ($sfQuickPhotoDefaultDriverId <= 0 && function_exists('bakery_get_selected_driver_id')) {
                $sfQuickPhotoDefaultDriverId = (int)bakery_get_selected_driver_id();
            }

            if ($sfQuickPhotoShopOnly || (in_array($sfQuickPhotoRole, ['administrator', 'manager'], true) && function_exists('table_exists') && table_exists($db, 'shop_photos'))) {
                $sfQuickPhotoStores[] = [
                    'id' => 'shop',
                    'name' => bakery_t('store_photo.shop_option'),
                    'address' => bakery_t('store_photo.shop_option_sub'),
                    'zone' => '',
                    'todayStop' => $sfQuickPhotoShopOnly,
                ];
            }

            if (!$sfQuickPhotoShopOnly && function_exists('table_exists') && table_exists($db, 'customers')) {
                $todayMap = [];
                if (table_exists($db, 'daily_orders') && table_exists($db, 'daily_order_assignments')) {
                    if ($sfQuickPhotoDefaultDriverId > 0 && !in_array($sfQuickPhotoRole, ['administrator', 'manager'], true)) {
                        $tStmt = $db->prepare(
                            'SELECT do.customer_id, do.id AS daily_order_id, doa.driver_id, doa.route_order
                             FROM daily_order_assignments doa
                             INNER JOIN daily_orders do ON do.id = doa.daily_order_id
                             WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.order_date = ?
                             ORDER BY doa.route_order ASC, do.id DESC'
                        );
                        $tStmt->execute([$sfQuickPhotoDefaultDriverId, $sfQuickPhotoDate, $sfQuickPhotoDate]);
                    } elseif ($sfQuickPhotoDefaultDriverId > 0) {
                        $tStmt = $db->prepare(
                            'SELECT do.customer_id, do.id AS daily_order_id, doa.driver_id, doa.route_order,
                                    CASE WHEN doa.driver_id = ? THEN 0 ELSE 1 END AS driver_rank
                             FROM daily_order_assignments doa
                             INNER JOIN daily_orders do ON do.id = doa.daily_order_id
                             WHERE doa.delivery_date = ? AND do.order_date = ?
                             ORDER BY driver_rank ASC, doa.route_order ASC, do.id DESC'
                        );
                        $tStmt->execute([$sfQuickPhotoDefaultDriverId, $sfQuickPhotoDate, $sfQuickPhotoDate]);
                    } else {
                        $tStmt = $db->prepare(
                            'SELECT do.customer_id, do.id AS daily_order_id, doa.driver_id, doa.route_order
                             FROM daily_order_assignments doa
                             INNER JOIN daily_orders do ON do.id = doa.daily_order_id
                             WHERE doa.delivery_date = ? AND do.order_date = ?
                             ORDER BY doa.route_order ASC, do.id DESC'
                        );
                        $tStmt->execute([$sfQuickPhotoDate, $sfQuickPhotoDate]);
                    }
                    foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $tRow) {
                        $cid = (int)($tRow['customer_id'] ?? 0);
                        if ($cid > 0 && !isset($todayMap[$cid])) {
                            $todayMap[$cid] = [
                                'dailyOrderId' => (int)($tRow['daily_order_id'] ?? 0),
                                'driverId' => (int)($tRow['driver_id'] ?? 0),
                                'ownStop' => $sfQuickPhotoDefaultDriverId > 0 && (int)($tRow['driver_id'] ?? 0) === $sfQuickPhotoDefaultDriverId,
                            ];
                        }
                    }
                }

                $hasCoords = function_exists('column_exists')
                    && column_exists($db, 'customers', 'latitude')
                    && column_exists($db, 'customers', 'longitude');
                $hasActive = function_exists('column_exists') && column_exists($db, 'customers', 'is_active');
                $originClause = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
                $coordCols = $hasCoords ? ', c.latitude, c.longitude' : '';
                $activeWhere = $hasActive ? 'WHERE COALESCE(c.is_active, 1) = 1' : 'WHERE 1=1';

                $cStmt = $db->query(
                    "SELECT c.id, c.name, c.address, c.zone{$coordCols}
                     FROM customers c
                     {$activeWhere} {$originClause}
                     ORDER BY c.name ASC"
                );
                $ownTodayRows = [];
                $otherTodayRows = [];
                $otherStoreRows = [];
                foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $cRow) {
                    $cid = (int)$cRow['id'];
                    $tMeta = $todayMap[$cid] ?? null;
                    $item = [
                        'id' => $cid,
                        'name' => (string)$cRow['name'],
                        'address' => (string)($cRow['address'] ?? ''),
                        'zone' => (string)($cRow['zone'] ?? ''),
                        'todayStop' => $tMeta !== null,
                        'dailyOrderId' => $tMeta ? (int)$tMeta['dailyOrderId'] : 0,
                        'driverId' => ($sfQuickPhotoDefaultDriverId > 0) ? $sfQuickPhotoDefaultDriverId : ($tMeta ? (int)$tMeta['driverId'] : 0),
                        'lat' => ($hasCoords && $cRow['latitude'] !== null && $cRow['latitude'] !== '') ? (float)$cRow['latitude'] : null,
                        'lng' => ($hasCoords && $cRow['longitude'] !== null && $cRow['longitude'] !== '') ? (float)$cRow['longitude'] : null,
                    ];
                    if ($tMeta && !empty($tMeta['ownStop'])) {
                        $ownTodayRows[] = $item;
                    } elseif ($tMeta) {
                        $otherTodayRows[] = $item;
                    } else {
                        $otherStoreRows[] = $item;
                    }
                }
                $sfQuickPhotoStores = array_merge($ownTodayRows, $otherTodayRows, $sfQuickPhotoStores, $otherStoreRows);
            }
        } catch (Throwable $sfQuickErr) {
            error_log('store_photo_quick init error: ' . $sfQuickErr->getMessage());
        }
    }

    $sfQuickPhotoHref = function_exists('bakery_asset_href')
        ? bakery_asset_href('css/store_photo_quick.css')
        : (BASE_URL . 'css/store_photo_quick.css');
    $sfQuickPhotoJsHref = function_exists('bakery_asset_href')
        ? bakery_asset_href('includes/store_photo_quick.js')
        : (BASE_URL . 'includes/store_photo_quick.js');
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($sfQuickPhotoHref, ENT_QUOTES, 'UTF-8'); ?>">
<button type="button"
    class="sf-quick-photo-fab"
    id="sfQuickPhotoFab"
    data-open-store-photo
    aria-label="<?php echo htmlspecialchars(bakery_t('store_photo.fab_aria'), ENT_QUOTES, 'UTF-8'); ?>">
  <svg class="sf-quick-photo-fab__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
    <circle cx="12" cy="13" r="4"></circle>
  </svg>
  <span><?php bakery_te('store_photo.fab'); ?></span>
</button>

<div class="sf-qp-modal" id="sfQuickPhotoModal" role="dialog" aria-modal="true" aria-labelledby="sfQuickPhotoTitle" hidden>
  <div class="sf-qp-sheet">
    <div class="sf-qp-header">
      <div>
        <span class="sf-qp-eyebrow"><?php bakery_te('store_photo.eyebrow'); ?></span>
        <h2 class="sf-qp-title" id="sfQuickPhotoTitle"><?php bakery_te('store_photo.title'); ?></h2>
      </div>
      <button type="button" class="sf-qp-close" id="sfQuickPhotoClose"><?php bakery_te('common.close'); ?></button>
    </div>
    <div class="sf-qp-body">
      <div class="sf-qp-selected" id="sfQuickSelectedBox" hidden>
        <div class="sf-qp-selected__info">
          <strong class="sf-qp-selected__name" id="sfQuickSelectedName"></strong>
          <span class="sf-qp-selected__sub" id="sfQuickSelectedSub"></span>
        </div>
        <?php if (!$sfQuickPhotoShopOnly): ?>
        <button type="button" class="sf-qp-change-btn" id="sfQuickChangeStoreBtn"><?php bakery_te('store_photo.change_store'); ?></button>
        <?php endif; ?>
      </div>

      <div class="sf-qp-capture-row">
        <button type="button" class="sf-qp-btn sf-qp-btn--primary" id="sfQuickTakeBtn"><?php bakery_te('store_photo.take_now'); ?></button>
        <button type="button" class="sf-qp-btn" id="sfQuickLibraryBtn"><?php bakery_te('store_photo.choose_library'); ?></button>
      </div>
      <input type="file" id="sfQuickCameraInput" accept="image/*" capture="environment" hidden>
      <input type="file" id="sfQuickLibraryInput" accept="image/*" hidden>

      <div class="sf-qp-status" id="sfQuickPhotoStatus" role="status" aria-live="polite" hidden></div>

      <div class="sf-qp-preview-card" id="sfQuickPreviewCard" hidden>
        <img class="sf-qp-preview-img" id="sfQuickPreviewImg" alt="">
        <div class="sf-qp-preview-actions">
          <button type="button" class="sf-qp-btn sf-qp-btn--primary" id="sfQuickRetryBtn" hidden><?php bakery_te('store_photo.retry'); ?></button>
          <button type="button" class="sf-qp-btn sf-qp-btn--primary" id="sfQuickAnotherBtn" hidden><?php bakery_te('store_photo.take_another'); ?></button>
          <button type="button" class="sf-qp-btn" id="sfQuickDoneBtn" hidden><?php bakery_te('store_photo.done'); ?></button>
        </div>
      </div>

      <div class="sf-qp-types" id="sfQuickTypes" role="group" aria-label="<?php echo htmlspecialchars(bakery_t('driver.photo_type'), ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" class="sf-qp-type-chip" data-qp-type="Before" aria-pressed="true"><?php bakery_te('store_photo.type_before'); ?></button>
        <button type="button" class="sf-qp-type-chip" data-qp-type="After" aria-pressed="false"><?php bakery_te('store_photo.type_after'); ?></button>
        <button type="button" class="sf-qp-type-chip" data-qp-type="Receipt" aria-pressed="false"><?php bakery_te('store_photo.type_receipt'); ?></button>
      </div>

      <input type="text" class="sf-qp-note" id="sfQuickNote" maxlength="255" placeholder="<?php echo htmlspecialchars(bakery_t('store_photo.note_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">

      <?php if (!$sfQuickPhotoShopOnly): ?>
      <div class="sf-qp-picker" id="sfQuickPickerBox">
        <p class="sf-qp-prompt" id="sfQuickPrompt"><?php bakery_te('store_photo.pick_store_prompt'); ?></p>
        <input type="search" class="sf-qp-search" id="sfQuickStoreSearch" enterkeyhint="search" autocomplete="off" placeholder="<?php echo htmlspecialchars(bakery_t('store_photo.search_placeholder'), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="sf-qp-store-list" id="sfQuickStoreList"></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
window.__SF_QUICK_PHOTO__ = <?php echo json_encode([
    'baseUrl' => BASE_URL,
    'shopOnly' => $sfQuickPhotoShopOnly,
    'defaultDate' => $sfQuickPhotoDate,
    'defaultDriverId' => (int)$sfQuickPhotoDefaultDriverId,
    'stores' => $sfQuickPhotoStores,
    'i18n' => [
        'take_now' => bakery_t('store_photo.take_now'),
        'take_for_store' => bakery_t('store_photo.take_for_store'),
        'pick_store_prompt' => bakery_t('store_photo.pick_store_prompt'),
        'photo_ready_pick_store' => bakery_t('store_photo.photo_ready_pick_store'),
        'section_today' => bakery_t('store_photo.section_today'),
        'section_nearby' => bakery_t('store_photo.section_nearby'),
        'section_all' => bakery_t('store_photo.section_all'),
        'nearby_badge' => bakery_t('store_photo.nearby_badge'),
        'today_badge' => bakery_t('store_photo.today_badge'),
        'preparing' => bakery_t('store_photo.preparing'),
        'saving' => bakery_t('store_photo.saving'),
        'saved' => bakery_t('store_photo.saved'),
        'no_match' => bakery_t('store_photo.no_match'),
        'upload_failed' => bakery_t('store_photo.upload_failed'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<script defer src="<?php echo htmlspecialchars($sfQuickPhotoJsHref, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endif; ?>
