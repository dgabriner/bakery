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
$canCoach = !empty($access['can_reply_as_coach']);
$coachUserId = (int)$access['coach_user_id'];
$staffOnly = !$customer && !empty($access['staff']);

$topicId = (int)($_REQUEST['topic'] ?? 0);
$topic = $topicId > 0 ? bakery_sfb_community_topic($db, $topicId) : null;
if (!$topic) {
    header('Location: sfb_community.php');
    exit;
}

$notice = '';
$noticeKind = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_reply') {
            if (!$canPost) {
                throw new RuntimeException(bakery_t('sfb.community_impersonation_no_post'));
            }
            bakery_sfb_add_community_reply($db, $topicId, $customerId, $_POST['body'] ?? '');
            header('Location: ' . bakery_sfb_community_topic_url($topicId) . '&saved=reply#community-replies');
            exit;
        }
        if ($action === 'add_coach_reply') {
            if (!$canCoach) {
                throw new RuntimeException(bakery_t('sfb.community_coach_reply_hint'));
            }
            bakery_sfb_add_community_reply($db, $topicId, 0, $_POST['body'] ?? '', 'coach', $coachUserId);
            header('Location: ' . bakery_sfb_community_topic_url($topicId) . '&saved=coach#community-replies');
            exit;
        }
        throw new InvalidArgumentException('That community action is not available.');
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$replies = bakery_sfb_community_replies($db, $topicId);
$copy = bakery_sfb_community_topic_copy($topic);
$authorName = trim((string)($topic['author_name'] ?? ''));
if ($authorName === '') {
    $authorName = bakery_t('sfb.origin_coach');
}
$saved = (string)($_GET['saved'] ?? '');
$page_title = $copy['title'] . ' - ' . bakery_t('sfb.community_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'] ?? bakery_t('sfb.origin_coach');
$locked = (int)$topic['is_locked'] === 1;
$bakeSummary = ((int)($topic['shared_batch_id'] ?? 0) > 0)
    ? bakery_sfb_community_bake_summary($db, (int)$topic['shared_batch_id'])
    : null;
$topicUrl = bakery_sfb_community_topic_url($topicId);
$feedUrl = bakery_sfb_community_feed_url();
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

    <p class="sfb-back-link"><a href="<?php echo htmlspecialchars($feedUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.community_back'); ?></a></p>
    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif ($saved === 'reply'): ?>
      <div class="notice notice--info"><?php bakery_te('sfb.community_reply_saved'); ?></div>
    <?php elseif ($saved === 'coach'): ?>
      <div class="notice notice--info"><?php bakery_te('sfb.community_coach_reply_saved'); ?></div>
    <?php elseif ($saved === 'created'): ?>
      <div class="notice notice--info"><?php bakery_te('sfb.community_reply_saved'); ?></div>
    <?php endif; ?>

    <article class="card sfb-topic-detail">
      <div class="card-body">
        <div class="sfb-topic-card__meta">
          <?php if (bakery_sfb_community_pinned_ready($db) && (int)($topic['is_pinned'] ?? 0) === 1): ?>
            <span class="sfb-topic-card__pinned"><?php bakery_te('sfb.community_pinned'); ?></span>
          <?php endif; ?>
          <span class="sfb-topic-card__category"><?php bakery_te(bakery_sfb_community_category_key($topic['category'])); ?></span>
          <span><?php echo htmlspecialchars($authorName, ENT_QUOTES, 'UTF-8'); ?></span>
          <?php echo bakery_sfb_render_origin_badge($topic, (string)($topic['author_kind'] ?? 'baker')); ?>
          <time datetime="<?php echo htmlspecialchars(date('c', strtotime($topic['created_at'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bakery_sfb_community_relative_time($topic['created_at']), ENT_QUOTES, 'UTF-8'); ?></time>
        </div>
        <h2><?php echo htmlspecialchars($copy['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="sfb-topic-detail__body"><?php echo nl2br(htmlspecialchars($copy['body'], ENT_QUOTES, 'UTF-8')); ?></div>
        <?php
        if ($bakeSummary) {
            $sfbBakeSummary = $bakeSummary;
            $sfbBakeViewerCustomerId = $customerId;
            $sfbBakeViewerIsStaff = $canCoach;
            require __DIR__ . '/includes/sfb_community_bake_card.php';
        }
        ?>
      </div>
    </article>

    <section id="community-replies" aria-labelledby="communityRepliesHeading">
      <h2 class="section-title" id="communityRepliesHeading"><?php echo htmlspecialchars(bakery_t('sfb.community_replies_count', ['count' => count($replies)]), ENT_QUOTES, 'UTF-8'); ?></h2>
      <?php if (!$replies): ?>
        <div class="card"><div class="card-body"><p class="empty-state"><?php bakery_te('sfb.community_no_replies'); ?></p></div></div>
      <?php else: ?>
        <div class="sfb-community-replies">
          <?php foreach ($replies as $reply): ?>
            <article class="card sfb-community-reply<?php echo ((string)($reply['author_kind'] ?? '') === 'coach') ? ' sfb-community-reply--coach' : ''; ?>">
              <div class="card-body">
                <div class="sfb-topic-card__meta">
                  <strong><?php echo htmlspecialchars((string)($reply['author_name'] ?? bakery_t('sfb.origin_coach')), ENT_QUOTES, 'UTF-8'); ?></strong>
                  <?php echo bakery_sfb_render_origin_badge($reply, (string)($reply['author_kind'] ?? 'baker')); ?>
                  <time datetime="<?php echo htmlspecialchars(date('c', strtotime($reply['created_at'])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bakery_sfb_community_relative_time($reply['created_at']), ENT_QUOTES, 'UTF-8'); ?></time>
                </div>
                <p><?php echo nl2br(htmlspecialchars($reply['body'], ENT_QUOTES, 'UTF-8')); ?></p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($locked): ?>
      <div class="notice notice--info"><?php bakery_te('sfb.community_closed'); ?></div>
    <?php else: ?>
      <?php if ($canCoach): ?>
        <section class="card sfb-community-compose sfb-community-compose--coach">
          <div class="card-header"><h2><?php bakery_te('sfb.community_coach_reply'); ?></h2></div>
          <div class="card-body">
            <p class="muted"><?php bakery_te('sfb.community_coach_reply_hint'); ?></p>
            <form method="post" action="<?php echo htmlspecialchars($topicUrl, ENT_QUOTES, 'UTF-8'); ?>" class="inline-form" style="grid-template-columns:1fr;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="add_coach_reply">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.community_reply'); ?></span>
                  <textarea name="body" rows="4" maxlength="4000" required placeholder="<?php bakery_te('sfb.community_reply_placeholder'); ?>"><?php echo htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_coach_reply' ? ($_POST['body'] ?? '') : ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>
              </div>
              <button type="submit" class="btn btn-block"><?php bakery_te('sfb.community_publish_reply'); ?></button>
            </form>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($canPost): ?>
        <section class="card sfb-community-compose">
          <div class="card-header"><h2><?php bakery_te('sfb.community_add_reply'); ?></h2></div>
          <div class="card-body">
            <form method="post" action="<?php echo htmlspecialchars($topicUrl, ENT_QUOTES, 'UTF-8'); ?>" class="inline-form" style="grid-template-columns:1fr;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="add_reply">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.community_reply'); ?></span>
                  <textarea name="body" rows="4" maxlength="4000" required placeholder="<?php bakery_te('sfb.community_reply_placeholder'); ?>"><?php echo htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_reply' ? ($_POST['body'] ?? '') : ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
                </label>
              </div>
              <button type="submit" class="btn btn-block"><?php bakery_te('sfb.community_publish_reply'); ?></button>
            </form>
          </div>
        </section>
      <?php elseif ($customer && !$canCoach): ?>
        <div class="notice notice--info"><?php bakery_te('sfb.community_impersonation_no_post'); ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
  <?php if (!$staffOnly) { require __DIR__ . '/includes/portal_nav.php'; } ?>
</body>
</html>
