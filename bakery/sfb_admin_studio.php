<?php
/**
 * Synthetic Manager: pace, roster, and full action log.
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
$adminId = (int)($admin['id'] ?? 0);
$error = '';
$notice = '';
$noticeKind = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_pace') {
            bakery_sfb_studio_save_settings($db, $_POST, $adminId);
            header('Location: sfb_admin_studio.php?saved=pace');
            exit;
        }
        if ($action === 'run_tick') {
            bakery_sfb_studio_tick($db, ['force' => true]);
            header('Location: sfb_admin_studio.php?saved=tick');
            exit;
        }
        throw new InvalidArgumentException('That manager action is not available.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$saved = (string)($_GET['saved'] ?? '');
if ($saved === 'pace') {
    $notice = bakery_t('sfb.studio_pace_saved');
} elseif ($saved === 'tick') {
    $notice = bakery_t('sfb.studio_tick_ran');
}

$settings = bakery_sfb_studio_settings($db);
$enrolled = bakery_sfb_studio_enroll($db, 'stagger');
$roster = bakery_sfb_studio_roster($db);
$logs = bakery_sfb_studio_logs($db, 0, 80);
$dueNow = 0;
foreach ($roster as $row) {
    if ((int)$row['paused'] === 0 && strtotime((string)$row['next_action_at']) <= time()) {
        $dueNow++;
    }
}

$page_title = bakery_t('sfb.studio_manager');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
require __DIR__ . '/includes/sfb_admin_styles.php';
?>
<main class="sfb-admin">
  <header class="sfb-admin__header">
    <div>
      <p class="page-eyebrow">Administrator workspace</p>
      <h1><?php bakery_te('sfb.studio_manager'); ?></h1>
      <p><?php bakery_te('sfb.studio_manager_desc'); ?></p>
    </div>
    <div>
      <a href="sfb_admin_overview.php"><?php bakery_te('sfb.admin_overview'); ?></a>
      <a href="sfb_community.php"><?php bakery_te('sfb.community_staff_circles'); ?></a>
    </div>
  </header>

  <?php if ($error !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php elseif ($notice !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php elseif ($enrolled > 0): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status"><?php echo htmlspecialchars(bakery_t('sfb.studio_enrolled', ['count' => (string)$enrolled]), ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <section class="sfb-admin__stats" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.studio_clock'), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="sfb-admin__stat">
      <strong><?php echo count($roster); ?></strong>
      <span><?php bakery_te('sfb.studio_roster'); ?></span>
    </div>
    <div class="sfb-admin__stat">
      <strong><?php echo (int)$settings['clock_enabled'] === 1 ? bakery_t('sfb.studio_clock_on') : bakery_t('sfb.studio_clock_off'); ?></strong>
      <span><?php bakery_te('sfb.studio_clock'); ?></span>
    </div>
    <div class="sfb-admin__stat">
      <strong><?php echo (int)$settings['min_interval_minutes']; ?>–<?php echo (int)$settings['max_interval_minutes']; ?>m</strong>
      <span><?php bakery_te('sfb.studio_min_minutes'); ?></span>
    </div>
    <div class="sfb-admin__stat">
      <strong><?php echo (int)$dueNow; ?></strong>
      <span><?php bakery_te('sfb.studio_due_count'); ?></span>
    </div>
  </section>

  <div class="sfb-admin__layout">
    <section class="sfb-admin__panel">
      <h2><?php bakery_te('sfb.studio_clock'); ?></h2>
      <p><?php bakery_te('sfb.studio_cron_help'); ?></p>
      <p><code><?php echo htmlspecialchars(bakery_t('sfb.studio_cron_cmd'), ENT_QUOTES, 'UTF-8'); ?></code></p>
      <form method="post">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="save_pace">
        <label style="margin: 12px 0; text-transform: none; letter-spacing: 0;">
          <input type="checkbox" name="clock_enabled" value="1"<?php echo (int)$settings['clock_enabled'] === 1 ? ' checked' : ''; ?>>
          <?php bakery_te('sfb.studio_enabled'); ?>
        </label>
        <div class="sfb-admin__pace">
          <label><?php bakery_te('sfb.studio_min_minutes'); ?>
            <input type="number" name="min_interval_minutes" min="1" max="240" value="<?php echo (int)$settings['min_interval_minutes']; ?>">
          </label>
          <label><?php bakery_te('sfb.studio_max_minutes'); ?>
            <input type="number" name="max_interval_minutes" min="1" max="240" value="<?php echo (int)$settings['max_interval_minutes']; ?>">
          </label>
          <label><?php bakery_te('sfb.studio_max_actions'); ?>
            <input type="number" name="max_actions_per_baker" min="1" max="6" value="<?php echo (int)$settings['max_actions_per_baker']; ?>">
          </label>
          <label><?php bakery_te('sfb.studio_max_bakers'); ?>
            <input type="number" name="max_bakers_per_tick" min="1" max="100" value="<?php echo (int)$settings['max_bakers_per_tick']; ?>">
          </label>
        </div>
        <div class="btn-row" style="display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit"><?php bakery_te('sfb.studio_save_pace'); ?></button>
        </div>
      </form>
      <form method="post" style="margin-top:10px;">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="run_tick">
        <button type="submit" class="sfb-admin__button--secondary"><?php bakery_te('sfb.studio_run_tick'); ?></button>
      </form>
    </section>

    <section class="sfb-admin__panel">
      <h2><?php bakery_te('sfb.studio_roster'); ?></h2>
      <?php if (!$roster): ?>
        <div class="sfb-admin__empty"><?php bakery_te('sfb.studio_log_empty'); ?></div>
      <?php else: ?>
        <div class="sfb-admin__batch-list">
          <?php foreach ($roster as $baker):
              $due = (int)$baker['paused'] === 0 && strtotime((string)$baker['next_action_at']) <= time();
          ?>
            <article class="sfb-admin__batch">
              <div class="sfb-admin__batch-head">
                <div>
                  <h3><?php echo htmlspecialchars((string)$baker['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                  <p class="sfb-admin__batch-meta">
                    <?php echo bakery_sfb_render_origin_badge($baker); ?>
                    <?php if (!empty($baker['cohort'])): ?><?php echo htmlspecialchars((string)$baker['cohort'], ENT_QUOTES, 'UTF-8'); ?> · <?php endif; ?>
                    <?php echo (int)$baker['loaf_total']; ?> <?php bakery_te('sfb.studio_loaves'); ?>
                  </p>
                </div>
                <div class="sfb-admin__pills">
                  <?php if ((int)$baker['paused'] === 1): ?>
                    <span class="sfb-admin__pill sfb-admin__pill--muted"><?php bakery_te('sfb.studio_paused'); ?></span>
                  <?php elseif ($due): ?>
                    <span class="sfb-admin__pill sfb-admin__pill--attention"><?php bakery_te('sfb.studio_running'); ?></span>
                  <?php else: ?>
                    <span class="sfb-admin__pill sfb-admin__pill--ok"><?php bakery_te('sfb.studio_waiting'); ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <p class="sfb-admin__batch-meta">
                <?php bakery_te('sfb.studio_next'); ?>:
                <?php echo $baker['next_action_at'] ? htmlspecialchars(date('M j, g:ia', strtotime((string)$baker['next_action_at'])), ENT_QUOTES, 'UTF-8') : '—'; ?>
                · <?php bakery_te('sfb.studio_last'); ?>:
                <?php echo $baker['last_action'] ? htmlspecialchars(bakery_sfb_studio_action_label($baker['last_action']), ENT_QUOTES, 'UTF-8') : '—'; ?>
              </p>
              <div class="sfb-admin__batch-actions">
                <a class="sfb-admin__button sfb-admin__button--secondary" href="sfb_admin_studio_baker.php?baker=<?php echo (int)$baker['id']; ?>"><?php bakery_te('sfb.studio_open_baker'); ?></a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <section class="sfb-admin__panel" style="margin-top:18px;">
    <h2><?php bakery_te('sfb.studio_log'); ?></h2>
    <?php if (!$logs): ?>
      <div class="sfb-admin__empty"><?php bakery_te('sfb.studio_log_empty'); ?></div>
    <?php else: ?>
      <table class="sfb-admin__log">
        <thead>
          <tr>
            <th><?php bakery_te('sfb.studio_when'); ?></th>
            <th><?php bakery_te('sfb.admin_filter_baker'); ?></th>
            <th><?php bakery_te('sfb.studio_action'); ?></th>
            <th><?php bakery_te('sfb.studio_status'); ?></th>
            <th><?php bakery_te('sfb.studio_summary'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr>
              <td><?php echo htmlspecialchars(date('M j, g:ia', strtotime((string)$log['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <a href="sfb_admin_studio_baker.php?baker=<?php echo (int)$log['customer_id']; ?>">
                  <?php echo htmlspecialchars((string)$log['baker_name'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
              </td>
              <td><?php echo htmlspecialchars(bakery_sfb_studio_action_label($log['action']), ENT_QUOTES, 'UTF-8'); ?></td>
              <td class="sfb-admin__status-<?php echo htmlspecialchars((string)$log['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$log['status'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                <?php echo htmlspecialchars((string)$log['summary'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if ((int)$log['id'] > 0): ?>
                  <a href="sfb_admin_studio_baker.php?baker=<?php echo (int)$log['customer_id']; ?>&log=<?php echo (int)$log['id']; ?>">↗</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
