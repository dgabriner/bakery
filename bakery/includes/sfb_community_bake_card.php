<?php
/**
 * Compact bake card for a community thread. Expects $sfbBakeSummary from
 * bakery_sfb_community_bake_summary() and optional $sfbBakeViewerCustomerId.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$summary = $sfbBakeSummary ?? null;
if (!is_array($summary) || empty($summary['batch'])) {
    return;
}
$bake = $summary['batch'];
$bakeId = (int)$bake['id'];
$viewerId = (int)($sfbBakeViewerCustomerId ?? 0);
$isOwner = $viewerId > 0 && $viewerId === (int)$bake['customer_id'];
$isStaff = !empty($sfbBakeViewerIsStaff);
$facts = [];
if ($summary['formula_name'] !== '') {
    $facts[] = $summary['formula_name'];
}
if ($summary['loaf_count'] > 0) {
    $facts[] = (string)$summary['loaf_count'] . ' ' . bakery_t($summary['loaf_count'] === 1 ? 'sfb.loaf' : 'sfb.loaves');
}
if ($summary['turn_count'] > 0) {
    $facts[] = bakery_t('sfb.community_bake_turns', ['count' => $summary['turn_count']]);
}
if ($summary['temp_min'] !== null && $summary['temp_max'] !== null) {
    $facts[] = number_format((float)$summary['temp_min'], 1) . '–' . number_format((float)$summary['temp_max'], 1) . ' F';
}
if ($summary['oven_temp_f'] !== null) {
    $facts[] = number_format((float)$summary['oven_temp_f'], 0) . ' F';
}
if ($summary['bulk_duration']) {
    $facts[] = bakery_t('sfb.shared_batch_bulk_time') . ' ' . $summary['bulk_duration'];
}
$fullCardUrl = bakery_sfb_community_shared_batch_url($bakeId);
?>
<aside class="sfb-inline-bake" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.community_shared_bake'), ENT_QUOTES, 'UTF-8'); ?>">
  <p class="sfb-inline-bake__label"><?php bakery_te('sfb.community_shared_bake'); ?></p>
  <h3><?php echo htmlspecialchars((string)$bake['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
  <p class="sfb-inline-bake__baker">
    <?php echo htmlspecialchars((string)$bake['baker_name'], ENT_QUOTES, 'UTF-8'); ?>
    <?php echo bakery_sfb_render_origin_badge($bake); ?>
  </p>
  <?php if ($facts): ?>
    <ul class="sfb-inline-bake__facts">
      <?php foreach ($facts as $fact): ?>
        <li><?php echo htmlspecialchars($fact, ENT_QUOTES, 'UTF-8'); ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!empty($summary['photos'])): ?>
    <div class="sfb-inline-bake__photos">
      <?php foreach ($summary['photos'] as $photo): ?>
        <img src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?: bakery_t('sfb.photos'), ENT_QUOTES, 'UTF-8'); ?>">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="sfb-lane-actions">
    <a class="btn btn-secondary" href="<?php echo htmlspecialchars($fullCardUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_view_bake'); ?></a>
    <?php if ($isOwner): ?>
      <a class="btn btn-secondary" href="sfb_batch.php?batch=<?php echo $bakeId; ?>"><?php bakery_te('sfb.community_open_journal'); ?></a>
      <a class="btn" href="sfb_batch.php?batch=<?php echo $bakeId; ?>#sfb-discussion"><?php bakery_te('sfb.community_ask_privately'); ?></a>
    <?php elseif ($isStaff): ?>
      <a class="btn" href="sfb_admin_batch.php?batch=<?php echo $bakeId; ?>"><?php bakery_te('sfb.community_open_private_coach'); ?></a>
    <?php endif; ?>
  </div>
</aside>
