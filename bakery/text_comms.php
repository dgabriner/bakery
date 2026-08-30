<?php
/**
 * Texting Command Center — every SMS on one screen, several views.
 *
 * Inbox (customer / test / general), activity feed, delivery health, and ops.
 * Sends go through bakery_text_send() only. The API stays read-only.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/text_comms.php';
require_once __DIR__ . '/includes/text_comms_media.php';

bakery_require_role(['administrator', 'manager']);

$user = bakery_current_user();
$today = date('Y-m-d');

$view = strtolower(trim((string)($_REQUEST['view'] ?? 'inbox')));
if (!in_array($view, bakery_text_views(), true)) {
    $view = 'inbox';
}
$laneFilter = strtolower(trim((string)($_REQUEST['lane'] ?? 'all')));
if ($laneFilter !== 'all' && !in_array($laneFilter, bakery_text_lanes(), true)) {
    $laneFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'sync') {
    bakery_require_csrf();
    $days = max(1, min(180, (int)($_POST['days'] ?? 30)));
    $returnView = in_array($view, bakery_text_views(), true) ? $view : 'inbox';
    if (!bakery_text_messages_ready($db)) {
        safe_redirect('text_comms.php?error=unavailable&view=' . urlencode($returnView));
    }
    if (!twilio_is_configured()) {
        safe_redirect('text_comms.php?sync_error=not_configured&view=' . urlencode($returnView));
    }
    $result = bakery_text_sync_history($db, $days);
    safe_redirect('text_comms.php?sync=1'
        . '&found=' . (int)$result['found']
        . '&inserted=' . (int)$result['inserted']
        . '&updated=' . (int)$result['updated']
        . '&skipped=' . (int)$result['skipped']
        . '&view=' . urlencode($returnView));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'send') {
    bakery_require_csrf();

    $manualTo = trim((string)($_POST['to_manual'] ?? ''));
    $customerChoice = trim((string)($_POST['to_customer'] ?? ''));
    $to = $manualTo !== '' ? $manualTo : $customerChoice;
    $body = trim((string)($_POST['body'] ?? ''));
    if (mb_strlen($body) > 1600) {
        $body = mb_substr($body, 0, 1600);
    }
    $date = trim((string)($_POST['date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $today;
    }
    $purpose = strtolower(trim((string)($_POST['purpose'] ?? 'customer')));
    $contextType = bakery_text_context_from_purpose($purpose);
    $returnPhone = bakery_text_normalize_phone($to);
    $returnView = in_array($view, bakery_text_views(), true) ? $view : 'inbox';

    if (!bakery_text_messages_ready($db)) {
        safe_redirect('text_comms.php?error=unavailable&view=' . urlencode($returnView));
    }

    $result = bakery_text_send($db, $to, $body, [
        'staff_user_id' => (int)($user['id'] ?? 0),
        'context_type' => $contextType,
        'operating_date' => $date,
    ]);

    $qs = 'date=' . urlencode($date)
        . '&phone=' . urlencode($returnPhone)
        . '&view=' . urlencode($returnView)
        . '&lane=' . urlencode($laneFilter);
    if ($result['ok']) {
        safe_redirect('text_comms.php?' . $qs . '&sent=1');
    }
    if (!empty($result['recorded_only']) || !empty($result['recorded_only'])) {
        safe_redirect('text_comms.php?' . $qs . '&recorded=1');
    }
    $errorCode = 'send_failed';
    if (in_array($result['error'], ['missing_to', 'missing_to'], true)) {
        $errorCode = 'missing_to';
    } elseif (in_array($result['error'], ['missing_body', 'missing_body'], true)) {
        $errorCode = 'missing_body';
    }
    safe_redirect('text_comms.php?' . $qs . '&error=' . $errorCode);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'survey_send') {
    bakery_require_csrf();

    $returnView = 'surveys';
    if (!bakery_text_messages_ready($db)) {
        safe_redirect('text_comms.php?error=unavailable&view=' . urlencode($returnView));
    }

    require_once __DIR__ . '/includes/surveys.php';
    if (!bakery_surveys_ready($db)) {
        safe_redirect('text_comms.php?error=survey_tables&view=' . urlencode($returnView));
    }

    try {
        $kind = (string)($_POST['survey_kind'] ?? 'route_review');
        $driverId = (int)($_POST['driver_id'] ?? 0);
        $deliveryDate = trim((string)($_POST['date'] ?? ''));
        if ($kind === 'store_verify' && $driverId <= 0) {
            $survey = bakery_survey_ensure_store_verify(
                $db,
                0,
                $deliveryDate,
                (int)($user['id'] ?? 0)
            );
        } else {
            $survey = bakery_survey_create($db, [
                'mode' => (string)($_POST['survey_mode'] ?? 'link'),
                'kind' => $kind,
                'audience' => (string)($_POST['survey_audience'] ?? 'driver'),
                'driver_id' => $driverId,
                'target_phone' => trim((string)($_POST['to_manual'] ?? '')),
                'question' => (string)($_POST['question'] ?? ''),
                'title' => trim((string)($_POST['title'] ?? '')),
                'questions' => isset($_POST['q_text']) && is_array($_POST['q_text']) ? bakery_survey_collect_questions_from_post($_POST) : [],
                'delivery_date' => $deliveryDate,
                'created_by' => (int)($user['id'] ?? 0),
            ]);
        }
        $result = bakery_survey_send($db, $survey, (int)($user['id'] ?? 0));
        $flag = !empty($result['send']['ok']) ? 'sent' : (!empty($result['send']['recorded_only']) ? 'recorded' : 'error');
        safe_redirect('text_comms.php?view=surveys&survey=' . urlencode($flag)
            . '&token=' . urlencode((string)$survey['token']));
    } catch (Throwable $e) {
        error_log('survey_send: ' . $e->getMessage());
        safe_redirect('text_comms.php?view=surveys&survey=invalid&reason=' . urlencode($e->getMessage()));
    }
}

$page_title = (string)bakery_t('page.text_comms');

$date = trim((string)($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today;
}
$selectedPhone = trim((string)($_GET['phone'] ?? ''));

$tablesReady = bakery_text_messages_ready($db);
$liveReady = twilio_is_configured();
$credsSane = twilio_credentials_look_sane();

$summary = $tablesReady
    ? bakery_text_summary($db, $date)
    : [
        'available' => false,
        'counts' => ['outbound' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'logged' => 0, 'received' => 0, 'unread' => 0],
        'lanes' => ['customer' => 0, 'test' => 0, 'general' => 0],
    ];

$conversations = $tablesReady ? bakery_text_conversations($db, 14)['conversations'] : [];
if ($laneFilter !== 'all') {
    $conversations = array_values(array_filter($conversations, static function (array $c) use ($laneFilter): bool {
        return (string)($c['lane'] ?? 'general') === $laneFilter;
    }));
}

$thread = [];
if ($tablesReady && $selectedPhone !== '') {
    $threadData = bakery_text_thread($db, $selectedPhone, true);
    $thread = $threadData['messages'];
    if (count($thread) > 200) {
        $thread = array_slice($thread, -200);
    }
    $selectedPhone = bakery_text_normalize_phone($selectedPhone);
}

$feed = ($tablesReady && $view === 'feed') ? bakery_text_feed($db, 14, 200)['messages'] : [];
$delivery = ($tablesReady && $view === 'delivery')
    ? bakery_text_delivery($db, 14)
    : ['failed' => [], 'in_flight' => [], 'logged' => []];
$ops = ($tablesReady && $view === 'ops')
    ? bakery_text_ops_snapshot($db, $date)
    : ['lanes' => ['customer' => 0, 'test' => 0, 'general' => 0], 'lanes_window' => ['customer' => 0, 'test' => 0, 'general' => 0], 'contexts' => [], 'from_number' => '', 'live' => false];

$surveysReady = false;
$surveyRows = [];
$driverChoices = [];
$surveyDetail = null;
$surveyDetailRow = null;
$surveyComposerDate = $date;
if ($view === 'surveys') {
    require_once __DIR__ . '/includes/surveys.php';
    $surveysReady = bakery_surveys_ready($db);
    if (function_exists('bakery_survey_next_delivery_date')) {
        $weekdays = function_exists('bakery_survey_delivery_weekdays')
            ? bakery_survey_delivery_weekdays($db)
            : [1, 2, 3, 4, 5, 6];
        $surveyComposerDate = bakery_survey_next_delivery_date($today, $weekdays);
    }
    if ($surveysReady) {
        $surveyRows = $db->query(
            'SELECT s.*,
                (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id AND sr.action <> \'sent\') AS response_count
             FROM surveys s ORDER BY s.id DESC LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);
        $driverChoices = bakery_survey_driver_choices($db);

        if ((string)($_GET['sid'] ?? '') !== '') {
            $sid = (int)$_GET['sid'];
            foreach ($surveyRows as $row) {
                if ((int)$row['id'] === $sid) {
                    $surveyDetailRow = $row;
                    break;
                }
            }
            if ($surveyDetailRow) {
                $surveyDetail = bakery_survey_results($db, $sid);
            }
        }
    }
}

$composeCustomers = [];
try {
    $stmt = $db->query(
        "SELECT id, name, COALESCE(NULLIF(phone,''), NULLIF(portal_phone,''), NULLIF(delivery_contact_phone,''), NULLIF(ordering_contact_phone,'')) AS phone
         FROM customers
         WHERE is_active = 1
           AND COALESCE(NULLIF(phone,''), NULLIF(portal_phone,''), NULLIF(delivery_contact_phone,''), NULLIF(ordering_contact_phone,'')) <> ''
         ORDER BY name"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $normalized = bakery_text_normalize_phone((string)$row['phone']);
        if ($normalized !== '') {
            $composeCustomers[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'phone' => $normalized];
        }
    }
} catch (Throwable $e) {
    error_log('text comms compose targets: ' . $e->getMessage());
}
$selectedCustomerId = 0;
foreach ($composeCustomers as $c) {
    if ($selectedPhone !== '' && bakery_text_phone_tail($c['phone']) === bakery_text_phone_tail($selectedPhone)) {
        $selectedCustomerId = $c['id'];
        break;
    }
}

$banner = '';
if (isset($_GET['sync'])) {
    $syncMsg = (string)bakery_t('texts.sync_done', [
        'found' => (string)max(0, (int)($_GET['found'] ?? 0)),
        'inserted' => (string)max(0, (int)($_GET['inserted'] ?? 0)),
        'updated' => (string)max(0, (int)($_GET['updated'] ?? 0)),
    ]);
    $banner = '<div class="tc-banner tc-banner-ok">' . htmlspecialchars($syncMsg, ENT_QUOTES, 'UTF-8') . '</div>';
} elseif (isset($_GET['sync_error'])) {
    $banner = '<div class="tc-banner tc-banner-error">' . htmlspecialchars((string)bakery_t('texts.sync_not_configured'), ENT_QUOTES, 'UTF-8') . '</div>';
} elseif (isset($_GET['sent'])) {
    $banner = '<div class="tc-banner tc-banner-ok">' . htmlspecialchars((string)bakery_t('texts.sent_ok'), ENT_QUOTES, 'UTF-8') . '</div>';
} elseif (isset($_GET['recorded'])) {
    $banner = '<div class="tc-banner tc-banner-warn">' . htmlspecialchars((string)bakery_t('texts.recorded_only'), ENT_QUOTES, 'UTF-8') . '</div>';
} elseif (isset($_GET['error'])) {
    $errorKeyMap = [
        'missing_to' => 'texts.error_missing_to',
        'missing_body' => 'texts.error_missing_body',
        'unavailable' => 'texts.unavailable_table',
        'send_failed' => 'texts.send_failed',
        'survey_tables' => 'texts.surveys_unavailable',
    ];
    $errorKey = $errorKeyMap[(string)$_GET['error']] ?? 'texts.send_failed';
    $banner = '<div class="tc-banner tc-banner-error">' . htmlspecialchars((string)bakery_t($errorKey), ENT_QUOTES, 'UTF-8') . '</div>';
}

if (isset($_GET['survey'])) {
    $surveyFlag = (string)$_GET['survey'];
    if ($surveyFlag === 'sent') {
        $banner .= '<div class="tc-banner tc-banner-ok">' . htmlspecialchars((string)bakery_t('texts.survey_sent_ok'), ENT_QUOTES, 'UTF-8') . '</div>';
    } elseif ($surveyFlag === 'recorded') {
        $banner .= '<div class="tc-banner tc-banner-warn">' . htmlspecialchars((string)bakery_t('texts.survey_sent_recorded'), ENT_QUOTES, 'UTF-8') . '</div>';
    } elseif ($surveyFlag === 'closed') {
        $banner .= '<div class="tc-banner tc-banner-ok">' . htmlspecialchars((string)bakery_t('texts.survey_closed_ok'), ENT_QUOTES, 'UTF-8') . '</div>';
    } elseif ($surveyFlag === 'invalid') {
        $reason = trim((string)($_GET['reason'] ?? ''));
        $msg = (string)bakery_t('texts.survey_send_invalid');
        if ($reason !== '') {
            $msg .= ' (' . $reason . ')';
        }
        $banner .= '<div class="tc-banner tc-banner-error">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

function bakery_text_status_label_key(string $direction, string $status): string
{
    if ($direction === 'inbound') {
        return 'texts.status_received';
    }
    switch ($status) {
        case 'delivered': return 'texts.status_delivered';
        case 'failed':
        case 'undelivered': return 'texts.status_failed';
        case 'logged': return 'texts.status_logged';
        default: return 'texts.status_sent';
    }
}

/** Render stored MMS media for a ledger row: inline thumbnails + full-size links. */
function bakery_text_media_thumbs(array $msg): string
{
    $id = (int)($msg['id'] ?? 0);
    if ($id <= 0 || !defined('BASE_URL')) {
        return '';
    }
    $media = bakery_text_media_decode((string)($msg['media_json'] ?? ''));
    if ($media === []) {
        // Row knows media exists (e.g. synced before download finished) but no
        // local files yet — say so instead of pretending.
        if ((int)($msg['media_count'] ?? 0) > 0) {
            return '<div class="tc-media"><span class="tc-hint">'
                . htmlspecialchars((string)bakery_t('texts.media_pending'), ENT_QUOTES, 'UTF-8')
                . '</span></div>';
        }
        return '';
    }
    $out = '';
    foreach ($media as $i => $m) {
        $url = htmlspecialchars(BASE_URL . 'text_media.php?id=' . $id . '&i=' . (int)$i, ENT_QUOTES, 'UTF-8');
        $isImage = strpos((string)($m['content_type'] ?? ''), 'image/') === 0;
        if ($isImage) {
            $out .= '<a class="tc-thumb" href="' . $url . '" target="_blank" rel="noopener">'
                . '<img src="' . $url . '" alt="' . htmlspecialchars((string)bakery_t('texts.media_image'), ENT_QUOTES, 'UTF-8') . '" loading="lazy"></a>';
        } else {
            $name = basename((string)($m['path'] ?? 'file'));
            $out .= '<a class="tc-thumb tc-thumb-file" href="' . $url . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</a>';
        }
    }
    return $out !== '' ? '<div class="tc-media">' . $out . '</div>' : '';
}

function bakery_text_cc_qs(string $date, string $view, string $lane, string $phone = ''): string
{
    $qs = 'date=' . urlencode($date) . '&view=' . urlencode($view) . '&lane=' . urlencode($lane);
    if ($phone !== '') {
        $qs .= '&phone=' . urlencode($phone);
    }
    return $qs;
}

function bakery_text_lane_label_key(string $lane): string
{
    switch ($lane) {
        case 'customer': return 'texts.lane_customer';
        case 'test': return 'texts.lane_test';
        default: return 'texts.lane_general';
    }
}

function bakery_text_render_compose(string $date, string $view, string $laneFilter, string $selectedPhone, array $composeCustomers, bool $liveReady, bool $credsSane): void
{
    $defaultPurpose = $view === 'ops' ? 'test' : ($laneFilter === 'test' ? 'test' : ($laneFilter === 'general' ? 'general' : 'customer'));
    ?>
        <form method="post" class="tc-compose" autocomplete="off">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="date" value="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="lane" value="<?php echo htmlspecialchars($laneFilter, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="tc-row-3">
                <div>
                    <label for="toCustomer"><?php bakery_te('texts.compose_customer'); ?></label>
                    <select id="toCustomer" name="to_customer">
                        <option value=""><?php bakery_te('texts.compose_pick_customer'); ?></option>
                        <?php foreach ($composeCustomers as $c): ?>
                            <option value="<?php echo htmlspecialchars($c['phone'], ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo ($selectedPhone !== '' && bakery_text_phone_tail($c['phone']) === bakery_text_phone_tail($selectedPhone)) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name'] . ' — ' . $c['phone'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="toManual"><?php bakery_te('texts.compose_manual'); ?></label>
                    <input type="text" id="toManual" name="to_manual" dir="ltr"
                           placeholder="+14155551234"
                           value="<?php echo htmlspecialchars($selectedPhone, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label for="tcPurpose"><?php bakery_te('texts.compose_purpose'); ?></label>
                    <select id="tcPurpose" name="purpose">
                        <option value="customer" <?php echo $defaultPurpose === 'customer' ? 'selected' : ''; ?>><?php bakery_te('texts.lane_customer'); ?></option>
                        <option value="test" <?php echo $defaultPurpose === 'test' ? 'selected' : ''; ?>><?php bakery_te('texts.lane_test'); ?></option>
                        <option value="general" <?php echo $defaultPurpose === 'general' ? 'selected' : ''; ?>><?php bakery_te('texts.lane_general'); ?></option>
                    </select>
                </div>
            </div>
            <div>
                <label for="tcBody"><?php bakery_te('texts.compose_body'); ?></label>
                <textarea id="tcBody" name="body" maxlength="1600" required
                          placeholder="<?php bakery_te('texts.compose_placeholder'); ?>"></textarea>
            </div>
            <div class="tc-send-row">
                <span class="tc-hint">
                    <?php if ($liveReady && $credsSane) { bakery_te('texts.hint_live'); } else { bakery_te('texts.hint_record_only'); } ?>
                </span>
                <button type="submit" class="tc-btn"><?php bakery_te($liveReady && $credsSane ? 'texts.send_button' : 'texts.record_button'); ?></button>
            </div>
        </form>
    <?php
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<style>
.tc-wrap { max-width: 1280px; margin: 0 auto; padding: 16px; display: grid; grid-template-columns: minmax(280px, 360px) 1fr; gap: 16px; }
@media (max-width: 900px) { .tc-wrap { grid-template-columns: 1fr; } }
.tc-strip { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 12px 0 4px; }
.tc-chip { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 13px; background: var(--sf-surface, #fff); border: 1px solid rgba(0,0,0,.12); }
.tc-chip strong { font-size: 15px; }
.tc-chip-ok strong { color: #1a7f37; }
.tc-chip-fail strong { color: #b42318; }
.tc-chip-warn strong { color: #92400e; }
.tc-banner { margin: 10px 0; padding: 10px 14px; border-radius: 8px; font-size: 14px; }
.tc-banner-ok { background: #e7f5ec; color: #14532d; }
.tc-banner-warn { background: #fef3c7; color: #78350f; }
.tc-banner-error { background: #fee2e2; color: #7f1d1d; }
.tc-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0 8px; }
.tc-tab { display: inline-block; padding: 8px 14px; border-radius: 8px; text-decoration: none; color: inherit; border: 1px solid rgba(0,0,0,.12); background: var(--sf-surface, #fff); font-weight: 600; font-size: 14px; }
.tc-tab.is-active { background: var(--sf-accent, #2563eb); color: #fff; border-color: transparent; }
.tc-filters { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 12px; }
.tc-filters a { font-size: 13px; padding: 4px 10px; border-radius: 999px; text-decoration: none; color: inherit; border: 1px solid rgba(0,0,0,.12); }
.tc-filters a.is-active { background: #111; color: #fff; border-color: #111; }
.tc-lane { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; padding: 0 6px; border-radius: 999px; border: 1px solid currentColor; opacity: .85; }
.tc-panel { background: var(--sf-surface, #fff); border: 1px solid rgba(0,0,0,.1); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; }
.tc-panel-head { padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,.08); font-weight: 600; display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.tc-panel-head small { font-weight: 400; opacity: .7; }
.tc-convo-list { list-style: none; margin: 0; padding: 0; overflow-y: auto; max-height: 62vh; }
.tc-convo { border-bottom: 1px solid rgba(0,0,0,.05); }
.tc-convo a { display: block; padding: 10px 16px; text-decoration: none; color: inherit; }
.tc-convo a:hover { background: rgba(0,0,0,.03); }
.tc-convo.is-active { background: rgba(59,130,246,.08); box-shadow: inset 3px 0 0 var(--sf-accent, #2563eb); }
.tc-convo-top { display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.tc-convo-name { font-weight: 600; font-size: 14px; }
.tc-convo-phone { font-size: 12px; opacity: .65; direction: ltr; }
.tc-convo-preview { font-size: 13px; opacity: .75; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.tc-badge { display: inline-block; min-width: 20px; text-align: center; padding: 1px 6px; border-radius: 999px; background: #dc2626; color: #fff; font-size: 11px; font-weight: 700; }
.tc-badge-fail { background: #b42318; }
.tc-thread { flex: 1; overflow-y: auto; max-height: 52vh; padding: 14px 16px; display: flex; flex-direction: column; gap: 8px; background: rgba(0,0,0,.02); }
.tc-msg { max-width: 72%; padding: 8px 12px; border-radius: 12px; font-size: 14px; line-height: 1.45; word-break: break-word; }
.tc-msg-out { align-self: flex-end; background: var(--sf-accent, #2563eb); color: #fff; border-bottom-right-radius: 3px; }
.tc-msg-in { align-self: flex-start; background: #fff; border: 1px solid rgba(0,0,0,.1); border-bottom-left-radius: 3px; }
.tc-msg-meta { font-size: 11px; opacity: .75; margin-top: 4px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.tc-status { font-size: 11px; padding: 0 5px; border-radius: 999px; border: 1px solid currentColor; }
.tc-empty { padding: 40px 20px; text-align: center; opacity: .6; }
.tc-compose { border-top: 1px solid rgba(0,0,0,.08); padding: 14px 16px; display: grid; gap: 10px; }
.tc-compose label { font-size: 12px; font-weight: 600; opacity: .75; display: block; margin-bottom: 3px; }
.tc-compose input[type="text"], .tc-compose select, .tc-compose textarea {
    width: 100%; padding: 8px 10px; border: 1px solid rgba(0,0,0,.18); border-radius: 8px; font: inherit; box-sizing: border-box;
}
.tc-compose textarea { min-height: 84px; resize: vertical; }
.tc-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.tc-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
@media (max-width: 700px) { .tc-row, .tc-row-3 { grid-template-columns: 1fr; } }
.tc-send-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.tc-btn { padding: 9px 18px; border-radius: 8px; border: none; background: var(--sf-accent, #2563eb); color: #fff; font-weight: 600; cursor: pointer; }
.tc-btn:hover { filter: brightness(.94); }
.tc-hint { font-size: 12px; opacity: .65; }
.tc-date-form { display: flex; gap: 8px; align-items: center; margin: 10px 0 0; }
.tc-sync { display: flex; gap: 8px; align-items: center; margin: 12px 0 0; padding: 10px 14px; background: var(--sf-surface, #fff); border: 1px solid rgba(0,0,0,.1); border-radius: 8px; font-size: 13px; }
.tc-sync select { padding: 5px 8px; border: 1px solid rgba(0,0,0,.18); border-radius: 6px; }
.tc-media { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.tc-thumb { display: inline-block; line-height: 0; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,.4); }
.tc-msg-in .tc-thumb { border-color: rgba(0,0,0,.12); }
.tc-thumb img { width: 132px; height: 132px; object-fit: cover; display: block; }
.tc-thumb-file { font-size: 12px; padding: 14px 12px; background: rgba(0,0,0,.06); text-decoration: none; color: inherit; }
.tc-feed { list-style: none; margin: 0; padding: 0; }
.tc-feed li { padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,.06); display: grid; gap: 4px; }
.tc-feed-top { display: flex; justify-content: space-between; gap: 8px; flex-wrap: wrap; font-size: 13px; }
.tc-feed-body { font-size: 14px; white-space: pre-wrap; }
.tc-cols { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
@media (max-width: 900px) { .tc-cols { grid-template-columns: 1fr; } }
.tc-ops { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
@media (max-width: 800px) { .tc-ops { grid-template-columns: 1fr; } }
.tc-metric { padding: 16px; }
.tc-metric strong { display: block; font-size: 28px; }
.tc-wide { max-width: 1280px; margin: 0 auto; padding: 0 16px 24px; }
</style>

<div class="page-header">
    <h1><?php bakery_te('page.text_comms'); ?></h1>
    <p class="subtitle"><?php bakery_te('texts.subtitle'); ?></p>
</div>

<?php echo $banner; ?>

<div class="tc-strip">
    <?php if (!$tablesReady): ?>
        <span class="tc-chip tc-chip-warn"><strong>—</strong> <?php bakery_te('texts.unavailable_table'); ?></span>
    <?php elseif (!$liveReady): ?>
        <span class="tc-chip tc-chip-warn"><strong>⏸</strong> <?php bakery_te('texts.badge_log'); ?></span>
    <?php elseif (!$credsSane): ?>
        <span class="tc-chip tc-chip-warn" title="<?php bakery_te('texts.token_warning'); ?>"><strong>?</strong> <?php bakery_te('texts.token_warning'); ?></span>
    <?php else: ?>
        <span class="tc-chip tc-chip-ok"><strong>●</strong> <?php bakery_te('texts.badge_live'); ?></span>
    <?php endif; ?>
    <span class="tc-chip"><strong><?php echo (int)$summary['counts']['outbound']; ?></strong> <?php bakery_te('texts.chip_outbound'); ?></span>
    <span class="tc-chip tc-chip-ok"><strong><?php echo (int)$summary['counts']['delivered']; ?></strong> <?php bakery_te('texts.chip_delivered'); ?></span>
    <span class="tc-chip"><strong><?php echo (int)$summary['counts']['sent']; ?></strong> <?php bakery_te('texts.chip_in_flight'); ?></span>
    <span class="tc-chip tc-chip-fail"><strong><?php echo (int)$summary['counts']['failed']; ?></strong> <?php bakery_te('texts.chip_failed'); ?></span>
    <?php if (!$liveReady): ?>
        <span class="tc-chip tc-chip-warn"><strong><?php echo (int)$summary['counts']['logged']; ?></strong> <?php bakery_te('texts.chip_logged'); ?></span>
    <?php endif; ?>
    <span class="tc-chip"><strong><?php echo (int)$summary['counts']['received']; ?></strong> <?php bakery_te('texts.chip_inbound'); ?></span>
    <?php if ((int)$summary['counts']['unread'] > 0): ?>
        <span class="tc-chip tc-chip-fail"><strong><?php echo (int)$summary['counts']['unread']; ?></strong> <?php bakery_te('texts.chip_unread'); ?></span>
    <?php endif; ?>
    <span class="tc-chip"><strong><?php echo (int)($summary['lanes']['customer'] ?? 0); ?></strong> <?php bakery_te('texts.lane_customer'); ?></span>
    <span class="tc-chip"><strong><?php echo (int)($summary['lanes']['test'] ?? 0); ?></strong> <?php bakery_te('texts.lane_test'); ?></span>
    <span class="tc-chip"><strong><?php echo (int)($summary['lanes']['general'] ?? 0); ?></strong> <?php bakery_te('texts.lane_general'); ?></span>
</div>

<?php if ($tablesReady): ?>
<form method="post" class="tc-sync">
    <?php echo bakery_csrf_field(); ?>
    <input type="hidden" name="action" value="sync">
    <label for="tcSyncDays"><?php bakery_te('texts.sync_label'); ?>:</label>
    <select id="tcSyncDays" name="days">
        <option value="7">7</option>
        <option value="30" selected>30</option>
        <option value="90">90</option>
        <option value="180">180</option>
    </select>
    <button type="submit" class="tc-btn" style="padding:6px 14px;"><?php bakery_te('texts.sync_button'); ?></button>
    <span class="tc-hint"><?php bakery_te('texts.sync_hint'); ?></span>
</form>
<?php endif; ?>

<nav class="tc-tabs" aria-label="<?php bakery_te('texts.views_label'); ?>">
    <?php foreach (['inbox' => 'texts.view_inbox', 'feed' => 'texts.view_feed', 'delivery' => 'texts.view_delivery', 'ops' => 'texts.view_ops', 'surveys' => 'texts.view_surveys'] as $viewKey => $viewLabel): ?>
        <a class="tc-tab<?php echo $view === $viewKey ? ' is-active' : ''; ?>" href="text_comms.php?<?php echo htmlspecialchars(bakery_text_cc_qs($date, $viewKey, $laneFilter, $selectedPhone), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($viewLabel); ?></a>
    <?php endforeach; ?>
</nav>

<form method="get" class="tc-date-form">
    <label for="tcDate"><?php bakery_te('common.date'); ?>:</label>
    <input type="date" id="tcDate" name="date" value="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="lane" value="<?php echo htmlspecialchars($laneFilter, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($selectedPhone !== ''): ?><input type="hidden" name="phone" value="<?php echo htmlspecialchars($selectedPhone, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
    <button type="submit" class="tc-btn" style="padding:6px 14px;"><?php bakery_te('common.view'); ?></button>
</form>

<?php if ($view === 'inbox'): ?>
<div class="tc-filters">
    <?php foreach (['all' => 'texts.lane_all', 'customer' => 'texts.lane_customer', 'test' => 'texts.lane_test', 'general' => 'texts.lane_general'] as $laneKey => $laneLabel): ?>
        <a class="<?php echo $laneFilter === $laneKey ? 'is-active' : ''; ?>" href="text_comms.php?<?php echo htmlspecialchars(bakery_text_cc_qs($date, 'inbox', $laneKey, $selectedPhone), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te($laneLabel); ?></a>
    <?php endforeach; ?>
</div>

<div class="tc-wrap">
    <section class="tc-panel">
        <div class="tc-panel-head">
            <?php bakery_te('texts.conversations_title'); ?>
            <small><?php bakery_te('texts.conversations_window'); ?></small>
        </div>
        <?php if (!$tablesReady): ?>
            <div class="tc-empty"><?php bakery_te('texts.unavailable_table'); ?></div>
        <?php elseif ($conversations === []): ?>
            <div class="tc-empty"><?php bakery_te('texts.no_conversations'); ?></div>
        <?php else: ?>
            <ul class="tc-convo-list">
                <?php foreach ($conversations as $convo): ?>
                    <?php
                        $phoneAttr = htmlspecialchars((string)$convo['phone'], ENT_QUOTES, 'UTF-8');
                        $isActive = $selectedPhone !== '' && bakery_text_phone_tail($selectedPhone) === bakery_text_phone_tail((string)$convo['phone']);
                        $lane = (string)($convo['lane'] ?? 'general');
                        $displayName = (string)($convo['label'] !== '' ? $convo['label'] : $convo['phone']);
                    ?>
                    <li class="tc-convo<?php echo $isActive ? ' is-active' : ''; ?>">
                        <a href="text_comms.php?<?php echo htmlspecialchars(bakery_text_cc_qs($date, 'inbox', $laneFilter, (string)$convo['phone']), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="tc-convo-top">
                                <span class="tc-convo-name">
                                    <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>
                                    <span class="tc-lane"><?php bakery_te(bakery_text_lane_label_key($lane)); ?></span>
                                    <?php if ((int)$convo['unread'] > 0): ?><span class="tc-badge"><?php echo (int)$convo['unread']; ?></span><?php endif; ?>
                                    <?php if ((int)$convo['failed'] > 0): ?><span class="tc-badge tc-badge-fail">!</span><?php endif; ?>
                                </span>
                                <span class="tc-convo-phone"><?php echo $phoneAttr; ?></span>
                            </span>
                            <span class="tc-convo-preview">
                                <?php echo ($convo['last_direction'] === 'inbound' ? '← ' : '→ ') . htmlspecialchars(mb_substr(trim((string)$convo['last_body']), 0, 90), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="tc-panel">
        <div class="tc-panel-head">
            <?php if ($selectedPhone !== ''): ?>
                <span>
                    <?php echo htmlspecialchars($selectedPhone, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($selectedCustomerId > 0): ?>
                        — <a href="<?php echo htmlspecialchars(BASE_URL . 'customers.php?search=' . urlencode($selectedPhone), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('texts.open_customer'); ?></a>
                    <?php endif; ?>
                </span>
                <small><?php echo count($thread); ?> <?php bakery_te('texts.messages_count_suffix'); ?></small>
            <?php else: ?>
                <span><?php bakery_te('texts.thread_title'); ?></span>
                <small><?php bakery_te('texts.thread_pick_hint'); ?></small>
            <?php endif; ?>
        </div>
        <div class="tc-thread">
            <?php if ($selectedPhone === ''): ?>
                <div class="tc-empty"><?php bakery_te('texts.thread_empty'); ?></div>
            <?php elseif ($thread === []): ?>
                <div class="tc-empty"><?php bakery_te('texts.thread_no_messages'); ?></div>
            <?php else: ?>
                <?php foreach ($thread as $msg): ?>
                    <?php
                        $isOut = (string)$msg['direction'] === 'outbound';
                        $statusKey = bakery_text_status_label_key((string)$msg['direction'], (string)$msg['status']);
                        $when = format_date((string)$msg['created_at'], 'M j g:i A');
                    ?>
                    <div class="tc-msg <?php echo $isOut ? 'tc-msg-out' : 'tc-msg-in'; ?>">
                        <?php if ((string)($msg['body'] ?? '') !== ''): ?>
                            <?php echo nl2br(htmlspecialchars((string)$msg['body'], ENT_QUOTES, 'UTF-8')); ?>
                        <?php endif; ?>
                        <?php echo bakery_text_media_thumbs($msg); ?>
                        <div class="tc-msg-meta">
                            <span><?php echo htmlspecialchars($when, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="tc-status"><?php bakery_te($statusKey); ?></span>
                            <?php if ((string)($msg['kind'] ?? 'sms') === 'mms'): ?><span class="tc-status">MMS</span><?php endif; ?>
                            <span class="tc-lane"><?php bakery_te(bakery_text_lane_label_key((string)($msg['lane'] ?? bakery_text_lane($msg)))); ?></span>
                            <?php if (!empty($msg['error_message'])): ?>
                                <span title="<?php echo htmlspecialchars((string)$msg['error_message'], ENT_QUOTES, 'UTF-8'); ?>">⚠</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php bakery_text_render_compose($date, $view, $laneFilter, $selectedPhone, $composeCustomers, $liveReady, $credsSane); ?>
    </section>
</div>

<?php elseif ($view === 'feed'): ?>
<div class="tc-wide">
    <section class="tc-panel">
        <div class="tc-panel-head">
            <?php bakery_te('texts.feed_title'); ?>
            <small><?php bakery_te('texts.feed_window'); ?></small>
        </div>
        <?php if ($feed === []): ?>
            <div class="tc-empty"><?php bakery_te('texts.feed_empty'); ?></div>
        <?php else: ?>
            <ul class="tc-feed">
                <?php foreach ($feed as $row): ?>
                    <li>
                        <div class="tc-feed-top">
                            <span>
                                <strong><?php echo htmlspecialchars((string)(($row['label'] !== '' ? $row['label'] : $row['counterpart'])), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <span class="tc-lane"><?php bakery_te(bakery_text_lane_label_key((string)$row['lane'])); ?></span>
                                <span class="tc-status"><?php bakery_te(bakery_text_status_label_key((string)$row['direction'], (string)$row['status'])); ?></span>
                            </span>
                            <span>
                                <?php echo htmlspecialchars(format_date((string)$row['created_at'], 'M j g:i A'), ENT_QUOTES, 'UTF-8'); ?>
                                · <a href="text_comms.php?<?php echo htmlspecialchars(bakery_text_cc_qs($date, 'inbox', 'all', (string)$row['counterpart']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('texts.open_thread'); ?></a>
                            </span>
                        </div>
                        <div class="tc-feed-body">
                            <?php if ((string)($row['body'] ?? '') !== ''): ?>
                                <?php echo htmlspecialchars((string)$row['body'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php elseif ((int)($row['media_count'] ?? 0) > 0): ?>
                                <span class="tc-hint"><?php bakery_te('texts.media_only'); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php echo bakery_text_media_thumbs($row); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php bakery_text_render_compose($date, $view, $laneFilter, $selectedPhone, $composeCustomers, $liveReady, $credsSane); ?>
    </section>
</div>

<?php elseif ($view === 'delivery'): ?>
<div class="tc-wide">
    <?php if ($liveReady && TWILIO_STATUS_CALLBACK_URL === ''): ?>
        <div class="tc-banner tc-banner-warn"><?php bakery_te('texts.delivery_no_callback_hint'); ?></div>
    <?php endif; ?>
    <div class="tc-cols">
        <?php foreach (['failed' => 'texts.delivery_failed', 'in_flight' => 'texts.delivery_in_flight', 'logged' => 'texts.delivery_logged'] as $bucket => $titleKey): ?>
            <section class="tc-panel">
                <div class="tc-panel-head"><?php bakery_te($titleKey); ?> <small><?php echo count($delivery[$bucket]); ?></small></div>
                <?php if ($delivery[$bucket] === []): ?>
                    <div class="tc-empty"><?php bakery_te('texts.delivery_empty'); ?></div>
<?php else: ?>
                    <ul class="tc-feed">
                        <?php foreach ($delivery[$bucket] as $row): ?>
                            <li>
                                <div class="tc-feed-top">
                                    <span>
                                        <?php echo htmlspecialchars((string)$row['counterpart'], ENT_QUOTES, 'UTF-8'); ?>
                                        <span class="tc-lane"><?php bakery_te(bakery_text_lane_label_key((string)$row['lane'])); ?></span>
                                    </span>
                                    <span><?php echo htmlspecialchars(format_date((string)$row['created_at'], 'M j g:i A'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="tc-feed-body"><?php echo htmlspecialchars((string)$row['body'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (!empty($row['error_message'])): ?>
                                    <div class="tc-hint"><?php echo htmlspecialchars((string)$row['error_message'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($view === 'surveys'): ?>
<div class="tc-wide">
    <?php if (!$surveysReady): ?>
        <div class="tc-banner tc-banner-warn"><?php bakery_te('texts.surveys_unavailable'); ?></div>
    <?php else: ?>
    <?php if (is_array($surveyDetailRow) && is_array($surveyDetail)): ?>
    <?php
        $dRow = $surveyDetailRow;
        $dRes = $surveyDetail;
        $maxTally = 1;
        foreach ($dRes['questions'] as $dq) {
            foreach ($dq['tally'] as $n) {
                $maxTally = max($maxTally, (int)$n);
            }
        }
    ?>
    <div class="tc-cols" style="grid-template-columns: 1fr;">
        <section class="tc-panel">
            <div class="tc-panel-head">
                <span>
                    <?php echo htmlspecialchars((string)(($dRow['title'] ?? '') !== '' ? $dRow['title'] : bakery_t($dRow['kind'] === 'route_review' ? 'texts.survey_kind_route' : ($dRow['kind'] === 'store_verify' ? 'texts.survey_kind_stores' : 'texts.survey_kind_question'))), ENT_QUOTES, 'UTF-8'); ?>
                    <span class="tc-lane"><?php bakery_te($dRow['status'] === 'open' ? 'texts.survey_status_open' : 'texts.survey_status_closed'); ?></span>
                </span>
                <small>
                    <a href="text_comms.php?view=surveys"><?php bakery_te('texts.survey_back_to_list'); ?></a>
                    · <a href="<?php echo htmlspecialchars(BASE_URL . 'survey.php?t=' . rawurlencode((string)$dRow['token']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('texts.survey_open_link'); ?></a>
                </small>
            </div>
            <div style="padding:12px 16px; display:flex; flex-wrap:wrap; gap:14px; font-size:13px;">
                <span><strong><?php echo (int)$dRes['respondents']; ?></strong> <?php bakery_te('texts.survey_stat_respondents'); ?></span>
                <span><strong><?php echo (int)($dRes['action_counts']['skip'] ?? 0); ?></strong> <?php bakery_te('texts.survey_stat_skips'); ?></span>
                <span><strong><?php echo (int)($dRes['action_counts']['claim'] ?? 0); ?></strong> <?php bakery_te('texts.survey_stat_claims'); ?></span>
                <span><strong><?php echo (int)($dRes['action_counts']['answer'] ?? 0) + (int)($dRes['action_counts']['reply'] ?? 0); ?></strong> <?php bakery_te('texts.survey_stat_answers'); ?></span>
                <form method="post" action="<?php echo htmlspecialchars(BASE_URL . 'survey.php?t=' . rawurlencode((string)$dRow['token']), ENT_QUOTES, 'UTF-8'); ?>" style="margin-left:auto">
                    <?php echo bakery_csrf_field(); ?>
                    <input type="hidden" name="action" value="<?php echo $dRow['status'] === 'open' ? 'close' : 'reopen'; ?>">
                    <button type="submit" class="tc-btn" style="padding:6px 14px;"><?php bakery_te($dRow['status'] === 'open' ? 'texts.survey_close_button' : 'texts.survey_reopen_button'); ?></button>
                </form>
            </div>
        </section>

        <?php if ($dRes['questions']): ?>
        <section class="tc-panel">
            <div class="tc-panel-head"><?php bakery_te('texts.survey_results_title'); ?></div>
            <div style="padding:12px 16px; display:grid; gap:16px;">
                <?php foreach ($dRes['questions'] as $dq): ?>
                <div>
                    <div style="font-weight:600; margin-bottom:6px;"><?php echo htmlspecialchars((string)$dq['text'], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php if ($dq['type'] === 'text'): ?>
                        <?php if ($dq['free'] === []): ?>
                            <div class="tc-hint"><?php bakery_te('texts.survey_no_answers_yet'); ?></div>
                        <?php else: ?>
                        <ul class="tc-feed">
                            <?php foreach ($dq['free'] as $free): ?>
                            <li>
                                <div class="tc-feed-top"><span><?php echo htmlspecialchars((string)$free['respondent'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span><?php echo htmlspecialchars(format_date((string)$free['at'], 'M j g:i A'), ENT_QUOTES, 'UTF-8'); ?></span></div>
                                <div class="tc-feed-body"><?php echo nl2br(htmlspecialchars((string)$free['text'], ENT_QUOTES, 'UTF-8')); ?></div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($dq['total'] === 0): ?>
                            <div class="tc-hint"><?php bakery_te('texts.survey_no_answers_yet'); ?></div>
                        <?php else: ?>
                        <div style="display:grid; gap:6px;">
                            <?php foreach ($dq['tally'] as $label => $n): ?>
                            <div style="display:grid; grid-template-columns: minmax(120px, 220px) 1fr 40px; gap:8px; align-items:center; font-size:13px;">
                                <span><?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></span>
                                <span style="background:rgba(37,99,235,.15); border-radius:4px; height:14px;"><span style="display:block; height:14px; width:<?php echo (int)round(($n / max(1, $dq['total'])) * 100); ?>%; background:var(--sf-accent, #2563eb); border-radius:4px;"></span></span>
                                <span style="text-align:right"><?php echo (int)$n; ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($dRes['actions']): ?>
        <section class="tc-panel">
            <div class="tc-panel-head"><?php bakery_te('texts.survey_actions_title'); ?> <small><?php echo count($dRes['actions']); ?></small></div>
            <ul class="tc-feed">
                <?php foreach ($dRes['actions'] as $act): ?>
                <li>
                    <div class="tc-feed-top">
                        <span>
                            <span class="tc-status"><?php
                                $actKey = 'survey.action_' . strtolower((string)$act['action']);
                                echo htmlspecialchars((string)bakery_t($actKey, [], (string)$act['action']), ENT_QUOTES, 'UTF-8');
                            ?></span>
                            <?php if ($act['customer'] !== ''): ?><strong><?php echo htmlspecialchars((string)$act['customer'], ENT_QUOTES, 'UTF-8'); ?></strong><?php endif; ?>
                        </span>
                        <span><?php echo htmlspecialchars(format_date((string)$act['created_at'], 'M j g:i A'), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if ($act['response'] !== '' || $act['respondent'] !== ''): ?>
                    <div class="tc-feed-body"><?php
                        $line2 = trim($act['respondent'] . ($act['response'] !== '' ? ($act['respondent'] !== '' ? ': ' : '') . $act['response'] : ''));
                        echo htmlspecialchars($line2, ENT_QUOTES, 'UTF-8');
                    ?></div>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="tc-cols" style="grid-template-columns: minmax(280px, 420px) 1fr;">
        <section class="tc-panel">
            <div class="tc-panel-head">
                <?php bakery_te('texts.survey_compose_title'); ?>
                <small><?php bakery_te('texts.hint_record_only' ); ?></small>
            </div>
            <form method="post" class="tc-compose" autocomplete="off">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="survey_send">
                <div class="tc-row">
                    <div>
                        <label for="svAudience"><?php bakery_te('texts.survey_audience'); ?></label>
                        <select id="svAudience" name="survey_audience">
                            <option value="driver"><?php bakery_te('texts.survey_audience_driver'); ?></option>
                            <option value="staff"><?php bakery_te('texts.survey_audience_staff'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="svDriver"><?php bakery_te('texts.survey_driver'); ?></label>
                        <select id="svDriver" name="driver_id">
                            <?php foreach ($driverChoices as $d): ?>
                                <option value="<?php echo (int)$d['id']; ?>"><?php echo htmlspecialchars((string)$d['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                            <option value="0"><?php bakery_te('texts.survey_driver_all'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="tc-row">
                    <div>
                        <label for="svKind"><?php bakery_te('texts.survey_kind'); ?></label>
                        <select id="svKind" name="survey_kind">
                            <option value="route_review"><?php bakery_te('texts.survey_kind_route'); ?></option>
                            <option value="store_verify"><?php bakery_te('texts.survey_kind_stores'); ?></option>
                            <option value="question"><?php bakery_te('texts.survey_kind_question'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="svMode"><?php bakery_te('texts.survey_mode'); ?></label>
                        <select id="svMode" name="survey_mode">
                            <option value="link"><?php bakery_te('texts.survey_mode_link'); ?></option>
                            <option value="text_reply"><?php bakery_te('texts.survey_mode_reply'); ?></option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="svPhone"><?php bakery_te('texts.compose_manual'); ?></label>
                    <input type="text" id="svPhone" name="to_manual" dir="ltr" placeholder="+14155551234" required>
                </div>
                <div>
                    <label for="svDate"><?php bakery_te('common.date'); ?></label>
                    <input type="date" id="svDate" name="date" value="<?php echo htmlspecialchars($surveyComposerDate, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div id="svCustomQuestions">
                    <label><?php bakery_te('texts.survey_questions_label'); ?></label>
                    <div id="svQuestionRows" style="display:grid; gap:8px;">
                        <div class="sv-qrow" style="border:1px solid rgba(0,0,0,.12); border-radius:8px; padding:8px; display:grid; gap:6px;">
                            <input type="text" name="q_text[]" maxlength="300" placeholder="<?php bakery_te('texts.survey_question_placeholder'); ?>">
                            <div class="tc-row">
                                <select name="q_type[]">
                                    <option value="yes_no"><?php bakery_te('texts.survey_type_yes_no'); ?></option>
                                    <option value="choice"><?php bakery_te('texts.survey_type_choice'); ?></option>
                                    <option value="text"><?php bakery_te('texts.survey_type_text'); ?></option>
                                </select>
                                <textarea name="q_options[]" rows="2" placeholder="<?php bakery_te('texts.survey_options_placeholder'); ?>"></textarea>
                            </div>
                        </div>
                    </div>
                    <button type="button" id="svAddQuestion" class="tc-btn" style="margin-top:8px; padding:5px 12px; font-size:13px;"><?php bakery_te('texts.survey_add_question'); ?></button>
                </div>
                <div class="tc-send-row">
                    <span class="tc-hint"><?php bakery_te($liveReady && $credsSane ? 'texts.hint_live' : 'texts.hint_record_only'); ?></span>
                    <button type="submit" class="tc-btn"><?php bakery_te('texts.survey_send_button'); ?></button>
                </div>
            </form>
        </section>

        <section class="tc-panel">
            <div class="tc-panel-head">
                <?php bakery_te('texts.surveys_recent_title'); ?>
                <small><?php echo count($surveyRows); ?></small>
            </div>
            <?php if ($surveyRows === []): ?>
                <div class="tc-empty"><?php bakery_te('texts.surveys_none'); ?></div>
            <?php else: ?>
            <ul class="tc-feed">
                <?php foreach ($surveyRows as $s): ?>
                    <?php
                        $detailQs = 'text_comms.php?view=surveys&sid=' . (int)$s['id'];
                        $listTitle = (string)($s['title'] ?? '');
                        if ($listTitle === '' && !empty($s['question'])) {
                            $listTitle = mb_substr((string)$s['question'], 0, 90);
                        }
                    ?>
                    <li>
                        <div class="tc-feed-top">
                            <span>
                                <a href="<?php echo htmlspecialchars($detailQs, ENT_QUOTES, 'UTF-8'); ?>" style="font-weight:600; color:inherit; text-decoration:underline;">
                                    <?php echo htmlspecialchars($listTitle !== '' ? $listTitle : bakery_t('texts.survey_kind_question'), ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <span class="tc-lane"><?php bakery_te($s['kind'] === 'route_review' ? 'texts.survey_kind_route' : ($s['kind'] === 'store_verify' ? 'texts.survey_kind_stores' : 'texts.survey_kind_question')); ?></span>
                                <span class="tc-lane"><?php bakery_te($s['audience'] === 'driver' ? 'texts.survey_audience_driver' : 'texts.survey_audience_staff'); ?></span>
                                <span class="tc-status"><?php bakery_te($s['status'] === 'open' ? 'texts.survey_status_open' : 'texts.survey_status_closed'); ?></span>
                            </span>
                            <span><?php echo htmlspecialchars(format_date((string)$s['created_at'], 'M j g:i A'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="tc-feed-body"><?php echo htmlspecialchars((string)$s['target_phone'], ENT_QUOTES, 'UTF-8'); ?><?php echo $s['delivery_date'] !== null && $s['delivery_date'] !== '' ? ' · ' . htmlspecialchars((string)$s['delivery_date'], ENT_QUOTES, 'UTF-8') : ''; ?></div>
                        <div class="tc-feed-top">
                            <span class="tc-hint">
                                <?php echo (int)$s['response_count']; ?> <?php bakery_te('texts.survey_responses_suffix'); ?>
                                · <a href="<?php echo htmlspecialchars($detailQs, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('texts.survey_view_results'); ?></a>
                                · <a href="<?php echo htmlspecialchars(BASE_URL . 'survey.php?t=' . rawurlencode((string)$s['token']), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('texts.survey_open_link'); ?></a>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php else: ?>
<div class="tc-wide">
    <div class="tc-ops">
        <section class="tc-panel tc-metric">
            <div class="tc-panel-head"><?php bakery_te('texts.ops_live'); ?></div>
            <strong><?php bakery_te($liveReady && $credsSane ? 'texts.badge_live' : 'texts.badge_log'); ?></strong>
            <p class="tc-hint"><?php echo htmlspecialchars((string)($ops['from_number'] !== '' ? $ops['from_number'] : bakery_t('texts.ops_no_from')), ENT_QUOTES, 'UTF-8'); ?></p>
        </section>
        <section class="tc-panel tc-metric">
            <div class="tc-panel-head"><?php bakery_te('texts.ops_window'); ?></div>
            <p><strong><?php echo (int)($ops['lanes_window']['customer'] ?? 0); ?></strong> <?php bakery_te('texts.lane_customer'); ?></p>
            <p><strong><?php echo (int)($ops['lanes_window']['test'] ?? 0); ?></strong> <?php bakery_te('texts.lane_test'); ?></p>
            <p><strong><?php echo (int)($ops['lanes_window']['general'] ?? 0); ?></strong> <?php bakery_te('texts.lane_general'); ?></p>
        </section>
        <section class="tc-panel">
            <div class="tc-panel-head"><?php bakery_te('texts.ops_contexts'); ?></div>
            <?php if (empty($ops['contexts'])): ?>
                <div class="tc-empty"><?php bakery_te('texts.feed_empty'); ?></div>
            <?php else: ?>
                <ul class="tc-feed">
                    <?php foreach ($ops['contexts'] as $ctx): ?>
                        <li><?php echo htmlspecialchars((string)$ctx['context_type'], ENT_QUOTES, 'UTF-8'); ?> — <?php echo (int)$ctx['n']; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <section class="tc-panel">
            <div class="tc-panel-head"><?php bakery_te('texts.compose_purpose'); ?></div>
            <?php bakery_text_render_compose($date, $view, $laneFilter, $selectedPhone, $composeCustomers, $liveReady, $credsSane); ?>
        </section>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var picker = document.getElementById('toCustomer');
    var manual = document.getElementById('toManual');
    if (picker && manual) {
        picker.addEventListener('change', function () {
            if (picker.value !== '') manual.value = picker.value;
        });
        manual.addEventListener('input', function () {
            if (manual.value.trim() === '') picker.value = '';
        });
    }

    var addBtn = document.getElementById('svAddQuestion');
    var rows = document.getElementById('svQuestionRows');
    if (addBtn && rows) {
        addBtn.addEventListener('click', function () {
            if (rows.children.length >= 12) return;
            var row = document.createElement('div');
            row.className = 'sv-qrow';
            row.style.cssText = 'border:1px solid rgba(0,0,0,.12); border-radius:8px; padding:8px; display:grid; gap:6px;';
            var text = document.createElement('input');
            text.type = 'text';
            text.name = 'q_text[]';
            text.maxLength = 300;
            text.placeholder = <?php echo json_encode((string)bakery_t('texts.survey_question_placeholder')); ?>;
            var grid = document.createElement('div');
            grid.className = 'tc-row';
            var type = document.createElement('select');
            type.name = 'q_type[]';
            [['yes_no', <?php echo json_encode((string)bakery_t('texts.survey_type_yes_no')); ?>],
             ['choice', <?php echo json_encode((string)bakery_t('texts.survey_type_choice')); ?>],
             ['text', <?php echo json_encode((string)bakery_t('texts.survey_type_text')); ?>]].forEach(function (pair) {
                var opt = document.createElement('option');
                opt.value = pair[0];
                opt.textContent = pair[1];
                type.appendChild(opt);
            });
            var opts = document.createElement('textarea');
            opts.name = 'q_options[]';
            opts.rows = 2;
            opts.placeholder = <?php echo json_encode((string)bakery_t('texts.survey_options_placeholder')); ?>;
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'tc-btn';
            remove.style.cssText = 'padding:4px 10px; font-size:12px; background:#b42318;';
            remove.textContent = '\u00d7';
            remove.addEventListener('click', function () { row.remove(); });
            grid.appendChild(type);
            grid.appendChild(opts);
            row.appendChild(text);
            row.appendChild(grid);
            row.appendChild(remove);
            rows.appendChild(row);
        });
    }
})();
</script>
<?php
require_once __DIR__ . '/includes/footer.php';
