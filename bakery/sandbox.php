<?php
/**
 * Staging practice home for Jasmine. Links into screens that already exist.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sandbox.php';

$page_title = bakery_t('page.sandbox');
$sandboxAreas = bakery_sandbox_areas();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<main class="sandbox">
  <p class="sandbox__kicker"><?php echo $h(bakery_t('sandbox.kicker')); ?></p>
  <h1><?php echo $h(bakery_t('page.sandbox')); ?></h1>
  <p class="sandbox__intro"><?php echo $h(bakery_t('sandbox.intro')); ?></p>

  <div class="sandbox__rules">
    <p><?php echo $h(bakery_t('sandbox.staging_rule')); ?></p>
    <p><?php echo $h(bakery_t('sandbox.live_rule')); ?></p>
  </div>

  <h2><?php echo $h(bakery_t('sandbox.codes_heading')); ?></h2>
  <ul class="sandbox__seats">
    <li><?php echo $h(bakery_t('sandbox.seat.jasmine')); ?></li>
    <li><?php echo $h(bakery_t('sandbox.seat.sales')); ?></li>
    <li><?php echo $h(bakery_t('sandbox.seat.driver')); ?></li>
  </ul>
  <p class="sandbox__note"><?php echo $h(bakery_t('sandbox.codes_note')); ?></p>

  <div class="sandbox__areas">
    <?php foreach ($sandboxAreas as $area): ?>
      <?php $key = (string)$area['key']; ?>
      <section class="sandbox__area" id="<?php echo $h('sandbox-' . $key); ?>">
        <h2><?php echo $h(bakery_t('sandbox.area.' . $key)); ?></h2>
        <p><?php echo $h(bakery_t('sandbox.area_desc.' . $key)); ?></p>
        <ul class="sandbox__links">
          <?php foreach ($area['links'] as $link): ?>
            <?php
              $labelKey = (string)$link['label_key'];
              $label = bakery_t($labelKey);
              if ($label === $labelKey) {
                  $label = (string)$link['href'];
              }
            ?>
            <li>
              <a href="<?php echo $h(BASE_URL . (string)$link['href']); ?>"><?php echo $h($label); ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="sandbox__check"><?php echo $h(bakery_t('sandbox.check.' . $key)); ?></p>
      </section>
    <?php endforeach; ?>
  </div>

  <p class="sandbox__note"><?php echo $h(bakery_t('sandbox.docs_note')); ?></p>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
