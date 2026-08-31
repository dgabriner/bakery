<?php
/**
 * Owner-requested surveys: ask a driver or manager a question over Twilio and
 * capture the answer in the system. Two modes:
 *   - text_reply: the reply SMS is matched by phone to the open survey.
 *   - link:       the message carries a tokenized survey.php URL; for
 *                 route_review it lists the driver's stops (skip/unskip) and
 *                 unassigned stores they can claim with one tap.
 *
 * Sends always go through bakery_text_send() so every attempt keeps its ledger
 * row. An open store_verify / route_review token is the auth on survey.php
 * (no PIN). No token still requires a staff login.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/driver_assignments.php';
require_once __DIR__ . '/driver_route_prep.php';
require_once __DIR__ . '/delivery_skip.php';
require_once __DIR__ . '/survey_store_verify.php';

function bakery_surveys_ready(PDO $db): bool
{
    return function_exists('table_exists')
        && table_exists($db, 'surveys')
        && table_exists($db, 'survey_responses');
}

function bakery_survey_modes(): array
{
    return ['link', 'text_reply'];
}

function bakery_survey_kinds(): array
{
    return ['route_review', 'store_verify', 'question'];
}

function bakery_survey_question_types(): array
{
    return ['text', 'yes_no', 'choice'];
}

/**
 * Normalized question list for a survey. Legacy single-question rows become
 * one text question keyed q1.
 */
function bakery_survey_questions(array $survey): array
{
    $out = [];
    $json = trim((string)($survey['questions_json'] ?? ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $i => $q) {
                $type = strtolower(trim((string)($q['type'] ?? 'text')));
                if (!in_array($type, bakery_survey_question_types(), true)) {
                    $type = 'text';
                }
                $options = [];
                if ($type === 'choice' && isset($q['options']) && is_array($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        $opt = trim((string)$opt);
                        if ($opt !== '') {
                            $options[] = mb_substr($opt, 0, 80);
                        }
                    }
                }
                $out[] = [
                    'key' => substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)($q['key'] ?? ''))) ?: ('q' . ($i + 1)), 0, 24) ?: ('q' . ($i + 1)),
                    'text' => trim((string)($q['text'] ?? '')),
                    'type' => $type,
                    'options' => $options,
                ];
            }
        }
    }
    if ($out === [] && trim((string)($survey['question'] ?? '')) !== '') {
        $out[] = [
            'key' => 'q1',
            'text' => trim((string)$survey['question']),
            'type' => 'text',
            'options' => [],
        ];
    }
    return $out;
}

/** Human label for a chosen option / free answer on one question. */
function bakery_survey_answer_label(array $question, string $raw): string
{
    if ($question['type'] === 'yes_no') {
        $normalized = strtolower(trim($raw));
        if (in_array($normalized, ['yes', 'y', 'si', 'sí', '1'], true)) {
            return (string)bakery_survey_text('survey.answer_yes', [], 'Yes');
        }
        if (in_array($normalized, ['no', 'n', '0'], true)) {
            return (string)bakery_survey_text('survey.answer_no', [], 'No');
        }
    }
    return $raw;
}

function bakery_survey_audiences(): array
{
    return ['driver', 'staff'];
}

/** Create + persist a survey row. Returns the row including its token. */
function bakery_survey_create(PDO $db, array $fields): array
{
    if (!bakery_surveys_ready($db)) {
        throw new RuntimeException('Surveys tables are missing (migration 061)');
    }

    $mode = strtolower(trim((string)($fields['mode'] ?? 'link')));
    if (!in_array($mode, bakery_survey_modes(), true)) {
        throw new RuntimeException('Unknown survey mode');
    }
    $kind = strtolower(trim((string)($fields['kind'] ?? 'route_review')));
    if (!in_array($kind, bakery_survey_kinds(), true)) {
        throw new RuntimeException('Unknown survey kind');
    }
    $audience = strtolower(trim((string)($fields['audience'] ?? 'driver')));
    if (!in_array($audience, bakery_survey_audiences(), true)) {
        throw new RuntimeException('Unknown survey audience');
    }
    if (($kind === 'route_review' || $kind === 'store_verify') && $audience !== 'driver') {
        throw new RuntimeException('Route review surveys target drivers');
    }

    $phone = '';
    if (function_exists('bakery_text_normalize_phone')) {
        require_once __DIR__ . '/text_comms.php';
        $phone = bakery_text_normalize_phone((string)($fields['target_phone'] ?? ''));
    } else {
        $phone = preg_replace('/[^0-9+]/', '', (string)($fields['target_phone'] ?? ''));
    }
    if ($mode === 'text_reply' && $phone === '') {
        throw new RuntimeException('Text-reply surveys need a destination phone number');
    }

    $driverId = (int)($fields['driver_id'] ?? 0);
    if ($kind === 'store_verify' && $driverId <= 0) {
        $driverId = 0;
    } elseif ($audience === 'driver') {
        if ($driverId <= 0) {
            throw new RuntimeException('Driver surveys need a driver');
        }
        $stmt = $db->prepare('SELECT id FROM drivers WHERE id = ? AND archived = 0');
        $stmt->execute([$driverId]);
        if (!$stmt->fetch()) {
            throw new RuntimeException('Driver not found or archived');
        }
    } else {
        $driverId = 0;
    }

    $deliveryDate = trim((string)($fields['delivery_date'] ?? ''));
    if ($kind === 'route_review' || $kind === 'store_verify') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            throw new RuntimeException('Route review needs a valid delivery date');
        }
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        $deliveryDate = '';
    }

    $question = trim((string)($fields['question'] ?? ''));
    if (mb_strlen($question) > 500) {
        $question = mb_substr($question, 0, 500);
    }

    // Optional multi-question payload (custom surveys). Each entry needs text;
    // choice questions need at least two non-empty options.
    $questionsJson = null;
    $normalizedQuestions = [];
    $title = trim((string)($fields['title'] ?? ''));
    if (mb_strlen($title) > 120) {
        $title = mb_substr($title, 0, 120);
    }
    if ($kind === 'question' && isset($fields['questions']) && is_array($fields['questions'])) {
        $normalizedQuestions = [];
        foreach (array_values($fields['questions']) as $i => $q) {
            $text = trim((string)($q['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $type = strtolower(trim((string)($q['type'] ?? 'text')));
            if (!in_array($type, bakery_survey_question_types(), true)) {
                $type = 'text';
            }
            $options = [];
            if ($type === 'choice') {
                if (isset($q['options']) && is_array($q['options'])) {
                    foreach ($q['options'] as $opt) {
                        $opt = trim((string)$opt);
                        if ($opt !== '') {
                            $options[] = mb_substr($opt, 0, 80);
                        }
                    }
                }
                if (count($options) < 2) {
                    throw new RuntimeException('Choice questions need at least two options');
                }
            }
            $normalizedQuestions[] = [
                'key' => 'q' . (count($normalizedQuestions) + 1),
                'text' => mb_substr($text, 0, 300),
                'type' => $type,
                'options' => $options,
            ];
        }
        if ($normalizedQuestions !== []) {
            if (count($normalizedQuestions) > 12) {
                $normalizedQuestions = array_slice($normalizedQuestions, 0, 12);
            }
            $questionsJson = json_encode(
                $normalizedQuestions,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($title === '') {
                $title = mb_substr(trim((string)$normalizedQuestions[0]['text']), 0, 120);
            }
        }
    }
    if ($kind === 'question' && $question === '' && $normalizedQuestions === []) {
        throw new RuntimeException('Question surveys need question text');
    }

    $token = bin2hex(random_bytes(16));
    $stmt = $db->prepare(
        'INSERT INTO surveys (token, mode, kind, audience, driver_id, staff_user_id, target_phone, question, delivery_date, status, created_by, title, questions_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $token,
        $mode,
        $kind,
        $audience,
        $audience === 'driver' && $driverId > 0 ? $driverId : null,
        $audience === 'staff' ? (int)($fields['staff_user_id'] ?? 0) ?: null : null,
        $phone,
        $question !== '' ? $question : null,
        $deliveryDate !== '' ? $deliveryDate : null,
        'open',
        (int)($fields['created_by'] ?? 0) ?: null,
        $title !== '' ? $title : null,
        $questionsJson,
    ]);

    return bakery_survey_find_by_token($db, $token);
}

function bakery_survey_find_by_token(PDO $db, string $token): array
{
    $stmt = $db->prepare('SELECT * FROM surveys WHERE token = ? LIMIT 1');
    $stmt->execute([trim($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function bakery_survey_find_by_id(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM surveys WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

/** Open text-reply survey that matches this sender's phone, newest first. */
function bakery_survey_open_for_phone(PDO $db, string $fromPhone): array
{
    if (!bakery_surveys_ready($db) || !function_exists('bakery_text_normalize_phone')) {
        return [];
    }
    $phone = bakery_text_normalize_phone($fromPhone);
    if ($phone === '') {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT * FROM surveys
         WHERE status = 'open' AND mode = 'text_reply' AND target_phone = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$phone]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

/**
 * Called from twilio_webhook.php after the inbound message lands on the
 * ledger: ties the reply to the sender's open text-reply survey, if any.
 */
function bakery_survey_record_inbound_reply(PDO $db, string $fromPhone, int $messageRowId, string $body): int
{
    $survey = bakery_survey_open_for_phone($db, $fromPhone);
    if (!$survey) {
        return 0;
    }
    return bakery_survey_record_response($db, [
        'survey_id' => (int)$survey['id'],
        'text_message_id' => $messageRowId > 0 ? $messageRowId : null,
        'action' => 'reply',
        'response' => $body,
    ]);
}

function bakery_survey_record_response(PDO $db, array $fields): int
{
    $stmt = $db->prepare(
        'INSERT INTO survey_responses (survey_id, text_message_id, action, daily_order_id, customer_id, response, question_key, respondent)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int)$fields['survey_id'],
        isset($fields['text_message_id']) && (int)$fields['text_message_id'] > 0 ? (int)$fields['text_message_id'] : null,
        substr(trim((string)($fields['action'] ?? 'reply')), 0, 24),
        isset($fields['daily_order_id']) && (int)$fields['daily_order_id'] > 0 ? (int)$fields['daily_order_id'] : null,
        isset($fields['customer_id']) && (int)$fields['customer_id'] > 0 ? (int)$fields['customer_id'] : null,
        isset($fields['response']) ? substr(trim((string)$fields['response']), 0, 16000) : null,
        isset($fields['question_key']) ? substr(trim((string)$fields['question_key']), 0, 24) : null,
        isset($fields['respondent']) ? substr(trim((string)$fields['respondent']), 0, 80) : null,
    ]);
    return (int)$db->lastInsertId();
}

function bakery_survey_set_status(PDO $db, int $surveyId, string $status): void
{
    if (!in_array($status, ['open', 'closed'], true)) {
        throw new RuntimeException('Unknown survey status');
    }
    $stmt = $db->prepare(
        $status === 'closed'
            ? "UPDATE surveys SET status = 'closed', closed_at = NOW() WHERE id = ?"
            : "UPDATE surveys SET status = 'open', closed_at = NULL WHERE id = ?"
    );
    $stmt->execute([$surveyId]);
}

/**
 * Aggregated results for the Command Center detail view.
 *
 * @return array{
 *   questions: list<array{key:string,text:string,type:string,options:array,total:int,
 *                          tally:array<string,int>,free:list<array{respondent:string,text:string,at:string}>}>,
 *   actions: list<array<string,mixed>>,
 *   action_counts: array<string,int>,
 *   respondents: int
 * }
 */
function bakery_survey_results(PDO $db, int $surveyId): array
{
    $survey = bakery_survey_find_by_id($db, $surveyId);
    $questions = bakery_survey_questions($survey);

    $stmt = $db->prepare(
        'SELECT action, question_key, respondent, response, daily_order_id, customer_id, created_at
         FROM survey_responses
         WHERE survey_id = ? AND action <> \'sent\'
         ORDER BY id ASC'
    );
    $stmt->execute([$surveyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Resolve names once for route-review actions.
    $customerNames = [];
    $customerIds = [];
    foreach ($rows as $r) {
        if (!empty($r['customer_id'])) {
            $customerIds[(int)$r['customer_id']] = true;
        }
    }
    if ($customerIds !== [] && function_exists('table_exists') && table_exists($db, 'customers')) {
        $ids = implode(',', array_map('intval', array_keys($customerIds)));
        foreach ($db->query("SELECT id, name FROM customers WHERE id IN ($ids)") as $row) {
            $customerNames[(int)$row['id']] = (string)$row['name'];
        }
    }

    foreach ($questions as &$q) {
        $q['total'] = 0;
        $q['tally'] = [];
        $q['free'] = [];
        unset($q);
    }
    unset($q);
    $byKey = [];
    foreach ($questions as $i => $q) {
        $byKey[$q['key']] = $i;
    }

    $actions = [];
    $actionCounts = [];
    $respondents = [];

    foreach ($rows as $r) {
        $action = (string)$r['action'];
        $actionCounts[$action] = ($actionCounts[$action] ?? 0) + 1;

        if ($action === 'answer' || $action === 'reply') {
            $key = (string)($r['question_key'] ?? '');
            if ($key === '' && count($questions) === 1) {
                $key = $questions[0]['key'];
            }
            if ($key !== '' && isset($byKey[$key])) {
                $qi = $byKey[$key];
                $label = bakery_survey_answer_label($questions[$qi], (string)$r['response']);
                $questions[$qi]['total']++;
                if ($questions[$qi]['type'] === 'text') {
                    $questions[$qi]['free'][] = [
                        'respondent' => (string)($r['respondent'] ?? ''),
                        'text' => (string)$r['response'],
                        'at' => (string)$r['created_at'],
                    ];
                } elseif ($questions[$qi]['type'] === 'choice') {
                    $questions[$qi]['tally'][$label] = ($questions[$qi]['tally'][$label] ?? 0) + 1;
                    $respondents[(string)($r['respondent'] ?? '?')] = true;
                } else {
                    $questions[$qi]['tally'][$label] = ($questions[$qi]['tally'][$label] ?? 0) + 1;
                    $respondents[(string)($r['respondent'] ?? '?')] = true;
                }
                continue;
            }
        }

        $actions[] = [
            'action' => $action,
            'respondent' => (string)($r['respondent'] ?? ''),
            'response' => (string)($r['response'] ?? ''),
            'customer' => !empty($r['customer_id']) ? (string)($customerNames[(int)$r['customer_id']] ?? ('#' . $r['customer_id'])) : '',
            'daily_order_id' => (int)($r['daily_order_id'] ?? 0),
            'created_at' => (string)$r['created_at'],
        ];

        if (($r['respondent'] ?? '') !== '') {
            $respondents[(string)$r['respondent']] = true;
        }
    }

    return [
        'questions' => $questions,
        'actions' => $actions,
        'action_counts' => $actionCounts,
        'respondents' => count($respondents),
    ];
}

/**
 * Full clickable URL for the survey link page — SMS links must be absolute.
 * Falls back to a relative path only when there is no request context (CLI).
 */
function bakery_survey_link_url(string $token): string
{
    $path = 'survey.php?t=' . rawurlencode($token);
    $host = function_exists('bakery_request_host') ? bakery_request_host() : '';
    if ($host === '') {
        return (defined('BASE_URL') ? BASE_URL : '/') . $path;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || $host === 'staging.sourflour.org'
        || $host === 'bakery.sourflour.org';
    return ($https ? 'https://' : 'http://') . $host . (defined('BASE_URL') ? BASE_URL : '/') . $path;
}

/** i18n with plain fallback so CLI tests and the webhook can use this file. */
function bakery_survey_text(string $key, array $params = [], string $fallback = ''): string
{
    if (function_exists('bakery_t')) {
        return bakery_t($key, $params);
    }
    $text = $fallback;
    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string)$value, $text);
    }
    return $text;
}

/** The outbound SMS body for a survey. */
function bakery_survey_build_message(array $survey): string
{
    $mode = (string)$survey['mode'];
    $kind = (string)$survey['kind'];

    $headline = trim((string)($survey['title'] ?? ''));
    $questions = bakery_survey_questions($survey);
    if ($headline === '' && count($questions) > 1) {
        $headline = (string)bakery_survey_text('survey.msg_multi_head', ['count' => count($questions)], 'Quick survey — :count questions:');
        $numbered = '';
        foreach ($questions as $i => $q) {
            $numbered .= "\n" . ($i + 1) . '. ' . $q['text'];
        }
        $headline = str_replace(':count', (string)count($questions), $headline) . $numbered;
    }

    if ($kind === 'route_review' || $kind === 'store_verify') {
        $dateLabel = (string)$survey['delivery_date'];
        if ($mode === 'link') {
            $msgKey = $kind === 'store_verify' ? 'survey.msg_store_verify_link' : 'survey.msg_route_review_link';
            $fallback = $kind === 'store_verify'
                ? 'Which stores can you cover on :date? Tap to confirm:'
                : 'Quick route check for :date — tap the link to skip stores you cannot cover or claim open stops:';
            $body = bakery_survey_text($msgKey, ['date' => $dateLabel], $fallback);
            $body .= "\n" . bakery_survey_link_url((string)$survey['token']);
        } else {
            $body = bakery_survey_text(
                'survey.msg_route_review_reply',
                ['date' => $dateLabel],
                'Route check for :date — reply with any store you cannot cover or want added.'
            );
        }
        return $body;
    }

    if ($headline !== '') {
        $body = $headline;
    } else {
        $body = trim((string)($survey['question'] ?? ''));
    }

    if ($mode === 'link') {
        $body .= "\n" . bakery_survey_text('survey.msg_question_link_tail', [], 'Tap to answer:');
        $body .= "\n" . bakery_survey_link_url((string)$survey['token']);
    } else {
        $body .= "\n" . bakery_survey_text('survey.msg_question_reply_tail', [], 'Reply to this text to answer.');
    }
    return $body;
}

/** Compose + send in one step. Returns [survey, send] where send is bakery_text_send's result. */
function bakery_survey_send(PDO $db, array $survey, int $staffUserId): array
{
    $to = (string)$survey['target_phone'];
    $body = bakery_survey_build_message($survey);
    $send = bakery_text_send($db, $to, $body, [
        'staff_user_id' => $staffUserId,
        'context_type' => $survey['audience'] === 'driver' ? 'driver' : 'general',
        'operating_date' => (string)($survey['delivery_date'] ?? date('Y-m-d')),
    ]);
    if (!empty($send['id'])) {
        bakery_survey_record_response($db, [
            'survey_id' => (int)$survey['id'],
            'text_message_id' => (int)$send['id'],
            'action' => 'sent',
        ]);
    }
    return ['survey' => $survey, 'send' => $send];
}

/**
 * Route review payload for the link page: the driver's pending stops plus
 * today's unassigned candidates. Reuses the canonical plan-search queries so
 * the survey never shows a parallel version of the route truth.
 */
function bakery_survey_route_review_data(PDO $db, int $driverId, string $deliveryDate): array
{
    $origin = bakery_sfb_ops_origin_clause('c', $db);
    $pieceSelect = '(SELECT COALESCE(SUM(doi.quantity), 0) FROM daily_order_items doi WHERE doi.daily_order_id = do.id)';
    $stopsStmt = $db->prepare(
        "SELECT doa.id AS assignment_id, doa.daily_order_id, doa.delivery_status,
                doa.notes AS stop_notes,
                c.id AS customer_id, c.name AS customer_name, c.zone,
                {$pieceSelect} AS pieces
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         {$origin}
         WHERE doa.driver_id = ? AND doa.delivery_date = ?
           AND doa.delivery_status IN ('pending', 'in_transit', 'cancelled')
         ORDER BY CAST(doa.route_order AS UNSIGNED), doa.id"
    );
    $stopsStmt->execute([$driverId, $deliveryDate]);
    $stops = [];
    foreach ($stopsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        // Skipped stops surface as cancelled; keep them visible so the driver
        // can restore straight from the survey page.
        $row['skipped'] = ((string)$row['delivery_status'] === 'cancelled');
        if ($row['skipped'] && $row['stop_notes'] !== null && stripos((string)$row['stop_notes'], 'Skipped:') === 0) {
            $row['skip_reason'] = trim(substr((string)$row['stop_notes'], 8));
        }
        $stops[] = $row;
    }

    $plan = bakery_driver_plan_search($db, $driverId, $deliveryDate, '');

    return [
        'stops' => $stops,
        'unassigned' => $plan['unassigned'],
    ];
}

/** Active drivers for the survey composer dropdown. */
function bakery_survey_driver_choices(PDO $db): array
{
    return $db->query('SELECT id, name FROM drivers WHERE archived = 0 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Turn the Command Center composer's parallel arrays
 * (q_text[], q_type[], q_options[] newline-separated) into create() input.
 */
function bakery_survey_collect_questions_from_post(array $post): array
{
    $out = [];
    $texts = isset($post['q_text']) && is_array($post['q_text']) ? $post['q_text'] : [];
    $types = isset($post['q_type']) && is_array($post['q_type']) ? $post['q_type'] : [];
    $optionLines = isset($post['q_options']) && is_array($post['q_options']) ? $post['q_options'] : [];
    foreach (array_keys($texts) as $i) {
        if (!is_scalar($texts[$i])) {
            continue;
        }
        $options = [];
        if (isset($optionLines[$i]) && is_string($optionLines[$i])) {
            foreach (explode("\n", $optionLines[$i]) as $opt) {
                $opt = trim($opt);
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
        }
        $out[] = [
            'text' => (string)$texts[$i],
            'type' => is_string($types[$i] ?? null) ? $types[$i] : 'text',
            'options' => $options,
        ];
    }
    return $out;
}

/**
 * Open (or create) the store-verify survey for this driver + next delivery day
 * so a logged-in driver can tap survey.php without a prior token.
 */
function bakery_survey_ensure_store_verify(PDO $db, int $driverId, string $deliveryDate, int $createdBy = 0): array
{
    $deliveryDate = bakery_survey_validate_ymd($deliveryDate);
    if ($driverId <= 0) {
        $stmt = $db->prepare(
            "SELECT * FROM surveys
             WHERE delivery_date = ? AND status = 'open' AND kind = 'store_verify'
               AND (driver_id IS NULL OR driver_id = 0)
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$deliveryDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
        return bakery_survey_create($db, [
            'mode' => 'link',
            'kind' => 'store_verify',
            'audience' => 'driver',
            'driver_id' => 0,
            'delivery_date' => $deliveryDate,
            'created_by' => $createdBy,
            'title' => 'HQ store verify',
        ]);
    }
    $stmt = $db->prepare(
        "SELECT * FROM surveys
         WHERE driver_id = ? AND delivery_date = ? AND status = 'open'
           AND kind IN ('store_verify', 'route_review')
         ORDER BY (kind = 'store_verify') DESC, id DESC
         LIMIT 1"
    );
    $stmt->execute([$driverId, $deliveryDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }
    return bakery_survey_create($db, [
        'mode' => 'link',
        'kind' => 'store_verify',
        'audience' => 'driver',
        'driver_id' => $driverId,
        'delivery_date' => $deliveryDate,
        'created_by' => $createdBy,
        'title' => 'Store verify',
    ]);
}
