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
 * row. The link page rides the existing staff/driver session gate — no second
 * auth system.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/driver_assignments.php';
require_once __DIR__ . '/driver_route_prep.php';
require_once __DIR__ . '/delivery_skip.php';

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
    return ['route_review', 'question'];
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
    if ($kind === 'route_review' && $audience !== 'driver') {
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
    if ($audience === 'driver') {
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
    if ($kind === 'route_review') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            throw new RuntimeException('Route review needs a valid delivery date');
        }
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
        $deliveryDate = '';
    }

    $question = trim((string)($fields['question'] ?? ''));
    if ($kind === 'question' && $question === '') {
        throw new RuntimeException('Question surveys need question text');
    }
    if (mb_strlen($question) > 500) {
        $question = mb_substr($question, 0, 500);
    }

    $token = bin2hex(random_bytes(16));
    $stmt = $db->prepare(
        'INSERT INTO surveys (token, mode, kind, audience, driver_id, staff_user_id, target_phone, question, delivery_date, status, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $token,
        $mode,
        $kind,
        $audience,
        $audience === 'driver' ? $driverId : null,
        $audience === 'staff' ? (int)($fields['staff_user_id'] ?? 0) ?: null : null,
        $phone,
        $question !== '' ? $question : null,
        $deliveryDate !== '' ? $deliveryDate : null,
        'open',
        (int)($fields['created_by'] ?? 0) ?: null,
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
        'INSERT INTO survey_responses (survey_id, text_message_id, action, daily_order_id, customer_id, response)
         VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int)$fields['survey_id'],
        isset($fields['text_message_id']) && (int)$fields['text_message_id'] > 0 ? (int)$fields['text_message_id'] : null,
        substr(trim((string)($fields['action'] ?? 'reply')), 0, 24),
        isset($fields['daily_order_id']) && (int)$fields['daily_order_id'] > 0 ? (int)$fields['daily_order_id'] : null,
        isset($fields['customer_id']) && (int)$fields['customer_id'] > 0 ? (int)$fields['customer_id'] : null,
        isset($fields['response']) ? substr(trim((string)$fields['response']), 0, 2000) : null,
    ]);
    return (int)$db->lastInsertId();
}

function bakery_survey_close(PDO $db, int $surveyId): void
{
    $stmt = $db->prepare("UPDATE surveys SET status = 'closed', closed_at = NOW() WHERE id = ?");
    $stmt->execute([$surveyId]);
}

function bakery_survey_link_url(string $token): string
{
    return (defined('BASE_URL') ? BASE_URL : '/') . 'survey.php?t=' . rawurlencode($token);
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
    if ($kind === 'route_review') {
        $dateLabel = (string)$survey['delivery_date'];
        if ($mode === 'link') {
            $body = bakery_survey_text(
                'survey.msg_route_review_link',
                ['date' => $dateLabel],
                'Quick route check for :date — tap the link to skip stores you cannot cover or claim open stops:'
            );
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
    $question = trim((string)($survey['question'] ?? ''));
    if ($mode === 'link') {
        $body = $question . "\n" . bakery_survey_text('survey.msg_question_link_tail', [], 'Tap to answer:');
        $body .= "\n" . bakery_survey_link_url((string)$survey['token']);
    } else {
        $body = $question . "\n" . bakery_survey_text('survey.msg_question_reply_tail', [], 'Reply to this text to answer.');
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
