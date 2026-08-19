<?php
/**
 * Administrator-only engagement hub for every SF Baker batch.
 * The normal staff sign-in gate runs in includes/database.php; this page is
 * intentionally narrower so the 9741 administrator account owns the space.
 */
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

$admin = bakery_current_user();
$adminId = (int)($admin['id'] ?? 0);
$adminName = trim((string)($admin['display_name'] ?? '')) ?: 'Sour Flour';
$error = '';

$customerId = (int)($_GET['baker'] ?? 0);
$status = (string)($_GET['status'] ?? 'all');
$engagement = (string)($_GET['engagement'] ?? 'all');
if (!in_array($status, ['all', 'in_progress', 'completed', 'abandoned'], true)) {
    $status = 'all';
}
if (!in_array($engagement, ['all', 'with_activity', 'needs_response'], true)) {
    $engagement = 'all';
}

function bakery_sfb_admin_overview_return_url($customerId, $status, $engagement, $saved = '') {
    $params = [];
    if ((int)$customerId > 0) {
        $params['baker'] = (int)$customerId;
    }
    if ($status !== 'all') {
        $params['status'] = $status;
    }
    if ($engagement !== 'all') {
        $params['engagement'] = $engagement;
    }
    if ($saved !== '') {
        $params['saved'] = $saved;
    }
    return 'sfb_admin_overview.php' . ($params ? '?' . http_build_query($params) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        $targetBatchId = (int)($_POST['batch_id'] ?? 0);
        $targetBatch = bakery_sfb_admin_batch($db, $targetBatchId);
        if (!$targetBatch) {
            throw new InvalidArgumentException('Choose a valid SF Baker batch.');
        }

        if ($action === 'post_message') {
            bakery_sfb_add_batch_message(
                $db,
                $targetBatchId,
                'admin',
                $adminName,
                (string)($_POST['body'] ?? ''),
                'comment',
                null,
                $adminId,
                (int)($_POST['parent_message_id'] ?? 0)
            );
            header('Location: ' . bakery_sfb_admin_overview_return_url($customerId, $status, $engagement, 'message'));
            exit;
        }
        if ($action === 'resolve_question') {
            bakery_sfb_resolve_batch_question($db, $targetBatchId, (int)($_POST['message_id'] ?? 0), $adminId);
            header('Location: ' . bakery_sfb_admin_overview_return_url($customerId, $status, $engagement, 'resolved'));
            exit;
        }
        throw new InvalidArgumentException('That engagement action is not available.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$summary = bakery_sfb_admin_summary($db);
$bakers = bakery_sfb_admin_bakers($db);
$batches = bakery_sfb_admin_batches($db, $customerId, $status, $engagement, 0);
$openQuestions = bakery_sfb_open_questions($db, 100);
$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'message' => bakery_t('sfb.admin_message_shared'),
    'resolved' => bakery_t('sfb.admin_question_resolved'),
];

$page_title = bakery_t('sfb.admin_overview');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
require __DIR__ . '/includes/sfb_admin_styles.php';
?>
<main class="sfb-admin">
  <header class="sfb-admin__header">
    <div>
      <p class="page-eyebrow">Administrator workspace</p>
      <h1><?php bakery_te('sfb.admin_overview'); ?></h1>
      <p><?php bakery_te('sfb.admin_overview_desc'); ?></p>
    </div>
      <a href="#sfb-admin-batches">Jump to batches</a>
      <a href="sfb_admin_studio.php"><?php bakery_te('sfb.studio_manager'); ?></a>
      <a href="sfb_community.php"><?php bakery_te('sfb.community_staff_circles'); ?></a>
  </header>

  <?php if ($error !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--error" role="alert"><?php echo htmlspecialchars($error); ?></div>
  <?php elseif (isset($savedMessages[$saved])): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
  <?php endif; ?>

  <section class="sfb-admin__stats" aria-label="SF Baker snapshot">
    <div class="sfb-admin__stat"><strong><?php echo (int)$summary['bakers']; ?></strong><span><?php bakery_te('sfb.admin_bakers'); ?></span></div>
    <div class="sfb-admin__stat"><strong><?php echo (int)$summary['active_batches']; ?></strong><span><?php bakery_te('sfb.admin_active_batches'); ?></span></div>
    <div class="sfb-admin__stat"><strong><?php echo (int)$summary['completed_batches']; ?></strong><span><?php bakery_te('sfb.admin_completed_batches'); ?></span></div>
    <div class="sfb-admin__stat"><strong><?php echo (int)$summary['open_questions']; ?></strong><span><?php bakery_te('sfb.admin_open_questions'); ?></span></div>
    <div class="sfb-admin__stat"><strong><?php echo (int)$summary['completed_loaves']; ?></strong><span><?php bakery_te('sfb.admin_total_loaves'); ?></span></div>
  </section>

  <div class="sfb-admin__layout">
    <div>
      <section class="sfb-admin__panel" aria-labelledby="sfbAttentionHeading">
        <h2 id="sfbAttentionHeading"><?php bakery_te('sfb.admin_attention'); ?></h2>
        <p>Questions remain here until you reply or mark them resolved.</p>
        <?php if (!$openQuestions): ?>
          <div class="sfb-admin__empty"><?php bakery_te('sfb.admin_attention_empty'); ?></div>
        <?php else: ?>
          <div class="sfb-admin__question-list">
            <?php foreach ($openQuestions as $question): ?>
              <article class="sfb-admin__question">
                <div class="sfb-admin__question-head">
                  <div>
                    <h3><?php echo htmlspecialchars($question['baker_name']); ?> · <?php echo htmlspecialchars($question['batch_name']); ?></h3>
                    <p class="sfb-admin__question-meta"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($question['created_at']))); ?> · <?php echo htmlspecialchars(bakery_sfb_phase_label(bakery_sfb_batch_phase(['status' => $question['batch_status']]))); ?></p>
                  </div>
                  <a class="sfb-admin__button sfb-admin__button--secondary" href="sfb_admin_batch.php?batch=<?php echo (int)$question['batch_id']; ?>#sfb-admin-conversation"><?php bakery_te('sfb.admin_view_batch'); ?></a>
                </div>
                <p class="sfb-admin__question-body"><?php echo nl2br(htmlspecialchars($question['body'])); ?></p>
                <form method="post" class="sfb-admin__reply-form">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="post_message">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$question['batch_id']; ?>">
                  <input type="hidden" name="parent_message_id" value="<?php echo (int)$question['id']; ?>">
                  <textarea name="body" maxlength="4000" required aria-label="<?php echo htmlspecialchars(bakery_t('sfb.admin_reply_to', ['name' => $question['author_name']])); ?>" placeholder="<?php bakery_te('sfb.admin_reply_placeholder'); ?>"></textarea>
                  <button type="submit"><?php bakery_te('sfb.admin_send_reply'); ?></button>
                </form>
                <form method="post" class="sfb-admin__message-actions">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="resolve_question">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$question['batch_id']; ?>">
                  <input type="hidden" name="message_id" value="<?php echo (int)$question['id']; ?>">
                  <button type="submit" class="sfb-admin__button sfb-admin__button--quiet"><?php bakery_te('sfb.admin_mark_resolved'); ?></button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="sfb-admin__panel" style="margin-top:18px;" aria-labelledby="sfbNoteHeading">
        <h2 id="sfbNoteHeading"><?php bakery_te('sfb.admin_share_note'); ?></h2>
        <p>Post feedback to any batch, even when no question has been asked.</p>
        <form method="post" class="sfb-admin__composer">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="post_message">
          <label><?php bakery_te('sfb.admin_select_batch'); ?>
            <select name="batch_id" required>
              <option value="">—</option>
              <?php foreach ($batches as $batch): ?>
                <option value="<?php echo (int)$batch['id']; ?>"><?php echo htmlspecialchars($batch['baker_name'] . ' · ' . $batch['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><?php bakery_te('sfb.message'); ?>
            <textarea name="body" maxlength="4000" required placeholder="<?php bakery_te('sfb.admin_note_placeholder'); ?>"></textarea>
          </label>
          <button type="submit"><?php bakery_te('sfb.admin_post_note'); ?></button>
        </form>
      </section>
    </div>

    <aside class="sfb-admin__panel">
      <h2>Filter the overview</h2>
      <form method="get" class="sfb-admin__filters">
        <label><?php bakery_te('sfb.admin_filter_baker'); ?>
          <select name="baker">
            <option value="0"><?php bakery_te('sfb.admin_filter_all_bakers'); ?></option>
            <?php foreach ($bakers as $baker): ?>
              <option value="<?php echo (int)$baker['id']; ?>"<?php echo $customerId === (int)$baker['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($baker['name']); ?> (<?php echo (int)$baker['batch_count']; ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?php bakery_te('sfb.admin_filter_status'); ?>
          <select name="status">
            <option value="all"><?php bakery_te('sfb.admin_filter_all_statuses'); ?></option>
            <option value="in_progress"<?php echo $status === 'in_progress' ? ' selected' : ''; ?>>In progress</option>
            <option value="completed"<?php echo $status === 'completed' ? ' selected' : ''; ?>>Complete</option>
            <option value="abandoned"<?php echo $status === 'abandoned' ? ' selected' : ''; ?>>Set aside</option>
          </select>
        </label>
        <label><?php bakery_te('sfb.admin_filter_engagement'); ?>
          <select name="engagement">
            <option value="all"><?php bakery_te('sfb.admin_filter_all_activity'); ?></option>
            <option value="with_activity"<?php echo $engagement === 'with_activity' ? ' selected' : ''; ?>><?php bakery_te('sfb.admin_filter_with_activity'); ?></option>
            <option value="needs_response"<?php echo $engagement === 'needs_response' ? ' selected' : ''; ?>><?php bakery_te('sfb.admin_filter_needs_response'); ?></option>
          </select>
        </label>
        <button type="submit"><?php bakery_te('sfb.admin_apply_filters'); ?></button>
      </form>
      <?php if ($bakers): ?>
        <form method="post" action="sfb_admin_impersonate.php" class="sfb-admin__filters" style="margin-top:16px; grid-template-columns: minmax(0, 1fr) auto;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="start">
          <label><?php bakery_te('sfb.admin_open_as_baker'); ?>
            <select name="customer_id" required>
              <option value="">—</option>
              <?php foreach ($bakers as $baker): ?>
                <option value="<?php echo (int)$baker['id']; ?>"><?php echo htmlspecialchars($baker['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="sfb-admin__button--secondary"><?php bakery_te('sfb.admin_open_as_baker_button'); ?></button>
        </form>
        <p style="color:#78675b; font-size:.86rem; margin:8px 0 0;"><?php bakery_te('sfb.admin_open_as_baker_desc'); ?></p>
      <?php endif; ?>
    </aside>
  </div>

  <section class="sfb-admin__panel" id="sfb-admin-batches" style="margin-top:18px;" aria-labelledby="sfbAdminBatchesHeading">
    <h2 id="sfbAdminBatchesHeading"><?php bakery_te('sfb.admin_all_batches'); ?> <span class="text-muted">(<?php echo count($batches); ?>)</span></h2>
    <?php if (!$batches): ?>
      <div class="sfb-admin__empty"><?php bakery_te('sfb.admin_no_batches'); ?></div>
    <?php else: ?>
      <div class="sfb-admin__batch-list">
        <?php foreach ($batches as $batch):
          $phase = bakery_sfb_batch_phase($batch);
          $statusClass = $batch['status'] === 'in_progress' ? 'active' : ($batch['status'] === 'completed' ? 'completed' : 'abandoned');
        ?>
          <article class="sfb-admin__batch sfb-admin__batch--<?php echo $statusClass; ?>">
            <div class="sfb-admin__batch-head">
              <div>
                <h3><?php echo htmlspecialchars($batch['baker_name']); ?> · <?php echo htmlspecialchars($batch['name']); ?></h3>
                <p class="sfb-admin__batch-meta"><?php echo htmlspecialchars($batch['formula_name'] ?? '—'); ?> · Started <?php echo htmlspecialchars(date('M j, Y g:ia', strtotime($batch['started_at']))); ?></p>
              </div>
              <div class="sfb-admin__pills">
                <span class="sfb-admin__pill <?php echo $batch['status'] === 'completed' ? 'sfb-admin__pill--ok' : ($batch['status'] === 'abandoned' ? 'sfb-admin__pill--muted' : ''); ?>"><?php echo htmlspecialchars(bakery_sfb_phase_label($phase)); ?></span>
                <?php if ((int)$batch['open_question_count'] > 0): ?><span class="sfb-admin__pill sfb-admin__pill--attention"><?php echo (int)$batch['open_question_count']; ?> open question<?php echo (int)$batch['open_question_count'] === 1 ? '' : 's'; ?></span><?php endif; ?>
              </div>
            </div>
            <div class="sfb-admin__batch-actions">
              <span class="sfb-admin__batch-meta">
                <?php echo (int)$batch['message_count']; ?> message<?php echo (int)$batch['message_count'] === 1 ? '' : 's'; ?> ·
                <?php if ($batch['last_message_at']): ?><?php echo htmlspecialchars(bakery_t('sfb.admin_last_activity', ['when' => date('M j, g:ia', strtotime($batch['last_message_at']))])); ?><?php else: ?><?php bakery_te('sfb.admin_no_activity'); ?><?php endif; ?>
              </span>
              <a class="sfb-admin__button sfb-admin__button--secondary" href="sfb_admin_batch.php?batch=<?php echo (int)$batch['id']; ?>"><?php bakery_te('sfb.admin_view_batch'); ?></a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
