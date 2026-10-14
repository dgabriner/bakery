<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/shop_photo_handler.php';

bakery_require_role(['cashier', 'manager', 'administrator']);

$page_title = 'Shop Photos';
$photosEnabled = table_exists($db, 'shop_photos');
$handler = new ShopPhotoHandler();
$today = date('Y-m-d');
$selectedDate = isset($_GET['date']) ? trim((string)$_GET['date']) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate) || strtotime($selectedDate) === false) {
    $selectedDate = $today;
}

$user = bakery_current_user();
$role = $user['role_slug'] ?? '';
$selectedCategory = isset($_GET['category']) ? trim((string)$_GET['category']) : 'all';
$categories = ShopPhotoHandler::categories();
if ($selectedCategory !== 'all' && !array_key_exists($selectedCategory, $categories)) {
    $selectedCategory = 'all';
}

$visibleCashierId = in_array($role, ['administrator', 'manager'], true) ? null : (int)($user['id'] ?? 0);
$rows = $photosEnabled ? $handler->getPhotos($db, $selectedDate, $visibleCashierId, $selectedCategory === 'all' ? null : $selectedCategory) : [];
$photos = $handler->formatForClient($rows);
$groupedPhotos = [];
foreach ($photos as $photo) {
    $categoryKey = $photo['photo_category'] ?? 'general';
    if (!isset($groupedPhotos[$categoryKey])) {
        $groupedPhotos[$categoryKey] = [];
    }
    $groupedPhotos[$categoryKey][] = $photo;
}

$photoDates = $photosEnabled ? $handler->getPhotoDates($db, 21) : [];
$prevDate = (new DateTimeImmutable($selectedDate))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTimeImmutable($selectedDate))->modify('+1 day')->format('Y-m-d');
$dateLabel = function_exists('bakery_localized_date_label')
    ? bakery_localized_date_label(new DateTimeImmutable($selectedDate), true)
    : $selectedDate;

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/cashier_shop_photos.css'); ?>">

<main class="shop-photos-page">
  <header class="shop-photos-hero">
    <div>
      <p class="page-eyebrow"><?php bakery_te('shop_photos.eyebrow'); ?></p>
      <h1><?php bakery_te('shop_photos.title'); ?></h1>
      <p class="shop-photos-hero__subtitle"><?php bakery_te('shop_photos.subtitle'); ?></p>
    </div>
    <div class="shop-photos-hero__stats">
      <div class="shop-photos-stat">
        <span class="shop-photos-stat__label"><?php bakery_te('shop_photos.selected_day'); ?></span>
        <strong><?php echo htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
      </div>
      <div class="shop-photos-stat">
        <span class="shop-photos-stat__label"><?php bakery_te('shop_photos.count'); ?></span>
        <strong id="shopPhotoCount"><?php echo count($photos); ?></strong>
      </div>
    </div>
  </header>

  <?php if (!$photosEnabled): ?>
    <section class="shop-photos-empty-state">
      <h2><?php bakery_te('shop_photos.unavailable_title'); ?></h2>
      <p><?php bakery_te('shop_photos.unavailable_body'); ?></p>
    </section>
  <?php else: ?>
    <section class="shop-photos-toolbar">
      <form method="get" action="cashier_shop_photos.php" class="shop-photos-toolbar__form">
        <div class="shop-photos-date-nav">
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL . 'cashier_shop_photos.php?date=' . urlencode($prevDate) . '&category=' . urlencode($selectedCategory), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('shop_photos.prev'); ?></a>
          <label class="shop-photos-field">
            <span><?php bakery_te('common.date'); ?></span>
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL . 'cashier_shop_photos.php?date=' . urlencode($nextDate) . '&category=' . urlencode($selectedCategory), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('shop_photos.next'); ?></a>
          <a class="sf-btn sf-btn--quiet sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL . 'cashier_shop_photos.php?date=' . urlencode($today) . '&category=' . urlencode($selectedCategory), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('common.today'); ?></a>
        </div>
        <label class="shop-photos-field">
          <span><?php bakery_te('shop_photos.category'); ?></span>
          <select name="category">
            <option value="all"<?php echo $selectedCategory === 'all' ? ' selected' : ''; ?>><?php bakery_te('shop_photos.all'); ?></option>
            <?php foreach ($categories as $value => $label): ?>
              <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selectedCategory === $value ? ' selected' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php bakery_te('shop_photos.view'); ?></button>
      </form>
      <?php if ($photoDates): ?>
        <div class="shop-photos-toolbar__dates" aria-label="<?php bakery_te('shop_photos.recent_days'); ?>">
          <?php foreach ($photoDates as $date): ?>
            <a class="shop-photos-chip<?php echo $date === $selectedDate ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'cashier_shop_photos.php?date=' . urlencode((string)$date), ENT_QUOTES, 'UTF-8'); ?>">
              <?php echo htmlspecialchars((string)$date, ENT_QUOTES, 'UTF-8'); ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="shop-photos-capture">
      <div class="shop-photos-card">
        <h2><?php bakery_te('shop_photos.take_title'); ?></h2>
        <p class="text-muted"><?php bakery_te('shop_photos.take_help'); ?></p>

        <form id="shopPhotoUploadForm" class="shop-photos-upload-form">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="photo_date" value="<?php echo htmlspecialchars($selectedDate, ENT_QUOTES, 'UTF-8'); ?>">

          <label class="shop-photos-field">
            <span><?php bakery_te('shop_photos.type'); ?></span>
            <select name="photo_category" id="shopPhotoCategory">
              <?php foreach ($categories as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="shop-photos-field">
            <span><?php bakery_te('shop_photos.caption'); ?></span>
            <input type="text" name="caption" id="shopPhotoCaption" maxlength="255" placeholder="<?php bakery_te('shop_photos.caption_placeholder'); ?>">
          </label>

          <label class="shop-photos-camera-button">
            <span><?php bakery_te('shop_photos.choose'); ?></span>
            <input type="file" name="photo" id="shopPhotoInput" accept="image/*" capture="environment" hidden>
          </label>

          <div class="shop-photos-preview" id="shopPhotoPreview" hidden>
            <img id="shopPhotoPreviewImage" alt="<?php bakery_te('shop_photos.preview_alt'); ?>">
          </div>

          <div class="shop-photos-actions">
            <button class="sf-btn sf-btn--primary" type="submit"><?php bakery_te('shop_photos.save'); ?></button>
            <p id="shopPhotoStatus" class="shop-photos-status" role="status" aria-live="polite"></p>
          </div>
        </form>
      </div>
    </section>

    <section class="shop-photos-gallery-section">
      <header class="shop-photos-gallery-section__header">
        <div>
          <h2><?php bakery_te('shop_photos.gallery'); ?></h2>
          <p class="text-muted"><?php bakery_te('shop_photos.gallery_help'); ?></p>
        </div>
      </header>

      <?php if (!$photos): ?>
        <div class="shop-photos-empty-state">
          <h3><?php bakery_te('shop_photos.empty_title'); ?></h3>
          <p><?php bakery_te('shop_photos.empty_body'); ?></p>
        </div>
      <?php else: ?>
        <?php foreach ($categories as $categoryKey => $categoryLabel): ?>
          <?php if (empty($groupedPhotos[$categoryKey])) { continue; } ?>
          <section class="shop-photo-group">
            <header class="shop-photo-group__header">
              <h3><?php echo htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8'); ?></h3>
              <span><?php echo htmlspecialchars(bakery_t('shop_photos.photo_count', ['count' => count($groupedPhotos[$categoryKey])]), ENT_QUOTES, 'UTF-8'); ?></span>
            </header>
            <div class="shop-photo-grid">
              <?php foreach ($groupedPhotos[$categoryKey] as $photo): ?>
                <article class="shop-photo-card" data-photo-id="<?php echo (int)$photo['id']; ?>">
                  <button type="button" class="shop-photo-card__image-button" data-view-photo>
                    <img src="<?php echo htmlspecialchars($photo['url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($photo['caption'] !== '' ? $photo['caption'] : $photo['category_label'], ENT_QUOTES, 'UTF-8'); ?>">
                  </button>
                  <div class="shop-photo-card__body">
                    <div class="shop-photo-card__meta">
                      <strong><?php echo htmlspecialchars($photo['category_label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                      <span><?php echo htmlspecialchars((string)$photo['cashier_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if (!empty($photo['caption'])): ?>
                      <p class="shop-photo-card__caption"><?php echo htmlspecialchars((string)$photo['caption'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <div class="shop-photo-card__footer">
                      <span><?php echo htmlspecialchars((string)$photo['created_at'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <button type="button" class="shop-photo-delete" data-delete-photo><?php bakery_te('shop_photos.delete'); ?></button>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<dialog id="shopPhotoViewer" class="shop-photo-viewer">
  <form method="dialog" class="shop-photo-viewer__frame">
    <button type="submit" class="shop-photo-viewer__close" aria-label="<?php bakery_te('shop_photos.close_viewer'); ?>">×</button>
    <img id="shopPhotoViewerImage" alt="">
    <div class="shop-photo-viewer__meta">
      <h3 id="shopPhotoViewerTitle"><?php bakery_te('shop_photos.viewer_title'); ?></h3>
      <p id="shopPhotoViewerCaption"></p>
    </div>
  </form>
</dialog>

<script>
window.shopPhotoI18n = <?php echo json_encode([
    'chooseFirst' => bakery_t('shop_photos.choose_first'),
    'uploading' => bakery_t('shop_photos.uploading'),
    'saved' => bakery_t('shop_photos.saved'),
    'uploadFailed' => bakery_t('shop_photos.upload_failed'),
    'confirmDelete' => bakery_t('shop_photos.confirm_delete'),
    'deleteFailed' => bakery_t('shop_photos.delete_failed'),
    'viewerTitle' => bakery_t('shop_photos.viewer_title'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
(function () {
  var form = document.getElementById('shopPhotoUploadForm');
  var input = document.getElementById('shopPhotoInput');
  var status = document.getElementById('shopPhotoStatus');
  var preview = document.getElementById('shopPhotoPreview');
  var previewImage = document.getElementById('shopPhotoPreviewImage');
  var viewer = document.getElementById('shopPhotoViewer');
  var viewerImage = document.getElementById('shopPhotoViewerImage');
  var viewerTitle = document.getElementById('shopPhotoViewerTitle');
  var viewerCaption = document.getElementById('shopPhotoViewerCaption');

  function setStatus(message, isError) {
    if (!status) return;
    status.textContent = message || '';
    status.classList.toggle('is-error', !!isError);
    status.classList.toggle('is-success', !!message && !isError);
  }

  if (input && preview && previewImage) {
    input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      if (!file) {
        preview.hidden = true;
        previewImage.removeAttribute('src');
        return;
      }
      previewImage.src = URL.createObjectURL(file);
      preview.hidden = false;
      setStatus('');
    });
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var file = input && input.files ? input.files[0] : null;
      if (!file) {
        setStatus(window.shopPhotoI18n.chooseFirst, true);
        return;
      }

      var data = new FormData(form);
      data.set('photo', file);
      setStatus(window.shopPhotoI18n.uploading, false);

      fetch('upload_shop_photo.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: data
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload || !payload.success) {
            throw new Error((payload && payload.error) || window.shopPhotoI18n.uploadFailed);
          }
          setStatus(window.shopPhotoI18n.saved, false);
          window.setTimeout(function () { window.location.reload(); }, 350);
        })
        .catch(function (error) {
          setStatus(error && error.message ? error.message : window.shopPhotoI18n.uploadFailed, true);
        });
    });
  }

  document.addEventListener('click', function (event) {
    var deleteButton = event.target.closest('[data-delete-photo]');
    if (deleteButton) {
      var card = deleteButton.closest('[data-photo-id]');
      var photoId = card ? card.getAttribute('data-photo-id') : '';
      if (!photoId) return;
      if (!window.confirm(window.shopPhotoI18n.confirmDelete)) return;

      var body = new URLSearchParams();
      body.set('action', 'delete');
      body.set('photo_id', photoId);
      var csrf = document.querySelector('input[name="csrf_token"]');
      if (csrf && csrf.value) body.set('csrf_token', csrf.value);

      fetch('upload_shop_photo.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'Accept': 'application/json'
        },
        body: body.toString()
      })
        .then(function (response) { return response.json(); })
        .then(function (payload) {
          if (!payload || !payload.success) {
            throw new Error((payload && payload.error) || window.shopPhotoI18n.deleteFailed);
          }
          window.location.reload();
        })
        .catch(function (error) {
          setStatus(error && error.message ? error.message : window.shopPhotoI18n.deleteFailed, true);
        });
      return;
    }

    var viewButton = event.target.closest('[data-view-photo]');
    if (viewButton) {
      var image = viewButton.querySelector('img');
      var cardNode = viewButton.closest('.shop-photo-card');
      var titleNode = cardNode ? cardNode.querySelector('.shop-photo-card__meta strong') : null;
      var captionNode = cardNode ? cardNode.querySelector('.shop-photo-card__caption') : null;
      if (!image || !viewer || !viewerImage) return;
      viewerImage.src = image.src;
      viewerImage.alt = image.alt || '';
      if (viewerTitle) viewerTitle.textContent = titleNode ? titleNode.textContent : window.shopPhotoI18n.viewerTitle;
      if (viewerCaption) viewerCaption.textContent = captionNode ? captionNode.textContent : '';
      if (typeof viewer.showModal === 'function') {
        viewer.showModal();
      }
    }
  });
}());
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
