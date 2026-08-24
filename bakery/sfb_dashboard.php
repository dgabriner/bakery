<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$journey = bakery_sfb_journey($db, $customerId);
$activeBatch = bakery_sfb_active_batch($db, $customerId);
$recentForReview = bakery_sfb_batches($db, $customerId, 20);
$recentBatches = array_slice($recentForReview, 0, 5);
$starters = bakery_sfb_starters($db, $customerId);
$formulaCount = count(bakery_sfb_formulas($db, $customerId));

// First-run home base: show the welcome strip on the signup redirect or
// whenever the baker has nothing started yet.
$firstRun = ($_GET['welcome'] ?? '') === '1'
    || (!$starters && $formulaCount === 0 && !$recentBatches);
$firstRunActions = $firstRun ? bakery_sfb_first_run_actions($db, $customerId) : [];
$firstRunLessonUrl = null;
foreach ($firstRunActions as $action) {
    if ($action['key'] === 'lesson' && !empty($action['lesson_id'])) {
        $firstRunLessonUrl = 'sfb_lesson.php?lesson=' . (int)$action['lesson_id'];
    }
}

$latestFeedings = [];
foreach ($starters as $starter) {
    $feedings = bakery_sfb_starter_feedings($db, (int)$starter['id'], 1);
    $latestFeedings[(int)$starter['id']] = $feedings[0] ?? null;
}

$milestones = [100, 250, 500, 750, 1000];
$lastCompleted = null;
foreach ($recentForReview as $recentBatch) {
    if (($recentBatch['status'] ?? '') === 'completed') {
        $lastCompleted = $recentBatch;
        break;
    }
}

$page_title = 'SF Baker';
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
    <?php $sfbActiveTab = 'dashboard'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($firstRun): ?>
      <section class="card" aria-labelledby="sfbFirstRun">
        <div class="card-header"><h2 id="sfbFirstRun"><?php bakery_te('sfb.first_run_title'); ?></h2></div>
        <div class="card-body">
          <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.first_run_intro'); ?></p>
          <ul class="line-list">
            <?php foreach ($firstRunActions as $action): ?>
              <li>
                <span>
                  <span class="badge <?php echo $action['done'] ? 'badge-ok' : 'badge-info'; ?>"><?php
                    bakery_te($action['done'] ? 'sfb.builder_step_done' : 'sfb.builder_step_todo');
                  ?></span>
                  <?php bakery_te('sfb.first_run_' . $action['key']); ?>
                </span>
                <?php if (!$action['done']): ?>
                  <?php if ($action['key'] === 'starter'): ?>
                    <a class="btn-link" href="sfb_starters.php"><?php bakery_te('sfb.first_run_go'); ?></a>
                  <?php elseif ($action['key'] === 'formula'): ?>
                    <a class="btn-link" href="sfb_formulas.php"><?php bakery_te('sfb.first_run_go'); ?></a>
                  <?php elseif ($action['key'] === 'lesson'): ?>
                    <a class="btn-link" href="<?php echo htmlspecialchars($firstRunLessonUrl ?? BASE_URL . 'sfb_resources.php', ENT_QUOTES, 'UTF-8'); ?>"><?php
                      echo htmlspecialchars($action['lesson_title'] !== '' ? $action['lesson_title'] : bakery_t('sfb.first_run_go'), ENT_QUOTES, 'UTF-8');
                    ?></a>
                  <?php endif; ?>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <section class="card hero-card sfb-hero">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.journey_label'); ?></p>
        <p class="sfb-journey-count"><?php echo (int)$journey['total']; ?> <span style="font-size:1rem;color:var(--muted);">/ <?php echo number_format($journey['goal']); ?> <?php bakery_te('sfb.loaves'); ?></span></p>
        <div class="sfb-journey-bar" role="progressbar" aria-valuenow="<?php echo (int)$journey['percent']; ?>" aria-valuemin="0" aria-valuemax="100">
          <div class="sfb-journey-fill" style="width: <?php echo (int)$journey['percent']; ?>%;"></div>
        </div>
        <div class="sfb-milestones">
          <?php foreach ($milestones as $milestone): ?>
            <span class="<?php echo $journey['total'] >= $milestone ? 'hit' : ''; ?>"><?php echo number_format($milestone); ?></span>
          <?php endforeach; ?>
        </div>
        <p class="muted" style="margin-top:10px;">
          <?php if ($journey['reached']): ?>
            <?php bakery_te('sfb.journey_reached'); ?>
          <?php else: ?>
            <?php echo htmlspecialchars(bakery_t('sfb.journey_remaining', ['count' => (int)$journey['remaining']])); ?>
          <?php endif; ?>
        </p>
      </div>
    </section>

    <?php if ($activeBatch): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.active_batch'); ?></h2></div>
        <div class="card-body">
          <p style="margin:0 0 4px;"><strong><?php echo htmlspecialchars($activeBatch['name']); ?></strong></p>
          <p class="muted">
            <?php echo htmlspecialchars($activeBatch['formula_name'] ?? ''); ?>
            · <?php echo htmlspecialchars(bakery_sfb_phase_label(bakery_sfb_batch_phase($activeBatch))); ?>
            · <?php echo htmlspecialchars(date('D, M j · g:ia', strtotime($activeBatch['started_at']))); ?>
          </p>
          <div class="btn-row">
            <a class="btn btn-block" href="sfb_batch.php?batch=<?php echo (int)$activeBatch['id']; ?>"><?php bakery_te('sfb.continue_batch'); ?></a>
          </div>
        </div>
      </section>
    <?php else: ?>
      <div class="btn-row" style="margin-bottom:14px;">
        <a class="btn btn-block" href="sfb_batches.php"><?php bakery_te('sfb.start_batch'); ?></a>
      </div>
    <?php endif; ?>

    <?php if ($lastCompleted): ?>
      <section class="card" style="margin-bottom:14px;">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('sfb.library_review_title'); ?></p>
          <p><?php echo htmlspecialchars($lastCompleted['name'], ENT_QUOTES, 'UTF-8'); ?></p>
          <div class="btn-row">
            <a class="btn btn-block" href="sfb_batch.php?batch=<?php echo (int)$lastCompleted['id']; ?>#sfb-review"><?php bakery_te('sfb.library_review_open'); ?></a>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <div class="sfb-quick" style="margin-bottom:14px;">
      <a href="sfb_starters.php"><strong><?php echo count($starters); ?></strong><?php bakery_te('sfb.tab_starters'); ?></a>
      <a href="sfb_formulas.php"><strong><?php echo (int)$formulaCount; ?></strong><?php bakery_te('sfb.tab_formulas'); ?></a>
      <a href="sfb_batches.php"><strong><?php echo count($recentBatches); ?></strong><?php bakery_te('sfb.tab_batches'); ?></a>
    </div>

    <?php if ($starters): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.starter_check'); ?></h2></div>
        <div class="card-body">
          <ul class="line-list">
            <?php foreach ($starters as $starter): $last = $latestFeedings[(int)$starter['id']] ?? null; ?>
              <li>
                <span>
                  <?php echo htmlspecialchars($starter['name']); ?>
                  <br>
                  <small class="muted">
                    <?php if ($last): ?>
                      <?php echo htmlspecialchars(bakery_t('sfb.last_fed', ['when' => date('D, M j · g:ia', strtotime($last['fed_at']))])); ?>
                      · <?php echo htmlspecialchars(bakery_sfb_feeding_ratio($last)); ?>
                    <?php else: ?>
                      <?php bakery_te('sfb.never_fed'); ?>
                    <?php endif; ?>
                  </small>
                </span>
                <a class="btn-link" href="sfb_starters.php?starter=<?php echo (int)$starter['id']; ?>"><?php bakery_te('sfb.feed_now'); ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($recentBatches): ?>
      <section>
        <h2 class="section-title"><?php bakery_te('sfb.recent_batches'); ?></h2>
        <?php foreach ($recentBatches as $batch):
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
                  <?php echo htmlspecialchars(date('D, M j', strtotime($batch['started_at']))); ?>
                  <?php if ($batch['status'] === 'completed'): ?>
                    · <?php echo (int)$batch['loaf_count']; ?> <?php echo (int)$batch['loaf_count'] === 1 ? bakery_t('sfb.loaf') : bakery_t('sfb.loaves'); ?>
                  <?php endif; ?>
                </p>
              </div>
              <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(bakery_sfb_phase_label($phase)); ?></span>
            </div>
          </article>
        <?php endforeach; ?>
        <a class="btn btn-secondary btn-block" href="sfb_batches.php"><?php bakery_te('sfb.all_batches'); ?></a>
      </section>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
