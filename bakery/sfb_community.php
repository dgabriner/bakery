<?php
declare(strict_types=1);

define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$access = bakery_sfb_require_community_access($db);
$customer = $access['customer'];
$customerId = (int)($customer['id'] ?? 0);
$canPost = !empty($access['can_post_as_baker']);
$staffOnly = !$customer && !empty($access['staff']);

$notice = '';
$noticeKind = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_topic') {
    try {
        bakery_require_csrf();
        if (!$canPost) {
            throw new RuntimeException(bakery_t('sfb.community_impersonation_no_post'));
        }
        $topicId = bakery_sfb_create_community_topic(
            $db,
            $customerId,
            $_POST['title'] ?? '',
            $_POST['body'] ?? '',
            $_POST['category'] ?? 'general',
            (int)($_POST['batch_id'] ?? 0)
        );
        header('Location: ' . bakery_sfb_community_topic_url($topicId) . '&saved=created');
        exit;
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$circles = bakery_sfb_community_categories($db);
$category = (string)($_GET['category'] ?? 'all');
if (!in_array($category, array_merge(['all'], $circles), true)) {
    $category = 'all';
}
$originFilter = bakery_sfb_community_origin_filter();
$search = trim((string)($_GET['q'] ?? ''));
if (function_exists('bakery_sfb_ensure_library_pins')) {
    bakery_sfb_ensure_library_pins($db, (int)($access['coach_user_id'] ?? 0));
}
$topics = bakery_sfb_community_topics($db, $category, 50, $originFilter, $search);
$activity = bakery_sfb_community_activity($db, $originFilter, 8);
$myBatches = $canPost ? bakery_sfb_batches($db, $customerId, 100) : [];
$requestedBatchId = (int)($_GET['batch'] ?? 0);
$selectedBatchId = 0;
foreach ($myBatches as $batch) {
    if ((int)$batch['id'] === $requestedBatchId) {
        $selectedBatchId = $requestedBatchId;
        break;
    }
}

$humanLoaves = bakery_sfb_human_loaf_total($db);
$librarySlug = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_GET['library'] ?? '')));
$libraryPrefill = $librarySlug !== '' ? bakery_sfb_library_compose_prefill($librarySlug, $selectedBatchId) : null;
$composeTitle = (string)($_POST['title'] ?? ($libraryPrefill['title'] ?? ''));
$composeBody = (string)($_POST['body'] ?? ($libraryPrefill['body'] ?? ''));
$composeCategory = (string)($_POST['category'] ?? ($libraryPrefill['category'] ?? ''));
if ($composeCategory === '' && $category !== 'all') {
    $composeCategory = $category;
}
if ($composeCategory === 'failures' && $selectedBatchId <= 0) {
    $composeCategory = 'general';
}

$composeOpen = $notice !== ''
    || $selectedBatchId > 0
    || $libraryPrefill
    || (string)($_GET['compose'] ?? '') === '1';
$feedUrl = bakery_sfb_community_feed_url(['category' => $category, 'origin' => $originFilter, 'q' => $search]);

$page_title = bakery_t('sfb.community_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'] ?? bakery_t('sfb.origin_coach');
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
    <?php if ($staffOnly): ?>
      <p class="sfb-back-link"><a href="sfb_admin_overview.php"><?php bakery_te('sfb.community_back_to_admin'); ?></a></p>
    <?php endif; ?>
    <?php $sfbActiveTab = 'community'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!$canPost && $customer): ?>
      <div class="notice notice--info"><?php bakery_te('sfb.community_impersonation_no_post'); ?></div>
    <?php endif; ?>

    <section class="card sfb-community-hero">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.community_eyebrow'); ?></p>
        <h2><?php bakery_te('sfb.community_title'); ?></h2>
        <p><?php bakery_te('sfb.community_intro'); ?></p>
        <p class="sfb-disclosure sfb-community-disclosure"><?php bakery_te('sfb.community_disclosure'); ?></p>
        <p class="sfb-human-loaves"><?php echo htmlspecialchars(bakery_t('sfb.community_human_loaves', [
            'count' => number_format($humanLoaves),
            'goal' => number_format(bakery_sfb_loaf_goal()),
        ]), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($canPost): ?>
          <p class="sfb-hero-actions"><a class="btn" href="#start-discussion"><?php bakery_te('sfb.community_start'); ?></a></p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($activity): ?>
      <section class="card sfb-activity" aria-labelledby="communityActivityHeading">
        <div class="card-header"><h2 id="communityActivityHeading"><?php bakery_te('sfb.community_activity'); ?></h2></div>
        <div class="card-body">
          <ol class="sfb-activity-list">
            <?php foreach ($activity as $item):
              $activityKey = 'sfb.community_activity_' . (string)$item['activity_type'];
              $href = ((int)($item['topic_id'] ?? 0) > 0)
                  ? bakery_sfb_community_topic_url((int)$item['topic_id'])
                  : (((int)($item['batch_id'] ?? 0) > 0) ? bakery_sfb_community_shared_batch_url((int)$item['batch_id']) : $feedUrl);
            ?>
              <li>
                <a href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>">
                  <span class="sfb-activity-list__kind"><?php bakery_te($activityKey); ?></span>
                  <strong><?php echo htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                  <span class="sfb-activity-list__meta">
                    <?php echo htmlspecialchars((string)$item['actor_name'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php echo bakery_sfb_render_origin_badge($item, (string)($item['author_kind'] ?? 'baker')); ?>
                    <time datetime="<?php echo htmlspecialchars(date('c', strtotime((string)$item['occurred_at'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bakery_sfb_community_relative_time($item['occurred_at']), ENT_QUOTES, 'UTF-8'); ?></time>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ol>
        </div>
      </section>
    <?php endif; ?>

    <form method="get" class="sfb-community-search" role="search">
      <?php if ($category !== 'all'): ?><input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <?php if ($originFilter !== 'both'): ?><input type="hidden" name="origin" value="<?php echo htmlspecialchars($originFilter, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
      <label><span class="visually-hidden"><?php bakery_te('sfb.community_search'); ?></span>
        <input type="search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" maxlength="80" placeholder="<?php bakery_te('sfb.community_search_placeholder'); ?>">
      </label>
      <button type="submit" class="btn btn-secondary"><?php bakery_te('sfb.community_search_submit'); ?></button>
    </form>

    <nav class="sfb-community-filters" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.community_filter_origin'), ENT_QUOTES, 'UTF-8'); ?>">
      <?php foreach (['both' => 'sfb.community_origin_both', 'human' => 'sfb.community_origin_human', 'synthetic' => 'sfb.community_origin_synthetic'] as $originValue => $originKey): ?>
        <a href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['origin' => $originValue, 'category' => $category, 'q' => $search]), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $originFilter === $originValue ? ' class="active"' : ''; ?>><?php bakery_te($originKey); ?></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($originFilter === 'human'): ?>
      <p class="sfb-filter-note"><?php bakery_te('sfb.community_synthetics_hidden'); ?>
        <a href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['origin' => 'both', 'category' => $category, 'q' => $search]), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_show_synthetic'); ?></a>
      </p>
    <?php endif; ?>

    <nav class="sfb-community-filters" aria-label="<?php echo htmlspecialchars(bakery_t('sfb.community_category'), ENT_QUOTES, 'UTF-8'); ?>">
      <a href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['category' => 'all', 'origin' => $originFilter, 'q' => $search]), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $category === 'all' ? ' class="active"' : ''; ?>><?php bakery_te('sfb.community_all'); ?></a>
      <?php foreach ($circles as $categoryOption): ?>
        <a href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['category' => $categoryOption, 'origin' => $originFilter, 'q' => $search]), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $category === $categoryOption ? ' class="active"' : ''; ?>><?php bakery_te(bakery_sfb_community_category_key($categoryOption)); ?></a>
      <?php endforeach; ?>
    </nav>

    <?php
    $libraryStrip = bakery_sfb_library_for_category($category);
    if ($libraryStrip):
    ?>
    <section class="card sfb-library-strip" aria-labelledby="libraryStripHeading">
      <div class="card-body">
        <p class="hero-label" id="libraryStripHeading"><?php bakery_te('sfb.library_pinned_eyebrow'); ?></p>
        <ul class="sfb-library-strip__list">
          <?php foreach ($libraryStrip as $piece): ?>
            <li>
              <a href="sfb_resources.php#library-<?php echo htmlspecialchars($piece['slug'], ENT_QUOTES, 'UTF-8'); ?>">
                <strong><?php bakery_te($piece['title_key']); ?></strong>
                <span><?php bakery_te('sfb.library_next_label'); ?>: <?php bakery_te($piece['action_key']); ?></span>
              </a>
              <?php if ($canPost): ?>
                <a class="sfb-library-strip__ask" href="<?php echo htmlspecialchars(bakery_sfb_library_ask_url($piece['slug'], $selectedBatchId), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.library_ask'); ?></a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
    <?php endif; ?>

    <section aria-labelledby="communityFeedHeading">
      <h2 class="section-title" id="communityFeedHeading"><?php bakery_te('sfb.community_recent'); ?></h2>
      <?php if (!$topics): ?>
        <div class="card"><div class="card-body">
          <?php if ($search !== ''): ?>
            <p class="empty-state"><?php bakery_te('sfb.community_empty_search'); ?></p>
            <p><a class="btn btn-secondary" href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['category' => $category, 'origin' => $originFilter, 'q' => '']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_clear_search'); ?></a></p>
          <?php elseif ($originFilter === 'human'): ?>
            <p class="empty-state"><?php bakery_te('sfb.community_empty_human'); ?></p>
            <p><a class="btn" href="<?php echo htmlspecialchars(bakery_sfb_community_feed_url(['origin' => 'both', 'category' => $category]), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_show_synthetic'); ?></a></p>
          <?php else: ?>
            <p class="empty-state"><?php bakery_te('sfb.community_empty'); ?></p>
            <?php if ($canPost): ?>
              <p><a class="btn" href="#start-discussion"><?php bakery_te('sfb.community_start'); ?></a></p>
            <?php endif; ?>
          <?php endif; ?>
        </div></div>
      <?php else: ?>
        <div class="sfb-community-feed">
          <?php foreach ($topics as $topic):
            $copy = bakery_sfb_community_topic_copy($topic);
            $preview = bakery_sfb_community_preview($copy['body']);
            $pinned = bakery_sfb_community_pinned_ready($db) && (int)($topic['is_pinned'] ?? 0) === 1;
            $authorName = trim((string)($topic['author_name'] ?? ''));
            if ($authorName === '') {
                $authorName = bakery_t('sfb.origin_coach');
            }
          ?>
            <article class="card sfb-topic-card<?php echo $pinned ? ' sfb-topic-card--pinned' : ''; ?>">
              <div class="card-body">
                <div class="sfb-topic-card__meta">
                  <?php if ($pinned): ?><span class="sfb-topic-card__pinned"><?php bakery_te('sfb.community_pinned'); ?></span><?php endif; ?>
                  <span class="sfb-topic-card__category"><?php bakery_te(bakery_sfb_community_category_key($topic['category'])); ?></span>
                  <span><?php echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php echo bakery_sfb_render_origin_badge($topic, (string)($topic['author_kind'] ?? 'baker')); ?>
                  <time datetime="<?php echo htmlspecialchars(date('c', strtotime((string)($topic['last_reply_at'] ?: $topic['created_at']))), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bakery_sfb_community_relative_time($topic['last_reply_at'] ?: $topic['created_at']), ENT_QUOTES, 'UTF-8'); ?></time>
                </div>
                <h3><a href="<?php echo htmlspecialchars(bakery_sfb_community_topic_url((int)$topic['id']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8'); ?></a></h3>
                <p><?php echo nl2br(htmlspecialchars($preview, ENT_QUOTES, 'UTF-8')); ?></p>
                <?php if ((int)($topic['shared_batch_id'] ?? 0) > 0): ?>
                  <a class="sfb-topic-card__batch" href="<?php echo htmlspecialchars(bakery_sfb_community_shared_batch_url((int)$topic['shared_batch_id']), ENT_QUOTES, 'UTF-8'); ?>">
                    <span><?php bakery_te('sfb.community_shared_bake'); ?></span>
                    <strong><?php echo htmlspecialchars(($topic['batch_formula_name'] ?: $topic['batch_name']), ENT_QUOTES, 'UTF-8'); ?></strong>
                  </a>
                <?php endif; ?>
                <a class="btn-link" href="<?php echo htmlspecialchars(bakery_sfb_community_topic_url((int)$topic['id']), ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo htmlspecialchars(bakery_t('sfb.community_replies_count', ['count' => (int)$topic['reply_count']]), ENT_QUOTES, 'UTF-8'); ?>
                  <?php if ((int)$topic['reply_count'] > 0): ?>
                    · <?php echo htmlspecialchars(bakery_t('sfb.community_last_activity', ['when' => bakery_sfb_community_relative_time($topic['last_reply_at'] ?: $topic['created_at'])]), ENT_QUOTES, 'UTF-8'); ?>
                  <?php endif; ?>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($canPost): ?>
    <details class="card sfb-community-compose" id="start-discussion"<?php echo $composeOpen ? ' open' : ''; ?>>
      <summary class="card-header"><h2><?php bakery_te('sfb.community_start'); ?></h2></summary>
      <div class="card-body">
        <p class="muted"><?php bakery_te('sfb.community_privacy'); ?></p>
        <form method="post" action="<?php echo htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="create_topic">
          <div class="sfb-grid2">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.community_category'); ?></span>
                <select name="category" id="sfbCommunityCategory">
                  <?php foreach ($circles as $categoryOption): ?>
                    <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $composeCategory === $categoryOption ? ' selected' : ''; ?>><?php bakery_te(bakery_sfb_community_category_key($categoryOption)); ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <small class="muted"><?php bakery_te('sfb.community_failures_need_batch'); ?></small>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.community_link_batch'); ?></span>
                <select name="batch_id" id="sfbCommunityBatch">
                  <option value="0"><?php bakery_te('sfb.community_no_batch'); ?></option>
                  <?php foreach ($myBatches as $batch): ?>
                    <option value="<?php echo (int)$batch['id']; ?>"<?php echo (int)$batch['id'] === $selectedBatchId ? ' selected' : ''; ?>>
                      <?php echo htmlspecialchars($batch['name'] . ' - ' . ($batch['formula_name'] ?? bakery_t('sfb.tab_batches')), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <small class="muted"><?php bakery_te('sfb.community_link_batch_hint'); ?></small>
            </div>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.community_title_label'); ?></span>
              <input type="text" name="title" maxlength="160" required placeholder="<?php bakery_te('sfb.community_title_placeholder'); ?>" value="<?php echo htmlspecialchars($composeTitle, ENT_QUOTES, 'UTF-8'); ?>">
            </label>
          </div>
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.community_post'); ?></span>
              <textarea name="body" id="sfbCommunityBody" rows="5" maxlength="4000" required placeholder="<?php bakery_te('sfb.community_post_placeholder'); ?>"><?php echo htmlspecialchars($composeBody, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>
            <p class="sfb-process-hint" id="sfbProcessHint"><?php bakery_te('sfb.community_process_hint'); ?></p>
          </div>
          <button type="submit" class="btn btn-block"><?php bakery_te('sfb.community_publish'); ?></button>
        </form>
      </div>
    </details>
    <?php endif; ?>
  </main>
  <?php if (!$staffOnly) { require __DIR__ . '/includes/portal_nav.php'; } ?>
  <?php if ($canPost): ?>
  <script>
    (function () {
      var cat = document.getElementById('sfbCommunityCategory');
      var batch = document.getElementById('sfbCommunityBatch');
      if (!cat || !batch) return;
      function sync() {
        var none = batch.querySelector('option[value="0"]');
        if (cat.value === 'failures') {
          batch.required = true;
          if (none) none.disabled = true;
          if (batch.value === '0') {
            var first = batch.querySelector('option:not([value="0"])');
            if (first) batch.value = first.value;
          }
        } else {
          batch.required = false;
          if (none) none.disabled = false;
        }
      }
      cat.addEventListener('change', sync);
      sync();
      if (window.location.hash === '#start-discussion') {
        var compose = document.getElementById('start-discussion');
        if (compose && 'open' in compose) compose.open = true;
      }
      document.querySelectorAll('a[href="#start-discussion"]').forEach(function (link) {
        link.addEventListener('click', function () {
          var compose = document.getElementById('start-discussion');
          if (compose && 'open' in compose) compose.open = true;
        });
      });
      var body = document.getElementById('sfbCommunityBody');
      var hint = document.getElementById('sfbProcessHint');
      if (body && hint) {
        function hasFact(text) {
          return /\d\s*°?\s*[FfCc]\b/.test(text)
            || /%/.test(text)
            || /\d+\s*(min|mins|minute|minutes|h|hr|hrs|hour|hours|hora|horas|minuto|minutos)\b/i.test(text)
            || /\b(flour|harina|rye|centeno|wheat|trigo|levain|starter|masa madre)\b/i.test(text);
        }
        function paint() {
          hint.classList.toggle('is-warn', !hasFact(body.value || ''));
        }
        body.addEventListener('input', paint);
        paint();
      }
    })();
  </script>
  <?php endif; ?>
</body>
</html>
