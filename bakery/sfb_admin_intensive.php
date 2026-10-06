<?php
/**
 * Staff desk for the Full Week Intensive.
 * Private notes on this page are never rendered on customer screens.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

bakery_require_role(['administrator']);
bakery_ensure_sfb_schema($db);

if (!bakery_sfb_intensive_ready($db)) {
    http_response_code(503);
    exit('The Intensive needs migration 083 before this desk can open.');
}

$admin = bakery_current_user();
$adminId = (int)($admin['id'] ?? 0);
$adminName = trim((string)($admin['display_name'] ?? '')) ?: 'Sour Flour';
$program = bakery_sfb_intensive_ensure_catalog($db);
$error = '';
$saved = (string)($_GET['saved'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $slotId = (int)($_POST['slot_id'] ?? 0);
        $back = 'sfb_admin_intensive.php' . ($enrollmentId > 0 ? '?enrollment=' . $enrollmentId : '');
        if ($action === 'windows' && $program) {
            bakery_sfb_intensive_update_windows(
                $db,
                (int)$program['id'],
                (int)($_POST['coaching_days'] ?? 7),
                $_POST['material_access_days'] ?? ''
            );
            header('Location: sfb_admin_intensive.php?saved=windows');
            exit;
        }
        if ($action === 'attach_purchase') {
            bakery_sfb_intensive_attach_purchase($db, $enrollmentId, (int)($_POST['purchase_id'] ?? 0));
        } elseif ($action === 'spawn') {
            $created = bakery_sfb_intensive_spawn(
                $db,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['pin'] ?? ''),
                (string)($_POST['phone'] ?? '')
            );
            $_SESSION['intensive_spawn_pin'] = $created['pin'];
            $_SESSION['intensive_spawn_name'] = $created['name'];
            header('Location: sfb_admin_intensive.php?enrollment=' . (int)$created['enrollment_id'] . '&saved=spawned');
            exit;
        } elseif ($action === 'enroll') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            if ($customerId <= 0) {
                throw new InvalidArgumentException('Choose a baker');
            }
            $newId = bakery_sfb_intensive_enroll($db, $customerId, (int)$program['id'], [
                'purchase_id' => (int)($_POST['purchase_id'] ?? 0),
                'assigned_user_id' => (int)($_POST['assigned_user_id'] ?? 0),
                'start_date' => (string)($_POST['start_date'] ?? ''),
                'internal_note' => (string)($_POST['internal_note'] ?? ''),
            ]);
            header('Location: sfb_admin_intensive.php?enrollment=' . $newId . '&saved=enrolled');
            exit;
        } elseif ($action === 'instruction') {
            bakery_sfb_intensive_set_instruction($db, $enrollmentId, (string)($_POST['instruction'] ?? ''));
        } elseif ($action === 'formula') {
            $target = bakery_sfb_intensive_enrollment($db, $enrollmentId);
            if (!$target) {
                throw new InvalidArgumentException('Intensive not found');
            }
            bakery_sfb_intensive_save_working_formula($db, (int)$target['customer_id'], (array)($_POST['pct'] ?? []));
        } elseif ($action === 'dough_temp') {
            $target = bakery_sfb_intensive_enrollment($db, $enrollmentId);
            if (!$target) {
                throw new InvalidArgumentException('Intensive not found');
            }
            bakery_sfb_intensive_save_dough_temp($db, (int)$target['customer_id'], (string)($_POST['dough_temp_f'] ?? ''));
        } elseif ($action === 'plan') {
            bakery_sfb_intensive_update_plan($db, $enrollmentId, $_POST);
        } elseif ($action === 'pause') {
            bakery_sfb_intensive_set_status($db, $enrollmentId, 'paused');
        } elseif ($action === 'resume') {
            bakery_sfb_intensive_set_status($db, $enrollmentId, 'active');
        } elseif ($action === 'cancel') {
            bakery_sfb_intensive_set_status($db, $enrollmentId, 'cancelled');
        } elseif ($action === 'reopen') {
            bakery_sfb_intensive_set_status($db, $enrollmentId, 'final_review');
        } elseif ($action === 'internal_note') {
            bakery_sfb_intensive_add_internal_note($db, $enrollmentId, (string)($_POST['note'] ?? ''), $adminName);
        } elseif ($action === 'visible_note') {
            bakery_sfb_intensive_add_message($db, $enrollmentId, 'admin', $adminName, (string)($_POST['body'] ?? ''), null, $adminId);
        } elseif ($action === 'assign_formula') {
            bakery_sfb_intensive_assign_formula($db, $slotId, (int)($_POST['formula_id'] ?? 0));
        } elseif ($action === 'release') {
            bakery_sfb_intensive_release_slot($db, $slotId, (string)($_POST['objective'] ?? ''));
        } elseif ($action === 'review') {
            bakery_sfb_intensive_review_slot($db, $slotId, (string)($_POST['feedback'] ?? ''), (string)($_POST['internal_summary'] ?? ''));
        } elseif ($action === 'checkpoint') {
            bakery_sfb_intensive_request_checkpoint($db, $slotId, (string)($_POST['stage'] ?? ''), (string)($_POST['instruction'] ?? ''), $adminId, $adminName);
        } elseif ($action === 'satisfy') {
            bakery_sfb_intensive_satisfy_checkpoint($db, (int)($_POST['checkpoint_id'] ?? 0));
        } elseif ($action === 'link_batch') {
            bakery_sfb_intensive_link_batch($db, $slotId, (int)($_POST['batch_id'] ?? 0));
        } elseif ($action === 'retry') {
            bakery_sfb_intensive_retry_slot($db, $slotId);
        } elseif ($action === 'complete') {
            bakery_sfb_intensive_complete($db, $enrollmentId, [
                'changed' => (string)($_POST['summary_changed'] ?? ''),
                'lessons' => (string)($_POST['summary_lessons'] ?? ''),
                'process' => (string)($_POST['summary_process'] ?? ''),
                'next' => (string)($_POST['summary_next'] ?? ''),
            ]);
        } else {
            throw new InvalidArgumentException('Choose an action');
        }
        header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&') . 'saved=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$program = bakery_sfb_intensive_program_by_slug($db, 'full-week');
$spawnPin = (string)($_SESSION['intensive_spawn_pin'] ?? '');
$spawnName = (string)($_SESSION['intensive_spawn_name'] ?? '');
unset($_SESSION['intensive_spawn_pin'], $_SESSION['intensive_spawn_name']);
$enrollmentId = (int)($_GET['enrollment'] ?? ($_POST['enrollment_id'] ?? 0));
$detail = $enrollmentId > 0 ? bakery_sfb_intensive_staff_detail($db, $enrollmentId) : null;
$includeClosed = (string)($_GET['closed'] ?? '') === '1';
$rows = $detail ? [] : bakery_sfb_intensive_staff_rows($db, $includeClosed);
$matches = [];
$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '' && !$detail) {
    $matches = bakery_sfb_intensive_search_customers($db, $search);
}
$staffUsers = bakery_sfb_intensive_staff_users($db);

$page_title = 'Full Week Intensive';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
require __DIR__ . '/includes/sfb_admin_styles.php';
?>
<main class="sfb-admin">
  <header class="sfb-admin__header">
    <div>
      <p class="page-eyebrow">Administrator workspace</p>
      <h1>Full Week Intensive</h1>
      <p>Who needs a look, which bake they are on, and the next thing you can do. Bakes stay in the ordinary journal.</p>
    </div>
    <a href="sfb_admin_overview.php">Engagement</a>
    <a href="sfb_admin_learn.php">Courses</a>
  </header>

  <?php if ($error !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php elseif ($saved !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status">Saved.</div>
  <?php endif; ?>
  <?php if ($spawnPin !== ''): ?>
    <div class="sfb-admin__notice sfb-admin__notice--success" role="status">
      <?php echo htmlspecialchars($spawnName, ENT_QUOTES, 'UTF-8'); ?> can sign in with <strong><?php echo htmlspecialchars($spawnPin, ENT_QUOTES, 'UTF-8'); ?></strong>
    </div>
  <?php endif; ?>

  <?php if ($detail): ?>
    <?php
    $enrollment = $detail['enrollment'];
    $baker = $detail['customer'];
    ?>
    <p><a href="sfb_admin_intensive.php">All Intensives</a></p>
    <section class="sfb-admin__panel">
      <h2><?php echo htmlspecialchars((string)$baker['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
      <p><?php echo htmlspecialchars((string)($baker['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
        · <a href="customer_record.php?customer_id=<?php echo (int)$baker['id']; ?>">Customer record</a></p>
      <?php
      $staffState = [
          'pending' => 'Waiting for setup',
          'awaiting_staff' => 'Needs a look',
          'active' => 'Bake in progress',
          'paused' => 'Paused',
          'awaiting_customer' => 'Waiting on the baker',
          'final_review' => 'Final review',
          'completed' => 'Complete',
          'cancelled' => 'Cancelled',
      ];
      ?>
      <p>
        <?php echo htmlspecialchars($staffState[(string)$enrollment['status']] ?? 'In progress', ENT_QUOTES, 'UTF-8'); ?>
        <?php if (!empty($enrollment['start_date'])): ?>
          · <?php echo htmlspecialchars((string)$enrollment['start_date'], ENT_QUOTES, 'UTF-8'); ?>
          to <?php echo htmlspecialchars((string)$enrollment['coaching_end_date'], ENT_QUOTES, 'UTF-8'); ?>
        <?php endif; ?>
        <?php if (!empty($detail['program']['coaching_days'])): ?>
          · coaching window <?php echo (int)$detail['program']['coaching_days']; ?> days
        <?php endif; ?>
      </p>
      <?php $preview = bakery_sfb_intensive_present($db, $enrollment, false); ?>
      <section class="sfb-admin__panel" style="margin:16px 0;background:#fffaf2;">
        <p class="page-eyebrow">What they see</p>
        <p class="muted" style="margin:0;">Loaf <?php echo (int)$preview['progress_n']; ?> of 3</p>
        <h2 style="margin:6px 0 12px;"><?php echo nl2br(htmlspecialchars((string)$preview['instruction'], ENT_QUOTES, 'UTF-8')); ?></h2>
        <?php if ($preview['goal'] !== ''): ?>
          <p><?php echo htmlspecialchars((string)$preview['goal'], ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <form method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="instruction">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <label>What they should do now
            <textarea name="instruction" required><?php echo htmlspecialchars((string)$preview['instruction'], ENT_QUOTES, 'UTF-8'); ?></textarea>
          </label>
          <button type="submit">Update their screen</button>
        </form>
        <?php if (!empty($preview['formula']['lines'])): ?>
          <form method="post" style="margin-top:14px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="formula">
            <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
            <p><strong>Formula you are setting together</strong></p>
            <?php foreach ($preview['formula']['lines'] as $line): ?>
              <label><?php echo htmlspecialchars($line['name'], ENT_QUOTES, 'UTF-8'); ?>
                <input type="number" name="pct[<?php echo (int)$line['id']; ?>]" min="0.1" max="500" step="0.1" value="<?php echo htmlspecialchars((string)$line['percentage'], ENT_QUOTES, 'UTF-8'); ?>">
              </label>
            <?php endforeach; ?>
            <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Save formula</button>
          </form>
          <details style="margin-top:14px;" <?php echo ($preview['dough_temp_f'] ?? '') !== '' ? 'open' : ''; ?>>
            <summary>Thermometer</summary>
            <form method="post" style="margin-top:10px;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="dough_temp">
              <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
              <label>Dough temperature (°F)
                <input type="number" name="dough_temp_f" min="40" max="120" step="0.1" value="<?php echo htmlspecialchars((string)($preview['dough_temp_f'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
              </label>
              <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Save temperature</button>
            </form>
          </details>
        <?php endif; ?>
      </section>
      <details style="margin-top:8px;">
        <summary>Dates, notes, and the three loaves</summary>
      <form method="post" class="sfb-admin__filters">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="plan">
        <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
        <label>Start
          <input type="date" name="start_date" value="<?php echo htmlspecialchars((string)$enrollment['start_date'], ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Coaching through
          <input type="date" name="coaching_end_date" value="<?php echo htmlspecialchars((string)$enrollment['coaching_end_date'], ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label>Coach
          <select name="assigned_user_id">
            <option value="0">Unassigned</option>
            <?php foreach ($detail['staff'] as $user): ?>
              <option value="<?php echo (int)$user['id']; ?>"<?php echo (int)$enrollment['assigned_user_id'] === (int)$user['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Their goal
          <textarea name="primary_goal"><?php echo htmlspecialchars((string)$enrollment['primary_goal'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <label>Focus they should see
          <textarea name="staff_objective"><?php echo htmlspecialchars((string)$enrollment['staff_objective'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <button type="submit">Save plan</button>
      </form>
      <p class="muted">
        Experience: <?php echo htmlspecialchars((string)$enrollment['experience_level'], ENT_QUOTES, 'UTF-8'); ?><br>
        Bread now: <?php echo nl2br(htmlspecialchars((string)$enrollment['customer_background'], ENT_QUOTES, 'UTF-8')); ?><br>
        When they can bake: <?php echo nl2br(htmlspecialchars((string)$enrollment['availability'], ENT_QUOTES, 'UTF-8')); ?><br>
        Room: <?php echo htmlspecialchars((string)$enrollment['room_temperature'], ENT_QUOTES, 'UTF-8'); ?>
        · Oven: <?php echo htmlspecialchars((string)$enrollment['oven_type'], ENT_QUOTES, 'UTF-8'); ?>
        · Vessel: <?php echo htmlspecialchars((string)$enrollment['bake_vessel'], ENT_QUOTES, 'UTF-8'); ?>
        · Flour: <?php echo htmlspecialchars((string)$enrollment['flour_used'], ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <?php if (!empty($detail['purchases'])): ?>
        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="attach_purchase">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <label>Paid Full Week Intensive purchase
            <select name="purchase_id">
              <?php foreach ($detail['purchases'] as $purchase): ?>
                <option value="<?php echo (int)$purchase['id']; ?>"<?php echo (int)$enrollment['purchase_id'] === (int)$purchase['id'] ? ' selected' : ''; ?>>
                  <?php echo htmlspecialchars(date('M j, Y', strtotime((string)($purchase['paid_at'] ?: 'now'))) . ' · $' . number_format(((int)$purchase['price_cents_snapshot']) / 100, 2), ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Attach payment</button>
        </form>
      <?php endif; ?>
      <div class="btn-row">
        <?php if ((string)$enrollment['status'] === 'paused'): ?>
          <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="resume"><input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>"><button type="submit">Resume</button></form>
        <?php elseif (!in_array((string)$enrollment['status'], ['completed', 'cancelled'], true)): ?>
          <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="pause"><input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>"><button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Pause</button></form>
        <?php endif; ?>
        <?php if ((string)$enrollment['status'] === 'completed'): ?>
          <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="reopen"><input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>"><button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Reopen</button></form>
        <?php elseif ((string)$enrollment['status'] !== 'cancelled'): ?>
          <form method="post"><?php echo bakery_csrf_field(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>"><button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Cancel</button></form>
        <?php endif; ?>
      </div>
    </section>

    <?php foreach ($detail['slots'] as $slot): ?>
      <section class="sfb-admin__panel" style="margin-top:16px;">
        <?php
        $slotLabels = [
            'upcoming' => 'Not open yet',
            'ready' => 'Ready for them',
            'in_progress' => 'In progress',
            'awaiting_review' => 'Needs a look',
            'reviewed' => 'Reviewed',
        ];
        ?>
        <h2>Batch <?php echo (int)$slot['sequence_number']; ?> · <?php echo htmlspecialchars($slotLabels[(string)$slot['status']] ?? 'In progress', ENT_QUOTES, 'UTF-8'); ?></h2>
        <p>
          Formula: <?php echo htmlspecialchars((string)($slot['formula_name'] ?? 'not assigned'), ENT_QUOTES, 'UTF-8'); ?>
          <?php if ((int)$slot['batch_id'] > 0): ?>
            · <a href="sfb_admin_batch.php?batch=<?php echo (int)$slot['batch_id']; ?>"><?php echo htmlspecialchars((string)($slot['batch_name'] ?? 'Open bake'), ENT_QUOTES, 'UTF-8'); ?></a>
          <?php endif; ?>
        </p>
        <?php if (!empty($slot['attempts'])): ?>
          <p class="muted">Earlier tries stay in the journal:
            <?php foreach ($slot['attempts'] as $attempt): ?>
              <a href="sfb_admin_batch.php?batch=<?php echo (int)$attempt['batch_id']; ?>"><?php echo htmlspecialchars((string)$attempt['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
        <?php if ((string)$slot['customer_feedback'] !== ''): ?>
          <p><strong>They can see:</strong> <?php echo nl2br(htmlspecialchars((string)$slot['customer_feedback'], ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>
        <?php if ((string)$slot['internal_summary'] !== ''): ?>
          <p><strong>Private:</strong> <?php echo nl2br(htmlspecialchars((string)$slot['internal_summary'], ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>

        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="assign_formula">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
          <label>Formula
            <select name="formula_id" required>
              <option value="">Choose</option>
              <optgroup label="Standard">
                <?php foreach ($detail['templates'] as $formula): ?>
                  <option value="<?php echo (int)$formula['id']; ?>"><?php echo htmlspecialchars((string)$formula['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </optgroup>
              <optgroup label="Theirs">
                <?php foreach ($detail['formulas'] as $formula): ?>
                  <option value="<?php echo (int)$formula['id']; ?>"><?php echo htmlspecialchars((string)$formula['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </label>
          <button type="submit">Assign formula</button>
        </form>

        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="release">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
          <label>Focus for this bake
            <textarea name="objective"><?php echo htmlspecialchars((string)$slot['objective'], ENT_QUOTES, 'UTF-8'); ?></textarea>
          </label>
          <button type="submit">Open this bake</button>
        </form>

        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="checkpoint">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
          <label>Check-in
            <select name="stage">
              <?php foreach (bakery_sfb_intensive_stages() as $stage): ?>
                <option value="<?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stage, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>What to ask
            <textarea name="instruction" placeholder="When the dough looks about halfway through bulk, add a photo."></textarea>
          </label>
          <button type="submit">Send check-in</button>
        </form>
        <?php if (!empty($slot['checkpoints'])): ?>
          <ul>
            <?php foreach ($slot['checkpoints'] as $check): ?>
              <li>
                <?php echo htmlspecialchars((string)$check['stage'], ENT_QUOTES, 'UTF-8'); ?>
                · <?php echo htmlspecialchars((string)$check['status'], ENT_QUOTES, 'UTF-8'); ?>
                · <?php echo htmlspecialchars((string)$check['instruction'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if ((string)$check['status'] === 'requested'): ?>
                  <form method="post" style="display:inline;">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="satisfy">
                    <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
                    <input type="hidden" name="checkpoint_id" value="<?php echo (int)$check['id']; ?>">
                    <button type="submit" class="sfb-admin__button sfb-admin__button--quiet">Mark done</button>
                  </form>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="link_batch">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
          <label>Link a bake they already started
            <select name="batch_id">
              <option value="">Choose</option>
              <?php foreach ($detail['batches'] as $batch): ?>
                <option value="<?php echo (int)$batch['id']; ?>"><?php echo htmlspecialchars((string)$batch['name'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Link bake</button>
        </form>

        <?php if ((int)$slot['batch_id'] > 0): ?>
          <form method="post">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="retry">
            <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
            <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
            <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Keep this bake and try again</button>
          </form>
        <?php endif; ?>

        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="review">
          <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
          <input type="hidden" name="slot_id" value="<?php echo (int)$slot['id']; ?>">
          <label>Note they will see
            <textarea name="feedback" required><?php echo htmlspecialchars((string)$slot['customer_feedback'], ENT_QUOTES, 'UTF-8'); ?></textarea>
          </label>
          <label>Private read of this bake
            <textarea name="internal_summary"><?php echo htmlspecialchars((string)$slot['internal_summary'], ENT_QUOTES, 'UTF-8'); ?></textarea>
          </label>
          <button type="submit">Save review</button>
        </form>
      </section>
    <?php endforeach; ?>

    <section class="sfb-admin__panel" style="margin-top:16px;">
      <h2>Notes they can see</h2>
      <ul>
        <?php foreach ($detail['messages'] as $message): ?>
          <li><strong><?php echo htmlspecialchars((string)$message['author_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
            · <?php echo htmlspecialchars((string)$message['created_at'], ENT_QUOTES, 'UTF-8'); ?><br>
            <?php echo nl2br(htmlspecialchars((string)$message['body'], ENT_QUOTES, 'UTF-8')); ?></li>
        <?php endforeach; ?>
      </ul>
      <form method="post">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="visible_note">
        <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
        <label>Note for them
          <textarea name="body" required></textarea>
        </label>
        <button type="submit">Send note</button>
      </form>
    </section>

    <section class="sfb-admin__panel" style="margin-top:16px;">
      <h2>Private notes</h2>
      <p><?php echo nl2br(htmlspecialchars((string)$enrollment['internal_notes'], ENT_QUOTES, 'UTF-8')); ?></p>
      <form method="post">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="internal_note">
        <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
        <label>Add a private note
          <textarea name="note" required></textarea>
        </label>
        <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Save private note</button>
      </form>
    </section>

    <section class="sfb-admin__panel" style="margin-top:16px;">
      <h2>Finish the week</h2>
      <form method="post" class="sfb-admin__filters">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="complete">
        <input type="hidden" name="enrollment_id" value="<?php echo (int)$enrollment['id']; ?>">
        <label>What changed
          <textarea name="summary_changed" required><?php echo htmlspecialchars((string)$enrollment['summary_changed'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <label>Lessons to keep
          <textarea name="summary_lessons" required><?php echo htmlspecialchars((string)$enrollment['summary_lessons'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <label>Process to keep using
          <textarea name="summary_process"><?php echo htmlspecialchars((string)$enrollment['summary_process'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <label>What to work on next
          <textarea name="summary_next"><?php echo htmlspecialchars((string)$enrollment['summary_next'], ENT_QUOTES, 'UTF-8'); ?></textarea>
        </label>
        <button type="submit">Complete the Intensive</button>
      </form>
    </section>
      </details>
  <?php else: ?>
    <section class="sfb-admin__panel" id="spawn">
      <h2>New intensive</h2>
      <p>Creates the baker and the week. Leave the code blank and one will be chosen. Hand them that code to sign in.</p>
      <form method="post" class="sfb-admin__filters">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="spawn">
        <label>Name
          <input type="text" name="name" required maxlength="120" placeholder="Test baker">
        </label>
        <label>Sign-in code
          <input type="text" name="pin" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="4 digits, or blank">
        </label>
        <label>Phone
          <input type="tel" name="phone" placeholder="Optional">
        </label>
        <button type="submit">Start their week</button>
      </form>
    </section>

    <section class="sfb-admin__stats">
      <div class="sfb-admin__stat"><strong><?php echo count($rows); ?></strong><span><?php echo $includeClosed ? 'On the list' : 'Open'; ?></span></div>
    </section>
    <section class="sfb-admin__panel">
      <h2>Active weeks</h2>
      <p><a href="sfb_admin_intensive.php<?php echo $includeClosed ? '' : '?closed=1'; ?>"><?php echo $includeClosed ? 'Hide finished' : 'Include finished'; ?></a></p>
      <div class="sfb-admin__scroll">
        <table class="sfb-admin__table">
          <thead>
            <tr><th>Baker</th><th>Day</th><th>Where they are</th><th>Last activity</th><th>Needs you?</th><th>Next</th></tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6">No Intensive is open yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><a href="sfb_admin_intensive.php?enrollment=<?php echo (int)$row['id']; ?>"><?php echo htmlspecialchars($row['customer_name'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                <td><?php echo htmlspecialchars($row['day'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['stage'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($row['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $row['needs'] ? 'Yes' : 'No'; ?></td>
                <td><?php echo htmlspecialchars($row['next'], ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="sfb-admin__panel" style="margin-top:16px;" id="enroll">
      <h2>Enroll a baker</h2>
      <p>Use this for the current buyer, a gift, or a payment that did not come through Purchase Home. They keep the same SF Baker login.</p>
      <form method="get" class="sfb-admin__filters">
        <label>Find by name or phone
          <input type="text" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Search</button>
      </form>
      <?php if ($matches): ?>
        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="enroll">
          <label>Baker
            <select name="customer_id" required>
              <?php foreach ($matches as $match): ?>
                <option value="<?php echo (int)$match['id']; ?>"><?php echo htmlspecialchars((string)$match['name'] . ' · ' . (string)$match['phone'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Coach
            <select name="assigned_user_id">
              <option value="0">Unassigned</option>
              <?php foreach ($staffUsers as $user): ?>
                <option value="<?php echo (int)$user['id']; ?>"><?php echo htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Start date
            <input type="date" name="start_date" value="<?php echo date('Y-m-d'); ?>">
          </label>
          <label>Private note
            <textarea name="internal_note"></textarea>
          </label>
          <button type="submit">Create Intensive</button>
        </form>
      <?php elseif ($search !== ''): ?>
        <p>No matching customer. They need an SF Baker account before they can be enrolled.</p>
      <?php endif; ?>
    </section>

    <?php if ($program): ?>
      <section class="sfb-admin__panel" style="margin-top:16px;">
        <h2>Windows</h2>
        <p>Coaching length is how long you plan to stay with them. Lesson access can stay open after that. Leaving lesson access blank keeps the course available.</p>
        <form method="post" class="sfb-admin__filters">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="windows">
          <label>Coaching days
            <input type="number" name="coaching_days" min="1" max="90" value="<?php echo (int)$program['coaching_days']; ?>">
          </label>
          <label>Lesson access days
            <input type="number" name="material_access_days" min="1" max="3650" placeholder="Open" value="<?php echo $program['material_access_days'] === null ? '' : (int)$program['material_access_days']; ?>">
          </label>
          <button type="submit" class="sfb-admin__button sfb-admin__button--secondary">Save windows</button>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
