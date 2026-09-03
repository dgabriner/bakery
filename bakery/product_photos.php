<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_portal.php';

$page_title = bakery_t('page.product_photos');
$photosEnabled = table_exists($db, 'product_images');
$uploadBase = BASE_URL . 'uploads/product_photos/';

$catalog = [];
$stats = ['total' => 0, 'with_photo' => 0];

if ($photosEnabled) {
    $rows = $db->query(
        'SELECT p.id, p.name,
                dt.id AS dough_type_id, dt.name AS dough_type_name,
                pl.id AS product_line_id, pl.name AS product_line_name,
                pl.color_code AS product_line_color, pl.sort_order AS product_line_sort,
                thumb.file_path AS thumb_path, thumb.is_primary AS thumb_is_primary
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         LEFT JOIN product_images thumb ON thumb.id = (
             SELECT pi.id FROM product_images pi
             WHERE pi.product_id = p.id
             ORDER BY pi.is_primary DESC, pi.sort_order, pi.id
             LIMIT 1
         )
         ORDER BY COALESCE(pl.sort_order, 9999), pl.name, dt.name, p.name'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $stats['total']++;
        $hasPhoto = !empty($row['thumb_path']);
        if ($hasPhoto) {
            $stats['with_photo']++;
        }

        $lineKey = $row['product_line_id'] ? (string)$row['product_line_id'] : 'unassigned';
        $classKey = $row['dough_type_id'] ? (string)$row['dough_type_id'] : 'no_class';

        if (!isset($catalog[$lineKey])) {
            $catalog[$lineKey] = [
                'id' => $row['product_line_id'] ? (int)$row['product_line_id'] : null,
                'name' => $row['product_line_name'] ?: 'Unassigned type',
                'color' => $row['product_line_color'] ?: '#b75c3f',
                'sort' => $row['product_line_sort'] ?? 9999,
                'classes' => [],
            ];
        }

        if (!isset($catalog[$lineKey]['classes'][$classKey])) {
            $catalog[$lineKey]['classes'][$classKey] = [
                'id' => $row['dough_type_id'] ? (int)$row['dough_type_id'] : null,
                'name' => $row['dough_type_name'] ?: 'No class',
                'products' => [],
            ];
        }

        $catalog[$lineKey]['classes'][$classKey]['products'][] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'has_photo' => $hasPhoto,
            'is_primary' => (int)($row['thumb_is_primary'] ?? 0) === 1,
            'image_url' => $hasPhoto ? ($uploadBase . ltrim($row['thumb_path'], '/')) : null,
        ];
    }

    uasort($catalog, static function ($a, $b) {
        return ($a['sort'] <=> $b['sort']) ?: strcasecmp($a['name'], $b['name']);
    });
}

$selectedId = (int)($_GET['product_id'] ?? 0);
$selectedProduct = null;
$selectedMeta = ['type' => '', 'class' => ''];
$images = [];
$photoUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$photoRole = (string)($photoUser['role_slug'] ?? '');
$canQuickAddProduct = in_array($photoRole, ['cashier', 'manager', 'administrator'], true);
$photoUploadFailed = !empty($_GET['photo_error']);


if ($photosEnabled && $selectedId > 0) {
    require_once __DIR__ . '/includes/product_photo_handler.php';
    $handler = new ProductPhotoHandler();
    $images = $handler->listImages($db, $selectedId);

    foreach ($catalog as $type) {
        foreach ($type['classes'] as $class) {
            foreach ($class['products'] as $product) {
                if ($product['id'] === $selectedId) {
                    $selectedProduct = $product;
                    $selectedMeta = [
                        'type' => $type['name'],
                        'class' => $class['name'],
                        'color' => $type['color'],
                    ];
                    break 3;
                }
            }
        }
    }
}

$captureMode = $selectedId > 0 && $selectedProduct;
$missingCount = $stats['total'] - $stats['with_photo'];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<div class="photo-app">
  <?php if (!$photosEnabled): ?>
    <div class="photo-app__notice">Run <code>php scripts/run_migrations.php</code> to enable product photos.</div>
  <?php elseif ($captureMode): ?>
    <header class="photo-app__bar">
      <a class="photo-app__back" href="product_photos.php" aria-label="Back to all products">&larr; All products</a>
    </header>

    <section class="photo-capture">
      <p class="photo-capture__crumb">
        <span class="photo-capture__type-dot" style="background:<?php echo htmlspecialchars($selectedMeta['color']); ?>"></span>
        <?php echo htmlspecialchars($selectedMeta['type']); ?>
        <span aria-hidden="true">›</span>
        <?php echo htmlspecialchars($selectedMeta['class']); ?>
      </p>
      <h1 class="photo-capture__title"><?php echo htmlspecialchars($selectedProduct['name']); ?></h1>
      <?php if ($photoUploadFailed): ?>
        <p class="photo-app__notice photo-app__notice--warn" role="status">
          <?php echo htmlspecialchars(bakery_t('cashier_add.photo_upload_failed'), ENT_QUOTES, 'UTF-8'); ?>
        </p>
      <?php endif; ?>

      <div class="photo-capture__hero">
        <?php if ($selectedProduct['image_url']): ?>
          <img src="<?php echo htmlspecialchars($selectedProduct['image_url']); ?>" alt="">
        <?php else: ?>
          <div class="photo-capture__placeholder">No photo yet</div>
        <?php endif; ?>
      </div>

      <div class="photo-capture__actions">
        <div class="photo-capture__sources" role="group" aria-label="Photo upload source">
          <label class="photo-capture__camera-btn">
            <span aria-hidden="true">📷</span>
            <?php echo htmlspecialchars(bakery_t('cashier_add.photo_camera'), ENT_QUOTES, 'UTF-8'); ?>
            <input type="file" id="photoCameraInput" accept="image/*" capture="environment" hidden>
          </label>
          <label class="photo-capture__camera-btn">
            <span aria-hidden="true">🖼</span>
            <?php echo htmlspecialchars(bakery_t('cashier_add.photo_library'), ENT_QUOTES, 'UTF-8'); ?>
            <input type="file" id="photoLibraryInput" accept="image/*" hidden>
          </label>
        </div>
        <label class="photo-capture__option">
          <input type="checkbox" id="setPrimary" checked>
          Set as primary
        </label>
        <p id="uploadStatus" class="photo-capture__status" role="status"></p>
      </div>

      <h2 class="photo-capture__gallery-title">Gallery</h2>
      <div id="gallery" class="photo-gallery">
        <?php if (!$images): ?>
          <p class="photo-gallery__empty">Additional photos will appear here.</p>
        <?php else: ?>
          <?php foreach ($images as $img): ?>
            <article class="photo-gallery__item gallery-item" data-image-id="<?php echo (int)$img['id']; ?>">
              <img src="<?php echo htmlspecialchars($img['url']); ?>" alt="">
              <div class="photo-gallery__item-actions">
                <?php if ($img['is_primary']): ?>
                  <span class="photo-badge photo-badge--primary">Primary</span>
                <?php else: ?>
                  <button type="button" class="photo-btn photo-btn--ghost btn-set-primary">Make primary</button>
                <?php endif; ?>
                <button type="button" class="photo-btn photo-btn--danger btn-delete">Delete</button>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <input type="hidden" id="selectedProductId" value="<?php echo (int)$selectedId; ?>">

  <?php else: ?>
    <header class="photo-app__hero-header">
      <h1 class="photo-app__title">Product Photos</h1>
      <p class="photo-app__subtitle">Tap a product to take or update its catalog photo.</p>
      <?php if ($canQuickAddProduct): ?>
        <p class="photo-app__add-wrap">
          <a class="photo-app__add" href="<?php echo htmlspecialchars(BASE_URL . 'cashier_add_product.php', ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars(bakery_t('cashier_add.add_cta'), ENT_QUOTES, 'UTF-8'); ?>
          </a>
        </p>
      <?php endif; ?>
      <div class="photo-app__stats">
        <span><strong><?php echo (int)$stats['with_photo']; ?></strong> with photos</span>
        <span><strong><?php echo (int)$missingCount; ?></strong> need photos</span>
      </div>
    </header>

    <div class="photo-app__search-wrap">
      <input type="search" id="productSearch" class="photo-app__search" placeholder="Search products…" autocomplete="off" enterkeyhint="search">
    </div>

    <div class="photo-app__filters" id="photoFilters">
      <button type="button" class="photo-filter is-active" data-filter="all">All</button>
      <button type="button" class="photo-filter" data-filter="missing">Needs photo</button>
      <button type="button" class="photo-filter" data-filter="done">Has photo</button>
    </div>

    <div class="photo-catalog" id="photoCatalog">
      <?php if (!$catalog): ?>
        <p class="photo-catalog__empty">No products found.</p>
      <?php endif; ?>

      <?php foreach ($catalog as $typeKey => $type): ?>
        <section class="photo-type" data-type-key="<?php echo htmlspecialchars($typeKey); ?>">
          <header class="photo-type__header" style="--type-color: <?php echo htmlspecialchars($type['color']); ?>">
            <span class="photo-type__dot"></span>
            <h2 class="photo-type__name"><?php echo htmlspecialchars($type['name']); ?></h2>
            <span class="photo-type__count"><?php
              $typeTotal = 0;
              $typePhotos = 0;
              foreach ($type['classes'] as $class) {
                  $typeTotal += count($class['products']);
                  foreach ($class['products'] as $p) {
                      if ($p['has_photo']) {
                          $typePhotos++;
                      }
                  }
              }
              echo (int)$typePhotos . '/' . (int)$typeTotal;
            ?></span>
          </header>

          <?php foreach ($type['classes'] as $classKey => $class): ?>
            <div class="photo-class" data-class-key="<?php echo htmlspecialchars($classKey); ?>">
              <h3 class="photo-class__name"><?php echo htmlspecialchars($class['name']); ?></h3>
              <div class="photo-class__grid">
                <?php foreach ($class['products'] as $product): ?>
                  <a href="product_photos.php?product_id=<?php echo (int)$product['id']; ?>"
                     class="photo-product <?php echo $product['has_photo'] ? 'has-photo' : 'needs-photo'; ?>"
                     data-name="<?php echo htmlspecialchars(strtolower($product['name'])); ?>"
                     data-has-photo="<?php echo $product['has_photo'] ? '1' : '0'; ?>">
                    <div class="photo-product__thumb">
                      <?php if ($product['image_url']): ?>
                        <img src="<?php echo htmlspecialchars($product['image_url']); ?>" alt="" loading="lazy">
                      <?php else: ?>
                        <span class="photo-product__no-img" aria-hidden="true">📷</span>
                      <?php endif; ?>
                      <?php if (!$product['has_photo']): ?>
                        <span class="photo-product__badge photo-product__badge--missing">Needs photo</span>
                      <?php endif; ?>
                    </div>
                    <span class="photo-product__name"><?php echo htmlspecialchars($product['name']); ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
    </div>

    <p id="noResults" class="photo-catalog__empty" hidden>No products match your search.</p>
  <?php endif; ?>
</div>

<style>
.photo-app {
  --ink: #33251f;
  --cream: #fffdf8;
  --terracotta: #b75c3f;
  --muted: #7a6a5c;
  --border: #e8ddd2;
  --green: #2d6a4f;
  --amber: #b8860b;
  max-width: 920px;
  margin: 0 auto;
  padding: 12px 14px calc(24px + env(safe-area-inset-bottom));
  color: var(--ink);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}

.photo-app__notice,
.photo-catalog__empty {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--muted);
  padding: 16px;
  text-align: center;
}

.photo-app__hero-header { margin-bottom: 14px; }
.photo-app__title {
  font-family: Georgia, 'Times New Roman', serif;
  font-size: 1.5rem;
  font-weight: normal;
  margin: 0 0 6px;
}
.photo-app__subtitle { color: var(--muted); font-size: .92rem; margin: 0 0 12px; }
.photo-app__add-wrap { margin: 0 0 12px; }
.photo-app__add {
  display: inline-block;
  background: #2f6f5e;
  color: #fff;
  font-weight: 700;
  text-decoration: none;
  border-radius: 999px;
  padding: 10px 16px;
  font-size: .95rem;
}
.photo-app__notice--warn {
  background: #fff4e5;
  border: 1px solid #f0c36d;
  color: #7a4e00;
  border-radius: 12px;
  padding: 12px 14px;
  margin: 0 0 12px;
}
.photo-app__stats {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.photo-app__stats span {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 999px;
  font-size: .82rem;
  padding: 6px 12px;
}

.photo-app__search-wrap { margin-bottom: 10px; }
.photo-app__search {
  border: 1px solid var(--border);
  border-radius: 12px;
  font-size: 16px;
  padding: 12px 14px;
  width: 100%;
}

.photo-app__filters {
  display: flex;
  gap: 8px;
  margin-bottom: 16px;
  overflow-x: auto;
  padding-bottom: 4px;
  -webkit-overflow-scrolling: touch;
}
.photo-filter {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 999px;
  color: var(--ink);
  cursor: pointer;
  flex-shrink: 0;
  font-size: .88rem;
  min-height: 40px;
  padding: 0 16px;
}
.photo-filter.is-active {
  background: var(--terracotta);
  border-color: var(--terracotta);
  color: #fff;
}

.photo-type { margin-bottom: 22px; }
.photo-type__header {
  align-items: center;
  display: flex;
  gap: 10px;
  margin-bottom: 10px;
  padding: 4px 0;
  position: sticky;
  top: 0;
  background: var(--cream);
  z-index: 2;
}
.photo-type__dot {
  background: var(--type-color, var(--terracotta));
  border-radius: 50%;
  flex-shrink: 0;
  height: 12px;
  width: 12px;
}
.photo-type__name {
  font-family: Georgia, serif;
  font-size: 1.08rem;
  font-weight: normal;
  margin: 0;
  flex: 1;
}
.photo-type__count {
  color: var(--muted);
  font-size: .78rem;
}

.photo-class { margin-bottom: 14px; }
.photo-class__name {
  color: var(--muted);
  font-size: .78rem;
  font-weight: 600;
  letter-spacing: .06em;
  margin: 0 0 8px;
  text-transform: uppercase;
}

.photo-class__grid {
  display: grid;
  gap: 10px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.photo-product {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 14px;
  color: inherit;
  display: flex;
  flex-direction: column;
  min-height: 100%;
  overflow: hidden;
  text-decoration: none;
  -webkit-tap-highlight-color: transparent;
  transition: transform .12s ease, box-shadow .12s ease;
}
.photo-product:active { transform: scale(.98); }
.photo-product.needs-photo { border-color: #f0d9a8; }
.photo-product__thumb {
  aspect-ratio: 1;
  background: #f3ece4;
  overflow: hidden;
  position: relative;
}
.photo-product__thumb img {
  height: 100%;
  object-fit: cover;
  width: 100%;
}
.photo-product__no-img {
  align-items: center;
  color: #c4b5a5;
  display: flex;
  font-size: 2rem;
  height: 100%;
  justify-content: center;
  width: 100%;
}
.photo-product__badge {
  border-radius: 999px;
  bottom: 8px;
  font-size: .65rem;
  font-weight: 600;
  left: 8px;
  padding: 4px 8px;
  position: absolute;
}
.photo-product__badge--missing {
  background: #fff3cd;
  color: #856404;
}
.photo-product__name {
  font-size: .88rem;
  line-height: 1.3;
  padding: 10px 10px 12px;
}

.photo-app__bar {
  margin-bottom: 8px;
  padding-top: env(safe-area-inset-top);
}
.photo-app__back {
  align-items: center;
  color: var(--terracotta);
  display: inline-flex;
  font-size: .95rem;
  min-height: 44px;
  text-decoration: none;
}

.photo-capture__crumb {
  align-items: center;
  color: var(--muted);
  display: flex;
  flex-wrap: wrap;
  font-size: .82rem;
  gap: 6px;
  margin: 0 0 6px;
}
.photo-capture__type-dot {
  border-radius: 50%;
  display: inline-block;
  height: 8px;
  width: 8px;
}
.photo-capture__title {
  font-family: Georgia, serif;
  font-size: 1.45rem;
  font-weight: normal;
  margin: 0 0 14px;
}
.photo-capture__hero {
  aspect-ratio: 4/3;
  background: #f3ece4;
  border-radius: 16px;
  margin-bottom: 16px;
  overflow: hidden;
}
.photo-capture__hero img {
  height: 100%;
  object-fit: cover;
  width: 100%;
}
.photo-capture__placeholder {
  align-items: center;
  color: var(--muted);
  display: flex;
  height: 100%;
  justify-content: center;
}
.photo-capture__actions {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 16px;
  margin-bottom: 20px;
  padding: 16px;
}
.photo-capture__camera-btn {
  align-items: center;
  background: var(--terracotta);
  border-radius: 14px;
  color: #fff;
  cursor: pointer;
  display: flex;
  font-size: 1.05rem;
  font-weight: 600;
  gap: 10px;
  justify-content: center;
  margin-bottom: 12px;
  min-height: 54px;
  width: 100%;
}
.photo-capture__sources {
  display: grid;
  gap: 12px;
  margin-bottom: 12px;
}
@media (min-width: 520px) {
  .photo-capture__sources {
    grid-template-columns: 1fr 1fr;
  }
}
.photo-capture__option {
  align-items: center;
  color: var(--muted);
  display: flex;
  font-size: .9rem;
  gap: 8px;
  justify-content: center;
}
.photo-capture__status {
  color: var(--muted);
  font-size: .88rem;
  margin: 10px 0 0;
  min-height: 1.2em;
  text-align: center;
}
.photo-capture__gallery-title {
  font-size: 1rem;
  margin: 0 0 10px;
}

.photo-gallery {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.photo-gallery__empty {
  color: var(--muted);
  grid-column: 1 / -1;
  margin: 0;
  text-align: center;
}
.photo-gallery__item {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.photo-gallery__item img {
  aspect-ratio: 1;
  object-fit: cover;
  width: 100%;
}
.photo-gallery__item-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px;
}
.photo-badge {
  border-radius: 999px;
  font-size: .72rem;
  padding: 4px 8px;
}
.photo-badge--primary {
  background: #e8f5ee;
  color: var(--green);
}
.photo-btn {
  border: 0;
  border-radius: 8px;
  cursor: pointer;
  font-size: .75rem;
  min-height: 32px;
  padding: 4px 10px;
}
.photo-btn--ghost {
  background: #faf6f1;
  color: var(--ink);
}
.photo-btn--danger {
  background: #fde8e8;
  color: #9b332c;
}

@media (min-width: 640px) {
  .photo-class__grid,
  .photo-gallery {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .photo-app { padding-left: 20px; padding-right: 20px; }
}

@media (min-width: 900px) {
  .photo-class__grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>

<script>
(function () {
  var searchInput = document.getElementById('productSearch');
  var filters = document.getElementById('photoFilters');
  var noResults = document.getElementById('noResults');
  var activeFilter = 'all';

  function applyBrowseFilters() {
    if (!searchInput) return;
    var q = searchInput.value.trim().toLowerCase();
    var visible = 0;

    document.querySelectorAll('.photo-product').forEach(function (card) {
      var nameMatch = !q || (card.getAttribute('data-name') || '').indexOf(q) !== -1;
      var hasPhoto = card.getAttribute('data-has-photo') === '1';
      var filterMatch = activeFilter === 'all'
        || (activeFilter === 'missing' && !hasPhoto)
        || (activeFilter === 'done' && hasPhoto);
      var show = nameMatch && filterMatch;
      card.hidden = !show;
      if (show) visible++;
    });

    document.querySelectorAll('.photo-class').forEach(function (section) {
      section.hidden = !section.querySelector('.photo-product:not([hidden])');
    });

    document.querySelectorAll('.photo-type').forEach(function (section) {
      section.hidden = !section.querySelector('.photo-product:not([hidden])');
    });

    if (noResults) {
      noResults.hidden = visible > 0;
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyBrowseFilters);
  }

  if (filters) {
    filters.addEventListener('click', function (e) {
      var btn = e.target.closest('.photo-filter');
      if (!btn) return;
      activeFilter = btn.getAttribute('data-filter') || 'all';
      filters.querySelectorAll('.photo-filter').forEach(function (b) {
        b.classList.toggle('is-active', b === btn);
      });
      applyBrowseFilters();
    });
  }

  var photoCameraInput = document.getElementById('photoCameraInput');
  var photoLibraryInput = document.getElementById('photoLibraryInput');
  var setPrimary = document.getElementById('setPrimary');
  var uploadStatus = document.getElementById('uploadStatus');
  var gallery = document.getElementById('gallery');
  var productIdEl = document.getElementById('selectedProductId');

  if ((!photoCameraInput && !photoLibraryInput) || !productIdEl) return;

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function uploadFile(file) {
    var productId = productIdEl.value;
    uploadStatus.textContent = 'Uploading…';
    var form = new FormData();
    form.append('action', 'upload');
    form.append('product_id', productId);
    form.append('set_primary', setPrimary.checked ? '1' : '0');
    form.append('photo', file);
    form.append('csrf_token', csrfToken());
    fetch('upload_product_photo.php', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: form
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          uploadStatus.textContent = 'Saved!';
          setTimeout(function () { window.location.reload(); }, 400);
        } else {
          uploadStatus.textContent = res.error || 'Upload failed';
        }
      })
      .catch(function () { uploadStatus.textContent = 'Network error'; });
  }

  if (photoCameraInput) {
    photoCameraInput.addEventListener('change', function () {
      if (photoCameraInput.files && photoCameraInput.files[0]) {
        uploadFile(photoCameraInput.files[0]);
      }
    });
  }
  if (photoLibraryInput) {
    photoLibraryInput.addEventListener('change', function () {
      if (photoLibraryInput.files && photoLibraryInput.files[0]) {
        uploadFile(photoLibraryInput.files[0]);
      }
    });
  }

  if (gallery) {
    gallery.addEventListener('click', function (e) {
      var item = e.target.closest('.gallery-item');
      if (!item) return;
      var imageId = item.getAttribute('data-image-id');
      var productId = productIdEl.value;
      var headers = {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      };

      if (e.target.classList.contains('btn-set-primary')) {
        var body = new URLSearchParams({
          action: 'set_primary',
          product_id: productId,
          image_id: imageId,
          csrf_token: csrfToken()
        });
        fetch('upload_product_photo.php', { method: 'POST', headers: headers, body: body.toString() })
          .then(function () { window.location.reload(); });
      }

      if (e.target.classList.contains('btn-delete')) {
        if (!confirm('Delete this photo?')) return;
        var delBody = new URLSearchParams({
          action: 'delete',
          product_id: productId,
          image_id: imageId,
          csrf_token: csrfToken()
        });
        fetch('upload_product_photo.php', { method: 'POST', headers: headers, body: delBody.toString() })
          .then(function () { window.location.reload(); });
      }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
