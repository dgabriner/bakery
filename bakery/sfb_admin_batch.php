<?php
/** Detailed administrator review and engagement view for one SF Baker batch. */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

bakery_require_role(['administrator']);
bakery_ensure_sfb_schema($db);

if (!bakery_sfb_tables_ready($db)
    || !bakery_sfb_formula_snapshots_ready($db)
    || !bakery_sfb_discussion_ready($db)) {
    http_response_code(503);
    exit('SF Baker engagement needs the latest database migration before it can be opened.');
}

$batchId = (int)($_REQUEST['batch'] ?? 0);
$batch = $batchId > 0 ? bakery_sfb_admin_batch($db, $batchId) : null;
if (!$batch) {
    header('Location: sfb_admin_overview.php');
    exit;
}

$admin = bakery_current_user();
$adminId = (int)($admin['id'] ?? 0);
$adminName = trim((string)($admin['display_name'] ?? '')) ?: 'Sour Flour';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'post_message') {
            bakery_sfb_add_batch_message(
                $db,
                (int)$batch['id'],
                'admin',
                $adminName,
                (string)($_POST['body'] ?? ''),
                'comment',
                null,
                $adminId,
                (int)($_POST['parent_message_id'] ?? 0)
            );
            header('Location: sfb_admin_batch.php?batch=' . (int)$batch['id'] . '&saved=message#sfb-admin-conversation');
            exit;
        }
        if ($action === 'resolve_question') {
            bakery_sfb_resolve_batch_question($db, (int)$batch['id'], (int)($_POST['message_id'] ?? 0), $adminId);
            header('Location: sfb_admin_batch.php?batch=' . (int)$batch['id'] . '&saved=resolved#sfb-admin-conversation');
            exit;
        }
        throw new InvalidArgumentException('That engagement action is not available.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$messages = bakery_sfb_batch_messages($db, (int)$batch['id']);
$threads = bakery_sfb_message_threads($messages);
$turns = bakery_sfb_batch_turns($db, (int)$batch['id']);
$temps = bakery_sfb_batch_temps($db, (int)$batch['id']);
$photos = bakery_sfb_batch_photos($db, (int)$batch['id']);
$formulaSnapshot = bakery_sfb_batch_formula_snapshot($db, (int)$batch['id']);
$formulaLines = $formulaSnapshot ? bakery_sfb_batch_formula_snapshot_lines($db, (int)$batch['id']) : [];
$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'message' => bakery_t('sfb.admin_message_shared'),
    'resolved' => bakery_t('sfb.admin_question_resolved'),
];
$phase = bakery_sfb_batch_phase($batch);

$timeline = [];
foreach ([
    'started_at' => 'Started',
    'mix_completed_at' => 'Mix completed',
    'bulk_started_at' => 'Bulk started',
    'bulk_ended_at' => 'Bulk ended',
    'shaped_at' => 'Shaped',
    'bake_started_at' => 'Into oven',
    'bake_ended_at' => 'Out of oven',
] as $field => $label) {
    if (!empty($batch[$field])) {
        $timeline[] = ['label' => $label, 'at' => $batch[$field]];
    }
}
usort($timeline, function ($left, $right) { return strcmp($left['at'], $right['at']); });

$page_title = bakery_t('sfb.admin_batch_detail');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
require __DIR__ . '/includes/sfb_admin_styles.php';
?>
<main class="sfb-admin">
  <header class="sfb-admin__header">
    <div>
      <p class="page-eyebrow"><?php bakery_te('sfb.admin_batch_detail'); ?></p>
      <h1><?php echo htmlspecialchars($batch['baker_name']); ?> · <?php echo htmlspecialchars($batch['name']); ?></h1>
      <p><?php echo htmlspecialchars($batch['formula_name'] ?? '—'); ?> · Started <?php echo htmlspecialchars(date('M j, Y g:ia', strtotime($batch['started_at']))); ?></p>
    </div>
    <a href="sfb_admin_overview.php"><?php bakery_te('sfb.admin_back_to_overview'); ?></a>
  </header>

  <?php if ($error !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--error" role="alert"><?php echo htmlspecialchars($error); ?></div>
  <?php elseif (isset($savedMessages[$saved])): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
  <?php endif; ?>

  <div class="sfb-admin__detail-grid">
    <section class="sfb-admin__panel" id="sfb-admin-conversation" aria-labelledby="sfbConversationHeading">
      <h2 id="sfbConversationHeading"><?php bakery_te('sfb.admin_conversation'); ?></h2>
      <p>Reply directly to a baker’s note or leave proactive feedback on this batch.</p>

      <form method="post" class="sfb-admin__composer" style="margin:16px 0 18px;grid-template-columns:minmax(0,1fr) auto;">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="post_message">
        <input type="hidden" name="parent_message_id" value="0">
        <label><?php bakery_te('sfb.admin_share_note'); ?>
          <textarea name="body" maxlength="4000" required placeholder="<?php bakery_te('sfb.admin_note_placeholder'); ?>"></textarea>
        </label>
        <button type="submit"><?php bakery_te('sfb.admin_post_note'); ?></button>
      </form>

      <?php if (!$threads['roots']): ?>
        <div class="sfb-admin__empty"><?php bakery_te('sfb.admin_conversation_empty'); ?></div>
      <?php else: ?>
        <div class="sfb-admin__message-list">
          <?php foreach ($threads['roots'] as $message):
            $messageId = (int)$message['id'];
            $isQuestion = $message['message_type'] === 'question';
            $isResolved = (int)$message['is_resolved'] === 1;
          ?>
            <article class="sfb-admin__message sfb-admin__message--<?php echo htmlspecialchars($message['author_type']); ?>">
              <div class="sfb-admin__message-head">
                <p class="sfb-admin__message-meta">
                  <strong><?php echo htmlspecialchars($message['author_name']); ?></strong>
                  · <?php echo $message['author_type'] === 'admin' ? bakery_t('sfb.from_sour_flour') : bakery_t('sfb.from_baker'); ?>
                  <?php if ($isQuestion): ?>· <span class="sfb-admin__pill <?php echo $isResolved ? 'sfb-admin__pill--ok' : 'sfb-admin__pill--attention'; ?>"><?php echo $isResolved ? bakery_t('sfb.answered') : bakery_t('sfb.question'); ?></span><?php endif; ?>
                  · <time datetime="<?php echo htmlspecialchars(date('c', strtotime($message['created_at']))); ?>"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($message['created_at']))); ?></time>
                </p>
                <?php if ($isQuestion && !$isResolved): ?>
                  <form method="post">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="resolve_question">
                    <input type="hidden" name="message_id" value="<?php echo $messageId; ?>">
                    <button type="submit" class="sfb-admin__button sfb-admin__button--quiet"><?php bakery_te('sfb.admin_mark_resolved'); ?></button>
                  </form>
                <?php endif; ?>
              </div>
              <p class="sfb-admin__message-body"><?php echo nl2br(htmlspecialchars($message['body'])); ?></p>
              <?php foreach ($threads['replies'][$messageId] ?? [] as $reply): ?>
                <article class="sfb-admin__message sfb-admin__message--reply sfb-admin__message--<?php echo htmlspecialchars($reply['author_type']); ?>">
                  <p class="sfb-admin__message-meta"><strong><?php echo htmlspecialchars($reply['author_name']); ?></strong> · <?php echo $reply['author_type'] === 'admin' ? bakery_t('sfb.from_sour_flour') : bakery_t('sfb.from_baker'); ?> · <time datetime="<?php echo htmlspecialchars(date('c', strtotime($reply['created_at']))); ?>"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($reply['created_at']))); ?></time></p>
                  <p class="sfb-admin__message-body"><?php echo nl2br(htmlspecialchars($reply['body'])); ?></p>
                </article>
              <?php endforeach; ?>
              <form method="post" class="sfb-admin__message-reply sfb-admin__reply-form">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="post_message">
                <input type="hidden" name="parent_message_id" value="<?php echo $messageId; ?>">
                <textarea name="body" maxlength="4000" required aria-label="<?php echo htmlspecialchars(bakery_t('sfb.admin_reply_to', ['name' => $message['author_name']])); ?>" placeholder="<?php bakery_te('sfb.admin_reply_placeholder'); ?>"></textarea>
                <button type="submit"><?php bakery_te('sfb.admin_send_reply'); ?></button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <aside>
      <section class="sfb-admin__panel" aria-labelledby="sfbDetailsHeading">
        <h2 id="sfbDetailsHeading"><?php bakery_te('sfb.admin_batch_details'); ?></h2>
        <div class="sfb-admin__facts">
          <div class="sfb-admin__fact"><span>Baker</span><strong><?php echo htmlspecialchars($batch['baker_name']); ?></strong></div>
          <div class="sfb-admin__fact"><span>Current phase</span><strong><?php echo htmlspecialchars(bakery_sfb_phase_label($phase)); ?></strong></div>
          <div class="sfb-admin__fact"><span>Loaves</span><strong><?php echo (int)$batch['loaf_count']; ?></strong></div>
          <div class="sfb-admin__fact"><span>Oven</span><strong><?php echo $batch['oven_temp_f'] !== null ? (float)$batch['oven_temp_f'] . '°F' : '—'; ?></strong></div>
          <div class="sfb-admin__fact"><span>Mix</span><strong><?php echo $batch['mix_minutes'] !== null ? (int)$batch['mix_minutes'] . ' min' : '—'; ?></strong></div>
          <div class="sfb-admin__fact"><span>Formula</span><strong><?php echo htmlspecialchars($batch['formula_name'] ?? '—'); ?></strong></div>
        </div>
      </section>

      <?php if ($timeline): ?>
        <section class="sfb-admin__panel" style="margin-top:18px;" aria-labelledby="sfbTimelineHeading">
          <h2 id="sfbTimelineHeading">Batch timeline</h2>
          <ul class="sfb-admin__timeline">
            <?php foreach ($timeline as $event): ?>
              <li><strong><?php echo htmlspecialchars($event['label']); ?></strong><br><span class="text-muted"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($event['at']))); ?></span></li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($batch['mix_notes'] || $batch['shape_notes'] || $batch['bake_notes'] || $batch['final_notes']): ?>
        <section class="sfb-admin__panel" style="margin-top:18px;" aria-labelledby="sfbNotesHeading">
          <h2 id="sfbNotesHeading">Baker notes</h2>
          <?php foreach (['mix_notes' => 'Mix', 'shape_notes' => 'Shape', 'bake_notes' => 'Bake', 'final_notes' => 'Final'] as $field => $label): ?>
            <?php if (!empty($batch[$field])): ?><p><strong><?php echo $label; ?>:</strong><br><?php echo nl2br(htmlspecialchars($batch[$field])); ?></p><?php endif; ?>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <?php if ($formulaLines): ?>
        <section class="sfb-admin__panel" style="margin-top:18px;" aria-labelledby="sfbFormulaHeading">
          <h2 id="sfbFormulaHeading"><?php bakery_te('sfb.formula_snapshot'); ?></h2>
          <ul class="sfb-admin__timeline">
            <?php foreach ($formulaLines as $line): ?><li><?php echo htmlspecialchars($line['line_name']); ?> <strong style="float:right;"><?php echo (float)$line['percentage']; ?>%</strong></li><?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($photos): ?>
        <section class="sfb-admin__panel" style="margin-top:18px;" aria-labelledby="sfbPhotosHeading">
          <h2 id="sfbPhotosHeading"><?php bakery_te('sfb.photos'); ?></h2>
          <div class="sfb-admin__media">
            <?php foreach ($photos as $photo): ?><img src="<?php echo htmlspecialchars(bakery_sfb_photo_url($photo['file_path'])); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?: 'Batch photo'); ?>"><?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </aside>
  </div>
</main>
</body>
</html>
