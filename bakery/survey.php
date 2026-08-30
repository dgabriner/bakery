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
require_once __DIR__ . '/includes/text_comms.php';

bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);

$user = bakery_current_user();
$isManager = bakery_user_has_role(['administrator', 'manager']);
$token = trim((string)($_REQUEST['t'] ?? ''));

if (!bakery_surveys_ready($db)) {
    bakery_survey_fail(
        (string)bakery_t('survey.unavailable_title', [], 'Surveys not set up here'),
        (string)bakery_t('survey.unavailable_body', [], 'This environment does not have the survey tables yet (migration 061). Ask the administrator to apply database/schema/061_surveys.sql.')
    );
}

$deliveryWeekdays = bakery_survey_delivery_weekdays($db);
$nextDeliveryDate = bakery_survey_next_delivery_date(date('Y-m-d'), $deliveryWeekdays);

try {
    $survey = $token !== '' ? bakery_survey_find_by_token($db, $token) : [];
} catch (Throwable $e) {
    error_log('survey.php lookup: ' . $e->getMessage());
    $survey = [];
    bakery_survey_fail(
        (string)bakery_t('survey.unavailable_title', [], 'Surveys not set up here'),
        (string)bakery_t('survey.unavailable_body', [], 'This environment does not have the survey tables yet (migration 061). Ask the administrator to apply database/schema/061_surveys.sql.')
    );
}

// Logged-in driver (no token): open this driver's next-delivery-day verify.
if (!$survey && $token === '') {
    $selfDriverId = bakery_route_worker_driver_id($db, $user, $nextDeliveryDate);
    if ($selfDriverId <= 0 && !empty($user['driver_id'])) {
        $selfDriverId = (int)$user['driver_id'];
    }
    if ($selfDriverId > 0) {
        try {
            $survey = bakery_survey_ensure_store_verify(
                $db,
                $selfDriverId,
                $nextDeliveryDate,
                (int)($user['id'] ?? 0)
            );
            $token = (string)($survey['token'] ?? '');
        } catch (Throwable $e) {
            error_log('survey.php ensure store-verify: ' . $e->getMessage());
        }
    }
}

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
$surveyDate = (string)($survey['delivery_date'] ?? '');
$verifyDate = $nextDeliveryDate;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $surveyDate) && $surveyDate >= date('Y-m-d')) {
    $verifyDate = $surveyDate;
}

// Drivers may only open their own survey; managers may inspect any.
if (!$isManager && (string)$survey['audience'] === 'driver') {
    try {
        bakery_assert_driver_identity($db, $driverId, $verifyDate);
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
        if (($action === 'close' || $action === 'reopen') && $isManager) {
            bakery_survey_set_status($db, (int)$survey['id'], $action === 'close' ? 'closed' : 'open');
            safe_redirect('text_comms.php?view=surveys&survey=' . ($action === 'close' ? 'closed' : 'reopened'));
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
            $questions = bakery_survey_questions($survey);
            $answered = 0;
            foreach ($questions as $q) {
                $raw = trim((string)($_POST['answer_' . $q['key']] ?? ''));
                if ($raw === '') {
                    continue;
                }
                $label = bakery_survey_answer_label($q, $raw);
                bakery_survey_record_response($db, [
                    'survey_id' => (int)$survey['id'],
                    'action' => 'answer',
                    'question_key' => $q['key'],
                    'respondent' => (string)($user['display_name'] ?? ''),
                    'response' => $label,
                ]);
                $answered++;
            }
            if ($answered === 0) {
                safe_redirect('survey.php?t=' . rawurlencode($token) . '&err=' . rawurlencode((string)bakery_t('survey.err_empty_answer', [], 'Please write an answer first.')));
            }
            safe_redirect('survey.php?t=' . rawurlencode($token) . '&done=' . rawurlencode((string)bakery_t('survey.done_answer', [], 'Answer recorded. Thank you!')));
        }
        if ($action === 'verify_stores') {
            if ($driverId > 0 && !$isManager) {
                bakery_assert_driver_identity($db, $driverId, $verifyDate);
            }
            $verifyData = bakery_survey_store_verify_data($db, $driverId, $verifyDate);
            $postedOn = [];
            if (isset($_POST['store_on']) && is_array($_POST['store_on'])) {
                foreach ($_POST['store_on'] as $rawId) {
                    $postedOn[] = (int)$rawId;
                }
            }
            $choice = bakery_survey_store_verify_collect($postedOn, $verifyData['assigned'], $verifyData['other']);
            $driverName = $verifyData['driver_name'] !== ''
                ? $verifyData['driver_name']
                : (string)($user['display_name'] ?? 'Driver');
            $result = bakery_survey_store_verify_submit($db, [
                'survey_id' => (int)$survey['id'],
                'driver_id' => $driverId,
                'driver_name' => $driverName,
                'delivery_date' => $verifyDate,
                'on' => $choice['on'],
                'off' => $choice['off'],
                'assigned_off_count' => $choice['assigned_off_count'],
                'staff_user_id' => (int)($user['id'] ?? 0),
            ]);
            $done = (string)bakery_t('survey.store_verify_done', [], 'Saved. Headquarters got your list.');
            if (empty($result['sms_ok'])) {
                $done = (string)bakery_t(
                    'survey.store_verify_sms_failed',
                    [],
                    'Saved your list. The text to headquarters did not send — ask the bakery to check Twilio.'
                );
            }
            safe_redirect('survey.php?t=' . rawurlencode($token) . '&done=' . rawurlencode($done));
        }
        safe_redirect('survey.php?t=' . rawurlencode($token) . '&err=' . rawurlencode('unknown_action'));
    } catch (RuntimeException $e) {
        safe_redirect('survey.php?t=' . rawurlencode($token) . '&err=' . rawurlencode($e->getMessage()));
    }
}

$pageTitle = (string)bakery_t('survey.page_title', [], 'Survey');
$data = [];
$routeReview = false;
$questions = bakery_survey_questions($survey);
$surveyKind = (string)($survey['kind'] ?? '');
$showStoreVerify = $driverId > 0 && in_array($surveyKind, ['route_review', 'store_verify'], true);
$storeVerify = ['driver_id' => $driverId, 'driver_name' => '', 'delivery_date' => $verifyDate, 'assigned' => [], 'other' => []];
if ($showStoreVerify) {
    $storeVerify = bakery_survey_store_verify_data($db, $driverId, $verifyDate);
    if ($storeVerify['driver_name'] === '' && !empty($user['display_name'])) {
        $storeVerify['driver_name'] = (string)$user['display_name'];
    }
}
if ($surveyKind === 'route_review') {
    $routeReview = true;
    $data = bakery_survey_route_review_data($db, $driverId, $deliveryDate);
}
$actionLabels = [
    'skip' => 'survey.action_skip',
    'unskip' => 'survey.action_unskip',
    'claim' => 'survey.action_claim',
    'answer' => 'survey.action_answer',
    'reply' => 'survey.action_reply',
    'store_verify' => 'survey.action_store_verify',
];
$responses = [];
$stmt = $db->prepare("SELECT action, question_key, respondent, response, created_at FROM survey_responses WHERE survey_id = ? AND action <> 'sent' ORDER BY id DESC LIMIT 40");
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
  .who { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
  .store { width: 100%; text-align: left; background: #fff; border: 2px solid #d8d0c2; border-radius: 14px; padding: 16px 14px; margin: 0 0 10px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px; -webkit-tap-highlight-color: transparent; }
  .store .name { font-size: 17px; }
  .store .pill { font-size: 13px; font-weight: 700; padding: 5px 10px; border-radius: 999px; background: #efe9df; color: #6b6256; }
  .store.on { border-color: #276b33; background: #e8f3ea; }
  .store.on .pill { background: #276b33; color: #fff; }
  .store input { position: absolute; opacity: 0; pointer-events: none; }
  .submit-bar { position: sticky; bottom: 0; background: #f6f3ee; padding: 10px 0 4px; }
  .submit-bar .btn { width: 100%; padding: 14px 16px; font-size: 16px; }
  .section-label { font-size: 13px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; opacity: .65; margin: 16px 0 8px; }
</style>
</head>
<body>
<main>
  <h1><?php echo $esc($showStoreVerify ? bakery_survey_text('survey.store_verify_title', [], 'Next delivery day') : bakery_survey_text('survey.page_title', [], 'Survey')); ?></h1>
  <?php if ($showStoreVerify): ?>
  <p class="who"><?php echo $esc(bakery_survey_text('survey.store_verify_driver', ['name' => $storeVerify['driver_name'] !== '' ? $storeVerify['driver_name'] : (string)($user['display_name'] ?? '')], 'Driver: :name')); ?></p>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.store_verify_sub', ['date' => $verifyDate], 'Tap the stores you will cover on :date')); ?></p>
  <?php elseif ($routeReview): ?>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.route_review_sub', ['date' => $deliveryDate], 'Route review for :date')); ?></p>
  <?php else: ?>
  <p class="sub"><?php
    $headline = trim((string)($survey['title'] ?? ''));
    if ($headline === '' && count($questions) === 1) {
        $headline = (string)$questions[0]['text'];
    }
    echo $esc($headline !== '' ? $headline : (string)bakery_survey_text('survey.page_title', [], 'Survey'));
    if (count($questions) > 1) {
        echo ' · ' . count($questions) . ' ' . bakery_survey_text('survey.questions_suffix', [], 'questions');
    }
  ?></p>
  <?php endif; ?>
  <?php if ($flash !== ''): ?><div class="flash"><?php echo $esc($flash); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash err"><?php echo $esc($error); ?></div><?php endif; ?>

  <?php if ($showStoreVerify): ?>
    <form method="post" action="survey.php?t=<?php echo $esc($token); ?>" id="storeVerifyForm">
      <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
      <input type="hidden" name="action" value="verify_stores">
      <div class="section-label"><?php echo $esc(bakery_survey_text('survey.store_verify_assigned', ['count' => count($storeVerify['assigned'])], 'Your assigned stores (:count)')); ?></div>
      <?php if ($storeVerify['assigned']): ?>
        <?php foreach ($storeVerify['assigned'] as $store): ?>
        <label class="store on" data-store-toggle>
          <input type="checkbox" name="store_on[]" value="<?php echo (int)$store['id']; ?>" checked>
          <span class="name"><?php echo $esc($store['name']); ?></span>
          <span class="pill" data-on="<?php echo $esc(bakery_survey_text('survey.store_verify_on', [], 'ON')); ?>" data-off="<?php echo $esc(bakery_survey_text('survey.store_verify_off', [], 'OFF')); ?>"><?php echo $esc(bakery_survey_text('survey.store_verify_on', [], 'ON')); ?></span>
        </label>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="meta"><?php echo $esc(bakery_survey_text('survey.store_verify_no_stores', [], 'No assigned stores for this delivery day yet.')); ?></p>
      <?php endif; ?>

      <div class="section-label"><?php echo $esc(bakery_survey_text('survey.store_verify_other', ['count' => count($storeVerify['other'])], 'Other stores (:count)')); ?></div>
      <?php foreach ($storeVerify['other'] as $store): ?>
      <label class="store" data-store-toggle>
        <input type="checkbox" name="store_on[]" value="<?php echo (int)$store['id']; ?>">
        <span class="name"><?php echo $esc($store['name']); ?></span>
        <span class="pill" data-on="<?php echo $esc(bakery_survey_text('survey.store_verify_on', [], 'ON')); ?>" data-off="<?php echo $esc(bakery_survey_text('survey.store_verify_off', [], 'OFF')); ?>"><?php echo $esc(bakery_survey_text('survey.store_verify_off', [], 'OFF')); ?></span>
      </label>
      <?php endforeach; ?>

      <div class="submit-bar">
        <button type="submit" class="btn primary"><?php echo $esc(bakery_survey_text('survey.store_verify_submit', [], 'Send my stores')); ?></button>
      </div>
    </form>
    <script>
    (function () {
      document.querySelectorAll('[data-store-toggle]').forEach(function (card) {
        var box = card.querySelector('input[type="checkbox"]');
        var pill = card.querySelector('.pill');
        if (!box || !pill) return;
        function sync() {
          var on = box.checked;
          card.classList.toggle('on', on);
          pill.textContent = on ? (pill.getAttribute('data-on') || 'ON') : (pill.getAttribute('data-off') || 'OFF');
        }
        box.addEventListener('change', sync);
      });
    })();
    </script>
  <?php endif; ?>

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
  <?php elseif ($surveyKind === 'question'): ?>
    <div class="card">
      <form method="post" action="survey.php?t=<?php echo $esc($token); ?>" style="display:grid;gap:14px">
        <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
        <input type="hidden" name="action" value="answer">
        <?php foreach ($questions as $q): ?>
        <div>
          <div class="name" style="margin-bottom:6px"><?php echo $esc($q['text']); ?></div>
          <?php if ($q['type'] === 'yes_no'): ?>
          <div style="display:flex;gap:10px">
            <label style="font-size:15px"><input type="radio" name="answer_<?php echo $esc($q['key']); ?>" value="yes" required> <?php echo $esc(bakery_survey_text('survey.answer_yes', [], 'Yes')); ?></label>
            <label style="font-size:15px"><input type="radio" name="answer_<?php echo $esc($q['key']); ?>" value="no"> <?php echo $esc(bakery_survey_text('survey.answer_no', [], 'No')); ?></label>
          </div>
          <?php elseif ($q['type'] === 'choice'): ?>
          <div style="display:grid;gap:6px">
            <?php foreach ($q['options'] as $opt): ?>
            <label style="font-size:15px"><input type="radio" name="answer_<?php echo $esc($q['key']); ?>" value="<?php echo $esc($opt); ?>" required> <?php echo $esc($opt); ?></label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <textarea name="answer_<?php echo $esc($q['key']); ?>" placeholder="<?php echo $esc(bakery_survey_text('survey.answer_placeholder', [], 'Type your answer…')); ?>"></textarea>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button type="submit" class="btn primary"><?php echo $esc(bakery_survey_text('survey.answer_send', [], 'Send answer')); ?></button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($responses): ?>
  <div class="card">
    <div class="meta"><?php echo $esc(bakery_survey_text('survey.responses_so_far', [], 'Answers so far')); ?></div>
    <?php foreach ($responses as $r): ?>
      <div class="stop">
        <span class="meta"><?php
          $labelKey = $actionLabels[(string)$r['action']] ?? '';
          echo $esc($labelKey !== '' ? bakery_survey_text($labelKey, [], (string)$r['action']) : (string)$r['action']);
        ?></span>
        <span class="meta"><?php
          $txt = trim((string)$r['response']);
          if (($r['respondent'] ?? '') !== '') {
              $txt = $r['respondent'] . ($txt !== '' ? ': ' . $txt : '');
          }
          echo $esc(mb_substr($txt, 0, 140));
        ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($isManager): ?>
  <form method="post" action="survey.php?t=<?php echo $esc($token); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
    <input type="hidden" name="action" value="<?php echo ((string)$survey['status'] === 'open') ? 'close' : 'reopen'; ?>">
    <button type="submit" class="btn warn"><?php echo $esc(bakery_survey_text(((string)$survey['status'] === 'open') ? 'survey.close' : 'survey.reopen', [], ((string)$survey['status'] === 'open') ? 'Close this survey' : 'Reopen this survey')); ?></button>
  </form>
  <?php endif; ?>
</main>
</body>
</html>
