<?php
/**
 * In-app gallery of English and Spanish usage walkthroughs.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/walkthroughs.php';

bakery_require_role(['administrator', 'manager']);

$page_title = bakery_t('page.walkthroughs');
$items = bakery_walkthrough_items();
$uiLocale = bakery_locale() === 'es' ? 'es' : 'en';
$locales = $uiLocale === 'es' ? ['es', 'en'] : ['en', 'es'];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/walkthroughs.css'); ?>">
<main class="walkthroughs-page">
  <header class="walkthroughs-hero">
    <p class="walkthroughs-kicker"><?php bakery_te('walkthroughs.kicker'); ?></p>
    <h1><?php bakery_te('page.walkthroughs'); ?></h1>
    <p class="walkthroughs-lead"><?php bakery_te('walkthroughs.lead'); ?></p>
  </header>

  <p class="walkthroughs-note"><?php bakery_te('walkthroughs.note'); ?></p>

  <div class="walkthroughs-grid">
    <?php foreach ($items as $item): ?>
      <?php
      $id = (string)$item['id'];
      $published = [];
      foreach (['en', 'es'] as $locale) {
          $href = bakery_walkthrough_href($id, $locale);
          if ($href !== null) {
              $published[$locale] = $href;
          }
      }
      ?>
      <article class="walkthroughs-card" id="walkthrough-<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
        <h2><?php bakery_te($item['title_key']); ?></h2>
        <p><?php bakery_te($item['desc_key']); ?></p>
        <?php if ($published === []): ?>
          <p class="walkthroughs-empty"><?php bakery_te('walkthroughs.empty'); ?></p>
          <p class="walkthroughs-hint"><code><?php bakery_te('walkthroughs.record_hint'); ?></code></p>
        <?php else: ?>
          <div class="walkthroughs-videos">
            <?php foreach ($locales as $locale): ?>
              <figure class="walkthroughs-video<?php echo $locale === $uiLocale ? ' is-preferred' : ''; ?>">
                <figcaption><?php bakery_te('walkthroughs.locale_' . $locale); ?></figcaption>
                <?php if (!empty($published[$locale])): ?>
                  <video controls playsinline preload="<?php echo $locale === $uiLocale ? 'metadata' : 'none'; ?>" src="<?php echo $published[$locale]; ?>"></video>
                  <a class="walkthroughs-download" href="<?php echo $published[$locale]; ?>" download><?php bakery_te('walkthroughs.download'); ?></a>
                <?php else: ?>
                  <p class="walkthroughs-empty"><?php bakery_te('walkthroughs.missing'); ?></p>
                <?php endif; ?>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
