<?php
declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);

$canonicalPieces = bakery_sfb_library_kind('canonical');
$troublePieces = bakery_sfb_library_kind('troubleshooting');

$page_title = bakery_t('sfb.resources_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];

function bakery_sfb_resources_render_card(array $piece): void {
    $isTrouble = ($piece['kind'] ?? '') === 'troubleshooting';
    ?>
        <article class="card sfb-resource-card<?php echo $isTrouble ? ' sfb-resource-card--trouble' : ''; ?>" id="library-<?php echo htmlspecialchars($piece['slug'], ENT_QUOTES, 'UTF-8'); ?>">
          <div class="card-body">
            <p class="sfb-resource-card__circle"><?php bakery_te(bakery_sfb_community_category_key($piece['category'])); ?></p>
            <h2><?php bakery_te($piece['title_key']); ?></h2>
            <p class="sfb-resource-card__lead"><?php bakery_te($piece['lead_key']); ?></p>
            <p class="sfb-resource-card__next"><strong><?php bakery_te('sfb.library_next_label'); ?>:</strong> <?php bakery_te($piece['action_key']); ?></p>
            <ul>
              <?php foreach ($piece['point_keys'] as $point): ?>
                <li><?php bakery_te($point); ?></li>
              <?php endforeach; ?>
            </ul>
            <div class="btn-row">
              <a class="btn btn-block" href="<?php echo htmlspecialchars(bakery_sfb_library_ask_url($piece['slug']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.library_ask'); ?></a>
            </div>
          </div>
        </article>
    <?php
}
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

    <?php if (bakery_sfb_payments_ready($db)): ?>
      <section class="card sfb-resource-hero">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('sfb.offerings_eyebrow'); ?></p>
          <h2><?php bakery_te('sfb.offerings_hero_title'); ?></h2>
          <p><?php bakery_te('sfb.offerings_hero_copy'); ?></p>
          <div class="btn-row" style="margin-top:14px;">
            <a class="btn btn-block" href="sfb_offerings.php"><?php bakery_te('sfb.offerings_link'); ?></a>
            <a class="btn btn-secondary btn-block" href="sfb_offerings.php#donate"><?php bakery_te('sfb.donate_link'); ?></a>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php
    $learnCourses = bakery_sfb_courses($db);
    if ($learnCourses):
    ?>
      <h2 class="section-title"><?php bakery_te('sfb.learn_courses_title'); ?></h2>
      <section class="sfb-resource-grid" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.learn_courses_title'), ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($learnCourses as $course): ?>
          <?php
          [$courseDoneSteps, $courseTotalSteps] = bakery_sfb_course_progress($db, (int)$customer['id'], (int)$course['id']);
          $lock = bakery_sfb_course_lock($db, (int)$customer['id'], $course);
          ?>
          <article class="card sfb-resource-card">
            <div class="card-body">
              <p class="sfb-resource-card__circle"><?php echo (int)$course['lesson_count']; ?> <?php bakery_te('sfb.lessons'); ?></p>
              <h2><?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
              <?php if (!empty($course['description'])): ?>
                <p class="sfb-resource-card__lead"><?php echo htmlspecialchars($course['description'], ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
              <?php if ($lock['offering']): ?>
                <span class="badge badge-info"><?php
                  echo htmlspecialchars(bakery_t('sfb.course_included_with', ['label' => (string)$lock['offering']['title']]), ENT_QUOTES, 'UTF-8');
                  if ((int)($lock['offering']['price_cents'] ?? 0) > 0) {
                      echo ' · $' . number_format((float)$lock['offering']['price_cents'] / 100, 2);
                  }
                ?></span>
              <?php else: ?>
                <span class="badge badge-ok"><?php bakery_te('sfb.course_free_label'); ?></span>
              <?php endif; ?>
              <?php if ($courseTotalSteps > 0 && !$lock['locked']): ?>
                <p class="muted"><?php
                  echo bakery_t('sfb.learn_course_progress', ['done' => (string)$courseDoneSteps, 'total' => (string)$courseTotalSteps]);
                ?></p>
              <?php endif; ?>
            </div>
            <?php
            $firstLesson = null;
            foreach (bakery_sfb_course_lessons($db, (int)$course['id']) as $candidate) {
                $firstLesson = $candidate;
                break;
            }
            ?>
            <div class="btn-row" style="padding:0 16px 16px;">
              <?php if ($lock['locked']): ?>
                <a class="btn btn-block" href="sfb_offerings.php#offering-<?php echo (int)$lock['offering']['id']; ?>"><?php bakery_te('sfb.lesson_locked_cta'); ?></a>
              <?php elseif ($firstLesson): ?>
                <a class="btn btn-block<?php echo ($courseTotalSteps > 0 && $courseDoneSteps === $courseTotalSteps) ? ' btn-secondary' : ''; ?>" href="sfb_lesson.php?lesson=<?php echo (int)$firstLesson['id']; ?>"><?php
                  if ($courseTotalSteps > 0 && $courseDoneSteps === $courseTotalSteps) {
                    echo '✓ ' . htmlspecialchars(bakery_t('sfb.course_complete_title'), ENT_QUOTES, 'UTF-8');
                  } else {
                    bakery_te('sfb.learn_open_course');
                  }
                ?></a>
              <?php else: ?>
                <span class="muted btn btn-block btn-secondary disabled" role="presentation"><?php bakery_te('sfb.admin_no_lessons'); ?></span>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>
      <?php if (function_exists('bakery_current_user') && bakery_current_user() !== null): ?>
        <p class="muted" style="margin-top:-6px;"><a class="btn-link" href="sfb_admin_learn.php"><?php bakery_te('sfb.admin_learn_link'); ?></a></p>
      <?php endif; ?>
    <?php endif; ?>

    <section class="card sfb-resource-hero">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.resources_eyebrow'); ?></p>
        <h2><?php bakery_te('sfb.resources_title'); ?></h2>
        <p><?php bakery_te('sfb.resources_intro'); ?></p>
        <p class="muted"><?php bakery_te('sfb.resources_method'); ?></p>
        <div class="btn-row" style="margin-top:14px;">
          <a class="btn btn-block" href="https://bakery.sourflour.org/breadeducation/" target="_blank" rel="noopener noreferrer"><?php bakery_te('sfb.open_learning_zone'); ?></a>
          <a class="btn btn-block" href="https://bakery.sourflour.org/breadeducation/fresh-loaf.html" target="_blank" rel="noopener noreferrer"><?php bakery_te('sfb.open_fresh_loaf_path'); ?></a>
        </div>
      </div>
    </section>

    <h2 class="section-title"><?php bakery_te('sfb.library_canonical_title'); ?></h2>
    <section class="sfb-resource-grid" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.library_canonical_title'), ENT_QUOTES, 'UTF-8'); ?>">
      <?php foreach ($canonicalPieces as $piece) { bakery_sfb_resources_render_card($piece); } ?>
    </section>

    <h2 class="section-title"><?php bakery_te('sfb.library_trouble_title'); ?></h2>
    <p class="muted" style="margin:0 0 10px;"><?php bakery_te('sfb.resources_trouble_intro'); ?></p>
    <section class="sfb-resource-grid" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.library_trouble_title'), ENT_QUOTES, 'UTF-8'); ?>">
      <?php foreach ($troublePieces as $piece) { bakery_sfb_resources_render_card($piece); } ?>
    </section>

    <section class="card sfb-resource-sources">
      <div class="card-header"><h2><?php bakery_te('sfb.debrief_sources_title'); ?></h2></div>
      <div class="card-body">
        <p><?php bakery_te('sfb.debrief_sources_intro'); ?></p>
        <ul class="line-list">
          <li><a href="https://www.thefreshloaf.com/lessons" target="_blank" rel="noopener noreferrer">The Fresh Loaf Lessons</a></li>
          <li><a href="https://www.thefreshloaf.com/up/TheFreshLoafPocketBookofBreadBaking.20110609.pdf" target="_blank" rel="noopener noreferrer">The Fresh Loaf Pocket Book of Bread Baking</a></li>
          <li><a href="https://www.thefreshloaf.com/forum" target="_blank" rel="noopener noreferrer">The Fresh Loaf Forums</a></li>
          <li><a href="https://www.thefreshloaf.com/node/79935/50-whole-wheat-sourdough" target="_blank" rel="noopener noreferrer">50% Whole Wheat Sourdough - example bake log</a></li>
          <li><a href="https://www.thefreshloaf.com/node/62957/sourdough-hydration-calculator" target="_blank" rel="noopener noreferrer">Sourdough Hydration Calculator - discussion</a></li>
        </ul>
        <p class="muted"><?php bakery_te('sfb.debrief_sources_note'); ?></p>
      </div>
    </section>

    <section class="card sfb-resource-action">
      <div class="card-body">
        <h2><?php bakery_te('sfb.debrief_action_title'); ?></h2>
        <p><?php bakery_te('sfb.debrief_action_copy'); ?></p>
        <div class="btn-row">
          <a class="btn btn-block" href="sfb_community.php"><?php bakery_te('sfb.go_to_community'); ?></a>
        </div>
      </div>
    </section>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
