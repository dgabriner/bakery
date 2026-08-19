<?php
/**
 * Synthetic Manager baker drill-in: clock, planned steps, batches, log detail.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';
require_once __DIR__ . '/includes/sfb_studio_clock.php';

bakery_require_role(['administrator']);
bakery_ensure_sfb_schema($db);
bakery_sfb_studio_ensure_schema($db);

$admin = bakery_current_user();
$bakerId = (int)($_GET['baker'] ?? $_POST['baker'] ?? 0);
$logId = (int)($_GET['log'] ?? 0);
$error = '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        $bakerId = (int)($_POST['baker'] ?? $bakerId);
        if ($action === 'pause') {
            bakery_sfb_studio_set_baker_paused($db, $bakerId, true);
            header('Location: sfb_admin_studio_baker.php?baker=' . $bakerId . '&saved=paused');
            exit;
        }
        if ($action === 'resume') {
            bakery_sfb_studio_set_baker_paused($db, $bakerId, false);
            header('Location: sfb_admin_studio_baker.php?baker=' . $bakerId . '&saved=resumed');
            exit;
        }
        if ($action === 'tick_baker') {
            bakery_sfb_studio_tick($db, ['force' => true, 'customer_id' => $bakerId]);
            header('Location: sfb_admin_studio_baker.php?baker=' . $bakerId . '&saved=tick');
            exit;
        }
        throw new InvalidArgumentException('That manager action is not available.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$saved = (string)($_GET['saved'] ?? '');
if ($saved === 'tick') {
    $notice = bakery_t('sfb.studio_tick_ran');
} elseif ($saved === 'paused' || $saved === 'resumed') {
    $notice = bakery_t('sfb.studio_pace_saved');
}

$detail = $bakerId > 0 ? bakery_sfb_studio_baker_detail($db, $bakerId) : null;
$logRow = $logId > 0 ? bakery_sfb_studio_log_row($db, $logId) : null;
if ($logRow && (int)$logRow['customer_id'] !== $bakerId) {
    $logRow = null;
}

$page_title = $detail ? (string)$detail['baker']['name'] : bakery_t('sfb.studio_manager');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
require __DIR__ . '/includes/sfb_admin_styles.php';
?>
<main class="sfb-admin">
  <p><a href="sfb_admin_studio.php"><?php bakery_te('sfb.studio_back'); ?></a></p>

  <?php if ($error !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php elseif ($notice !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <?php if (!$detail): ?>
    <div class="sfb-admin__empty"><?php bakery_te('sfb.studio_no_baker'); ?></div>
  <?php else:
      $baker = $detail['baker'];
      $state = $detail['state'];
      $active = $state['active'] ?? null;
      $completed = $state['latest_completed'] ?? null;
  ?>
    <header class="sfb-admin__header">
      <div>
        <p class="page-eyebrow"><?php bakery_te('sfb.studio_manager'); ?></p>
        <h1><?php echo htmlspecialchars((string)$baker['name'], ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>
          <?php echo bakery_sfb_render_origin_badge($baker); ?>
          <?php if (!empty($baker['cohort'])): echo htmlspecialchars((string)$baker['cohort'], ENT_QUOTES, 'UTF-8') . ' · '; endif; ?>
          <?php echo !empty($baker['locale']) ? htmlspecialchars((string)$baker['locale'], ENT_QUOTES, 'UTF-8') : 'en'; ?>
          <?php if (!empty($baker['is_mentor'])): ?> · mentor<?php endif; ?>
        </p>
      </div>
      <div>
        <a href="sfb_admin_impersonate.php"><?php bakery_te('sfb.admin_open_as_baker_button'); ?></a>
      </div>
    </header>

    <section class="sfb-admin__stats">
      <div class="sfb-admin__stat">
        <strong><?php echo (int)$baker['actions_taken']; ?></strong>
        <span><?php bakery_te('sfb.studio_action'); ?></span>
      </div>
      <div class="sfb-admin__stat">
        <strong><?php echo $baker['next_action_at'] ? htmlspecialchars(date('M j, g:ia', strtotime((string)$baker['next_action_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?></strong>
        <span><?php bakery_te('sfb.studio_next'); ?></span>
      </div>
      <div class="sfb-admin__stat">
        <strong><?php echo $baker['last_action'] ? htmlspecialchars(bakery_sfb_studio_action_label($baker['last_action']), ENT_QUOTES, 'UTF-8') : '—'; ?></strong>
        <span><?php bakery_te('sfb.studio_last'); ?></span>
      </div>
      <div class="sfb-admin__stat">
        <strong><?php echo (int)($baker['paused'] ?? 0) === 1 ? bakery_t('sfb.studio_paused') : bakery_t('sfb.studio_waiting'); ?></strong>
        <span><?php bakery_te('sfb.studio_clock'); ?></span>
      </div>
    </section>

    <div class="btn-row" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:18px;">
      <form method="post">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="baker" value="<?php echo (int)$baker['id']; ?>">
        <input type="hidden" name="action" value="<?php echo (int)($baker['paused'] ?? 0) === 1 ? 'resume' : 'pause'; ?>">
        <button type="submit" class="sfb-admin__button--secondary">
          <?php bakery_te((int)($baker['paused'] ?? 0) === 1 ? 'sfb.studio_resume_baker' : 'sfb.studio_pause_baker'); ?>
        </button>
      </form>
      <form method="post">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="baker" value="<?php echo (int)$baker['id']; ?>">
        <input type="hidden" name="action" value="tick_baker">
        <button type="submit"><?php bakery_te('sfb.studio_tick_baker'); ?></button>
      </form>
    </div>

    <div class="sfb-admin__detail-grid">
      <section class="sfb-admin__panel">
        <h2><?php bakery_te('sfb.studio_planned'); ?></h2>
        <?php if (empty($detail['planned'])): ?>
          <div class="sfb-admin__empty"><?php bakery_te('sfb.studio_log_empty'); ?></div>
        <?php else: ?>
          <ol class="sfb-admin__timeline">
            <?php foreach ($detail['planned'] as $step): ?>
              <li><?php echo htmlspecialchars(bakery_sfb_studio_action_label($step), ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>

        <div class="sfb-admin__facts">
          <div class="sfb-admin__fact">
            <span><?php bakery_te('sfb.admin_active_batches'); ?></span>
            <strong><?php echo $active ? htmlspecialchars((string)$active['name'], ENT_QUOTES, 'UTF-8') : '—'; ?></strong>
          </div>
          <div class="sfb-admin__fact">
            <span><?php bakery_te('sfb.admin_completed_batches'); ?></span>
            <strong><?php echo $completed ? htmlspecialchars((string)$completed['name'], ENT_QUOTES, 'UTF-8') : '—'; ?></strong>
          </div>
          <div class="sfb-admin__fact">
            <span>Folds / temps</span>
            <strong><?php echo count($state['turns'] ?? []); ?> / <?php echo count($state['temps'] ?? []); ?></strong>
          </div>
          <div class="sfb-admin__fact">
            <span><?php bakery_te('sfb.community_title'); ?></span>
            <strong><?php echo (int)($state['topic_id'] ?? 0) > 0 ? '#' . (int)$state['topic_id'] : '—'; ?></strong>
          </div>
        </div>

        <?php if ($active): ?>
          <p style="margin-top:14px;"><a class="sfb-admin__button sfb-admin__button--secondary" href="sfb_admin_batch.php?batch=<?php echo (int)$active['id']; ?>"><?php bakery_te('sfb.admin_view_batch'); ?></a></p>
        <?php elseif ($completed): ?>
          <p style="margin-top:14px;"><a class="sfb-admin__button sfb-admin__button--secondary" href="sfb_admin_batch.php?batch=<?php echo (int)$completed['id']; ?>"><?php bakery_te('sfb.admin_view_batch'); ?></a></p>
        <?php endif; ?>
      </section>

      <section class="sfb-admin__panel">
        <h2><?php bakery_te('sfb.studio_detail'); ?></h2>
        <?php if ($logRow): ?>
          <p class="sfb-admin__batch-meta">
            <?php echo htmlspecialchars(bakery_sfb_studio_action_label($logRow['action']), ENT_QUOTES, 'UTF-8'); ?>
            · <?php echo htmlspecialchars((string)$logRow['status'], ENT_QUOTES, 'UTF-8'); ?>
            · <?php echo htmlspecialchars(date('M j, g:ia', strtotime((string)$logRow['created_at'])), ENT_QUOTES, 'UTF-8'); ?>
          </p>
          <p><?php echo htmlspecialchars((string)$logRow['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
          <?php
            $decoded = json_decode((string)($logRow['detail_json'] ?? ''), true);
            $pretty = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string)$logRow['detail_json'];
          ?>
          <?php if (trim($pretty) !== '' && $pretty !== '[]'): ?>
            <pre class="sfb-admin__pre"><?php echo htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8'); ?></pre>
          <?php endif; ?>
          <?php if ((int)$logRow['batch_id'] > 0): ?>
            <p><a href="sfb_admin_batch.php?batch=<?php echo (int)$logRow['batch_id']; ?>"><?php bakery_te('sfb.admin_view_batch'); ?></a></p>
          <?php endif; ?>
          <?php if ((int)$logRow['topic_id'] > 0): ?>
            <p><a href="sfb_community_topic.php?topic=<?php echo (int)$logRow['topic_id']; ?>"><?php bakery_te('sfb.community_title'); ?></a></p>
          <?php endif; ?>
        <?php else: ?>
          <p class="sfb-admin__batch-meta"><?php bakery_te('sfb.studio_log'); ?></p>
        <?php endif; ?>
      </section>
    </div>

    <section class="sfb-admin__panel" style="margin-top:18px;">
      <h2><?php bakery_te('sfb.studio_log'); ?></h2>
      <?php if (!$detail['logs']): ?>
        <div class="sfb-admin__empty"><?php bakery_te('sfb.studio_log_empty'); ?></div>
      <?php else: ?>
        <table class="sfb-admin__log">
          <thead>
            <tr>
              <th><?php bakery_te('sfb.studio_when'); ?></th>
              <th><?php bakery_te('sfb.studio_action'); ?></th>
              <th><?php bakery_te('sfb.studio_status'); ?></th>
              <th><?php bakery_te('sfb.studio_summary'); ?></th>
              <th><?php bakery_te('sfb.studio_tick_id'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($detail['logs'] as $log): ?>
              <tr>
                <td><?php echo htmlspecialchars(date('M j, g:ia', strtotime((string)$log['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><a href="sfb_admin_studio_baker.php?baker=<?php echo (int)$baker['id']; ?>&log=<?php echo (int)$log['id']; ?>"><?php echo htmlspecialchars(bakery_sfb_studio_action_label($log['action']), ENT_QUOTES, 'UTF-8'); ?></a></td>
                <td class="sfb-admin__status-<?php echo htmlspecialchars((string)$log['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$log['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars((string)$log['summary'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><code><?php echo htmlspecialchars((string)$log['tick_id'], ENT_QUOTES, 'UTF-8'); ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
