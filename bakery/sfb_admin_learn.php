<?php
/** Learning-center authoring: courses, lessons, steps with photo/video. */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

bakery_require_role(['administrator']);

if (!bakery_sfb_learning_ready($db)) {
    http_response_code(503);
    exit('The learning center needs the latest database migration before it can be opened.');
}

$admin = bakery_current_user();
$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_course':
                bakery_sfb_create_course($db, $_POST['title'] ?? '', $_POST['description'] ?? '');
                header('Location: sfb_admin_learn.php?saved=course_created');
                exit;

            case 'create_invite':
                $invite = bakery_sfb_create_invite(
                    $db,
                    ($_POST['intent'] ?? 'learn') === 'share' ? 'share' : 'learn',
                    $_POST['label'] ?? '',
                    (int)($admin['id'] ?? 0)
                );
                header('Location: sfb_admin_learn.php?saved=invite_created&invite=' . rawurlencode((string)$invite['code']) . '#invites');
                exit;

            case 'mark_purchase':
                $markNote = trim((string)($_POST['note'] ?? ''));
                bakery_sfb_set_purchase_status(
                    $db,
                    (int)($_POST['purchase_id'] ?? 0),
                    (string)($_POST['status'] ?? ''),
                    null,
                    $markNote !== '' ? $markNote : 'recorded by ' . (string)($admin['display_name'] ?? 'staff'),
                    (int)($admin['id'] ?? 0)
                );
                header('Location: sfb_admin_learn.php?saved=purchase_marked#purchases');
                exit;

            case 'create_offering':
                bakery_sfb_create_offering(
                    $db,
                    $_POST['title'] ?? '',
                    (float)($_POST['price'] ?? 0),
                    $_POST['kind'] ?? 'class',
                    $_POST['description'] ?? '',
                    ($_POST['entitlement_days'] ?? '') !== '' ? (int)$_POST['entitlement_days'] : null
                );
                header('Location: sfb_admin_learn.php?saved=offering_created');
                exit;

            case 'toggle_offering':
                bakery_sfb_toggle_offering($db, (int)($_POST['offering_id'] ?? 0));
                header('Location: sfb_admin_learn.php?saved=course_updated');
                exit;

            case 'toggle_course':
                bakery_sfb_toggle_course($db, (int)($_POST['course_id'] ?? 0));
                header('Location: sfb_admin_learn.php?saved=course_updated');
                exit;

            case 'create_lesson':
                $lessonId = bakery_sfb_create_lesson(
                    $db,
                    (int)($_POST['course_id'] ?? 0),
                    $_POST['title'] ?? '',
                    $_POST['summary'] ?? '',
                    $_POST['external_url'] ?? ''
                );
                header('Location: sfb_admin_learn.php?lesson=' . $lessonId . '&saved=lesson_created');
                exit;

            case 'toggle_lesson':
                bakery_sfb_toggle_lesson($db, (int)($_POST['lesson_id'] ?? 0));
                header('Location: sfb_admin_learn.php?saved=lesson_updated');
                exit;

            case 'add_step':
                $mediaPath = '';
                $mediaKind = 'photo';
                if (isset($_FILES['media']) && ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $stored = bakery_sfb_save_education_media($_FILES['media']);
                    $mediaPath = $stored['path'];
                    $mediaKind = $stored['kind'];
                }
                bakery_sfb_add_lesson_step(
                    $db,
                    (int)($_POST['lesson_id'] ?? 0),
                    $_POST['body_text'] ?? '',
                    $mediaPath,
                    $mediaKind
                );
                header('Location: sfb_admin_learn.php?lesson=' . (int)($_POST['lesson_id'] ?? 0) . '&saved=step_added');
                exit;

            case 'move_step':
                bakery_sfb_move_lesson_step(
                    $db,
                    (int)($_POST['lesson_id'] ?? 0),
                    (int)($_POST['step_id'] ?? 0),
                    ($_POST['direction'] ?? 'up') === 'down' ? 'down' : 'up'
                );
                header('Location: sfb_admin_learn.php?lesson=' . (int)($_POST['lesson_id'] ?? 0) . '&saved=step_moved');
                exit;

            case 'delete_step':
                bakery_sfb_delete_lesson_step($db, (int)($_POST['step_id'] ?? 0));
                header('Location: sfb_admin_learn.php?lesson=' . (int)($_POST['lesson_id'] ?? 0) . '&saved=step_deleted');
                exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$courses = bakery_sfb_courses($db, true);

$selectedLesson = null;
$selectedSteps = [];
$selectedCourseLessons = [];
$selectedLessonId = (int)($_REQUEST['lesson'] ?? 0);
if ($selectedLessonId > 0) {
    $selectedLesson = bakery_sfb_lesson($db, $selectedLessonId);
}
if ($selectedLesson) {
    $selectedCourseLessons = bakery_sfb_course_lessons($db, (int)$selectedLesson['course_id'], true);
    $selectedSteps = bakery_sfb_lesson_steps($db, (int)$selectedLesson['id']);
}

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'course_created' => bakery_t('sfb.admin_saved_course'),
    'course_updated' => bakery_t('sfb.admin_saved_course'),
    'lesson_created' => bakery_t('sfb.admin_saved_lesson'),
    'lesson_updated' => bakery_t('sfb.admin_saved_lesson'),
    'step_added' => bakery_t('sfb.admin_saved_step'),
    'step_moved' => bakery_t('sfb.admin_saved_step'),
    'step_deleted' => bakery_t('sfb.admin_deleted_step'),
    'invite_created' => bakery_t('sfb.invite_created_saved'),
    'offering_created' => bakery_t('sfb.offering_saved'),
    'purchase_marked' => bakery_t('sfb.purchase_marked_saved'),
];

$recentInvites = bakery_sfb_recent_invites($db);
$recentPurchases = bakery_sfb_recent_purchases($db);
$adminOfferings = bakery_sfb_offerings($db, true);
$joinBase = BASE_URL . 'sfb_join.php';

$page_title = bakery_t('sfb.admin_learn_title');
$currentLocale = bakery_locale();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
</head>
<body class="sfb-body">
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container sfb-app">
    <p class="sfb-back-link"><a href="sfb_admin_overview.php"><?php bakery_te('sfb.community_back_to_admin'); ?></a></p>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card hero-card">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.learn_title'); ?></p>
        <h2 class="hero-date"><?php bakery_te('sfb.admin_learn_title'); ?></h2>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.admin_courses'); ?></h2></div>
      <div class="card-body">
        <?php if (!$courses): ?>
          <p class="muted"><?php bakery_te('sfb.admin_no_courses'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($courses as $course): ?>
              <li>
                <span>
                  <strong><?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <?php if ((int)$course['is_active'] !== 1): ?><span class="badge badge-muted"><?php bakery_te('sfb.admin_hidden'); ?></span><?php endif; ?>
                  <span class="muted"> · <?php echo (int)$course['lesson_count']; ?> <?php bakery_te('sfb.lessons'); ?></span>
                </span>
                <span class="line-qty" style="display:flex;gap:6px;">
                  <form method="post" style="margin:0;"><?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle_course">
                    <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
                    <button class="btn-link" type="submit"><?php bakery_te((int)$course['is_active'] === 1 ? 'sfb.admin_hide' : 'sfb.admin_show'); ?></button>
                  </form>
                  <a class="btn-link" href="sfb_admin_learn.php?course=<?php echo (int)$course['id']; ?>#new-lesson"><?php bakery_te('sfb.admin_add_lesson'); ?></a>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <details class="add-row" id="new-course">
          <summary><?php bakery_te('sfb.admin_new_course'); ?></summary>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;margin-top:10px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="create_course">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.title_label'); ?></span>
                <input type="text" name="title" required maxlength="150">
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.description_label'); ?></span>
                <textarea name="description" rows="2"></textarea>
              </label>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.admin_new_course'); ?></button>
          </form>
        </details>
      </div>
    </section>

    <?php
    $openCourseId = (int)($_GET['course'] ?? ($selectedLesson['course_id'] ?? 0));
    if ($openCourseId > 0):
      $openCourse = bakery_sfb_course($db, $openCourseId);
      if ($openCourse):
        $openLessons = bakery_sfb_course_lessons($db, (int)$openCourse['id'], true);
    ?>
    <section class="card">
      <div class="card-header"><h2><?php echo htmlspecialchars($openCourse['title'], ENT_QUOTES, 'UTF-8'); ?></h2></div>
      <div class="card-body">
        <?php if (!$openLessons): ?>
          <p class="muted"><?php bakery_te('sfb.admin_no_lessons'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($openLessons as $openLesson): ?>
              <li>
                <span>
                  <a href="sfb_admin_learn.php?lesson=<?php echo (int)$openLesson['id']; ?>" style="color:inherit;">
                    <?php echo htmlspecialchars($openLesson['title'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                  <?php if ((int)$openLesson['is_active'] !== 1): ?><span class="badge badge-muted"><?php bakery_te('sfb.admin_hidden'); ?></span><?php endif; ?>
                </span>
                <span class="line-qty">
                  <form method="post" style="margin:0;display:inline;"><?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle_lesson">
                    <input type="hidden" name="lesson_id" value="<?php echo (int)$openLesson['id']; ?>">
                    <button class="btn-link" type="submit"><?php bakery_te((int)$openLesson['is_active'] === 1 ? 'sfb.admin_hide' : 'sfb.admin_show'); ?></button>
                  </form>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <details class="add-row" id="new-lesson" <?php echo isset($_GET['course']) ? ' open' : ''; ?>>
          <summary><?php bakery_te('sfb.admin_add_lesson'); ?></summary>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;margin-top:10px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="create_lesson">
            <input type="hidden" name="course_id" value="<?php echo (int)$openCourse['id']; ?>">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.title_label'); ?></span>
                <input type="text" name="title" required maxlength="150">
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.summary_label'); ?></span>
                <textarea name="summary" rows="2"></textarea>
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.external_url_label'); ?></span>
                <input type="url" name="external_url" maxlength="500" placeholder="https://bakery.sourflour.org/breadeducation/...">
              </label>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.admin_add_lesson'); ?></button>
          </form>
        </details>
      </div>
    </section>
    <?php endif; endif; ?>

    <?php if ($selectedLesson): ?>
    <section class="card" id="purchases">
      <div class="card-header"><h2><?php bakery_te('sfb.purchase_ops_title'); ?></h2></div>
      <div class="card-body">
        <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.purchase_ops_intro'); ?></p>
        <?php if (!$recentPurchases): ?>
          <p class="muted"><?php bakery_te('sfb.purchase_none'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($recentPurchases as $purchaseRow): ?>
              <li>
                <span>
                  <?php echo htmlspecialchars((string)$purchaseRow['customer_name'], ENT_QUOTES, 'UTF-8'); ?>
                  · <?php echo htmlspecialchars($purchaseRow['offering_title_snapshot'], ENT_QUOTES, 'UTF-8'); ?>
                  · $<?php echo number_format((float)$purchaseRow['price_cents_snapshot'] / 100, 2); ?>
                  <br><small class="muted"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($purchaseRow['created_at'])), ENT_QUOTES, 'UTF-8'); ?><?php
                    if (!empty($purchaseRow['manual_note'])) { echo ' · ' . htmlspecialchars($purchaseRow['manual_note'], ENT_QUOTES, 'UTF-8'); }
                  ?></small>
                </span>
                <span class="line-qty" style="display:flex;gap:6px;align-items:center;">
                  <span class="badge <?php
                    echo $purchaseRow['status'] === 'paid' ? 'badge-ok' : (in_array($purchaseRow['status'], ['pending', 'intent'], true) ? 'badge-info' : 'badge-muted');
                  ?>"><?php bakery_te('sfb.purchase_status_' . (string)$purchaseRow['status']); ?></span>
                  <?php foreach ([['paid', 'sfb.purchase_mark_paid'], ['refunded', 'sfb.purchase_mark_refunded']] as $markAction): ?>
                    <?php
                    $canMark = ($markAction[0] === 'paid' && in_array($purchaseRow['status'], ['intent', 'pending', 'failed'], true))
                        || ($markAction[0] === 'refunded' && $purchaseRow['status'] === 'paid');
                    ?>
                    <?php if ($canMark): ?>
                      <form method="post" style="margin:0;"><?php echo bakery_csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_purchase">
                        <input type="hidden" name="purchase_id" value="<?php echo (int)$purchaseRow['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo $markAction[0]; ?>">
                        <button class="btn-link" type="submit"><?php bakery_te($markAction[1]); ?></button>
                      </form>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <details class="add-row" style="margin-top:12px;">
          <summary><?php bakery_te('sfb.offering_new'); ?></summary>
          <form method="post" class="inline-form" style="grid-template-columns:1fr;margin-top:10px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="create_offering">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.title_label'); ?></span>
                <input type="text" name="title" required maxlength="150">
              </label>
            </div>
            <div class="sfb-grid3">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.offering_price_label'); ?></span>
                  <input type="number" name="price" min="0" max="1000000" step="0.01" required placeholder="45.00">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.offering_kind_label'); ?></span>
                  <select name="kind">
                    <option value="class"><?php bakery_te('sfb.offering_kind_class'); ?></option>
                    <option value="membership"><?php bakery_te('sfb.offering_kind_membership'); ?></option>
                    <option value="kit"><?php bakery_te('sfb.offering_kind_kit'); ?></option>
                  </select>
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.offering_days_label'); ?></span>
                  <input type="number" name="entitlement_days" min="0" max="3650" placeholder="<?php bakery_te('sfb.offering_days_hint'); ?>">
                </label>
              </div>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.description_label'); ?></span>
                <textarea name="description" rows="2"></textarea>
              </label>
            </div>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.offering_new'); ?></button>
          </form>
        </details>

        <?php if ($adminOfferings): ?>
          <ul class="line-list" style="margin-top:10px;">
            <?php foreach ($adminOfferings as $adminOffering): ?>
              <li>
                <span>
                  <?php echo htmlspecialchars($adminOffering['title'], ENT_QUOTES, 'UTF-8'); ?>
                  · $<?php echo number_format((float)$adminOffering['price_cents'] / 100, 2); ?>
                  <?php if ((int)$adminOffering['is_active'] !== 1): ?><span class="badge badge-muted"><?php bakery_te('sfb.admin_hidden'); ?></span><?php endif; ?>
                </span>
                <form method="post" style="margin:0;"><?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle_offering">
                  <input type="hidden" name="offering_id" value="<?php echo (int)$adminOffering['id']; ?>">
                  <button class="btn-link" type="submit"><?php bakery_te((int)$adminOffering['is_active'] === 1 ? 'sfb.admin_hide' : 'sfb.admin_show'); ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </section>

    <section class="card" id="invites">
      <div class="card-header"><h2><?php bakery_te('sfb.invite_title'); ?></h2></div>
      <div class="card-body">
        <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.invite_intro'); ?></p>
        <?php if ($recentInvites): ?>
          <ul class="line-list">
            <?php foreach ($recentInvites as $inviteRow): ?>
              <li>
                <span>
                  <small class="mono"><strong><?php echo htmlspecialchars($inviteRow['code'], ENT_QUOTES, 'UTF-8'); ?></strong></small>
                  · <?php bakery_te($inviteRow['intent'] === 'share' ? 'sfb.invite_intent_share' : 'sfb.invite_intent_learn'); ?>
                  <?php if (!empty($inviteRow['label'])): ?>
                    · <?php echo htmlspecialchars($inviteRow['label'], ENT_QUOTES, 'UTF-8'); ?>
                  <?php endif; ?>
                  <?php if ($inviteRow['used_by_customer_id'] !== null): ?>
                    <span class="badge badge-ok"><?php bakery_te('sfb.invite_used'); ?></span>
                  <?php endif; ?>
                </span>
                <?php if ($inviteRow['used_by_customer_id'] === null): ?>
                  <span class="line-qty">
                    <a class="btn-link" href="<?php echo htmlspecialchars($joinBase . '?invite=' . rawurlencode((string)$inviteRow['code']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.invite_open_link'); ?></a>
                  </span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="muted"><?php bakery_te('sfb.invite_none'); ?></p>
        <?php endif; ?>
        <form method="post" class="inline-form" style="grid-template-columns:1fr;margin-top:12px;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="create_invite">
          <div class="sfb-grid2">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.invite_intent_label'); ?></span>
                <select name="intent">
                  <option value="learn"><?php bakery_te('sfb.invite_intent_learn'); ?></option>
                  <option value="share"><?php bakery_te('sfb.invite_intent_share'); ?></option>
                </select>
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.invite_label_label'); ?></span>
                <input type="text" name="label" maxlength="150" placeholder="<?php bakery_te('sfb.invite_label_placeholder'); ?>">
              </label>
            </div>
          </div>
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.invite_create'); ?></button>
        </form>
      </div>
    </section>

    <section class="card">
        <div class="card-header"><h2><?php echo htmlspecialchars($selectedLesson['title'], ENT_QUOTES, 'UTF-8'); ?></h2></div>
        <div class="card-body">
          <p class="muted"><?php bakery_te('sfb.admin_steps_hint'); ?></p>
          <?php if (!$selectedSteps): ?>
            <p class="muted"><?php bakery_te('sfb.admin_no_steps'); ?></p>
          <?php else: ?>
            <?php foreach ($selectedSteps as $stepIndex => $step): ?>
              <article class="delivery-item" style="margin-bottom:10px;" id="astep-<?php echo (int)$step['id']; ?>">
                <div>
                  <span class="delivery-item__date"><?php echo ($stepIndex + 1); ?>.</span>
                  <?php if (!empty($step['body_text'])): ?>
                    <div><?php echo nl2br(htmlspecialchars($step['body_text'], ENT_QUOTES, 'UTF-8')); ?></div>
                  <?php endif; ?>
                  <?php if (!empty($step['media_path'])): ?>
                    <div class="delivery-item__meta">
                      <?php if (($step['media_kind'] ?? 'photo') === 'video'): ?>
                        <video controls preload="metadata" style="max-width:100%;"
                          src="<?php echo htmlspecialchars(bakery_sfb_media_url($step['media_path']), ENT_QUOTES, 'UTF-8'); ?>"></video>
                      <?php else: ?>
                        <img loading="lazy" style="max-width:100%;"
                          src="<?php echo htmlspecialchars(bakery_sfb_media_url($step['media_path']), ENT_QUOTES, 'UTF-8'); ?>"
                          alt="<?php echo htmlspecialchars(bakery_t('sfb.photos'), ENT_QUOTES, 'UTF-8'); ?>">
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                  <div style="display:flex;gap:8px;margin-top:6px;">
                    <form method="post" style="margin:0;"><?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="move_step">
                      <input type="hidden" name="lesson_id" value="<?php echo (int)$selectedLesson['id']; ?>">
                      <input type="hidden" name="step_id" value="<?php echo (int)$step['id']; ?>">
                      <input type="hidden" name="direction" value="up">
                      <button class="btn-link" type="submit">↑</button>
                    </form>
                    <form method="post" style="margin:0;"><?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="move_step">
                      <input type="hidden" name="lesson_id" value="<?php echo (int)$selectedLesson['id']; ?>">
                      <input type="hidden" name="step_id" value="<?php echo (int)$step['id']; ?>">
                      <input type="hidden" name="direction" value="down">
                      <button class="btn-link" type="submit">↓</button>
                    </form>
                    <form method="post" style="margin:0;"><?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="delete_step">
                      <input type="hidden" name="lesson_id" value="<?php echo (int)$selectedLesson['id']; ?>">
                      <input type="hidden" name="step_id" value="<?php echo (int)$step['id']; ?>">
                      <button class="btn-link" type="submit">✕</button>
                    </form>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>

          <form method="post" enctype="multipart/form-data" class="inline-form" style="grid-template-columns:1fr;margin-top:12px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="add_step">
            <input type="hidden" name="lesson_id" value="<?php echo (int)$selectedLesson['id']; ?>">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.step_text_label'); ?></span>
                <textarea name="body_text" rows="3"></textarea>
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.step_media_label'); ?></span>
                <input type="file" name="media" accept="image/*,video/mp4,video/webm,video/quicktime">
              </label>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.admin_add_step'); ?></button>
          </form>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
