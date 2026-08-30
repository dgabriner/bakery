<?php
/**
 * Survey landing page for Twilio link-mode surveys (drivers and managers).
 *
 * An open store_verify / route_review token IS the auth — phones can open
 * survey.php?t=TOKEN with no PIN. No token still rides the staff/driver
 * session gate. Skip/claim/answer still require a logged-in role.
 *
 * GET  ?t=TOKEN                 render the survey
 * POST ?t=TOKEN  action=skip|unskip|claim|answer|close|verify_stores
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/surveys.php';
require_once __DIR__ . '/includes/text_comms.php';

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

if (bakery_survey_page_needs_login($token, $survey)) {
    bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
}

$user = bakery_current_user() ?: [];
$isManager = bakery_user_has_role(['administrator', 'manager']);

// Logged-in driver (no token): open this driver's next-delivery-day verify.
if (!$survey && $token === '') {
    $selfDriverId = bakery_route_worker_driver_id($db, $user ?: null, $nextDeliveryDate);
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
$dateParam = trim((string)($_REQUEST['date'] ?? ''));
try {
    $verifyDate = bakery_survey_store_verify_resolve_date(
        $verifyDate,
        $dateParam !== '' ? $dateParam : null
    );
} catch (RuntimeException $e) {
    // Keep the default next-delivery / survey date when the query is junk.
}

// Token-public store_verify / route_review skips identity. Logged-in
// drivers without a public token may only open their own survey.
if (bakery_survey_page_needs_identity($survey) && !$isManager && (string)$survey['audience'] === 'driver') {
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
            bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
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
            bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
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
            bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
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
            bakery_require_role(['administrator', 'manager', 'driver', 'driver_assistant']);
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
            if (bakery_survey_page_needs_identity($survey) && $driverId > 0 && !$isManager) {
                bakery_assert_driver_identity($db, $driverId, $verifyDate);
            }
            $isHqSubmit = ((string)($survey['kind'] ?? '') === 'store_verify' && $driverId <= 0);
            if ($isHqSubmit) {
                $hqGroups = bakery_survey_store_verify_hq_data($db, $verifyDate);
                $postedByDriver = [];
                if (isset($_POST['store_on']) && is_array($_POST['store_on'])) {
                    foreach ($_POST['store_on'] as $rawDriverId => $ids) {
                        if (!is_array($ids)) {
                            continue;
                        }
                        $postedByDriver[(int)$rawDriverId] = array_map('intval', $ids);
                    }
                }
                $moves = [];
                if (isset($_POST['store_move']) && is_array($_POST['store_move'])) {
                    foreach ($_POST['store_move'] as $raw) {
                        if (!is_array($raw)) {
                            continue;
                        }
                        $moves[] = [
                            'store_id' => (int)($raw['store_id'] ?? 0),
                            'to_driver_id' => (int)($raw['to_driver_id'] ?? 0),
                        ];
                    }
                }
                if ($moves !== []) {
                    $postedByDriver = bakery_survey_store_verify_apply_moves($postedByDriver, $moves);
                }
                $choice = bakery_survey_store_verify_collect_hq($postedByDriver, $hqGroups);
                $driverName = 'HQ';
            } else {
                $verifyData = bakery_survey_store_verify_data($db, $driverId, $verifyDate);
                $postedOn = [];
                if (isset($_POST['store_on']) && is_array($_POST['store_on'])) {
                    foreach ($_POST['store_on'] as $rawId) {
                        if (is_array($rawId)) {
                            continue;
                        }
                        $postedOn[] = (int)$rawId;
                    }
                }
                $choice = bakery_survey_store_verify_collect($postedOn, $verifyData['assigned'], $verifyData['other']);
                $driverName = $verifyData['driver_name'] !== ''
                    ? $verifyData['driver_name']
                    : (string)($user['display_name'] ?? 'Driver');
            }
            $result = bakery_survey_store_verify_submit($db, [
                'survey_id' => (int)$survey['id'],
                'driver_id' => $driverId,
                'driver_name' => $driverName,
                'delivery_date' => $verifyDate,
                'on' => $choice['on'],
                'off' => $choice['off'],
                'added' => $choice['added'] ?? [],
                'dropped' => $choice['dropped'] ?? [],
                'assigned_off_count' => $choice['assigned_off_count'],
                'drivers' => $choice['drivers'] ?? [],
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
            safe_redirect(
                'survey.php?t=' . rawurlencode($token)
                . '&date=' . rawurlencode($verifyDate)
                . '&done=' . rawurlencode($done)
            );
        }
        safe_redirect('survey.php?t=' . rawurlencode($token) . '&date=' . rawurlencode($verifyDate) . '&err=' . rawurlencode('unknown_action'));
    } catch (RuntimeException $e) {
        safe_redirect('survey.php?t=' . rawurlencode($token) . '&date=' . rawurlencode($verifyDate) . '&err=' . rawurlencode($e->getMessage()));
    }
}

$pageTitle = (string)bakery_t('survey.page_title', [], 'Survey');
$data = [];
$routeReview = false;
$questions = bakery_survey_questions($survey);
$surveyKind = (string)($survey['kind'] ?? '');
$isHqStoreVerify = $surveyKind === 'store_verify' && $driverId <= 0;
$showStoreVerify = $isHqStoreVerify
    || ($driverId > 0 && in_array($surveyKind, ['route_review', 'store_verify'], true));
$hqGroups = [];
$storeVerify = ['driver_id' => $driverId, 'driver_name' => '', 'delivery_date' => $verifyDate, 'assigned' => [], 'other' => []];
if ($isHqStoreVerify) {
    $hqGroups = bakery_survey_store_verify_hq_data($db, $verifyDate);
} elseif ($showStoreVerify) {
    $storeVerify = bakery_survey_store_verify_data($db, $driverId, $verifyDate);
    if ($storeVerify['driver_name'] === '' && !empty($user['display_name'])) {
        $storeVerify['driver_name'] = (string)$user['display_name'];
    }
}
// Skip/claim need a logged-in role (plan-search asserts identity). Token
// GET still renders store-verify without calling that path.
if ($surveyKind === 'route_review' && $user !== [] && $driverId > 0) {
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
$zoneEmpty = (string)bakery_survey_text('survey.store_verify_no_zone', [], 'No zone');
$onLabel = (string)bakery_survey_text('survey.store_verify_on', [], 'ON');
$offLabel = (string)bakery_survey_text('survey.store_verify_off', [], 'OFF');
$renderStoreCards = static function (
    array $stores,
    string $inputName,
    bool $defaultOn,
    string $onLabel,
    string $offLabel,
    string $zoneEmpty,
    callable $esc
): void {
    $lane = $defaultOn ? 'assigned' : 'other';
    $byZone = bakery_survey_store_verify_group_by_zone($stores, $zoneEmpty);
    foreach ($byZone as $zoneName => $zoneStores) {
        echo '<div class="zone-group" data-zone="' . $esc($zoneName) . '" data-lane="' . $esc($lane) . '">';
        echo '<div class="zone-label">' . $esc($zoneName) . '</div>';
        foreach ($zoneStores as $store) {
            $checked = $defaultOn ? ' checked' : '';
            $onClass = $defaultOn ? ' on' : '';
            $pill = $defaultOn ? $onLabel : $offLabel;
            $zoneKey = bakery_survey_store_verify_zone_key($store, $zoneEmpty);
            echo '<label class="store' . $onClass . '" data-store-toggle data-store-id="' . (int)$store['id'] . '" data-zone="' . $esc($zoneKey) . '">';
            echo '<input type="checkbox" name="' . $esc($inputName) . '" value="' . (int)$store['id'] . '"' . $checked . '>';
            echo '<span class="name">' . $esc($store['name']) . '</span>';
            echo '<span class="pill" data-on="' . $esc($onLabel) . '" data-off="' . $esc($offLabel) . '">' . $esc($pill) . '</span>';
            echo '</label>';
        }
        echo '</div>';
    }
};
$driverLinkTokens = [];
if ($isHqStoreVerify) {
    foreach ($hqGroups as $group) {
        $gid = (int)($group['driver_id'] ?? 0);
        if ($gid <= 0) {
            continue;
        }
        try {
            $linkSurvey = bakery_survey_ensure_store_verify($db, $gid, $verifyDate, (int)($user['id'] ?? 0));
            $driverLinkTokens[$gid] = (string)($linkSurvey['token'] ?? '');
        } catch (Throwable $e) {
            error_log('survey.php driver link token: ' . $e->getMessage());
        }
    }
}
$selfUrl = 'survey.php?t=' . rawurlencode($token) . '&date=' . rawurlencode($verifyDate);
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
  .ghost { background: #efe9df; color: #24303e; }
  textarea { width: 100%; box-sizing: border-box; min-height: 84px; font: inherit; padding: 9px; border-radius: 9px; border: 1px solid #d8d0c2; }
  .flash { background: #e8f3ea; border: 1px solid #bcd9c2; color: #276b33; padding: 9px 12px; border-radius: 9px; margin-bottom: 10px; font-size: 14px; }
  .flash.err { background: #fdeaea; border-color: #eec3c3; color: #a33; }
  form.inline { display: contents; }
  .who { font-size: 16px; font-weight: 700; margin: 0 0 4px; }
  .store { width: 100%; text-align: left; background: #fff; border: 2px solid #d8d0c2; border-radius: 14px; padding: 16px 14px; margin: 0 0 10px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 10px; -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
  .store .name { font-size: 17px; }
  .store .pill { font-size: 13px; font-weight: 700; padding: 5px 10px; border-radius: 999px; background: #efe9df; color: #6b6256; flex-shrink: 0; }
  .store.on { border-color: #276b33; background: #e8f3ea; }
  .store.on .pill { background: #276b33; color: #fff; }
  .store.selected { outline: 3px solid #2c5aa0; }
  .store input { position: absolute; opacity: 0; pointer-events: none; }
  .submit-bar { position: sticky; bottom: 0; background: #f6f3ee; padding: 10px 0 4px; z-index: 5; }
  .submit-bar .btn { width: 100%; padding: 14px 16px; font-size: 16px; }
  .section-label { font-size: 13px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; opacity: .65; margin: 16px 0 8px; }
  .zone-label { font-size: 12px; font-weight: 700; color: #6b6256; margin: 10px 0 6px; padding-left: 2px; }
  .zone-group { margin: 0 0 4px; }
  .lane { margin: 0 0 4px; }
  .date-bar { display: flex; gap: 8px; align-items: end; margin: 0 0 14px; flex-wrap: wrap; }
  .date-bar label { font-size: 12px; font-weight: 700; opacity: .7; display: grid; gap: 4px; flex: 1; min-width: 140px; }
  .date-bar input[type="date"] { font: inherit; padding: 10px 12px; border-radius: 10px; border: 1px solid #d8d0c2; background: #fff; }
  .toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 12px; }
  .toolbar .btn { font-size: 13px; padding: 8px 11px; }
  .toolbar .btn.active { background: #2c5aa0; color: #fff; }
  .driver-block { background: #fff; border: 1px solid #e4ddd2; border-radius: 14px; margin: 0 0 12px; overflow: hidden; }
  .driver-block > summary { list-style: none; cursor: pointer; padding: 14px 14px; font-weight: 700; font-size: 16px; display: flex; justify-content: space-between; align-items: center; gap: 10px; -webkit-tap-highlight-color: transparent; }
  .driver-block > summary::-webkit-details-marker { display: none; }
  .driver-block > summary::after { content: "▾"; opacity: .45; font-size: 14px; }
  .driver-block[open] > summary::after { content: "▴"; }
  .driver-block .body { padding: 0 12px 12px; }
  .count-chip { font-size: 12px; font-weight: 700; background: #e8f3ea; color: #276b33; padding: 4px 8px; border-radius: 999px; }
  .links-card a, .links-card button.linkish { font: inherit; color: #2c5aa0; background: none; border: none; padding: 0; cursor: pointer; font-weight: 600; text-align: left; }
  .links-card .row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid #efe9df; align-items: center; }
  .links-card .row:last-child { border-bottom: none; }
  .move-panel { display: none; background: #eef3fa; border: 1px solid #c9d7ea; border-radius: 12px; padding: 10px 12px; margin: 0 0 12px; }
  body.move-mode .move-panel { display: block; }
  .move-panel select { width: 100%; font: inherit; padding: 10px; border-radius: 10px; border: 1px solid #d8d0c2; margin-top: 6px; }
  .lang-row { display: flex; justify-content: flex-end; margin: 0 0 10px; }
  .bakery-lang-switch--inline { background: rgba(0,0,0,.06); border-radius: 999px; display: inline-flex; gap: 2px; padding: 3px; }
  .bakery-lang-switch--inline .bakery-lang-switch__btn { border-radius: 999px; color: #6b6256; font-size: .82rem; padding: 6px 12px; text-decoration: none; }
  .bakery-lang-switch--inline .bakery-lang-switch__btn--active { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.12); color: #24303e; font-weight: 600; }
</style>
</head>
<body>
<main>
  <div class="lang-row"><?php $langSwitchVariant = 'inline'; require __DIR__ . '/includes/language_switch.php'; ?></div>
  <h1><?php
    if ($isHqStoreVerify) {
        echo $esc(bakery_survey_text('survey.store_verify_hq_title', [], 'All drivers — next delivery day'));
    } elseif ($showStoreVerify) {
        echo $esc(bakery_survey_text('survey.store_verify_title', [], 'Next delivery day'));
    } else {
        echo $esc(bakery_survey_text('survey.page_title', [], 'Survey'));
    }
  ?></h1>
  <?php if ($isHqStoreVerify): ?>
  <p class="who"><?php echo $esc(bakery_survey_text('survey.store_verify_all_drivers', [], 'Every active driver. Assigned stores start ON; other stores start OFF. One send texts headquarters.')); ?></p>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.store_verify_sub', ['date' => $verifyDate], 'Tap the stores you will cover on :date')); ?></p>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.store_verify_manager_hint', [], 'Use ON/OFF for the driver you are editing, or Move stores to hand a stop to someone else.')); ?></p>
  <?php elseif ($showStoreVerify): ?>
  <p class="who"><?php echo $esc(bakery_survey_text('survey.store_verify_driver', ['name' => $storeVerify['driver_name'] !== '' ? $storeVerify['driver_name'] : (string)($user['display_name'] ?? '')], 'Driver: :name')); ?></p>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.store_verify_sub', ['date' => $verifyDate], 'Tap the stores you will cover on :date')); ?></p>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.store_verify_driver_hint', [], 'Tap to add or drop stores on your list. No move between drivers here.')); ?></p>
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
  <form class="date-bar" method="get" action="survey.php">
    <input type="hidden" name="t" value="<?php echo $esc($token); ?>">
    <label><?php echo $esc(bakery_survey_text('survey.store_verify_date', [], 'Delivery day')); ?>
      <input type="date" name="date" value="<?php echo $esc($verifyDate); ?>" required>
    </label>
    <button type="submit" class="btn ghost"><?php echo $esc(bakery_survey_text('survey.store_verify_date_go', [], 'Show day')); ?></button>
  </form>
  <?php endif; ?>

  <?php if ($isHqStoreVerify): ?>
    <div class="card links-card">
      <div class="meta"><?php echo $esc(bakery_survey_text('survey.store_verify_links', [], 'Share links (no text required)')); ?></div>
      <div class="row">
        <span><?php echo $esc(bakery_survey_text('survey.store_verify_link_manager', [], 'Manager — all drivers')); ?></span>
        <button type="button" class="linkish" data-copy-url="<?php echo $esc($selfUrl); ?>"><?php echo $esc(bakery_survey_text('survey.store_verify_copy_link', [], 'Copy link')); ?></button>
      </div>
      <?php foreach ($hqGroups as $group): ?>
        <?php
          $gid = (int)($group['driver_id'] ?? 0);
          $gname = (string)($group['driver_name'] ?? '');
          $dtok = (string)($driverLinkTokens[$gid] ?? '');
          if ($gid <= 0 || $dtok === '') {
              continue;
          }
          $durl = 'survey.php?t=' . rawurlencode($dtok) . '&date=' . rawurlencode($verifyDate);
        ?>
        <div class="row">
          <span><?php echo $esc($gname); ?></span>
          <button type="button" class="linkish" data-copy-url="<?php echo $esc($durl); ?>"><?php echo $esc(bakery_survey_text('survey.store_verify_copy_link', [], 'Copy link')); ?></button>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="toolbar">
      <button type="button" class="btn ghost" id="moveModeBtn" aria-pressed="false"><?php echo $esc(bakery_survey_text('survey.store_verify_move_mode', [], 'Move stores')); ?></button>
    </div>
    <div class="move-panel" id="movePanel">
      <div class="meta"><?php echo $esc(bakery_survey_text('survey.store_verify_move_help', [], 'Tap a store, then choose who should cover it. This only updates this survey list.')); ?></div>
      <label class="meta" for="moveTarget"><?php echo $esc(bakery_survey_text('survey.store_verify_move_to', [], 'Move to driver')); ?></label>
      <select id="moveTarget">
        <option value=""><?php echo $esc(bakery_survey_text('survey.store_verify_move_pick', [], 'Choose driver…')); ?></option>
        <?php foreach ($hqGroups as $group): ?>
          <option value="<?php echo (int)$group['driver_id']; ?>"><?php echo $esc((string)$group['driver_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <form method="post" action="survey.php?t=<?php echo $esc($token); ?>&amp;date=<?php echo $esc($verifyDate); ?>" id="storeVerifyForm">
      <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
      <input type="hidden" name="action" value="verify_stores">
      <input type="hidden" name="date" value="<?php echo $esc($verifyDate); ?>">
      <div id="moveFields"></div>
      <?php foreach ($hqGroups as $gi => $group): ?>
        <?php
          $gid = (int)($group['driver_id'] ?? 0);
          $gname = (string)($group['driver_name'] ?? '');
          $onCount = count($group['assigned'] ?? []);
        ?>
        <details class="driver-block" data-driver-block="<?php echo $gid; ?>"<?php echo $gi === 0 ? ' open' : ''; ?>>
          <summary>
            <span><?php echo $esc($gname); ?></span>
            <span class="count-chip" data-on-count><?php echo (int)$onCount; ?> <?php echo $esc(bakery_survey_text('survey.store_verify_on', [], 'ON')); ?></span>
          </summary>
          <div class="body" data-driver-body="<?php echo $gid; ?>">
            <div class="lane" data-lane-root="assigned">
              <div class="section-label"><?php echo $esc(bakery_survey_text('survey.store_verify_assigned', ['count' => count($group['assigned'] ?? [])], 'Your assigned stores (:count)')); ?></div>
              <?php if (!empty($group['assigned'])): ?>
                <?php $renderStoreCards($group['assigned'], 'store_on[' . $gid . '][]', true, $onLabel, $offLabel, $zoneEmpty, $esc); ?>
              <?php else: ?>
                <p class="meta" data-empty-assigned><?php echo $esc(bakery_survey_text('survey.store_verify_no_stores', [], 'No assigned stores for this delivery day yet.')); ?></p>
              <?php endif; ?>
            </div>
            <div class="lane" data-lane-root="other">
              <div class="section-label"><?php echo $esc(bakery_survey_text('survey.store_verify_other', ['count' => count($group['other'] ?? [])], 'Other stores (:count)')); ?></div>
              <?php $renderStoreCards($group['other'] ?? [], 'store_on[' . $gid . '][]', false, $onLabel, $offLabel, $zoneEmpty, $esc); ?>
            </div>
          </div>
        </details>
      <?php endforeach; ?>
      <div class="submit-bar">
        <button type="submit" class="btn primary"><?php echo $esc(bakery_survey_text('survey.store_verify_submit', [], 'Send my stores')); ?></button>
      </div>
    </form>
  <?php elseif ($showStoreVerify): ?>
    <form method="post" action="survey.php?t=<?php echo $esc($token); ?>&amp;date=<?php echo $esc($verifyDate); ?>" id="storeVerifyForm">
      <input type="hidden" name="csrf_token" value="<?php echo $esc(bakery_csrf_token()); ?>">
      <input type="hidden" name="action" value="verify_stores">
      <input type="hidden" name="date" value="<?php echo $esc($verifyDate); ?>">
      <div class="section-label"><?php echo $esc(bakery_survey_text('survey.store_verify_assigned', ['count' => count($storeVerify['assigned'])], 'Your assigned stores (:count)')); ?></div>
      <?php if ($storeVerify['assigned']): ?>
        <?php $renderStoreCards($storeVerify['assigned'], 'store_on[]', true, $onLabel, $offLabel, $zoneEmpty, $esc); ?>
      <?php else: ?>
        <p class="meta"><?php echo $esc(bakery_survey_text('survey.store_verify_no_stores', [], 'No assigned stores for this delivery day yet.')); ?></p>
      <?php endif; ?>
      <div class="section-label"><?php echo $esc(bakery_survey_text('survey.store_verify_other', ['count' => count($storeVerify['other'])], 'Other stores (:count)')); ?></div>
      <?php $renderStoreCards($storeVerify['other'], 'store_on[]', false, $onLabel, $offLabel, $zoneEmpty, $esc); ?>
      <div class="submit-bar">
        <button type="submit" class="btn primary"><?php echo $esc(bakery_survey_text('survey.store_verify_submit', [], 'Send my stores')); ?></button>
      </div>
    </form>
  <?php endif; ?>
  <?php if ($showStoreVerify): ?>
    <script>
    (function () {
      function syncCard(card) {
        var box = card.querySelector('input[type="checkbox"]');
        var pill = card.querySelector('.pill');
        if (!box || !pill) return;
        var on = box.checked;
        card.classList.toggle('on', on);
        pill.textContent = on ? (pill.getAttribute('data-on') || 'ON') : (pill.getAttribute('data-off') || 'OFF');
      }
      function refreshCounts() {
        document.querySelectorAll('[data-driver-block]').forEach(function (block) {
          var chip = block.querySelector('[data-on-count]');
          if (!chip) return;
          var n = block.querySelectorAll('input[type="checkbox"]:checked').length;
          chip.textContent = n + ' ON';
        });
      }
      document.querySelectorAll('[data-store-toggle]').forEach(function (card) {
        var box = card.querySelector('input[type="checkbox"]');
        if (!box) return;
        box.addEventListener('change', function () { syncCard(card); refreshCounts(); });
      });
      document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var rel = btn.getAttribute('data-copy-url') || '';
          var url = rel.indexOf('http') === 0 ? rel : (window.location.origin.replace(/\/$/, '') + '/' + rel.replace(/^\//, ''));
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
              btn.textContent = '✓';
              setTimeout(function () { btn.textContent = btn.getAttribute('data-label') || 'Copy link'; }, 1200);
            }).catch(function () { window.prompt('Copy link', url); });
          } else {
            window.prompt('Copy link', url);
          }
        });
        btn.setAttribute('data-label', btn.textContent);
      });
      <?php if ($isHqStoreVerify): ?>
      var emptyZoneLabel = <?php echo json_encode($zoneEmpty, JSON_UNESCAPED_UNICODE); ?>;
      function cleanupEmptyZoneGroups(root) {
        if (!root) return;
        root.querySelectorAll('.zone-group').forEach(function (group) {
          if (!group.querySelector('[data-store-toggle]')) {
            group.remove();
          }
        });
      }
      function ensureZoneGroup(body, zone, lane) {
        lane = lane || 'assigned';
        zone = zone || emptyZoneLabel;
        var laneEl = body.querySelector('[data-lane-root="' + lane + '"]') || body;
        var emptyNote = laneEl.querySelector('[data-empty-assigned]');
        if (emptyNote) emptyNote.remove();
        var group = null;
        laneEl.querySelectorAll('.zone-group').forEach(function (candidate) {
          if (!group && candidate.getAttribute('data-zone') === zone) {
            group = candidate;
          }
        });
        if (!group) {
          group = document.createElement('div');
          group.className = 'zone-group';
          group.setAttribute('data-zone', zone);
          group.setAttribute('data-lane', lane);
          var label = document.createElement('div');
          label.className = 'zone-label';
          label.textContent = zone;
          group.appendChild(label);
          laneEl.appendChild(group);
        }
        return group;
      }
      var moveBtn = document.getElementById('moveModeBtn');
      var moveTarget = document.getElementById('moveTarget');
      var moveFields = document.getElementById('moveFields');
      var selectedCard = null;
      var moveIndex = 0;
      if (moveBtn) {
        moveBtn.addEventListener('click', function () {
          var on = document.body.classList.toggle('move-mode');
          moveBtn.classList.toggle('active', on);
          moveBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
          if (!on && selectedCard) {
            selectedCard.classList.remove('selected');
            selectedCard = null;
          }
        });
      }
      document.querySelectorAll('#storeVerifyForm [data-store-toggle]').forEach(function (card) {
        card.addEventListener('click', function (ev) {
          if (!document.body.classList.contains('move-mode')) return;
          ev.preventDefault();
          ev.stopPropagation();
          if (selectedCard) selectedCard.classList.remove('selected');
          selectedCard = card;
          card.classList.add('selected');
          if (moveTarget && moveTarget.value) {
            applyMove();
          }
        }, true);
      });
      function applyMove() {
        if (!selectedCard || !moveTarget || !moveTarget.value) return;
        var toDriver = moveTarget.value;
        var box = selectedCard.querySelector('input[type="checkbox"]');
        var storeId = box ? box.value : selectedCard.getAttribute('data-store-id');
        if (!box || !storeId) return;
        var zone = selectedCard.getAttribute('data-zone') || emptyZoneLabel;
        var sourceBody = selectedCard.closest('[data-driver-body]');

        document.querySelectorAll('#storeVerifyForm [data-store-toggle]').forEach(function (other) {
          if (other === selectedCard) return;
          var otherBox = other.querySelector('input[type="checkbox"]');
          if (otherBox && otherBox.value === storeId) {
            var parentGroup = other.closest('.zone-group');
            other.remove();
            if (parentGroup && !parentGroup.querySelector('[data-store-toggle]')) {
              parentGroup.remove();
            }
          }
        });

        box.name = 'store_on[' + toDriver + '][]';
        box.checked = true;
        syncCard(selectedCard);
        selectedCard.setAttribute('data-zone', zone);

        var body = document.querySelector('[data-driver-body="' + toDriver + '"]');
        if (body) {
          var group = ensureZoneGroup(body, zone, 'assigned');
          group.appendChild(selectedCard);
          var details = body.closest('details');
          if (details) details.open = true;
        }
        cleanupEmptyZoneGroups(sourceBody);

        if (moveFields) {
          var sid = document.createElement('input');
          sid.type = 'hidden';
          sid.name = 'store_move[' + moveIndex + '][store_id]';
          sid.value = storeId;
          var tid = document.createElement('input');
          tid.type = 'hidden';
          tid.name = 'store_move[' + moveIndex + '][to_driver_id]';
          tid.value = toDriver;
          moveFields.appendChild(sid);
          moveFields.appendChild(tid);
          moveIndex += 1;
        }
        selectedCard.classList.remove('selected');
        selectedCard = null;
        refreshCounts();
      }
      if (moveTarget) {
        moveTarget.addEventListener('change', function () {
          if (selectedCard) applyMove();
        });
      }
      <?php endif; ?>
      refreshCounts();
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
