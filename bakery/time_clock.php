<?php
/**
 * Personal time clock. Cashiers land here after login. Drivers open it
 * from their route bar and return to the stop they were on.
 * Managers and administrators also see who is in, today's punches, and the week.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/time_clock.php';

bakery_require_role(['cashier', 'manager', 'administrator', 'driver', 'driver_assistant']);

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
    } elseif ($action === 'override') {
        if (!$canSeeBoard) {
            $notice = 'forbidden';
        } else {
            $result = bakery_time_clock_override(
                $db,
                $userId,
                $role,
                (int)($_POST['punch_id'] ?? 0),
                (string)($_POST['clock_in'] ?? ''),
                (string)($_POST['clock_out'] ?? ''),
                (string)($_POST['note'] ?? '')
            );
            $notice = !empty($result['ok']) ? 'override' : (string)($result['error'] ?? 'save_failed');
        }
    }
    $returnTo = bakery_time_clock_safe_return((string)($_POST['return'] ?? ''));
    if (($notice === 'in' || $notice === 'out') && $returnTo !== '') {
        header('Location: ' . $returnTo);
        exit;
    }
    $location = BASE_URL . 'time_clock.php?notice=' . rawurlencode($notice);
    if ($action === 'override') {
        $location .= '&week=' . rawurlencode(bakery_time_clock_week_monday((string)($_POST['week'] ?? '')));
        $location .= '#time-clock-week';
    }
    header('Location: ' . $location);
    exit;
}

$notice = (string)($_GET['notice'] ?? '');
$noticeKeys = [
    'already_in' => 'time_clock.notice_already_in',
    'not_in' => 'time_clock.notice_not_in',
    'not_ready' => 'time_clock.not_ready',
    'save_failed' => 'time_clock.save_failed',
    'override' => 'time_clock.notice_override',
    'forbidden' => 'time_clock.notice_forbidden',
    'bad_time' => 'time_clock.bad_time',
    'out_before_in' => 'time_clock.out_before_in',
    'future' => 'time_clock.future',
    'note_required' => 'time_clock.note_required',
    'not_found' => 'time_clock.not_found',
];
$noticeKey = $noticeKeys[$notice] ?? '';

$weekMonday = bakery_time_clock_week_monday((string)($_GET['week'] ?? ''));
$overridesReady = $ready && bakery_time_clock_overrides_ready($db);
$week = $canSeeBoard && $ready ? bakery_time_clock_week($db, $weekMonday) : null;
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
$dayShort = [
    '1' => 'day.mon_short',
    '2' => 'day.tue_short',
    '3' => 'day.wed_short',
    '4' => 'day.thu_short',
    '5' => 'day.fri_short',
    '6' => 'day.sat_short',
    '7' => 'day.sun_short',
];
$flagKey = static function (string $flag): string {
    switch ($flag) {
        case 'no_out':
            return 'time_clock.flag_no_out';
        case 'long':
            return 'time_clock.flag_long';
        case 'next_day':
            return 'time_clock.flag_next_day';
        default:
            return 'time_clock.flag_no_out';
    }
};
$renderFix = static function (array $punch) use ($h, $weekMonday, $overridesReady, $flagKey): void {
    $flags = is_array($punch['flags'] ?? null) ? $punch['flags'] : [];
    $inValue = bakery_time_clock_local_input((string)($punch['clock_in_at'] ?? ''));
    $outValue = bakery_time_clock_local_input(isset($punch['clock_out_at']) && $punch['clock_out_at'] !== null && $punch['clock_out_at'] !== '' ? (string)$punch['clock_out_at'] : null);
    ?>
    <article class="time-clock__fix<?php echo $flags ? ' time-clock__fix--flag' : ''; ?>">
      <p class="time-clock__fix-when">
        <?php echo $h(bakery_time_clock_format_time((string)$punch['clock_in_at'])); ?>
        –
        <?php echo $h($punch['clock_out_at'] ? bakery_time_clock_format_time((string)$punch['clock_out_at']) : bakery_t('time_clock.still_in')); ?>
        <?php if (!empty($punch['original_clock_in_at'])): ?>
          <span class="time-clock__corrected"><?php echo $h(bakery_t('time_clock.corrected')); ?></span>
        <?php endif; ?>
      </p>
      <?php if ($flags): ?>
        <ul class="time-clock__flags">
          <?php foreach ($flags as $flag): ?>
            <li><?php echo $h(bakery_t($flagKey((string)$flag))); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if (!empty($punch['original_clock_in_at'])): ?>
        <p class="time-clock__was"><?php echo $h(bakery_t('time_clock.was', [
            'time' => bakery_time_clock_format_time((string)$punch['original_clock_in_at'])
                . ' – '
                . ($punch['original_clock_out_at'] ? bakery_time_clock_format_time((string)$punch['original_clock_out_at']) : bakery_t('time_clock.still_in')),
        ])); ?></p>
        <?php if (!empty($punch['override_note'])): ?>
          <p class="time-clock__was"><?php echo $h((string)$punch['override_note']); ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($overridesReady): ?>
        <form method="post" action="<?php echo $h(BASE_URL . 'time_clock.php'); ?>">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="override">
          <input type="hidden" name="punch_id" value="<?php echo (int)$punch['id']; ?>">
          <input type="hidden" name="week" value="<?php echo $h($weekMonday); ?>">
          <label class="time-clock__field"><?php echo $h(bakery_t('time_clock.col_in')); ?>
            <input type="datetime-local" name="clock_in" required value="<?php echo $h($inValue); ?>">
          </label>
          <label class="time-clock__field"><?php echo $h(bakery_t('time_clock.col_out')); ?>
            <input type="datetime-local" name="clock_out" value="<?php echo $h($outValue); ?>"<?php echo $punch['clock_out_at'] ? ' required' : ''; ?>>
          </label>
          <label class="time-clock__field"><?php echo $h(bakery_t('time_clock.override_note')); ?>
            <input name="note" required maxlength="255" value="">
          </label>
          <button class="time-clock__save" type="submit"><?php echo $h(bakery_t('time_clock.override_save')); ?></button>
        </form>
      <?php else: ?>
        <p class="time-clock__was"><?php echo $h(bakery_t('time_clock.not_ready')); ?></p>
      <?php endif; ?>
    </article>
    <?php
};
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/time_clock.css'); ?>">

<main class="time-clock">
  <header class="time-clock__hero">
    <p class="time-clock__eyebrow"><?php echo $h(bakery_navigation_role_label($role)); ?></p>
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
      <p class="time-clock__week-jump"><a href="#time-clock-week"><?php echo $h(bakery_t('time_clock.this_week')); ?></a></p>
      <h2><?php echo $h(bakery_t('time_clock.who_in')); ?></h2>
      <?php if (!$whoIsIn): ?>
        <p class="time-clock__empty"><?php echo $h(bakery_t('time_clock.nobody_in')); ?></p>
      <?php else: ?>
        <ul class="time-clock__people">
          <?php foreach ($whoIsIn as $person): ?>
            <li>
              <span class="time-clock__who">
                <strong><?php echo $h((string)$person['display_name']); ?></strong>
                <span class="time-clock__role"><?php echo $h(bakery_navigation_role_label((string)($person['role_slug'] ?? ''))); ?></span>
              </span>
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
              <th><?php echo $h(bakery_t('time_clock.col_role')); ?></th>
              <th><?php echo $h(bakery_t('time_clock.col_in')); ?></th>
              <th><?php echo $h(bakery_t('time_clock.col_out')); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($todayPunches as $punch): ?>
              <tr>
                <td><?php echo $h((string)$punch['display_name']); ?></td>
                <td><?php echo $h(bakery_navigation_role_label((string)($punch['role_slug'] ?? ''))); ?></td>
                <td><?php echo $h(bakery_time_clock_format_time((string)$punch['clock_in_at'])); ?></td>
                <td><?php echo $h($punch['clock_out_at'] ? bakery_time_clock_format_time((string)$punch['clock_out_at']) : bakery_t('time_clock.still_in')); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <?php if (is_array($week)): ?>
      <?php
        $prevMonday = date('Y-m-d', strtotime($week['monday'] . ' -7 days'));
        $nextMonday = date('Y-m-d', strtotime($week['monday'] . ' +7 days'));
        $showNext = $nextMonday <= bakery_time_clock_week_monday(date('Y-m-d'));
        $weekEnd = $week['days'][6];
      ?>
      <section class="time-clock__week" id="time-clock-week">
        <div class="time-clock__week-nav">
          <a href="<?php echo $h(BASE_URL . 'time_clock.php?week=' . rawurlencode($prevMonday) . '#time-clock-week'); ?>"><?php echo $h(bakery_t('time_clock.prev_week')); ?></a>
          <h2><?php echo $h(bakery_t('time_clock.week_title', ['start' => date('n/j', strtotime($week['monday'])), 'end' => date('n/j', strtotime($weekEnd))])); ?></h2>
          <?php if ($showNext): ?>
            <a href="<?php echo $h(BASE_URL . 'time_clock.php?week=' . rawurlencode($nextMonday) . '#time-clock-week'); ?>"><?php echo $h(bakery_t('time_clock.next_week')); ?></a>
          <?php else: ?>
            <span></span>
          <?php endif; ?>
        </div>
        <?php if (!$week['people']): ?>
          <p class="time-clock__empty"><?php echo $h(bakery_t('time_clock.week_empty')); ?></p>
        <?php endif; ?>
        <?php foreach ($week['people'] as $person): ?>
          <article class="time-clock__person">
            <header class="time-clock__person-head">
              <strong><?php echo $h((string)$person['display_name']); ?></strong>
              <span class="time-clock__role"><?php echo $h(bakery_navigation_role_label((string)$person['role_slug'])); ?></span>
              <span><?php echo $h(bakery_t('time_clock.week_total', ['time' => bakery_time_clock_format_duration((int)$person['minutes'])])); ?></span>
            </header>
            <div class="time-clock__days">
              <?php foreach ($week['days'] as $day): ?>
                <?php $dayMinutes = (int)($person['days'][$day] ?? 0); ?>
                <div class="time-clock__day<?php echo $dayMinutes > 12 * 60 ? ' time-clock__day--flag' : ''; ?>">
                  <span><?php echo $h(bakery_t($dayShort[(string)date('N', strtotime($day . ' 12:00:00'))])); ?></span>
                  <strong><?php echo $dayMinutes > 0 ? $h(bakery_time_clock_format_duration($dayMinutes)) : '–'; ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
            <?php foreach ($person['earlier'] as $punch): ?>
              <p class="time-clock__earlier"><?php echo $h(bakery_t('time_clock.still_open_earlier', ['time' => bakery_time_clock_format_time((string)$punch['clock_in_at'])])); ?></p>
              <?php $renderFix($punch); ?>
            <?php endforeach; ?>
            <?php foreach ($person['flagged'] as $punch): ?>
              <?php $renderFix($punch); ?>
            <?php endforeach; ?>
            <?php if ($person['other']): ?>
              <details class="time-clock__other">
                <summary><?php echo $h(bakery_t('time_clock.other_punches')); ?></summary>
                <?php foreach ($person['other'] as $punch): ?>
                  <?php $renderFix($punch); ?>
                <?php endforeach; ?>
              </details>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
