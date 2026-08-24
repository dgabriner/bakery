<?php
/**
 * Survey landing page for Twilio link-mode surveys (drivers and managers).
 *
 * The token only selects WHICH survey opens; every action still rides the
 * normal staff/driver session gate (trusted-device login restores itself) and
 * the same mutation helpers My Route uses. No second auth system.
 *
 * GET  ?t=TOKEN                 render the survey
 * POST ?t=TOKEN  action=skip|unskip|claim|answer|close
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/surveys.php';

bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);

$user = bakery_current_user();
$isManager = bakery_user_has_role(['administrator', 'manager']);
$token = trim((string)($_REQUEST['t'] ?? ''));
$survey = $token !== '' ? bakery_survey_find_by_token($db, $token) : [];

function bakery_survey_fail(string $title, string $message): void
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars(bakery_locale(), ENT_QUOTES, 'UTF-8') . '">'
        . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $t . '</title><body style="font-family:system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 16px">'
        . '<h1 style="font-size:20px">' . $t . '</h1><p>' . $m . '</p>'
        . '<p><a href="' . htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') . 'driver.php" style="color:#2c5aa0">' . htmlspecialchars(bakery_t('survey.open_my_route'), ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '</body></html>';
    exit;
}

if (!$survey) {
    bakery_survey_fail(
        (string)bakery_t('survey.not_found_title', [], 'Survey not found'),
        (string)bakery_t('survey.not_found_body', [], 'This survey link is not valid. Ask the bakery to resend it.')
    );
}
if ((string)$survey['status'] !== 'open') {
    bakery_survey_fail(
        (string)bakery_t('survey.closed_title', [], 'Survey closed'),
        (string)bakery_t('survey.closed_body', [], 'This survey already closed. Thank you!')
    );
}

$driverId = (int)($survey['driver_id'] ?? 0);
$deliveryDate = (string)($survey['delivery_date'] ?? '');
if ($deliveryDate === '') {
    $deliveryDate = date('Y-m-d');
}

// Drivers may only open their own survey; managers may inspect any.
if (!$isManager && (string)$survey['audience'] === 'driver') {
    try {
        bakery_assert_driver_identity($db, $driverId, $deliveryDate);
    } catch (RuntimeException $e) {
        bakery_survey_fail(
            (string)bakery_t('survey.wrong_driver_title', [], 'Not your survey'),
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        );
    }
}

$flash = trim((string)($_GET['done'] ?? ''));
$error = trim((string)($_GET['err'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    bakery_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'close' && $isManager) {
            bakery_survey_close($db, (int)$survey['id']);
            safe_redirect('text_comms.php?view=inbox&survey_closed=1');
        }
        if ($action === 'skip') {
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') {
                $reason = (string)bakery_t('survey.skip_default_reason', [], 'Skipped from driver survey');
            } else {
                $reason = '[survey] ' . $reason;
            }
            bakery_skip_delivery_stop($db, $dailyOrderId, $reason);
            bakery_survey_record_response($db, [
                'survey_id' => (int)$survey['id'],
                'action' => 'skip',
                'daily_order_id' => $dailyOrderId,
                'response' => $reason,
            ]);
            safe_redirect('survey.php?t=' . rawurlencode($token) . '&done=' . rawurlencode((string)bakery_t('survey.done_skip', [], 'Stop skipped. Thank you!')));
        }
        if ($action === 'unskip') {
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            bakery_unskip_delivery_stop($db, $dailyOrderId);
            bakery_survey_record_response($db, [
                'survey_id' => (int)$survey['id'],
                'action' => 'unskip',
                'daily_order_id' => $dailyOrderId,
            ]);
            safe_redirect('survey.php?t=' . rawurlencode($token) . '&done=' . rawurlencode((string)bakery_t('survey.done_unskip', [], 'Stop restored.')));
        }
        if ($action === 'claim') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $result = bakery_driver_plan_add_stop($db, $driverId, $deliveryDate, $customerId, false);
            bakery_survey_record_response($db, [
                'survey_id' => (int)$survey['id'],
                'action' => 'claim',
                'customer_id' => $customerId,
                'daily_order_id' => (int)$result['daily_order_id'],
                'response' => $result['message'],
            ]);
            safe_redirect('survey.php?t=' . rawurlencode($token) . '&done=' . rawurlencode($result['message']));
        }
        if ($action === 'answer') {
            $answer = trim((string)($_POST['answer'] ?? ''));
            if ($answer === '') {
                safe_redirect('survey.php?t=' . rawurlencode($token) . '&err=' . rawurlencode((string)bakery_t('survey.err_empty_answer', [], 'Please write an answer first.')));
            }
            bakery_survey_record_response($db, [
                'survey_id' => (int)$survey['id'],
                'action' => 'answer',
                'response' => $answer,
            ]);
            safe_redirect('survey.php?t=' . rawurlencode($token) . '&done=' . rawurlencode((string)bakery_t('survey.done_answer', [], 'Answer recorded. Thank you!')));
        }
        safe_redirect('survey.php?t=' . rawurlencode($token) . '&err=' . rawurlencode('unknown_action'));
    } catch (RuntimeException $e) {
        safe_redirect('survey.php?t=' . rawurlencode($token) . '&err=' . rawurlencode($e->getMessage()));
    }
}

$pageTitle = (string)bakery_t('survey.page_title', [], 'Survey');
$data = [];
$routeReview = false;
if ((string)$survey['kind'] === 'route_review') {
    $routeReview = true;
    $data = bakery_survey_route_review_data($db, $driverId, $deliveryDate);
}
$responses = [];
$stmt = $db->prepare('SELECT action, response, created_at FROM survey_responses WHERE survey_id = ? ORDER BY id DESC LIMIT 30');
$stmt->execute([(int)$survey['id']]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$esc = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="<?php echo $esc(bakery_locale()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($pageTitle); ?></title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f6f3ee; color: #24303e; }
  main { max-width: 520px; margin: 0 auto; padding: 16px 14px 48px; }
  h1 { font-size: 19px; margin: 8px 0 4px; }
  .sub { font-size: 13px; opacity: .7; margin-bottom: 14px; }
  .card { background: #fff; border: 1px solid #e4ddd2; border-radius: 12px; padding: 12px; margin-bottom: 10px; }
  .stop { display: flex; justify-content: space-between; align-items: center; gap: 8px; padding: 9px 0; border-bottom: 1px solid #efe9df; }
  .stop:last-child { border-bottom: none; }
  .name { font-weight: 600; font-size: 15px; }
  .meta { font-size: 12px; opacity: .65; }
  button, .btn { font: inherit; border: none; border-radius: 9px; padding: 9px 13px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
  .warn { background: #fdeaea; color: #a33; }
  .ok { background: #e8f3ea; color: #276b33; }
  .primary { background: #2c5aa0; color: #fff; }
  textarea { width: 100%; box-sizing: border-box; min-height: 84px; font: inherit; padding: 9px; border-radius: 9px; border: 1px solid #d8d0c2; }
  .flash { background: #e8f3ea; border: 1px solid #bcd9c2; color: #276b33; padding: 9px 12px; border-radius: 9px; margin-bottom: 10px; font-size: 14px; }
  .flash.err { background: #fdeaea; border-color: #eec3c3; color: #a33; }
  form.inline { display: contents; }
</style>
</head>
<body>
<main>
  <h1><?php echo $esc(bakery_survey_text('survey.page_title', [], 'Survey')); ?></h1>
  <?php if ($routeReview): ?>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.route_review_sub', ['date' => $deliveryDate], 'Route review for :date')); ?></p>
  <?php else: ?>
  <p class="sub"><?php echo $esc((string)($survey['question'] ?? '')); ?></p>
  <?php endif; ?>
  <?php if ($flash !== ''): ?><div class="flash"><?php echo $esc($flash); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash err"><?php echo $esc($error); ?></div><?php endif; ?>

  <?php if ($routeReview): ?>
    <div class="card">
      <div class="meta"><?php echo $esc(bakery_survey_text('survey.your_stops', ['count' => count($data['stops'])], 'Your stops (:count)')); ?></div>
      <?php foreach ($data['stops'] as $stop): ?>
      <div class="stop">
        <div>
          <div class="name"><?php echo $esc($stop['customer_name']); ?></div>
          <div class="meta"><?php echo $esc(trim(($stop['zone'] ?? '') !== '' ? $stop['zone'] . ' · ' : '')); ?><?php echo (int)($stop['pieces'] ?? 0); ?> pcs<?php echo !empty($stop['skip_reason']) ? ' · ⚠ ' . $esc($stop['skip_reason']) : (!empty($stop['skipped']) ? ' · ⚠' : ''); ?></div>
        </div>
        <form class="inline" method="post" action="survey.php?t=<?php echo $esc($token); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
          <input type="hidden" name="daily_order_id" value="<?php echo (int)$stop['daily_order_id']; ?>">
          <?php if (!empty($stop['skipped'])): ?>
            <input type="hidden" name="action" value="unskip">
            <button type="submit" class="btn ok"><?php echo $esc(bakery_survey_text('survey.unskip', [], 'Restore')); ?></button>
          <?php else: ?>
            <input type="hidden" name="action" value="skip">
            <input type="hidden" name="reason" value="">
            <button type="submit" class="btn warn"><?php echo $esc(bakery_survey_text('survey.skip', [], 'Skip')); ?></button>
          <?php endif; ?>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if (!$data['stops']): ?>
      <p class="meta"><?php echo $esc(bakery_survey_text('survey.no_stops', [], 'No pending stops on this route.')); ?></p>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="meta"><?php echo $esc(bakery_survey_text('survey.unassigned_stops', ['count' => count($data['unassigned'])], 'Stores with no driver (:count) — tap to add to your route')); ?></div>
      <?php foreach (array_slice($data['unassigned'], 0, 20) as $candidate): ?>
      <div class="stop">
        <div>
          <div class="name"><?php echo $esc($candidate['customer_name']); ?></div>
          <div class="meta"><?php echo (int)$candidate['pieces']; ?> pcs</div>
        </div>
        <form class="inline" method="post" action="survey.php?t=<?php echo $esc($token); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
          <input type="hidden" name="action" value="claim">
          <input type="hidden" name="customer_id" value="<?php echo (int)$candidate['customer_id']; ?>">
          <button type="submit" class="btn primary"><?php echo $esc(bakery_survey_text('survey.claim', [], 'Add to my route')); ?></button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if (!$data['unassigned']): ?>
      <p class="meta"><?php echo $esc(bakery_survey_text('survey.no_unassigned', [], 'Every store has a driver. Nice.')); ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <form method="post" action="survey.php?t=<?php echo $esc($token); ?>" style="display:grid;gap:10px">
        <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
        <input type="hidden" name="action" value="answer">
        <textarea name="answer" placeholder="<?php echo $esc(bakery_survey_text('survey.answer_placeholder', [], 'Type your answer…')); ?>"></textarea>
        <button type="submit" class="btn primary"><?php echo $esc(bakery_survey_text('survey.answer_send', [], 'Send answer')); ?></button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($responses): ?>
  <div class="card">
    <div class="meta"><?php echo $esc(bakery_survey_text('survey.responses_so_far', [], 'Answers so far')); ?></div>
    <?php foreach ($responses as $r): ?>
      <div class="stop"><span class="meta"><?php echo $esc($r['action']); ?></span><span class="meta"><?php echo $esc(substr((string)$r['response'], 0, 120)); ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($isManager): ?>
  <form method="post" action="survey.php?t=<?php echo $esc($token); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
    <input type="hidden" name="action" value="close">
    <button type="submit" class="btn warn"><?php echo $esc(bakery_survey_text('survey.close', [], 'Close this survey')); ?></button>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
