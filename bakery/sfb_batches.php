<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'start_batch':
                $formula = bakery_sfb_formula($db, $customerId, (int)($_POST['formula_id'] ?? 0));
                if (!$formula || (int)$formula['customer_id'] !== $customerId) {
                    throw new InvalidArgumentException('Choose one of your formulas for this batch');
                }
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    $name = $formula['name'] . ' — ' . date('M j');
                }
                $batchId = bakery_sfb_start_batch(
                    $db,
                    $customerId,
                    (int)$formula['id'],
                    $name,
                    $_POST['started_at'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . $batchId . '&saved=started');
                exit;

            case 'start_template_batch':
                $template = bakery_sfb_template($db, (int)($_POST['template_id'] ?? 0));
                if (!$template) {
                    throw new InvalidArgumentException('Choose a standard formula for your first batch');
                }
                // A template is copied before use, so the baker owns this
                // formula and can adjust it after getting started.
                $formulaId = bakery_sfb_copy_template($db, $customerId, (int)$template['id']);
                $batchId = bakery_sfb_start_batch(
                    $db,
                    $customerId,
                    $formulaId,
                    trim((string)($_POST['name'] ?? '')),
                    $_POST['started_at'] ?? ''
                );
                header('Location: sfb_batch.php?batch=' . $batchId . '&saved=started');
                exit;

            case 'abandon_batch':
                $batch = bakery_sfb_batch($db, $customerId, (int)($_POST['batch_id'] ?? 0));
                if (!$batch) {
                    throw new InvalidArgumentException('Batch not found');
                }
                $db->prepare('UPDATE sfb_batches SET status = "abandoned" WHERE id = ? AND status = "in_progress"')
                    ->execute([(int)$batch['id']]);
                header('Location: sfb_batches.php?saved=abandoned');
                exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$batches = bakery_sfb_batches($db, $customerId);
$formulas = bakery_sfb_formulas($db, $customerId);
$templates = bakery_sfb_templates($db);
$journey = bakery_sfb_journey($db, $customerId);

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'started' => 'Batch started — happy mixing!',
    'abandoned' => 'Batch set aside. It will not count toward your loaf total.',
];

$page_title = 'SF Baker — Batches';
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

    <?php if (($_GET['welcome'] ?? '') === '1'): ?>
      <section class="card" style="margin-bottom:14px;">
        <div class="card-body">
          <p class="hero-label">You’re in</p>
          <h2 style="margin:0 0 8px;">Start your first batch</h2>
          <p class="muted" style="margin:0;">Choose a formula below, optionally name the batch, and tap Start batch. You can add your name or email later in Account.</p>
        </div>
      </section>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.start_batch'); ?></h2></div>
      <div class="card-body">
        <?php if (!$formulas && $templates): ?>
          <p class="muted" style="margin-top:0;">Pick a standard formula and we’ll make an editable copy just for you.</p>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="start_template_batch">
            <div class="sfb-field">
              <label><span>Your first formula</span>
                <select name="template_id">
                  <?php foreach ($templates as $template): ?>
                    <option value="<?php echo (int)$template['id']; ?>"><?php echo htmlspecialchars($template['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <input type="hidden" name="started_at" value="<?php echo htmlspecialchars(bakery_sfb_now_local_value()); ?>">
            <button type="submit" class="btn btn-block">Start my first batch</button>
          </form>
        <?php elseif (!$formulas): ?>
          <p class="empty-state"><?php bakery_te('sfb.need_formula_first'); ?></p>
          <a class="btn btn-secondary btn-block" href="sfb_formulas.php"><?php bakery_te('sfb.tab_formulas'); ?></a>
        <?php else: ?>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="start_batch">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.formula'); ?></span>
                <select name="formula_id">
                  <?php foreach ($formulas as $formula): ?>
                    <option value="<?php echo (int)$formula['id']; ?>"><?php echo htmlspecialchars($formula['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="sfb-grid2">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.batch_name'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_batch_name')); ?></span>
                  <input type="text" name="name" maxlength="120" placeholder="<?php bakery_te('sfb.enter_optional_batch_name'); ?>">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.started_at'); ?></span>
                  <input type="datetime-local" name="started_at" value="<?php echo bakery_sfb_now_local_value(); ?>">
                </label>
              </div>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.start_batch'); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <section>
      <h2 class="section-title"><?php bakery_te('sfb.my_batches'); ?></h2>
      <?php if (!$batches): ?>
        <div class="card"><div class="card-body"><p class="empty-state"><?php bakery_te('sfb.no_batches'); ?></p></div></div>
      <?php else: ?>
        <?php foreach ($batches as $batch):
          $phase = bakery_sfb_batch_phase($batch);
          $badgeClass = $batch['status'] === 'completed' ? 'badge-ok' : ($batch['status'] === 'abandoned' ? 'badge-muted' : 'badge-info');
        ?>
          <article class="delivery-card">
            <div class="delivery-card-top">
              <div>
                <h3 class="delivery-card-date">
                  <a href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>" style="color:inherit;text-decoration:none;">
                    <?php echo htmlspecialchars($batch['name']); ?>
                  </a>
                </h3>
                <p class="delivery-card-summary">
                  <?php echo htmlspecialchars($batch['formula_name'] ?? '—'); ?>
                  · <?php echo htmlspecialchars(date('D, M j · g:ia', strtotime($batch['started_at']))); ?>
                  <?php if ($batch['status'] === 'completed'): ?>
                    · <?php echo (int)$batch['loaf_count']; ?> <?php echo (int)$batch['loaf_count'] === 1 ? bakery_t('sfb.loaf') : bakery_t('sfb.loaves'); ?>
                  <?php endif; ?>
                </p>
              </div>
              <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(bakery_sfb_phase_label($phase)); ?></span>
            </div>
            <div class="delivery-card-actions" style="display:flex;gap:10px;flex-wrap:wrap;">
              <a class="btn btn-secondary btn-block" style="flex:1;" href="sfb_batch.php?batch=<?php echo (int)$batch['id']; ?>">
                <?php echo $batch['status'] === 'in_progress' ? bakery_t('sfb.continue_batch') : bakery_t('sfb.view_batch'); ?>
              </a>
              <?php if ($batch['status'] === 'in_progress'): ?>
                <form method="post" style="margin:0;" onsubmit="return confirm('Set this batch aside? It will not count toward your loaf total.');">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="abandon_batch">
                  <input type="hidden" name="batch_id" value="<?php echo (int)$batch['id']; ?>">
                  <button type="submit" class="btn-link" style="color:#9b332c;"><?php bakery_te('sfb.abandon'); ?></button>
                </form>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <p class="muted" style="text-align:center;">
      <?php echo (int)$journey['total']; ?> / <?php echo (int)$journey['goal']; ?> <?php bakery_te('sfb.loaves_journey'); ?>
    </p>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
