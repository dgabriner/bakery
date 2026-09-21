<?php
/**
 * Personal time clock. Cashiers land here after login.
 * Managers and administrators also see who is in and today's punches.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/time_clock.php';

bakery_require_role(['cashier', 'manager', 'administrator']);

$user = bakery_current_user();
$userId = (int)($user['id'] ?? 0);
$role = (string)($user['role_slug'] ?? '');
$canSeeBoard = in_array($role, ['administrator', 'manager'], true);
$ready = bakery_time_clock_ready($db);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    bakery_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $notice = 'save_failed';
    if (!$ready) {
        $notice = 'not_ready';
    } elseif ($action === 'clock_in') {
        $result = bakery_time_clock_in($db, $userId);
        $notice = !empty($result['ok']) ? 'in' : (string)($result['error'] ?? 'save_failed');
    } elseif ($action === 'clock_out') {
        $result = bakery_time_clock_out($db, $userId);
        $notice = !empty($result['ok']) ? 'out' : (string)($result['error'] ?? 'save_failed');
    }
    header('Location: ' . BASE_URL . 'time_clock.php?notice=' . rawurlencode($notice));
    exit;
}

$notice = (string)($_GET['notice'] ?? '');
$noticeKeys = [
    'already_in' => 'time_clock.notice_already_in',
    'not_in' => 'time_clock.notice_not_in',
    'not_ready' => 'time_clock.not_ready',
    'save_failed' => 'time_clock.save_failed',
];
$noticeKey = $noticeKeys[$notice] ?? '';

$open = $ready ? bakery_time_clock_open_punch($db, $userId) : null;
$clockedIn = is_array($open);
$whoIsIn = $canSeeBoard && $ready ? bakery_time_clock_who_is_in($db) : [];
$todayPunches = $canSeeBoard && $ready ? bakery_time_clock_punches_for_day($db, date('Y-m-d')) : [];

$page_title = bakery_t('page.time_clock');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/time_clock.css'); ?>">

<main class="time-clock">
  <header class="time-clock__hero">
    <p class="time-clock__eyebrow"><?php echo $h(bakery_t('time_clock.eyebrow')); ?></p>
    <h1><?php echo $h(bakery_t('time_clock.title')); ?></h1>
    <p class="time-clock__lead"><?php echo $h((string)($user['display_name'] ?? '')); ?></p>
  </header>

  <?php if ($noticeKey !== ''): ?>
    <p class="time-clock__notice" role="status"><?php echo $h(bakery_t($noticeKey)); ?></p>
  <?php endif; ?>

  <?php if (!$ready): ?>
    <p class="time-clock__notice"><?php echo $h(bakery_t('time_clock.not_ready')); ?></p>
  <?php else: ?>
    <section class="time-clock__card" aria-live="polite">
      <?php if ($clockedIn): ?>
        <p class="time-clock__status time-clock__status--in"><?php echo $h(bakery_t('time_clock.status_in', ['time' => bakery_time_clock_format_time((string)$open['clock_in_at'])])); ?></p>
      <?php else: ?>
        <p class="time-clock__status"><?php echo $h(bakery_t('time_clock.status_out')); ?></p>
      <?php endif; ?>
      <form method="post" action="<?php echo $h(BASE_URL . 'time_clock.php'); ?>">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="<?php echo $clockedIn ? 'clock_out' : 'clock_in'; ?>">
        <button class="time-clock__punch<?php echo $clockedIn ? ' time-clock__punch--out' : ''; ?>" type="submit">
          <?php echo $h(bakery_t($clockedIn ? 'time_clock.clock_out' : 'time_clock.clock_in')); ?>
        </button>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($canSeeBoard): ?>
    <section class="time-clock__board" id="time-clock-board">
      <h2><?php echo $h(bakery_t('time_clock.who_in')); ?></h2>
      <?php if (!$whoIsIn): ?>
        <p class="time-clock__empty"><?php echo $h(bakery_t('time_clock.nobody_in')); ?></p>
      <?php else: ?>
        <ul class="time-clock__people">
          <?php foreach ($whoIsIn as $person): ?>
            <li>
              <strong><?php echo $h((string)$person['display_name']); ?></strong>
              <span><?php echo $h(bakery_t('time_clock.since', ['time' => bakery_time_clock_format_time((string)$person['clock_in_at'])])); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <h2><?php echo $h(bakery_t('time_clock.today')); ?></h2>
      <?php if (!$todayPunches): ?>
        <p class="time-clock__empty"><?php echo $h(bakery_t('time_clock.no_punches')); ?></p>
      <?php else: ?>
        <table class="time-clock__table">
          <thead>
            <tr>
              <th><?php echo $h(bakery_t('time_clock.col_name')); ?></th>
              <th><?php echo $h(bakery_t('time_clock.col_in')); ?></th>
              <th><?php echo $h(bakery_t('time_clock.col_out')); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($todayPunches as $punch): ?>
              <tr>
                <td><?php echo $h((string)$punch['display_name']); ?></td>
                <td><?php echo $h(bakery_time_clock_format_time((string)$punch['clock_in_at'])); ?></td>
                <td><?php echo $h($punch['clock_out_at'] ? bakery_time_clock_format_time((string)$punch['clock_out_at']) : bakery_t('time_clock.still_in')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
