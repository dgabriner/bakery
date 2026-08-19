<?php
/**
 * Diagnose / review / ask panel. Include after setting:
 *   $sfbLibraryBatchId, $sfbLibraryBatch, $sfbLibraryTurns, $sfbLibraryTemps,
 *   $sfbLibraryFormulaLines, $sfbLibraryShowReview, $sfbLibraryCanAsk
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$sfbLibraryBatchId = (int)($sfbLibraryBatchId ?? 0);
$sfbLibraryBatch = is_array($sfbLibraryBatch ?? null) ? $sfbLibraryBatch : [];
$sfbLibraryTurns = is_array($sfbLibraryTurns ?? null) ? $sfbLibraryTurns : [];
$sfbLibraryTemps = is_array($sfbLibraryTemps ?? null) ? $sfbLibraryTemps : [];
$sfbLibraryFormulaLines = is_array($sfbLibraryFormulaLines ?? null) ? $sfbLibraryFormulaLines : [];
$sfbLibraryShowReview = !empty($sfbLibraryShowReview);
$sfbLibraryCanAsk = !empty($sfbLibraryCanAsk);
$sfbLibrarySuggestions = bakery_sfb_library_diagnose_suggestions(
    $sfbLibraryBatch,
    $sfbLibraryTurns,
    $sfbLibraryTemps,
    $sfbLibraryFormulaLines
);
?>
<section class="card sfb-library-panel" id="sfb-review">
  <div class="card-body">
    <?php if ($sfbLibraryShowReview): ?>
      <p class="hero-label"><?php bakery_te('sfb.library_review_title'); ?></p>
      <p class="muted"><?php bakery_te('sfb.library_review_lead'); ?></p>
      <ol class="sfb-review-list">
        <?php foreach (bakery_sfb_library_review_slugs() as $slug):
            $piece = bakery_sfb_library_piece($slug);
            if (!$piece) {
                continue;
            }
        ?>
          <li>
            <a href="sfb_resources.php#library-<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($piece['title_key']); ?></a>
            <?php if ($sfbLibraryCanAsk): ?>
              <a class="btn-link" href="<?php echo htmlspecialchars(bakery_sfb_library_ask_url($slug, $sfbLibraryBatchId), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.library_ask'); ?></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>

    <p class="hero-label"<?php echo $sfbLibraryShowReview ? ' style="margin-top:16px;"' : ''; ?>><?php bakery_te('sfb.library_diagnose_title'); ?></p>
    <p class="muted"><?php bakery_te('sfb.library_diagnose_lead'); ?></p>
    <?php if ($sfbLibrarySuggestions): ?>
      <p class="sfb-library-panel__suggest"><?php bakery_te('sfb.library_diagnose_from_card'); ?></p>
      <ul class="sfb-diagnose-chips">
        <?php foreach ($sfbLibrarySuggestions as $slug):
            $piece = bakery_sfb_library_piece($slug);
            if (!$piece) {
                continue;
            }
        ?>
          <li>
            <a href="sfb_resources.php#library-<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($piece['title_key']); ?></a>
            <?php if ($sfbLibraryCanAsk): ?>
              <a class="btn-link" href="<?php echo htmlspecialchars(bakery_sfb_library_ask_url($slug, $sfbLibraryBatchId), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.library_ask'); ?></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <ul class="sfb-diagnose-chips">
      <?php foreach (bakery_sfb_library_diagnose_common_slugs() as $slug):
          $piece = bakery_sfb_library_piece($slug);
          if (!$piece) {
              continue;
          }
      ?>
        <li>
          <a href="sfb_resources.php#library-<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($piece['title_key']); ?></a>
          <?php if ($sfbLibraryCanAsk): ?>
            <a class="btn-link" href="<?php echo htmlspecialchars(bakery_sfb_library_ask_url($slug, $sfbLibraryBatchId), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.library_ask'); ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
