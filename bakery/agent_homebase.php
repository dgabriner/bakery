<?php
/**
 * Agent Learning Studio / Homebase — administrator view of living agent craft.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/agent_homebase.php';

bakery_require_role(['administrator']);

$admin = bakery_current_user();
$adminId = (int)($admin['id'] ?? 0);
$error = '';
$notice = '';
$loadError = '';
$panel = (string)($_GET['panel'] ?? 'home');
$allowedPanels = ['home', 'learn', 'bugs', 'board', 'log'];
if (!in_array($panel, $allowedPanels, true)) {
    $panel = 'home';
}
$track = (string)($_GET['track'] ?? '');
$lessonSlug = (string)($_GET['lesson'] ?? '');

try {
    bakery_agent_homebase_ensure($db);
} catch (Throwable $e) {
    error_log('agent_homebase.php ensure: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $loadError = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $loadError === '') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        $agentName = (string)($_POST['agent_name'] ?? 'admin');
        if ($action === 'start_session') {
            bakery_agent_homebase_start_session($db, $agentName, (string)($_POST['mission'] ?? ''), $adminId);
            $notice = bakery_t('homebase.notice_session');
        } elseif ($action === 'handoff') {
            bakery_agent_homebase_handoff($db, $agentName, (string)($_POST['handoff_md'] ?? ''), (string)($_POST['files_touched'] ?? ''));
            $notice = bakery_t('homebase.notice_handoff');
        } elseif ($action === 'complete_lesson') {
            bakery_agent_homebase_complete_lesson($db, $agentName, (string)($_POST['lesson_slug'] ?? ''), (string)($_POST['notes'] ?? ''));
            $notice = bakery_t('homebase.notice_lesson');
        } elseif ($action === 'pin') {
            bakery_agent_homebase_pin(
                $db,
                (string)($_POST['title'] ?? ''),
                (string)($_POST['body'] ?? ''),
                (string)($_POST['column_key'] ?? 'now'),
                $agentName
            );
            $notice = bakery_t('homebase.notice_pin');
            $panel = 'board';
        } elseif ($action === 'move_card') {
            bakery_agent_homebase_move_card($db, (int)($_POST['card_id'] ?? 0), (string)($_POST['column_key'] ?? ''));
            $panel = 'board';
        } elseif ($action === 'archive_card') {
            bakery_agent_homebase_archive_card($db, (int)($_POST['card_id'] ?? 0));
            $panel = 'board';
        } elseif ($action === 'log_bug') {
            bakery_agent_homebase_log_bug(
                $db,
                (string)($_POST['title'] ?? ''),
                (string)($_POST['detail'] ?? ''),
                (string)($_POST['severity'] ?? 'watch'),
                (string)($_POST['focus_area'] ?? 'ops'),
                $agentName
            );
            $notice = bakery_t('homebase.notice_bug');
            $panel = 'bugs';
        } elseif ($action === 'bug_status') {
            bakery_agent_homebase_set_bug_status($db, (int)($_POST['bug_id'] ?? 0), (string)($_POST['status'] ?? ''));
            $panel = 'bugs';
        } elseif ($action === 'add_note') {
            bakery_agent_homebase_add_note(
                $db,
                (string)($_POST['kind'] ?? 'coach'),
                (string)($_POST['body'] ?? ''),
                (string)($_POST['title'] ?? ''),
                $agentName
            );
            $notice = bakery_t('homebase.notice_note');
        } else {
            throw new InvalidArgumentException(bakery_t('homebase.error_action'));
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$columns = bakery_agent_homebase_whiteboard_columns();
$tracks = bakery_agent_homebase_tracks();
$stats = [
    'open_sessions' => 0,
    'handoffs' => 0,
    'open_bugs' => 0,
    'cards' => 0,
    'lessons' => 0,
    'questions' => 0,
];
$brief = [
    'product' => '',
    'unread_required_lessons' => [],
    'open_bugs' => [],
    'whiteboard_now' => [],
];
$lessons = [];
$openLesson = null;
$bugs = [];
$board = [];
$sessions = [];
$notes = [];
foreach (array_keys($columns) as $columnKey) {
    $board[$columnKey] = [];
}

if ($loadError === '') {
    try {
        $stats = bakery_agent_homebase_stats($db);
        $brief = bakery_agent_homebase_brief($db, 'admin');
        $lessons = bakery_agent_homebase_lessons($db, $track !== '' ? $track : null);
        $openLesson = $lessonSlug !== '' ? bakery_agent_homebase_lesson_by_slug($db, $lessonSlug) : null;
        $bugs = bakery_agent_homebase_bugs($db);
        $board = bakery_agent_homebase_board($db);
        $sessions = bakery_agent_homebase_sessions($db, 40);
        $notes = bakery_agent_homebase_notes($db, 30);
    } catch (Throwable $e) {
        error_log('agent_homebase.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $loadError = $e->getMessage();
    }
}

$page_title = bakery_t('homebase.title');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>css/agent_homebase.css">
<main class="ah">
  <header class="ah-hero">
    <div>
      <p class="ah-eyebrow"><?php bakery_te('homebase.eyebrow'); ?></p>
      <h1><?php bakery_te('homebase.title'); ?></h1>
      <p><?php bakery_te('homebase.lead'); ?></p>
      <p><code>php scripts/agent_homebase.php brief --agent=your-mission --json</code></p>
    </div>
    <div class="ah-hero-actions">
      <span class="ah-pill"><?php bakery_te('homebase.link_manual'); ?>: BAKERY_PRODUCT_CONTEXT.md</span>
    </div>
  </header>

  <?php if ($loadError !== ''): ?>
    <div class="ah-notice ah-notice--err" role="alert">
      <?php bakery_te('homebase.error_load'); ?>
      <?php if (defined('DEBUG_MODE') && DEBUG_MODE): ?>
        <pre class="ah-cli"><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></pre>
      <?php endif; ?>
    </div>
  <?php elseif ($error !== ''): ?>
    <div class="ah-notice ah-notice--err" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php elseif ($notice !== ''): ?>
    <div class="ah-notice ah-notice--ok" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <nav class="ah-tabs" aria-label="<?php bakery_te('homebase.nav'); ?>">
    <?php foreach (['home' => 'homebase.tab_home', 'learn' => 'homebase.tab_learn', 'bugs' => 'homebase.tab_bugs', 'board' => 'homebase.tab_board', 'log' => 'homebase.tab_log'] as $key => $labelKey): ?>
      <a class="<?php echo $panel === $key ? 'is-active' : ''; ?>" href="agent_homebase.php?panel=<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($labelKey); ?></a>
    <?php endforeach; ?>
  </nav>

  <section class="ah-stats" aria-label="<?php bakery_te('homebase.stats'); ?>">
    <div class="ah-stat"><strong><?php echo (int)$stats['open_sessions']; ?></strong><span><?php bakery_te('homebase.stat_sessions'); ?></span></div>
    <div class="ah-stat"><strong><?php echo (int)$stats['handoffs']; ?></strong><span><?php bakery_te('homebase.stat_handoffs'); ?></span></div>
    <div class="ah-stat"><strong><?php echo (int)$stats['open_bugs']; ?></strong><span><?php bakery_te('homebase.stat_bugs'); ?></span></div>
    <div class="ah-stat"><strong><?php echo (int)$stats['cards']; ?></strong><span><?php bakery_te('homebase.stat_cards'); ?></span></div>
    <div class="ah-stat"><strong><?php echo (int)$stats['lessons']; ?></strong><span><?php bakery_te('homebase.stat_lessons'); ?></span></div>
    <div class="ah-stat"><strong><?php echo (int)$stats['questions']; ?></strong><span><?php bakery_te('homebase.stat_questions'); ?></span></div>
  </section>

  <?php if ($panel === 'home'): ?>
    <div class="ah-grid">
      <section class="ah-panel">
        <h2><?php bakery_te('homebase.briefing'); ?></h2>
        <p><?php echo htmlspecialchars($brief['product'], ENT_QUOTES, 'UTF-8'); ?></p>
        <p class="ah-muted"><?php bakery_te('homebase.unread'); ?>: <?php echo count($brief['unread_required_lessons']); ?></p>
        <ul class="ah-list">
          <?php foreach (array_slice($brief['open_bugs'], 0, 5) as $bug): ?>
            <li class="ah-card ah-card--<?php echo $bug['severity'] === 'critical' ? 'critical' : ($bug['severity'] === 'broken-window' ? 'broken' : ''); ?>">
              <h3><?php echo htmlspecialchars($bug['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
              <p class="ah-meta"><?php echo htmlspecialchars($bug['severity'] . ' · ' . $bug['focus_area'], ENT_QUOTES, 'UTF-8'); ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
        <h2 style="margin-top:18px"><?php bakery_te('homebase.now'); ?></h2>
        <?php if (empty($brief['whiteboard_now'])): ?>
          <p class="ah-empty"><?php bakery_te('homebase.empty_now'); ?></p>
        <?php else: ?>
          <ul class="ah-list">
            <?php foreach ($brief['whiteboard_now'] as $title): ?>
              <li class="ah-card"><h3><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h3></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <section class="ah-panel">
        <h2><?php bakery_te('homebase.coach'); ?></h2>
        <p><?php bakery_te('homebase.coach_help'); ?></p>
        <form class="ah-form" method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="add_note">
          <input type="hidden" name="kind" value="coach">
          <label><?php bakery_te('homebase.agent_name'); ?><input name="agent_name" value="admin"></label>
          <label><?php bakery_te('homebase.title_field'); ?><input name="title" required></label>
          <label><?php bakery_te('homebase.body'); ?><textarea name="body" required></textarea></label>
          <button type="submit"><?php bakery_te('homebase.post_coach'); ?></button>
        </form>
        <h2 style="margin-top:18px"><?php bakery_te('homebase.questions'); ?></h2>
        <?php $qcount = 0; foreach ($notes as $note): if ($note['kind'] !== 'question') { continue; } $qcount++; ?>
          <article class="ah-card">
            <h3><?php echo htmlspecialchars($note['title'] !== '' ? $note['title'] : $note['agent_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="ah-body"><?php echo bakery_agent_homebase_format_body($note['body']); ?></p>
            <p class="ah-meta"><?php echo htmlspecialchars($note['agent_name'] . ' · ' . $note['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
          </article>
        <?php endforeach; if ($qcount === 0): ?>
          <p class="ah-empty"><?php bakery_te('homebase.empty_questions'); ?></p>
        <?php endif; ?>
        <pre class="ah-cli">php scripts/agent_homebase.php brief --agent=NAME --json
php scripts/agent_homebase.php start --agent=NAME --mission="..."
php scripts/agent_homebase.php handoff --agent=NAME --summary="..." --files="a.php"</pre>
      </section>
    </div>
  <?php elseif ($panel === 'learn'): ?>
    <section class="ah-panel">
      <h2><?php bakery_te('homebase.curriculum'); ?></h2>
      <p><?php bakery_te('homebase.curriculum_help'); ?></p>
      <div class="ah-pills" style="margin:10px 0 14px">
        <a class="ah-btn ah-btn-quiet" href="agent_homebase.php?panel=learn"><?php bakery_te('homebase.all_tracks'); ?></a>
        <?php foreach ($tracks as $key => $label): ?>
          <a class="ah-btn ah-btn-quiet" href="agent_homebase.php?panel=learn&amp;track=<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($openLesson): ?>
        <article class="ah-card ah-lesson">
          <p class="ah-pill"><?php echo htmlspecialchars($openLesson['track'], ENT_QUOTES, 'UTF-8'); ?></p>
          <h3><?php echo htmlspecialchars($openLesson['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
          <div class="ah-body"><?php echo bakery_agent_homebase_format_body($openLesson['body_md']); ?></div>
          <form class="ah-form" method="post">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="complete_lesson">
            <input type="hidden" name="lesson_slug" value="<?php echo htmlspecialchars($openLesson['slug'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="ah-form-row">
              <label><?php bakery_te('homebase.agent_name'); ?><input name="agent_name" required placeholder="cursor-agent"></label>
              <label><?php bakery_te('homebase.notes'); ?><input name="notes"></label>
            </div>
            <button type="submit"><?php bakery_te('homebase.mark_learned'); ?></button>
          </form>
        </article>
      <?php endif; ?>
      <ul class="ah-list">
        <?php foreach ($lessons as $lesson): ?>
          <li class="ah-card ah-lesson">
            <h3><a href="agent_homebase.php?panel=learn&amp;lesson=<?php echo htmlspecialchars($lesson['slug'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($lesson['title'], ENT_QUOTES, 'UTF-8'); ?></a></h3>
            <p class="ah-muted"><?php echo htmlspecialchars($lesson['summary'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="ah-meta"><?php echo htmlspecialchars($lesson['track'] . ' · ' . $lesson['slug'], ENT_QUOTES, 'UTF-8'); ?><?php echo (int)$lesson['is_required'] ? ' · ' . bakery_t('homebase.required') : ''; ?></p>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php elseif ($panel === 'bugs'): ?>
    <div class="ah-grid">
      <section class="ah-panel">
        <h2><?php bakery_te('homebase.watchlist'); ?></h2>
        <ul class="ah-list">
          <?php foreach ($bugs as $bug): ?>
            <li class="ah-card ah-card--<?php echo $bug['severity'] === 'critical' ? 'critical' : ($bug['severity'] === 'broken-window' ? 'broken' : ''); ?>">
              <h3><?php echo htmlspecialchars($bug['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
              <div class="ah-body"><?php echo bakery_agent_homebase_format_body($bug['detail']); ?></div>
              <p class="ah-meta"><?php echo htmlspecialchars($bug['status'] . ' · ' . $bug['severity'] . ' · ' . $bug['focus_area'], ENT_QUOTES, 'UTF-8'); ?></p>
              <form method="post" class="ah-actions">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="bug_status">
                <input type="hidden" name="bug_id" value="<?php echo (int)$bug['id']; ?>">
                <?php foreach (['open','watching','fixed','wont-fix'] as $st): ?>
                  <button class="ah-btn-quiet" name="status" value="<?php echo $st; ?>" type="submit"><?php echo htmlspecialchars($st, ENT_QUOTES, 'UTF-8'); ?></button>
                <?php endforeach; ?>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <section class="ah-panel">
        <h2><?php bakery_te('homebase.log_bug'); ?></h2>
        <form class="ah-form" method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="log_bug">
          <label><?php bakery_te('homebase.agent_name'); ?><input name="agent_name" value="admin"></label>
          <label><?php bakery_te('homebase.title_field'); ?><input name="title" required></label>
          <label><?php bakery_te('homebase.detail'); ?><textarea name="detail" required></textarea></label>
          <div class="ah-form-row">
            <label><?php bakery_te('homebase.severity'); ?>
              <select name="severity">
                <option value="critical">critical</option>
                <option value="watch" selected>watch</option>
                <option value="broken-window">broken-window</option>
              </select>
            </label>
            <label><?php bakery_te('homebase.focus'); ?><input name="focus_area" value="ops"></label>
          </div>
          <button type="submit"><?php bakery_te('homebase.log_bug'); ?></button>
        </form>
      </section>
    </div>
  <?php elseif ($panel === 'board'): ?>
    <section class="ah-panel">
      <h2><?php bakery_te('homebase.whiteboard'); ?></h2>
      <p><?php bakery_te('homebase.whiteboard_help'); ?></p>
      <form class="ah-form" method="post">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="pin">
        <div class="ah-form-row">
          <label><?php bakery_te('homebase.agent_name'); ?><input name="agent_name" value="admin"></label>
          <label><?php bakery_te('homebase.column'); ?>
            <select name="column_key">
              <?php foreach ($columns as $key => $label): ?>
                <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label><?php bakery_te('homebase.title_field'); ?><input name="title" required></label>
        <label><?php bakery_te('homebase.body'); ?><textarea name="body" required></textarea></label>
        <button type="submit"><?php bakery_te('homebase.pin'); ?></button>
      </form>
      <div class="ah-board" style="margin-top:16px">
        <?php foreach ($columns as $key => $label): ?>
          <div class="ah-col">
            <h3><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></h3>
            <?php foreach ($board[$key] ?? [] as $card): ?>
              <article class="ah-card">
                <h3><?php echo htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                <div class="ah-body"><?php echo bakery_agent_homebase_format_body($card['body']); ?></div>
                <p class="ah-meta"><?php echo htmlspecialchars($card['agent_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                <form method="post" class="ah-actions">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="move_card">
                  <input type="hidden" name="card_id" value="<?php echo (int)$card['id']; ?>">
                  <select name="column_key" onchange="this.form.submit()">
                    <?php foreach ($columns as $colKey => $colLabel): ?>
                      <option value="<?php echo htmlspecialchars($colKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $colKey === $key ? ' selected' : ''; ?>><?php echo htmlspecialchars($colLabel, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <form method="post" class="ah-actions">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="archive_card">
                  <input type="hidden" name="card_id" value="<?php echo (int)$card['id']; ?>">
                  <button class="ah-btn-quiet" type="submit"><?php bakery_te('homebase.archive'); ?></button>
                </form>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php else: ?>
    <div class="ah-grid">
      <section class="ah-panel">
        <h2><?php bakery_te('homebase.sessions'); ?></h2>
        <form class="ah-form" method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="start_session">
          <div class="ah-form-row">
            <label><?php bakery_te('homebase.agent_name'); ?><input name="agent_name" required></label>
            <label><?php bakery_te('homebase.mission'); ?><input name="mission" required></label>
          </div>
          <button type="submit"><?php bakery_te('homebase.start'); ?></button>
        </form>
        <ul class="ah-list">
          <?php foreach ($sessions as $session): ?>
            <li class="ah-card">
              <h3><?php echo htmlspecialchars($session['agent_name'] . ' — ' . ($session['mission'] ?: bakery_t('homebase.untitled')), ENT_QUOTES, 'UTF-8'); ?></h3>
              <p class="ah-meta"><?php echo htmlspecialchars($session['status'] . ' · ' . $session['started_at'], ENT_QUOTES, 'UTF-8'); ?></p>
              <?php if (!empty($session['handoff_md'])): ?>
                <div class="ah-body"><?php echo bakery_agent_homebase_format_body($session['handoff_md']); ?></div>
              <?php endif; ?>
              <?php if (!empty($session['files_touched'])): ?>
                <p class="ah-meta"><?php echo htmlspecialchars($session['files_touched'], ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>
      <section class="ah-panel">
        <h2><?php bakery_te('homebase.post_handoff'); ?></h2>
        <form class="ah-form" method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="handoff">
          <label><?php bakery_te('homebase.agent_name'); ?><input name="agent_name" required></label>
          <label><?php bakery_te('homebase.files'); ?><input name="files_touched" placeholder="includes/foo.php, bar.php"></label>
          <label><?php bakery_te('homebase.handoff'); ?><textarea name="handoff_md" required placeholder="<?php bakery_te('homebase.handoff_ph'); ?>"></textarea></label>
          <button type="submit"><?php bakery_te('homebase.post_handoff'); ?></button>
        </form>
        <h2 style="margin-top:18px"><?php bakery_te('homebase.notes_feed'); ?></h2>
        <?php foreach ($notes as $note): ?>
          <article class="ah-card">
            <p class="ah-pill"><?php echo htmlspecialchars($note['kind'], ENT_QUOTES, 'UTF-8'); ?></p>
            <h3><?php echo htmlspecialchars($note['title'] !== '' ? $note['title'] : $note['kind'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <div class="ah-body"><?php echo bakery_agent_homebase_format_body($note['body']); ?></div>
            <p class="ah-meta"><?php echo htmlspecialchars($note['agent_name'] . ' · ' . $note['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
          </article>
        <?php endforeach; ?>
      </section>
    </div>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
