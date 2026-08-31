<?php
/**
 * Survey link interaction telemetry: opens (GET) and submits (POST).
 * Matches anonymous token clicks to staff sessions, SMS targets, and login history.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/login_audit.php';

function bakery_survey_interactions_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'survey_interactions');
}

function bakery_survey_interactions_request_context(): array
{
    $ctx = bakery_login_audit_request_context();
    return [
        'ip_address' => $ctx['ip_address'] ?? null,
        'user_agent' => $ctx['user_agent'] ?? null,
        'referer' => $ctx['referer'] ?? null,
        'request_uri' => $ctx['request_uri'] ?? null,
        'session_hash' => $ctx['session_hash'] ?? null,
    ];
}

/**
 * Best-effort identity for a survey interaction.
 *
 * @return array{guessed_name:string,match_source:string,staff_user_id:?int,driver_id:?int,target_phone:string}
 */
function bakery_survey_interactions_guess_actor(PDO $db, array $survey, ?int $staffUserId, ?int $driverId): array
{
    $targetPhone = trim((string)($survey['target_phone'] ?? ''));
    $out = [
        'guessed_name' => '',
        'match_source' => '',
        'staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
        'driver_id' => $driverId > 0 ? $driverId : null,
        'target_phone' => $targetPhone,
    ];

    if ($staffUserId > 0 && table_exists($db, 'users')) {
        $stmt = $db->prepare('SELECT display_name, driver_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$staffUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $out['guessed_name'] = trim((string)($row['display_name'] ?? ''));
            $out['match_source'] = 'session';
            if ($out['driver_id'] === null && !empty($row['driver_id'])) {
                $out['driver_id'] = (int)$row['driver_id'];
            }
            if ($out['guessed_name'] !== '') {
                return $out;
            }
        }
    }

    if ($driverId > 0 && table_exists($db, 'drivers')) {
        $stmt = $db->prepare('SELECT name FROM drivers WHERE id = ? LIMIT 1');
        $stmt->execute([$driverId]);
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        if ($name !== '') {
            $out['guessed_name'] = $name;
            $out['match_source'] = 'session';
            return $out;
        }
    }

    if ($targetPhone !== '' && function_exists('bakery_text_normalize_phone')) {
        $normalized = bakery_text_normalize_phone($targetPhone);
        if ($normalized !== '' && table_exists($db, 'text_messages')) {
            $stmt = $db->prepare(
                "SELECT staff_user_id, customer_id
                 FROM text_messages
                 WHERE direction = 'outbound' AND to_number = ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$normalized]);
            $msg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($msg && !empty($msg['staff_user_id']) && table_exists($db, 'users')) {
                $uStmt = $db->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
                $uStmt->execute([(int)$msg['staff_user_id']]);
                $name = trim((string)($uStmt->fetchColumn() ?: ''));
                if ($name !== '') {
                    $out['guessed_name'] = $name;
                    $out['match_source'] = 'sms_target';
                    $out['staff_user_id'] = (int)$msg['staff_user_id'];
                    return $out;
                }
            }
            if ($msg && !empty($msg['customer_id']) && table_exists($db, 'customers')) {
                $cStmt = $db->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
                $cStmt->execute([(int)$msg['customer_id']]);
                $name = trim((string)($cStmt->fetchColumn() ?: ''));
                if ($name !== '') {
                    $out['guessed_name'] = $name;
                    $out['match_source'] = 'sms_target';
                    return $out;
                }
            }
        }
    }

    $ctx = bakery_survey_interactions_request_context();
    $ip = trim((string)($ctx['ip_address'] ?? ''));
    if ($ip !== '' && bakery_login_audit_ready($db)) {
        $stmt = $db->prepare(
            "SELECT la.user_id, u.display_name
             FROM login_audit la
             JOIN users u ON u.id = la.user_id
             WHERE la.auth_type = 'staff'
               AND la.outcome = 'success'
               AND la.ip_address = ?
               AND la.login_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
             ORDER BY la.login_at DESC
             LIMIT 1"
        );
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $name = trim((string)($row['display_name'] ?? ''));
            if ($name !== '') {
                $out['guessed_name'] = $name;
                $out['match_source'] = 'login_ip';
                $out['staff_user_id'] = (int)$row['user_id'];
                return $out;
            }
        }
    }

    $surveyDriverId = (int)($survey['driver_id'] ?? 0);
    if ($surveyDriverId > 0 && table_exists($db, 'drivers')) {
        $stmt = $db->prepare('SELECT name FROM drivers WHERE id = ? LIMIT 1');
        $stmt->execute([$surveyDriverId]);
        $name = trim((string)($stmt->fetchColumn() ?: ''));
        if ($name !== '') {
            $out['guessed_name'] = $name;
            $out['match_source'] = 'survey_driver';
            $out['driver_id'] = $surveyDriverId;
            return $out;
        }
    }

    if ($targetPhone !== '') {
        $out['match_source'] = 'phone_only';
        $out['guessed_name'] = $targetPhone;
    }

    return $out;
}

function bakery_survey_interactions_recent_duplicate(
    PDO $db,
    int $surveyId,
    string $type,
    ?string $ip,
    ?string $sessionHash,
    int $withinMinutes = 3
): bool {
    if ($surveyId <= 0 || $type !== 'open') {
        return false;
    }
    $sql = 'SELECT id FROM survey_interactions
            WHERE survey_id = ? AND interaction_type = ?
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)';
    $params = [$surveyId, $type, max(1, $withinMinutes)];
    if ($sessionHash !== null && $sessionHash !== '') {
        $sql .= ' AND session_hash = ?';
        $params[] = $sessionHash;
    } elseif ($ip !== null && $ip !== '') {
        $sql .= ' AND ip_address = ?';
        $params[] = $ip;
    } else {
        return false;
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

/**
 * Record a survey open or submit interaction.
 *
 * @param array<string,mixed> $survey
 * @param array<string,mixed> $opts submit_action, staff_user_id, driver_id, survey_response_id
 */
function bakery_survey_record_interaction(PDO $db, array $survey, string $type, array $opts = []): int
{
    if (!bakery_survey_interactions_ready($db)) {
        return 0;
    }
    $surveyId = (int)($survey['id'] ?? 0);
    if ($surveyId <= 0) {
        return 0;
    }
    $type = $type === 'submit' ? 'submit' : 'open';
    $ctx = bakery_survey_interactions_request_context();
    $staffUserId = isset($opts['staff_user_id']) ? (int)$opts['staff_user_id'] : 0;
    $driverId = isset($opts['driver_id']) ? (int)$opts['driver_id'] : 0;

    if ($type === 'open' && bakery_survey_interactions_recent_duplicate(
        $db,
        $surveyId,
        $type,
        $ctx['ip_address'] ?? null,
        $ctx['session_hash'] ?? null
    )) {
        return 0;
    }

    $actor = bakery_survey_interactions_guess_actor($db, $survey, $staffUserId ?: null, $driverId ?: null);
    if ($staffUserId <= 0 && !empty($actor['staff_user_id'])) {
        $staffUserId = (int)$actor['staff_user_id'];
    }
    if ($driverId <= 0 && !empty($actor['driver_id'])) {
        $driverId = (int)$actor['driver_id'];
    }

    $submitAction = isset($opts['submit_action']) ? substr(trim((string)$opts['submit_action']), 0, 24) : null;
    if ($submitAction === '') {
        $submitAction = null;
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO survey_interactions
             (survey_id, interaction_type, submit_action, staff_user_id, driver_id, survey_response_id,
              target_phone, guessed_name, match_source, ip_address, user_agent, referer, request_uri, session_hash)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $surveyId,
            $type,
            $submitAction,
            $staffUserId > 0 ? $staffUserId : null,
            $driverId > 0 ? $driverId : null,
            isset($opts['survey_response_id']) && (int)$opts['survey_response_id'] > 0
                ? (int)$opts['survey_response_id'] : null,
            $actor['target_phone'] !== '' ? substr($actor['target_phone'], 0, 32) : null,
            $actor['guessed_name'] !== '' ? substr($actor['guessed_name'], 0, 120) : null,
            $actor['match_source'] !== '' ? substr($actor['match_source'], 0, 24) : null,
            isset($ctx['ip_address']) ? substr((string)$ctx['ip_address'], 0, 45) : null,
            isset($ctx['user_agent']) ? (string)$ctx['user_agent'] : null,
            isset($ctx['referer']) ? substr((string)$ctx['referer'], 0, 1024) : null,
            isset($ctx['request_uri']) ? substr((string)$ctx['request_uri'], 0, 1024) : null,
            isset($ctx['session_hash']) ? substr((string)$ctx['session_hash'], 0, 64) : null,
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('survey interaction record: ' . $e->getMessage());
        return 0;
    }
}

/**
 * @return array{opens:int,submits:int,rows:list<array<string,mixed>>}
 */
function bakery_survey_interactions_for_survey(PDO $db, int $surveyId, int $limit = 100): array
{
    if (!bakery_survey_interactions_ready($db) || $surveyId <= 0) {
        return ['opens' => 0, 'submits' => 0, 'rows' => []];
    }
    $limit = max(1, min(500, $limit));
    $counts = $db->prepare(
        "SELECT interaction_type, COUNT(*) AS n
         FROM survey_interactions WHERE survey_id = ? GROUP BY interaction_type"
    );
    $counts->execute([$surveyId]);
    $opens = 0;
    $submits = 0;
    foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ((string)$c['interaction_type'] === 'open') {
            $opens = (int)$c['n'];
        } elseif ((string)$c['interaction_type'] === 'submit') {
            $submits = (int)$c['n'];
        }
    }

    $stmt = $db->prepare(
        "SELECT si.*, u.display_name AS staff_name, d.name AS driver_name
         FROM survey_interactions si
         LEFT JOIN users u ON u.id = si.staff_user_id
         LEFT JOIN drivers d ON d.id = si.driver_id
         WHERE si.survey_id = ?
         ORDER BY si.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$surveyId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['opens' => $opens, 'submits' => $submits, 'rows' => $rows];
}

/**
 * Cross-survey feed for managers.
 *
 * @return list<array<string,mixed>>
 */
function bakery_survey_interactions_recent(PDO $db, int $limit = 60): array
{
    if (!bakery_survey_interactions_ready($db)) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $stmt = $db->query(
        "SELECT si.*, s.kind, s.token, s.target_phone AS survey_phone, s.delivery_date,
                u.display_name AS staff_name, d.name AS driver_name
         FROM survey_interactions si
         JOIN surveys s ON s.id = si.survey_id
         LEFT JOIN users u ON u.id = si.staff_user_id
         LEFT JOIN drivers d ON d.id = si.driver_id
         ORDER BY si.id DESC
         LIMIT {$limit}"
    );
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/** Human label for match_source in the staff UI. */
function bakery_survey_interaction_match_label(string $source): string
{
    $key = 'texts.survey_match_' . preg_replace('/[^a-z0-9_]/', '', strtolower($source));
    if (function_exists('bakery_t')) {
        $label = bakery_t($key, [], '');
        if ($label !== '' && $label !== $key) {
            return $label;
        }
    }
    $fallbacks = [
        'session' => 'Signed in',
        'sms_target' => 'SMS recipient',
        'login_ip' => 'Same IP as login',
        'survey_driver' => 'Intended driver',
        'phone_only' => 'Phone on survey',
    ];
    return $fallbacks[$source] ?? $source;
}

/** Display name for one interaction row. */
function bakery_survey_interaction_who_label(array $row): string
{
    $staff = trim((string)($row['staff_name'] ?? ''));
    if ($staff !== '') {
        return $staff;
    }
    $driver = trim((string)($row['driver_name'] ?? ''));
    if ($driver !== '') {
        return $driver;
    }
    $guessed = trim((string)($row['guessed_name'] ?? ''));
    if ($guessed !== '') {
        return $guessed;
    }
    $phone = trim((string)($row['target_phone'] ?? ''));
    return $phone !== '' ? $phone : '?';
}

/** Record a submit interaction after a survey action succeeds. */
function bakery_survey_track_submit(PDO $db, array $survey, string $action, ?int $staffUserId = null, ?int $driverId = null): void
{
    bakery_survey_record_interaction($db, $survey, 'submit', [
        'submit_action' => $action,
        'staff_user_id' => $staffUserId,
        'driver_id' => $driverId,
    ]);
}
