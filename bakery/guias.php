<?php
/**
 * Public Spanish driver walkthrough gallery — a link that can be texted to drivers.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/walkthroughs.php';

if (function_exists('bakery_set_locale')) {
    bakery_set_locale('es', false);
}

$page_title = bakery_t('page.driver_guides');
$items = bakery_driver_walkthrough_items();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="app-base-url" content="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
  <?php require __DIR__ . '/includes/client_refresh.php'; ?>
  <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?> — Sour Flour OS</title>
  <link rel="stylesheet" href="<?php echo bakery_asset_href('css/tokens.css'); ?>">
  <link rel="stylesheet" href="<?php echo bakery_asset_href('css/driver_guides.css'); ?>">
</head>
<body class="driver-guides">
  <header class="driver-guides-hero">
    <div class="driver-guides-brands" aria-hidden="true">
      <img src="<?php echo bakery_asset_href('assets/logos/la-victoria.png'); ?>" alt="">
      <img src="<?php echo bakery_asset_href('assets/logos/sour-flour-full.png'); ?>" alt="">
    </div>
    <p class="driver-guides-kicker"><?php bakery_te('walkthroughs.driver.kicker'); ?></p>
    <h1><?php bakery_te('page.driver_guides'); ?></h1>
    <p class="driver-guides-lead"><?php bakery_te('walkthroughs.driver.lead'); ?></p>
  </header>

  <main class="driver-guides-list">
    <?php foreach ($items as $item): ?>
      <?php
      $id = (string)$item['id'];
      $href = bakery_walkthrough_href($id, 'es');
      ?>
      <article class="driver-guides-card" id="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
        <h2><?php bakery_te($item['title_key']); ?></h2>
        <p><?php bakery_te($item['desc_key']); ?></p>
        <?php if ($href): ?>
          <video controls playsinline preload="metadata" src="<?php echo $href; ?>"></video>
        <?php else: ?>
          <p class="driver-guides-empty"><?php bakery_te('walkthroughs.driver.missing'); ?></p>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </main>
</body>
</html>
