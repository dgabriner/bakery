<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';
require_once __DIR__ . '/includes/sfb_step_text.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$lessonId = (int)($_REQUEST['lesson'] ?? 0);
$lesson = $lessonId > 0 ? bakery_sfb_lesson($db, $lessonId) : null;
if (!$lesson || (int)$lesson['is_active'] !== 1) {
    header('Location: sfb_resources.php');
    exit;
}

// Course gating (migration 068): paid classes show an enrollment card
// instead of lesson content until this baker holds a valid entitlement.
$courseRow = bakery_sfb_course($db, (int)$lesson['course_id']);
$lock = $courseRow
    ? bakery_sfb_course_lock($db, $customerId, $courseRow)
    : ['locked' => false, 'offering' => null];

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_course_batch') {
    try {
        if ($lock['locked']) {
            // Locked courses never hand off formula bakes either.
            header('Location: sfb_offerings.php');
            exit;
        }
        bakery_require_csrf();
        $newBatchId = bakery_sfb_start_batch_from_course(
            $db,
            $customerId,
            (int)$lesson['course_id']
        );
        header('Location: sfb_batch.php?batch=' . (int)$newBatchId . '&saved=started');
        exit;
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_step') {
    try {
        if ($lock['locked']) {
            // Locked courses never accept progress writes.
            header('Location: sfb_offerings.php');
            exit;
        }
        bakery_require_csrf();
        $nowDone = bakery_sfb_toggle_lesson_progress(
            $db,
            $customerId,
            (int)$lesson['id'],
            (int)($_POST['step_id'] ?? 0)
        );
        header('Location: sfb_lesson.php?lesson=' . (int)$lesson['id']
            . '&step=' . (int)($_POST['step_id'] ?? 0)
            . ($nowDone ? '&saved=done' : '&saved=undone')
            . '#step-' . (int)($_POST['step_id'] ?? 0));
        exit;
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$courseId = (int)$lesson['course_id'];
$courseLessons = [];
$steps = [];
$completedSteps = [];
$completedSet = [];
[$courseDone, $courseTotal] = [0, 0];
$nextLesson = null;
$isLastLesson = false;
if (!$lock['locked']) {
    $courseLessons = bakery_sfb_course_lessons($db, $courseId);
    $steps = bakery_sfb_lesson_steps($db, (int)$lesson['id']);
    $completedSteps = bakery_sfb_lesson_progress($db, $customerId, (int)$lesson['id']);
    $completedSet = array_fill_keys($completedSteps, true);
    [$courseDone, $courseTotal] = bakery_sfb_course_progress($db, $customerId, $courseId);

    foreach ($courseLessons as $i => $row) {
        if ((int)$row['id'] === (int)$lesson['id']) {
            $isLastLesson = !isset($courseLessons[$i + 1]);
            if (isset($courseLessons[$i + 1])) {
                $nextLesson = $courseLessons[$i + 1];
            }
            break;
        }
    }
}

// Migration 069: the last lesson of a mapped course offers a one-click bake.
$handoffFormulaId = (!$lock['locked'] && bakery_sfb_handoff_ready($db) && $isLastLesson
    && !empty($courseRow['template_formula_id'])) ? (int)$courseRow['template_formula_id'] : 0;

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'done' => bakery_t('sfb.learn_step_done'),
    'undone' => bakery_t('sfb.learn_step_undone'),
];

$page_title = $lesson['title'] . ' — ' . bakery_t('sfb.learn_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];
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
    <?php $sfbActiveTab = 'resources'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <p class="sfb-back-link"><a href="sfb_resources.php"><?php bakery_te('sfb.resources_back_to_center'); ?></a></p>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card hero-card">
      <div class="card-body">
        <p class="hero-label"><?php echo htmlspecialchars($lesson['course_title'], ENT_QUOTES, 'UTF-8'); ?></p>
        <h2 class="hero-date"><?php echo htmlspecialchars($lesson['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php if (!empty($lesson['summary'])): ?>
          <p class="muted"><?php echo htmlspecialchars($lesson['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if (!$lock['locked']): ?>
        <div class="meta-row">
          <span class="badge badge-info"><?php
            echo bakery_t('sfb.learn_course_progress', ['done' => (string)$courseDone, 'total' => (string)$courseTotal]);
          ?></span>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($lock['locked']): ?>
      <section class="card">
        <div class="card-body">
          <h2><?php bakery_te('sfb.lesson_locked_title'); ?></h2>
          <p class="muted"><?php bakery_te('sfb.lesson_locked_copy'); ?></p>
          <?php if (!empty($lock['offering'])): ?>
            <p><strong><?php echo htmlspecialchars((string)$lock['offering']['title'], ENT_QUOTES, 'UTF-8'); ?>
              · $<?php echo number_format((float)$lock['offering']['price_cents'] / 100, 2); ?></strong></p>
          <?php endif; ?>
          <a class="btn btn-block" href="sfb_offerings.php<?php echo !empty($lock['offering']) ? '#offering-' . (int)$lock['offering']['id'] : ''; ?>"><?php bakery_te('sfb.lesson_locked_cta'); ?></a>
        </div>
      </section>
    <?php else: ?>
    <?php foreach ($steps as $index => $step): ?>
      <?php $stepDone = isset($completedSet[(int)$step['id']]); ?>
      <section class="card sfb-phase <?php echo $stepDone ? '' : 'current'; ?>" id="step-<?php echo (int)$step['id']; ?>">
        <div class="card-header"><h2><?php echo ($index + 1); ?>.</h2></div>
        <div class="card-body">
          <?php if (!empty($step['body_text'])): ?>
            <p><?php echo bakery_sfb_render_step_text($step['body_text']); ?></p>
          <?php endif; ?>
          <?php if (!empty($step['media_path'])): ?>
            <?php if (($step['media_kind'] ?? 'photo') === 'video'): ?>
              <video class="sfb-photo" controls preload="metadata"
                src="<?php echo htmlspecialchars(bakery_sfb_media_url($step['media_path']), ENT_QUOTES, 'UTF-8'); ?>"></video>
            <?php else: ?>
              <img class="sfb-photo" loading="lazy"
                src="<?php echo htmlspecialchars(bakery_sfb_media_url($step['media_path']), ENT_QUOTES, 'UTF-8'); ?>"
                alt="<?php echo htmlspecialchars(bakery_t('sfb.photos'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
          <?php endif; ?>
          <form method="post" style="margin:10px 0 0;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="toggle_step">
            <input type="hidden" name="step_id" value="<?php echo (int)$step['id']; ?>">
            <button type="submit" class="btn <?php echo $stepDone ? 'btn-secondary' : ''; ?> btn-block">
              <?php bakery_te($stepDone ? 'sfb.learn_mark_undone' : 'sfb.learn_mark_done'); ?>
            </button>
          </form>
        </div>
      </section>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($lesson['external_url'])): ?>
      <section class="card">
        <div class="card-body">
          <a class="btn btn-block" href="<?php echo htmlspecialchars($lesson['external_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
            <?php bakery_te('sfb.open_learning_zone'); ?>
          </a>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($nextLesson): ?>
      <a class="btn btn-block" href="sfb_lesson.php?lesson=<?php echo (int)$nextLesson['id']; ?>"><?php
        echo bakery_t('sfb.learn_next_lesson') . ': ' . htmlspecialchars($nextLesson['title'], ENT_QUOTES, 'UTF-8');
      ?></a>
    <?php endif; ?>
    <?php if ($handoffFormulaId > 0): ?>
      <form method="post" style="margin-top:8px;">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="start_course_batch">
        <button type="submit" class="btn btn-block"><?php bakery_te('sfb.learn_start_bake_cta'); ?></button>
      </form>
    <?php endif; ?>
    <a class="btn btn-secondary btn-block" href="sfb_batch.php?ask=mix#sfb-discussion" style="margin-top:8px;"><?php bakery_te('sfb.learn_ask_coach'); ?></a>
    <a class="btn btn-secondary btn-block" href="sfb_resources.php"><?php bakery_te('sfb.resources_back_to_center'); ?></a>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
