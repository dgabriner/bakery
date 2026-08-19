<?php
declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$access = bakery_sfb_require_community_access($db);
$customer = $access['customer'];
$staffOnly = !$customer && !empty($access['staff']);

$batchId = (int)($_GET['batch'] ?? 0);
$batch = $batchId > 0 ? bakery_sfb_shared_batch($db, $batchId) : null;
if (!$batch) {
    header('Location: sfb_community.php');
    exit;
}

$formulaSnapshot = bakery_sfb_batch_formula_snapshot($db, $batchId);
$formulaLines = $formulaSnapshot ? bakery_sfb_batch_formula_snapshot_lines($db, $batchId) : [];
$formulaTarget = null;
if ($formulaSnapshot && $formulaSnapshot['target_dough_g'] !== null && $formulaLines) {
    $formulaTarget = bakery_sfb_formula_grams($formulaLines, (float)$formulaSnapshot['target_dough_g']);
    $formulaTarget['target'] = (float)$formulaSnapshot['target_dough_g'];
}
$photos = bakery_sfb_batch_photos($db, $batchId);
$turns = bakery_sfb_batch_turns($db, $batchId);
$temps = bakery_sfb_batch_temps($db, $batchId);
$phase = bakery_sfb_batch_phase($batch);

$temperatureValues = array_map(static function (array $temp): float { return (float)$temp['temp_f']; }, $temps);
$linkedTopics = bakery_sfb_community_topics_for_batch($db, $batchId, 8);
$viewerId = (int)($customer['id'] ?? 0);
$isOwner = $viewerId > 0 && $viewerId === (int)$batch['customer_id'];
$isStaff = !empty($access['staff']) || !empty($access['can_reply_as_coach']);
$feedUrl = bakery_sfb_community_feed_url();
$bulkDuration = bakery_sfb_community_duration_label($batch['bulk_started_at'], $batch['bulk_ended_at']);
$bakeDuration = bakery_sfb_community_duration_label($batch['bake_started_at'], $batch['bake_ended_at']);

$page_title = $batch['name'] . ' - ' . bakery_t('sfb.shared_batch_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = ($customer['name'] ?? '') !== '' ? $customer['name'] : bakery_t('sfb.origin_coach');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
</head>
<body class="sfb-body">
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container sfb-app">
    <?php if ($staffOnly): ?>
      <p class="sfb-back-link"><a href="sfb_admin_overview.php"><?php bakery_te('sfb.community_back_to_admin'); ?></a></p>
    <?php endif; ?>
    <?php $sfbActiveTab = 'community'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <p class="sfb-back-link"><a href="<?php echo htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_back'); ?></a></p>
    <section class="card sfb-shared-batch-hero">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.shared_batch_eyebrow'); ?></p>
        <h2><?php echo htmlspecialchars($batch['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="sfb-shared-batch-hero__formula"><?php echo htmlspecialchars($batch['formula_name'] ?? bakery_t('sfb.formula'), ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="meta-row">
          <span class="badge <?php echo $batch['status'] === 'completed' ? 'badge-ok' : ($batch['status'] === 'abandoned' ? 'badge-muted' : 'badge-info'); ?>"><?php echo htmlspecialchars(bakery_sfb_phase_label($phase), ENT_QUOTES, 'UTF-8'); ?></span>
          <span><?php echo htmlspecialchars($batch['baker_name'], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php echo bakery_sfb_render_origin_badge($batch); ?>
          <span><?php echo htmlspecialchars(date('M j, Y', strtotime($batch['started_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <p class="muted"><?php bakery_te('sfb.shared_batch_privacy'); ?></p>
        <div class="sfb-lane-actions">
          <?php if ($isOwner): ?>
            <a class="btn btn-secondary" href="sfb_batch.php?batch=<?php echo $batchId; ?>"><?php bakery_te('sfb.community_open_journal'); ?></a>
            <a class="btn" href="sfb_batch.php?batch=<?php echo $batchId; ?>#sfb-discussion"><?php bakery_te('sfb.community_ask_privately'); ?></a>
          <?php elseif ($isStaff): ?>
            <a class="btn" href="sfb_admin_batch.php?batch=<?php echo $batchId; ?>"><?php bakery_te('sfb.community_open_private_coach'); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($linkedTopics): ?>
      <section class="card" aria-labelledby="bakeDiscussionsHeading">
        <div class="card-header"><h2 id="bakeDiscussionsHeading"><?php bakery_te('sfb.community_bake_discussions'); ?></h2></div>
        <div class="card-body">
          <ul class="sfb-bake-discussions">
            <?php foreach ($linkedTopics as $linked):
              $linkedCopy = bakery_sfb_community_topic_copy($linked);
            ?>
              <li>
                <a href="<?php echo htmlspecialchars(bakery_sfb_community_topic_url((int)$linked['id']), ENT_QUOTES, 'UTF-8'); ?>">
                  <strong><?php echo htmlspecialchars($linkedCopy['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <span><?php echo htmlspecialchars(bakery_t('sfb.community_replies_count', ['count' => (int)$linked['reply_count']]), ENT_QUOTES, 'UTF-8'); ?>
                    · <?php echo htmlspecialchars(bakery_sfb_community_relative_time($linked['last_reply_at'] ?: $linked['created_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($formulaLines): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.formula'); ?><?php echo $formulaTarget ? ' - ' . (int)$formulaTarget['target'] . 'g' : ''; ?></h2></div>
        <div class="card-body">
          <p class="muted"><?php bakery_te('sfb.shared_batch_formula_note'); ?></p>
          <ul class="line-list">
            <?php foreach ($formulaTarget ? $formulaTarget['lines'] : $formulaLines as $line): ?>
              <li>
                <span><?php echo htmlspecialchars($line['line_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="line-qty"><?php echo (float)$line['percentage']; ?>%<?php echo isset($line['grams']) ? ' - ' . number_format((float)$line['grams'], 1) . 'g' : ''; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.shared_batch_facts'); ?></h2></div>
      <div class="card-body">
        <dl class="sfb-shared-facts">
          <div><dt><?php bakery_te('sfb.started_at'); ?></dt><dd><?php echo htmlspecialchars(date('M j, Y g:ia', strtotime($batch['started_at'])), ENT_QUOTES, 'UTF-8'); ?></dd></div>
          <?php if ($batch['status'] === 'completed'): ?><div><dt><?php bakery_te('sfb.loaf_count'); ?></dt><dd><?php echo (int)$batch['loaf_count']; ?></dd></div><?php endif; ?>
          <?php if ($batch['mix_minutes'] !== null): ?><div><dt><?php bakery_te('sfb.mix_minutes'); ?></dt><dd><?php echo (int)$batch['mix_minutes']; ?> min</dd></div><?php endif; ?>
          <?php if (!empty($batch['mix_speed'])): ?><div><dt><?php bakery_te('sfb.mix_speed'); ?></dt><dd><?php echo htmlspecialchars($batch['mix_speed'], ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
          <?php if ($bulkDuration !== null): ?><div><dt><?php bakery_te('sfb.shared_batch_bulk_time'); ?></dt><dd><?php echo htmlspecialchars($bulkDuration, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
          <?php if ($turns): ?><div><dt><?php bakery_te('sfb.turns'); ?></dt><dd><?php echo count($turns); ?></dd></div><?php endif; ?>
          <?php if ($temperatureValues): ?><div><dt><?php bakery_te('sfb.dough_temps'); ?></dt><dd><?php echo number_format(min($temperatureValues), 1); ?> - <?php echo number_format(max($temperatureValues), 1); ?> F</dd></div><?php endif; ?>
          <?php if ($batch['oven_temp_f'] !== null): ?><div><dt><?php bakery_te('sfb.oven_temp'); ?></dt><dd><?php echo number_format((float)$batch['oven_temp_f'], 0); ?> F</dd></div><?php endif; ?>
          <?php if ($bakeDuration !== null): ?><div><dt><?php bakery_te('sfb.shared_batch_bake_time'); ?></dt><dd><?php echo htmlspecialchars($bakeDuration, ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
        </dl>
      </div>
    </section>

    <?php if ($turns): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.shared_batch_turns'); ?></h2></div>
        <div class="card-body">
          <ol class="sfb-bake-log">
            <?php foreach ($turns as $turn): ?>
              <li>
                <strong><?php echo htmlspecialchars(bakery_sfb_turn_type_label($turn['turn_type']), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars(date('g:ia', strtotime($turn['occurred_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($turn['dough_temp_f'] !== null): ?>
                  <span><?php echo number_format((float)$turn['dough_temp_f'], 1); ?> F</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($temps): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.shared_batch_temps'); ?></h2></div>
        <div class="card-body">
          <ol class="sfb-bake-log">
            <?php foreach ($temps as $temp): ?>
              <li>
                <strong><?php echo htmlspecialchars(bakery_sfb_phase_label($temp['phase']), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars(date('g:ia', strtotime($temp['measured_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                <span><?php echo number_format((float)$temp['temp_f'], 1); ?> F</span>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($photos): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.photos'); ?></h2></div>
        <div class="card-body">
          <div class="sfb-shared-photos">
            <?php foreach ($photos as $photo): ?>
              <figure>
                <img src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path']), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?: bakery_t('sfb.photos'), ENT_QUOTES, 'UTF-8'); ?>">
                <?php if (!empty($photo['caption'])): ?><figcaption><?php echo htmlspecialchars($photo['caption'], ENT_QUOTES, 'UTF-8'); ?></figcaption><?php endif; ?>
              </figure>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php
    $sfbLibraryBatchId = $batchId;
    $sfbLibraryBatch = $batch;
    $sfbLibraryTurns = $turns;
    $sfbLibraryTemps = $temps;
    $sfbLibraryFormulaLines = $formulaLines;
    $sfbLibraryShowReview = ($batch['status'] ?? '') === 'completed';
    $sfbLibraryCanAsk = !$staffOnly;
    require __DIR__ . '/includes/sfb_library_panel.php';
    ?>
  </main>
  <?php if (!$staffOnly) { require __DIR__ . '/includes/portal_nav.php'; } ?>
</body>
</html>
