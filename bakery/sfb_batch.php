<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';
require_once __DIR__ . '/includes/sfb_photo_handler.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$batchId = (int)($_REQUEST['batch'] ?? 0);
$batch = $batchId > 0 ? bakery_sfb_batch($db, $customerId, $batchId) : null;
if (!$batch) {
    header('Location: sfb_batches.php');
    exit;
}

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $editable = $batch['status'] === 'in_progress';
        switch ($_POST['action']) {
            case 'save_mix':
                bakery_sfb_save_batch_mix(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['mix_minutes'] ?? 0,
                    $_POST['mix_speed'] ?? '',
                    $_POST['mix_notes'] ?? '',
                    $_POST['mix_completed_at'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=mix');
                exit;

            case 'save_development':
                bakery_sfb_save_batch_bulk(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['bulk_started_at'] ?? '',
                    $_POST['bulk_ended_at'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=development');
                exit;

            case 'add_turn':
                bakery_sfb_add_batch_turn(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['turn_type'] ?? 'stretch_fold',
                    $_POST['dough_temp_f'] ?? '',
                    $_POST['occurred_at'] ?? '',
                    $_POST['notes'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=turn');
                exit;

            case 'delete_turn':
                if (!$editable) { throw new RuntimeException('This batch is closed'); }
                $db->prepare('DELETE FROM sfb_batch_turns WHERE id = ? AND batch_id = ?')
                    ->execute([(int)($_POST['turn_id'] ?? 0), (int)$batch['id']]);
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=turn_deleted');
                exit;

            case 'save_shape':
                bakery_sfb_save_batch_shape(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['shaped_at'] ?? '',
                    $_POST['shape_notes'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=shape');
                exit;

            case 'save_bake':
                bakery_sfb_save_batch_bake(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['oven_temp_f'] ?? '',
                    $_POST['bake_started_at'] ?? '',
                    $_POST['bake_ended_at'] ?? '',
                    $_POST['bake_notes'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=bake');
                exit;

            case 'add_temp':
                bakery_sfb_add_batch_temp(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['temp_f'] ?? 0,
                    $_POST['phase'] ?? 'development',
                    $_POST['measured_at'] ?? '',
                    $_POST['notes'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=temp');
                exit;

            case 'delete_temp':
                if (!$editable) { throw new RuntimeException('This batch is closed'); }
                $db->prepare('DELETE FROM sfb_batch_temps WHERE id = ? AND batch_id = ?')
                    ->execute([(int)($_POST['temp_id'] ?? 0), (int)$batch['id']]);
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=temp_deleted');
                exit;

            case 'upload_photo':
                if (!$editable) { throw new RuntimeException('This batch is closed'); }
                $handler = new SfbPhotoHandler();
                $result = $handler->processUpload(
                    $db,
                    $_FILES['photo'] ?? [],
                    (int)$batch['id'],
                    $customerId,
                    (string)($_POST['phase'] ?? 'final'),
                    $_POST['caption'] ?? null
                );
                if (!$result['success']) {
                    throw new RuntimeException($result['error']);
                }
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=photo');
                exit;

            case 'delete_photo':
                if (!$editable) { throw new RuntimeException('This batch is closed'); }
                $handler = new SfbPhotoHandler();
                $handler->deletePhoto($db, (int)$batch['id'], $customerId, (int)($_POST['photo_id'] ?? 0));
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=photo_deleted');
                exit;

            case 'complete_batch':
                bakery_sfb_complete_batch(
                    $db,
                    $customerId,
                    (int)$batch['id'],
                    $_POST['loaf_count'] ?? 0,
                    $_POST['final_notes'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=completed');
                exit;

            case 'unshare_batch':
                bakery_require_csrf();
                bakery_sfb_unshare_batch($db, $customerId, (int)$batch['id']);
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=unshared');
                exit;

            case 'share_to_community':
                bakery_require_csrf();
                if ($batch['status'] !== 'completed') {
                    throw new RuntimeException('Share a completed bake card');
                }
                $topicId = bakery_sfb_create_community_topic(
                    $db,
                    $customerId,
                    $_POST['title'] ?? $batch['name'],
                    $_POST['body'] ?? '',
                    $_POST['category'] ?? 'general',
                    (int)$batch['id']
                );
                header('Location: sfb_community_topic.php?topic=' . $topicId . '&saved=created');
                exit;

            case 'add_discussion':
                $messageType = (string)($_POST['message_type'] ?? 'comment');
                bakery_sfb_add_batch_message(
                    $db,
                    (int)$batch['id'],
                    'baker',
                    (string)$customer['name'],
                    (string)($_POST['body'] ?? ''),
                    $messageType,
                    $customerId,
                    null,
                    null,
                    (string)($_POST['phase'] ?? '')
                );
                header('Location: sfb_batch.php?batch=' . (int)$batch['id'] . '&saved=discussion#sfb-discussion');
                exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

// Reload after any mutation attempt so forms show current values.
$batch = bakery_sfb_batch($db, $customerId, (int)$batch['id']);
$editable = $batch['status'] === 'in_progress';
$phase = bakery_sfb_batch_phase($batch);
$turns = bakery_sfb_batch_turns($db, (int)$batch['id']);
$temps = bakery_sfb_batch_temps($db, (int)$batch['id']);
$photos = bakery_sfb_batch_photos($db, (int)$batch['id']);
$discussionMessages = bakery_sfb_batch_messages($db, (int)$batch['id']);
$discussionThreads = bakery_sfb_message_threads($discussionMessages);
$answeredCount = 0;
foreach ($discussionThreads['roots'] as $rootMessage) {
    if (($rootMessage['message_type'] ?? '') === 'question' && (int)($rootMessage['is_resolved'] ?? 0) === 1) {
        $answeredCount++;
    }
}
$batchShare = bakery_sfb_batch_share($db, (int)$batch['id']);
$photosByPhase = [];
foreach ($photos as $photo) {
    $photosByPhase[$photo['phase']][] = $photo;
}
$formulaSnapshot = bakery_sfb_batch_formula_snapshot($db, (int)$batch['id']);
$formulaLines = $formulaSnapshot
    ? bakery_sfb_batch_formula_snapshot_lines($db, (int)$batch['id'])
    : ($batch['formula_id'] ? bakery_sfb_formula_lines($db, (int)$batch['formula_id']) : []);
$formulaTarget = null;
if ($formulaSnapshot && $formulaSnapshot['target_dough_g'] !== null && $formulaLines) {
    $formulaTarget = bakery_sfb_formula_grams($formulaLines, (float)$formulaSnapshot['target_dough_g']);
    $formulaTarget['target'] = (float)$formulaSnapshot['target_dough_g'];
} elseif ($batch['formula_id']) {
    $formulaRow = bakery_sfb_formula($db, $customerId, (int)$batch['formula_id']);
    if ($formulaRow && $formulaRow['target_dough_g'] !== null && $formulaLines) {
        $formulaTarget = bakery_sfb_formula_grams($formulaLines, (float)$formulaRow['target_dough_g']);
        $formulaTarget['target'] = (float)$formulaRow['target_dough_g'];
    }
}

// Version truth: has the source formula moved since this batch froze its copy?
$formulaDrift = null;
$formulaSourceRemoved = false;
if ($formulaSnapshot && !empty($formulaSnapshot['source_formula_id'])) {
    $snapshotSourceId = (int)$formulaSnapshot['source_formula_id'];
    $sourceLines = bakery_sfb_formula_lines($db, $snapshotSourceId);
    if ($sourceLines && $formulaLines) {
        $formulaDrift = bakery_sfb_snapshot_drift($formulaLines, $sourceLines);
    } elseif (!$sourceLines && !bakery_sfb_formula($db, $customerId, $snapshotSourceId)) {
        $formulaSourceRemoved = true;
    }
}

$builderReady = bakery_sfb_builder_ready($db);
$askPhase = (string)($_GET['ask'] ?? '');
if (!in_array($askPhase, bakery_sfb_builder_phases(), true)) {
    $askPhase = '';
}

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'started' => 'Batch started — happy mixing!',
    'mix' => 'Mix saved.',
    'development' => 'Bulk fermentation saved.',
    'turn' => 'Turn logged.',
    'turn_deleted' => 'Turn removed.',
    'shape' => 'Shaping saved.',
    'bake' => 'Bake saved.',
    'temp' => 'Dough temperature logged.',
    'temp_deleted' => 'Temperature removed.',
    'photo' => 'Photo added.',
    'photo_deleted' => 'Photo removed.',
    'completed' => 'Batch complete! Loaves added to your journey.',
    'unshared' => bakery_t('sfb.batch_unshared_saved'),
    'shared' => bakery_t('sfb.batch_share_one_click_saved'),
    'discussion' => 'Your message was shared with Sour Flour.',
];

$photoPhases = ['starter', 'mix', 'development', 'shape', 'bake', 'final'];
$nextSteps = [
    'mix' => ['action' => 'sfb.next_mix', 'hint' => 'sfb.next_mix_hint', 'anchor' => 'sfb-mix'],
    'development' => ['action' => 'sfb.next_bulk', 'hint' => 'sfb.next_bulk_hint', 'anchor' => 'sfb-bulk'],
    'shape' => ['action' => 'sfb.next_shape', 'hint' => 'sfb.next_shape_hint', 'anchor' => 'sfb-shape'],
    'bake' => ['action' => 'sfb.next_bake', 'hint' => 'sfb.next_bake_hint', 'anchor' => 'sfb-bake'],
];
$nextStep = $editable ? ($nextSteps[$phase] ?? null) : null;

$page_title = 'SF Baker — ' . $batch['name'];
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
</head>
<body class="sfb-body">
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container sfb-app">
    <?php $sfbActiveTab = 'batches'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card hero-card">
      <div class="card-body">
        <p class="hero-label"><?php echo htmlspecialchars($batch['formula_name'] ?? bakery_t('sfb.tab_batches')); ?></p>
        <h2 class="hero-date"><?php echo htmlspecialchars($batch['name']); ?></h2>
        <div class="meta-row">
          <span class="badge <?php echo $batch['status'] === 'completed' ? 'badge-ok' : ($batch['status'] === 'abandoned' ? 'badge-muted' : 'badge-info'); ?>">
            <?php echo htmlspecialchars(bakery_sfb_phase_label($phase)); ?>
          </span>
          <span><?php echo htmlspecialchars(date('D, M j · g:ia', strtotime($batch['started_at']))); ?></span>
          <?php if ($batch['status'] === 'completed'): ?>
            <span><?php echo (int)$batch['loaf_count']; ?> <?php echo (int)$batch['loaf_count'] === 1 ? bakery_t('sfb.loaf') : bakery_t('sfb.loaves'); ?></span>
          <?php endif; ?>
        </div>
        <div class="sfb-timeline">
          <?php
          $stages = ['mix', 'development', 'shape', 'bake', 'done'];
          $nowIdx = array_search($phase, $stages, true);
          foreach ($stages as $idx => $stage):
              $cls = '';
              if ($phase === 'abandoned') { $cls = ''; }
              elseif ($nowIdx !== false && $idx < $nowIdx) { $cls = 'hit'; }
              elseif ($idx === $nowIdx) { $cls = $phase === 'done' ? 'hit' : 'now'; }
          ?>
            <span class="<?php echo $cls; ?>"><?php echo htmlspecialchars(bakery_sfb_phase_label($stage)); ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="card sfb-batch-share">
      <div class="card-body">
        <?php if ($batchShare): ?>
          <p class="hero-label"><?php bakery_te('sfb.batch_shared_eyebrow'); ?></p>
          <h2><?php bakery_te('sfb.batch_shared_title'); ?></h2>
          <p class="muted"><?php bakery_te('sfb.batch_shared_copy'); ?></p>
          <div class="btn-row">
            <a class="btn btn-secondary btn-block" href="<?php echo htmlspecialchars(bakery_sfb_community_shared_batch_url((int)$batch['id']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.batch_view_shared'); ?></a>
            <a class="btn btn-block" href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['batch' => (int)$batch['id'], 'hash' => 'start-discussion', 'compose' => '1']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_start'); ?></a>
            <a class="btn btn-secondary btn-block" href="#sfb-discussion"><?php bakery_te('sfb.community_ask_privately'); ?></a>
          </div>
          <form method="post" class="sfb-batch-share__revoke">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="unshare_batch">
            <button type="submit" class="btn-link"><?php bakery_te('sfb.batch_stop_sharing'); ?></button>
          </form>
        <?php else: ?>
          <p class="hero-label"><?php bakery_te('sfb.batch_share_eyebrow'); ?></p>
          <h2><?php bakery_te($batch['status'] === 'completed' ? 'sfb.batch_share_one_click' : 'sfb.batch_share_title'); ?></h2>
          <p class="muted"><?php bakery_te($batch['status'] === 'completed' ? 'sfb.batch_share_one_click_copy' : 'sfb.batch_share_copy'); ?></p>
          <?php if ($batch['status'] === 'completed' && bakery_sfb_community_ready($db)): ?>
            <form method="post" class="inline-form" style="grid-template-columns:1fr;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="share_to_community">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.community_category'); ?></span>
                  <select name="category">
                    <?php foreach (bakery_sfb_community_categories($db) as $categoryOption): ?>
                      <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te(bakery_sfb_community_category_key($categoryOption)); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.community_title_label'); ?></span>
                  <input type="text" name="title" maxlength="160" required value="<?php echo htmlspecialchars((string)$batch['name'], ENT_QUOTES, 'UTF-8'); ?>">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.community_post'); ?></span>
                  <textarea name="body" rows="3" maxlength="4000" required placeholder="<?php bakery_te('sfb.community_post_placeholder'); ?>"></textarea>
                </label>
              </div>
              <button type="submit" class="btn btn-block"><?php bakery_te('sfb.batch_share_one_click'); ?></button>
            </form>
            <p class="sfb-lane-split"><a href="#sfb-discussion"><?php bakery_te('sfb.community_ask_privately'); ?></a></p>
          <?php else: ?>
            <div class="btn-row">
              <a class="btn btn-block" href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['batch' => (int)$batch['id'], 'hash' => 'start-discussion', 'compose' => '1']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.batch_share_cta'); ?></a>
              <a class="btn btn-secondary btn-block" href="#sfb-discussion"><?php bakery_te('sfb.community_ask_privately'); ?></a>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <?php
    $sfbLibraryBatchId = (int)$batch['id'];
    $sfbLibraryBatch = $batch;
    $sfbLibraryTurns = $turns;
    $sfbLibraryTemps = $temps;
    $sfbLibraryFormulaLines = $formulaLines;
    $sfbLibraryShowReview = ($batch['status'] ?? '') === 'completed';
    $sfbLibraryCanAsk = true;
    require __DIR__ . '/includes/sfb_library_panel.php';
    ?>
    <?php if ($nextStep): ?>
      <section class="card sfb-phase current" aria-labelledby="sfbCurrentStep">
        <div class="card-body">
          <p class="hero-label" style="margin-top:0;"><?php bakery_te('sfb.current_step'); ?></p>
          <h2 id="sfbCurrentStep" style="margin:0 0 6px;"><?php bakery_te($nextStep['action']); ?></h2>
          <p class="muted" style="margin:0 0 14px;"><?php bakery_te($nextStep['hint']); ?></p>
          <a class="btn btn-block" href="#<?php echo htmlspecialchars($nextStep['anchor']); ?>">
            <?php bakery_te($nextStep['action']); ?>
          </a>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($builderReady): ?>
      <?php
      $builderSteps = [
          ['phase' => 'mix', 'label' => 'sfb.phase_mix', 'anchor' => 'sfb-mix'],
          ['phase' => 'development', 'label' => 'sfb.phase_development', 'anchor' => 'sfb-bulk'],
          ['phase' => 'shape', 'label' => 'sfb.phase_shape', 'anchor' => 'sfb-shape'],
          ['phase' => 'bake', 'label' => 'sfb.phase_bake', 'anchor' => 'sfb-bake'],
      ];
      $stageOrder = array_search($phase, ['mix', 'development', 'shape', 'bake', 'done'], true);
      ?>
      <section class="card" aria-labelledby="sfbBuilderSteps">
        <div class="card-header"><h2 id="sfbBuilderSteps"><?php bakery_te('sfb.builder_steps_title'); ?></h2></div>
        <div class="card-body">
          <ul class="line-list">
            <?php foreach ($builderSteps as $stepIdx => $step): ?>
              <?php
              $stepState = 'todo';
              if ($batch['status'] === 'completed') {
                  $stepState = 'done';
              } elseif ($stageOrder !== false && $stepIdx < $stageOrder) {
                  $stepState = 'done';
              } elseif ($stageOrder !== false && $stepIdx === $stageOrder) {
                  $stepState = 'current';
              }
              ?>
              <li>
                <span>
                  <span class="badge <?php echo $stepState === 'done' ? 'badge-ok' : ($stepState === 'current' ? 'badge-info' : 'badge-muted'); ?>">
                    <?php echo bakery_t($stepState === 'done' ? 'sfb.builder_step_done' : ($stepState === 'current' ? 'sfb.builder_step_current' : 'sfb.builder_step_todo')); ?>
                  </span>
                  <a href="#<?php echo htmlspecialchars($step['anchor']); ?>" style="color:inherit;"><?php bakery_te($step['label']); ?></a>
                </span>
                <span class="line-qty">
                  <a class="btn-link" href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>&ask=<?php echo $step['phase']; ?>#sfb-discussion"><?php bakery_te('sfb.ask_about_step'); ?></a>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($formulaLines): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.formula'); ?><?php echo $formulaTarget ? ' — ' . (int)$formulaTarget['target'] . 'g' : ''; ?></h2></div>
        <div class="card-body">
          <?php if ($formulaSnapshot): ?>
            <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.formula_snapshot'); ?></p>
          <?php endif; ?>
          <?php if ($formulaDrift && $formulaDrift['drifted']): ?>
            <div class="notice notice--warn" style="margin:0 0 12px;">
              <strong><?php bakery_te('sfb.formula_drift_title'); ?></strong>
              <?php
              $driftParts = [];
              foreach (['changed', 'added', 'removed'] as $driftKind) {
                  if ($formulaDrift[$driftKind]) {
                      $driftParts[] = htmlspecialchars(implode(', ', $formulaDrift[$driftKind]));
                  }
              }
              echo implode(' · ', $driftParts);
              ?>
            </div>
          <?php elseif ($formulaSourceRemoved): ?>
            <p class="muted" style="margin:0 0 12px;"><?php bakery_te('sfb.formula_source_removed'); ?></p>
          <?php endif; ?>
          <ul class="line-list">
            <?php foreach ($formulaTarget ? $formulaTarget['lines'] : $formulaLines as $line): ?>
              <li>
                <span><?php echo htmlspecialchars($line['line_name']); ?></span>
                <span class="line-qty">
                  <?php echo (float)$line['percentage']; ?>%<?php echo isset($line['grams']) ? ' · ' . number_format((float)$line['grams'], 1) . 'g' : ''; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <section id="sfb-mix" class="card sfb-phase <?php echo $phase === 'mix' ? 'current' : ''; ?>">
      <div class="card-header"><h2>1. <?php bakery_te('sfb.phase_mix'); ?></h2>
        <?php if ($builderReady): ?><span style="float:right;"><a class="btn-link" href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>&ask=mix#sfb-discussion"><?php bakery_te('sfb.ask_about_step'); ?></a></span><?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="save_mix">
          <div class="sfb-grid2">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.mix_minutes'); ?></span>
                <input type="number" name="mix_minutes" min="0" max="600" step="1" value="<?php echo $batch['mix_minutes'] !== null ? (int)$batch['mix_minutes'] : ''; ?>" placeholder="<?php bakery_te('sfb.enter_minutes'); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.mix_speed'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_mix_method')); ?></span>
                <input type="text" name="mix_speed" maxlength="50" placeholder="<?php bakery_te('sfb.enter_method'); ?>" value="<?php echo htmlspecialchars($batch['mix_speed'] ?? ''); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
              </label>
            </div>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.mix_finished_at'); ?></span>
              <input type="datetime-local" name="mix_completed_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($batch['mix_completed_at'])); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
            </label>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.mix_notes'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_mix_notes')); ?></span>
              <textarea name="mix_notes" rows="2" placeholder="<?php bakery_te('sfb.enter_notes'); ?>" <?php echo $editable ? '' : 'disabled'; ?>><?php echo htmlspecialchars($batch['mix_notes'] ?? ''); ?></textarea>
            </label>
          </div>
          <?php if ($editable): ?>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
          <?php endif; ?>
        </form>
        <?php $phasePhotos = $photosByPhase['mix'] ?? []; if ($phasePhotos): ?>
          <div class="sfb-photos">
            <?php foreach ($phasePhotos as $photo): ?>
              <div class="sfb-photo-wrap">
                <img class="sfb-photo" src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? 'Mix photo'); ?>">
                <?php if ($editable): ?>
                  <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>"><button class="sfb-photo-del" type="submit" aria-label="Delete">✕</button></form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section id="sfb-bulk" class="card sfb-phase <?php echo $phase === 'development' ? 'current' : ''; ?>">
      <div class="card-header"><h2>2. <?php bakery_te('sfb.phase_development'); ?></h2>
        <?php if ($builderReady): ?><span style="float:right;"><a class="btn-link" href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>&ask=development#sfb-discussion"><?php bakery_te('sfb.ask_about_step'); ?></a></span><?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="save_development">
          <div class="sfb-grid2">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.bulk_started'); ?></span>
                <input type="datetime-local" name="bulk_started_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($batch['bulk_started_at'])); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.bulk_ended'); ?></span>
                <input type="datetime-local" name="bulk_ended_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($batch['bulk_ended_at'])); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
              </label>
            </div>
          </div>
          <?php if ($editable): ?>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
          <?php endif; ?>
        </form>

        <h3 style="font-size:.95rem;margin:16px 0 8px;"><?php bakery_te('sfb.turns'); ?> (<?php echo count($turns); ?>)</h3>
        <?php foreach ($turns as $turn): ?>
          <div class="sfb-turn">
            <div style="display:flex;justify-content:space-between;gap:10px;">
              <span>
                <strong><?php echo htmlspecialchars(bakery_sfb_turn_type_label($turn['turn_type'])); ?></strong>
                · <?php echo htmlspecialchars(date('D g:ia', strtotime($turn['occurred_at']))); ?>
                <?php if ($turn['dough_temp_f'] !== null): ?>
                  · <?php echo (float)$turn['dough_temp_f']; ?>°F
                <?php endif; ?>
              </span>
              <?php if ($editable): ?>
                <form method="post" style="margin:0;">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_turn">
                  <input type="hidden" name="turn_id" value="<?php echo (int)$turn['id']; ?>">
                  <button type="submit" class="btn-link">✕</button>
                </form>
              <?php endif; ?>
            </div>
            <?php if (!empty($turn['notes'])): ?>
              <p class="muted"><?php echo htmlspecialchars($turn['notes']); ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($editable): ?>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;margin-top:10px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="add_turn">
            <div class="sfb-grid3">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.turn_type'); ?></span>
                <select name="turn_type">
                  <?php foreach (bakery_sfb_turn_types() as $key => $label): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($label); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.turn_at'); ?></span>
                <input type="datetime-local" name="occurred_at" value="<?php echo bakery_sfb_now_local_value(); ?>">
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.dough_temp_optional'); ?></span>
                <input type="number" name="dough_temp_f" min="0" max="150" step="0.1" placeholder="<?php bakery_te('sfb.enter_temperature'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_dough_temperature')); ?>">
              </div>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.notes'); ?></span>
              <input type="text" name="notes" maxlength="255" placeholder="<?php bakery_te('sfb.enter_notes'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_development_notes')); ?>">
            </label>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.log_turn'); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section id="sfb-shape" class="card sfb-phase <?php echo $phase === 'shape' ? 'current' : ''; ?>">
      <div class="card-header"><h2>3. <?php bakery_te('sfb.phase_shape'); ?></h2>
        <?php if ($builderReady): ?><span style="float:right;"><a class="btn-link" href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>&ask=shape#sfb-discussion"><?php bakery_te('sfb.ask_about_step'); ?></a></span><?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="save_shape">
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.shaped_at'); ?></span>
            <input type="datetime-local" name="shaped_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($batch['shaped_at'])); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
          </label>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.shape_notes'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_shape_notes')); ?></span>
            <textarea name="shape_notes" rows="2" placeholder="<?php bakery_te('sfb.enter_notes'); ?>" <?php echo $editable ? '' : 'disabled'; ?>><?php echo htmlspecialchars($batch['shape_notes'] ?? ''); ?></textarea>
          </label>
          </div>
          <?php if ($editable): ?>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
          <?php endif; ?>
        </form>
        <?php $phasePhotos = $photosByPhase['shape'] ?? []; if ($phasePhotos): ?>
          <div class="sfb-photos">
            <?php foreach ($phasePhotos as $photo): ?>
              <div class="sfb-photo-wrap">
                <img class="sfb-photo" src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? 'Shape photo'); ?>">
                <?php if ($editable): ?>
                  <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>"><button class="sfb-photo-del" type="submit" aria-label="Delete">✕</button></form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section id="sfb-bake" class="card sfb-phase <?php echo $phase === 'bake' ? 'current' : ''; ?>">
      <div class="card-header"><h2>4. <?php bakery_te('sfb.phase_bake'); ?></h2>
        <?php if ($builderReady): ?><span style="float:right;"><a class="btn-link" href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>&ask=bake#sfb-discussion"><?php bakery_te('sfb.ask_about_step'); ?></a></span><?php endif; ?>
      </div>
      <div class="card-body">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="save_bake">
          <div class="sfb-grid3">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.bake_started'); ?></span>
              <input type="datetime-local" name="bake_started_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($batch['bake_started_at'])); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
            </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.bake_ended'); ?></span>
              <input type="datetime-local" name="bake_ended_at" value="<?php echo htmlspecialchars(bakery_sfb_datetime_local_value($batch['bake_ended_at'])); ?>" <?php echo $editable ? '' : 'disabled'; ?>>
            </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.oven_temp'); ?></span>
              <input type="number" name="oven_temp_f" min="0" max="600" step="1" placeholder="<?php bakery_te('sfb.enter_temperature'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_oven_temperature')); ?>" value="<?php echo $batch['oven_temp_f'] !== null ? (float)$batch['oven_temp_f'] : ''; ?>" <?php echo $editable ? '' : 'disabled'; ?>>
            </label>
            </div>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.bake_notes'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_bake_notes')); ?></span>
            <textarea name="bake_notes" rows="2" placeholder="<?php bakery_te('sfb.enter_notes'); ?>" <?php echo $editable ? '' : 'disabled'; ?>><?php echo htmlspecialchars($batch['bake_notes'] ?? ''); ?></textarea>
          </label>
          </div>
          <?php if ($editable): ?>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_phase'); ?></button>
          <?php endif; ?>
        </form>
        <?php $phasePhotos = $photosByPhase['bake'] ?? []; if ($phasePhotos): ?>
          <div class="sfb-photos">
            <?php foreach ($phasePhotos as $photo): ?>
              <div class="sfb-photo-wrap">
                <img class="sfb-photo" src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? 'Bake photo'); ?>">
                <?php if ($editable): ?>
                  <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>"><button class="sfb-photo-del" type="submit" aria-label="Delete">✕</button></form>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.dough_temps'); ?></h2></div>
      <div class="card-body">
        <?php if (!$temps): ?>
          <p class="muted"><?php bakery_te('sfb.no_temps'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($temps as $tempRow): ?>
              <li>
                <span><?php echo htmlspecialchars(bakery_sfb_phase_label($tempRow['phase'])); ?> · <?php echo htmlspecialchars(date('D g:ia', strtotime($tempRow['measured_at']))); ?></span>
                <span class="line-qty">
                  <?php echo (float)$tempRow['temp_f']; ?>°F
                  <?php if ($editable): ?>
                    <form method="post" style="display:inline;margin:0 0 0 8px;">
                      <?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="delete_temp">
                      <input type="hidden" name="temp_id" value="<?php echo (int)$tempRow['id']; ?>">
                      <button type="submit" class="btn-link">✕</button>
                    </form>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($editable): ?>
          <form method="post" class="inline-form" style="grid-template-columns:1fr 1fr 1fr auto;margin-top:10px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="add_temp">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.phase'); ?></span>
              <select name="phase">
                <option value="mix"><?php bakery_te('sfb.phase_mix'); ?></option>
                <option value="development" selected><?php bakery_te('sfb.phase_development'); ?></option>
                <option value="shape"><?php bakery_te('sfb.phase_shape'); ?></option>
                <option value="bake"><?php bakery_te('sfb.phase_bake'); ?></option>
              </select>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.measured_at'); ?></span>
              <input type="datetime-local" name="measured_at" value="<?php echo bakery_sfb_now_local_value(); ?>">
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.temp_f'); ?></span>
              <input type="number" name="temp_f" min="0" max="150" step="0.1" required placeholder="<?php bakery_te('sfb.enter_temperature'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_dough_temperature')); ?>">
            </div>
            <button type="submit" class="btn btn-secondary"><?php bakery_te('sfb.log_temp'); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.photos'); ?></h2></div>
      <div class="card-body">
        <?php $finalPhotos = $photosByPhase['final'] ?? []; $starterPhotos = $photosByPhase['starter'] ?? []; $developmentPhotos = $photosByPhase['development'] ?? []; ?>
        <?php if ($finalPhotos || $starterPhotos || $developmentPhotos): ?>
          <div class="sfb-photos">
            <?php foreach (array_merge($finalPhotos, $starterPhotos, $developmentPhotos) as $photo): ?>
              <div class="sfb-photo-wrap">
                <img class="sfb-photo" src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? 'Batch photo'); ?>">
                <?php if ($editable): ?>
                  <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="delete_photo"><input type="hidden" name="photo_id" value="<?php echo (int)$photo['id']; ?>"><button class="sfb-photo-del" type="submit" aria-label="Delete">✕</button></form>
                <?php endif; ?>
                <?php if (!empty($photo['caption'])): ?>
                  <p class="sfb-caption"><?php echo htmlspecialchars($photo['caption']); ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted"><?php bakery_te('sfb.no_photos'); ?></p>
        <?php endif; ?>
        <?php if ($editable): ?>
          <form method="post" enctype="multipart/form-data" class="inline-form" style="grid-template-columns:1fr;margin-top:10px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="upload_photo">
            <div class="sfb-grid2">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.phase'); ?></span>
                <select name="phase">
                  <?php foreach ($photoPhases as $photoPhase): ?>
                    <option value="<?php echo $photoPhase; ?>"<?php echo $photoPhase === 'final' ? ' selected' : ''; ?>><?php echo htmlspecialchars(bakery_sfb_phase_label($photoPhase) !== ucfirst($photoPhase) ? bakery_sfb_phase_label($photoPhase) : ucfirst($photoPhase)); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.caption'); ?></span>
                <input type="text" name="caption" maxlength="255" placeholder="<?php bakery_te('sfb.enter_optional_caption'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_photo_caption')); ?>">
              </div>
            </div>
            <div class="sfb-field">
              <input type="file" name="photo" accept="image/*" required>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.add_photo'); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section class="card sfb-discussion" id="sfb-discussion" aria-labelledby="sfbDiscussionHeading">
      <div class="card-header">
        <div>
          <h2 id="sfbDiscussionHeading"><?php bakery_te('sfb.discussion'); ?></h2>
          <p class="muted sfb-discussion__intro"><?php bakery_te('sfb.discussion_hint'); ?></p>
        </div>
        <?php if ($answeredCount > 0): ?>
          <span class="badge badge-ok">✓ <?php echo htmlspecialchars(bakery_t('sfb.discussion_answered_chip', ['count' => $answeredCount]), ENT_QUOTES, 'UTF-8'); ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$discussionThreads['roots']): ?>
          <p class="muted"><?php bakery_te('sfb.discussion_empty'); ?></p>
        <?php else: ?>
          <div class="sfb-message-list">
            <?php foreach ($discussionThreads['roots'] as $message):
              $messageId = (int)$message['id'];
              $isQuestion = $message['message_type'] === 'question';
              $isResolved = (int)$message['is_resolved'] === 1;
            ?>
              <article class="sfb-message sfb-message--<?php echo htmlspecialchars($message['author_type']); ?>">
                <div class="sfb-message__meta">
                  <strong><?php echo htmlspecialchars($message['author_name']); ?></strong>
                  <span class="sfb-message__role"><?php echo $message['author_type'] === 'admin' ? bakery_t('sfb.from_sour_flour') : bakery_t('sfb.from_baker'); ?></span>
                  <?php if ($isQuestion): ?>
                    <span class="sfb-message__type <?php echo $isResolved ? 'is-resolved' : ''; ?>">
                      <?php echo $isResolved ? bakery_t('sfb.answered') : bakery_t('sfb.question'); ?>
                    </span>
                  <?php endif; ?>
                  <?php if (!empty($message['phase'])): ?>
                    <span class="badge badge-muted"><?php echo htmlspecialchars(bakery_sfb_phase_label($message['phase']), ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                  <time datetime="<?php echo htmlspecialchars(date('c', strtotime($message['created_at']))); ?>"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($message['created_at']))); ?></time>
                </div>
                <p class="sfb-message__body"><?php echo nl2br(htmlspecialchars($message['body'])); ?></p>
              </article>
              <?php foreach ($discussionThreads['replies'][$messageId] ?? [] as $reply): ?>
                <article class="sfb-message sfb-message--reply sfb-message--<?php echo htmlspecialchars($reply['author_type']); ?>">
                  <div class="sfb-message__meta">
                    <strong><?php echo htmlspecialchars($reply['author_name']); ?></strong>
                    <span class="sfb-message__role"><?php echo $reply['author_type'] === 'admin' ? bakery_t('sfb.from_sour_flour') : bakery_t('sfb.from_baker'); ?></span>
                    <time datetime="<?php echo htmlspecialchars(date('c', strtotime($reply['created_at']))); ?>"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($reply['created_at']))); ?></time>
                  </div>
                  <p class="sfb-message__body"><?php echo nl2br(htmlspecialchars($reply['body'])); ?></p>
                </article>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="inline-form sfb-discussion__composer" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="add_discussion">
          <?php if ($builderReady): ?>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.discussion_phase'); ?></span>
                <select name="phase">
                  <option value=""><?php bakery_te('sfb.discussion_phase_any'); ?></option>
                  <?php foreach (bakery_sfb_builder_phases() as $composerPhase): ?>
                    <option value="<?php echo htmlspecialchars($composerPhase, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $askPhase === $composerPhase ? ' selected' : ''; ?>><?php echo htmlspecialchars(bakery_sfb_phase_label($composerPhase), ENT_QUOTES, 'UTF-8'); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          <?php endif; ?>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.message_type'); ?></span>
              <select name="message_type">
                <option value="comment"><?php bakery_te('sfb.comment'); ?></option>
                <option value="question"><?php bakery_te('sfb.question'); ?></option>
              </select>
            </label>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.message'); ?></span>
              <textarea name="body" rows="3" maxlength="4000" required placeholder="<?php bakery_te('sfb.discussion_placeholder'); ?>"></textarea>
            </label>
          </div>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.share_message'); ?></button>
        </form>
      </div>
    </section>

    <?php if ($editable): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.complete_batch'); ?></h2></div>
        <div class="card-body">
          <form method="post" class="inline-form" style="grid-template-columns:1fr;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="complete_batch">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.loaf_count'); ?></span>
              <input type="number" name="loaf_count" min="0" max="500" step="1" required placeholder="<?php bakery_te('sfb.enter_loaf_count'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_loaf_count')); ?>">
            </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.final_notes'); ?></span>
              <textarea name="final_notes" rows="2" placeholder="<?php bakery_te('sfb.enter_notes'); ?>" title="<?php echo htmlspecialchars(bakery_t('sfb.tip_final_notes')); ?>"></textarea>
            </label>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.complete_batch'); ?></button>
          </form>
        </div>
      </section>
    <?php elseif ($batch['status'] === 'completed' && !empty($batch['final_notes'])): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.final_notes'); ?></h2></div>
        <div class="card-body"><p class="muted"><?php echo nl2br(htmlspecialchars($batch['final_notes'])); ?></p></div>
      </section>
    <?php endif; ?>

    <a class="btn btn-secondary btn-block" href="sfb_batches.php"><?php bakery_te('sfb.all_batches'); ?></a>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
