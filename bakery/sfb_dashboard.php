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
$feedback = bakery_sfb_baker_feedback($db, $customerId, 3);

$completedCourses = [];
foreach (bakery_sfb_courses($db) as $learnCourse) {
    [$learnDone, $learnTotal] = bakery_sfb_course_progress($db, $customerId, (int)$learnCourse['id']);
    if ($learnTotal > 0 && $learnDone === $learnTotal) {
        $completedCourses[] = (string)$learnCourse['title'];
    }
}

// First-run home base: show the welcome strip on the signup redirect or
// whenever the baker has nothing started yet.
$firstRun = ($_GET['welcome'] ?? '') === '1'
    || (!$starters && $formulaCount === 0 && !$recentBatches);

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
        <?php if ($journey['total'] >= 1000): ?>
          <?php
          $celebratedMilestone = 100;
          foreach ($milestones as $milestone) {
              if ($journey['total'] >= $milestone) {
                  $celebratedMilestone = $milestone;
              }
          }
          ?>
          <p style="margin:10px 0 0;">
            <span class="badge badge-ok"><?php echo htmlspecialchars(bakery_t('sfb.milestone_reached', ['count' => number_format($celebratedMilestone)]), ENT_QUOTES, 'UTF-8'); ?></span>
          </p>
        <?php endif; ?>
        <p class="muted" style="margin-top:10px;">
          <?php if ($journey['reached']): ?>
            <?php bakery_te('sfb.journey_reached'); ?>
          <?php else: ?>
            <?php echo htmlspecialchars(bakery_t('sfb.journey_remaining', ['count' => (int)$journey['remaining']])); ?>
          <?php endif; ?>
        </p>
        <p class="sfb-journey-how"><?php bakery_te('sfb.journey_how'); ?></p>
        <div class="sfb-hero-action">
          <?php if ($activeBatch): ?>
            <p>
              <strong><?php echo htmlspecialchars($activeBatch['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
              <span><?php echo htmlspecialchars(bakery_sfb_phase_label(bakery_sfb_batch_phase($activeBatch)), ENT_QUOTES, 'UTF-8'); ?></span>
            </p>
            <a class="btn" href="sfb_batch.php?batch=<?php echo (int)$activeBatch['id']; ?>"><?php bakery_te('sfb.continue_batch'); ?></a>
          <?php else: ?>
            <a class="btn" href="sfb_batches.php"><?php bakery_te($firstRun ? 'sfb.first_bake_cta' : 'sfb.start_batch'); ?></a>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <?php if ($feedback['coach_notes'] || $feedback['open_question_count'] > 0): ?>
      <section class="card sfb-feedback" aria-labelledby="sfbFeedbackTitle">
        <div class="card-header">
          <div>
            <h2 id="sfbFeedbackTitle"><?php bakery_te('sfb.feedback_title'); ?></h2>
            <p class="muted sfb-feedback__intro"><?php bakery_te('sfb.feedback_intro'); ?></p>
          </div>
          <?php if ($feedback['open_question_count'] > 0): ?>
            <span class="badge badge-info"><?php echo htmlspecialchars(bakery_t(
                $feedback['open_question_count'] === 1 ? 'sfb.feedback_waiting' : 'sfb.feedback_waiting_plural',
                ['count' => $feedback['open_question_count']]
            ), ENT_QUOTES, 'UTF-8'); ?></span>
          <?php endif; ?>
        </div>
        <?php if ($feedback['coach_notes']): ?>
          <div class="card-body">
            <ul class="sfb-feedback-list">
              <?php foreach ($feedback['coach_notes'] as $coachNote): ?>
                <?php
                $feedbackBody = trim((string)$coachNote['body']);
                if (mb_strlen($feedbackBody) > 180) {
                    $feedbackBody = rtrim(mb_substr($feedbackBody, 0, 177)) . '…';
                }
                ?>
                <li>
                  <div>
                    <strong><?php echo htmlspecialchars($coachNote['batch_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    <p><?php echo nl2br(htmlspecialchars($feedbackBody, ENT_QUOTES, 'UTF-8')); ?></p>
                  </div>
                  <a class="btn-link" href="sfb_batch.php?batch=<?php echo (int)$coachNote['batch_id']; ?>#sfb-discussion"><?php bakery_te('sfb.feedback_open'); ?></a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </section>
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

    <section class="card sfb-setup" aria-labelledby="sfbSetupTitle">
      <div class="card-header"><h2 id="sfbSetupTitle"><?php bakery_te('sfb.setup_title'); ?></h2></div>
      <div class="card-body">
        <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.setup_copy'); ?></p>
        <div class="sfb-quick">
          <a href="sfb_starters.php"><strong><?php echo count($starters); ?></strong><?php bakery_te('sfb.tab_starters'); ?></a>
          <a href="sfb_formulas.php"><strong><?php echo (int)$formulaCount; ?></strong><?php bakery_te('sfb.tab_formulas'); ?></a>
          <a href="sfb_ingredients.php"><strong>+</strong><?php bakery_te('sfb.tab_ingredients'); ?></a>
        </div>
        <?php if (bakery_sfb_payments_ready($db) || bakery_sfb_starter_jar_ready($db)): ?>
          <div class="sfb-setup__links">
            <?php if (bakery_sfb_starter_jar_ready($db)): ?><a class="btn-link" href="starter.php"><?php bakery_te('sfb.starter_jar_dash_cta'); ?></a><?php endif; ?>
            <?php if (bakery_sfb_payments_ready($db)): ?><a class="btn-link" href="sfb_offerings.php"><?php bakery_te('sfb.offerings_quick'); ?></a><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>

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

    <?php if ($completedCourses): ?>
      <section class="card" style="margin-bottom:14px;">
        <div class="card-header"><h2><?php bakery_te('sfb.course_complete_title'); ?></h2></div>
        <div class="card-body">
          <ul class="line-list">
            <?php foreach ($completedCourses as $completedCourseTitle): ?>
              <li>
                <a class="btn-link" href="sfb_resources.php"><?php echo htmlspecialchars($completedCourseTitle, ENT_QUOTES, 'UTF-8'); ?></a>
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
