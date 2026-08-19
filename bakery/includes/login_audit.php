<?php
/**
 * Login/session telemetry. Location is deliberately opt-in in the browser;
 * device and network fields are captured from the request for administrators.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_login_audit_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'login_audit');
}

function bakery_login_audit_activity_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'login_audit_activity');
}

/** Record a safe, human-readable navigation marker for the current session. */
function bakery_login_audit_record_activity(PDO $db, int $auditId, string $eventType, array $details = []): void
{
    if ($auditId <= 0 || !bakery_login_audit_activity_ready($db)) {
        return;
    }
    $allowedTypes = ['session_started', 'session_ended', 'page_view'];
    if (!in_array($eventType, $allowedTypes, true)) {
        return;
    }
    try {
        $pagePath = isset($details['page_path']) && is_scalar($details['page_path'])
            ? substr((string)$details['page_path'], 0, 1024) : null;
        $pageTitle = isset($details['page_title']) && is_scalar($details['page_title'])
            ? substr(trim((string)$details['page_title']), 0, 255) : null;
        $metadata = [];
        foreach (['viewport', 'timezone', 'platform'] as $key) {
            if (isset($details[$key]) && is_scalar($details[$key])) {
                $metadata[$key] = substr((string)$details[$key], 0, 100);
            }
        }
        $stmt = $db->prepare(
            'INSERT INTO login_audit_activity (login_audit_id, event_type, page_path, page_title, client_metadata)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $auditId,
            $eventType,
            $pagePath ?: null,
            $pageTitle ?: null,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Login audit activity record error: ' . $e->getMessage());
    }
}

function bakery_ensure_login_audit_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (function_exists('bakery_runtime_schema_ddl_allowed') && !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    $schemaDir = dirname(__DIR__) . '/database/schema';
    if (!bakery_login_audit_ready($db)) {
        $path = $schemaDir . '/027_login_audit.sql';
        if (is_readable($path)) {
            try {
                $sql = file_get_contents($path);
                if ($sql !== false && trim($sql) !== '') {
                    $db->exec($sql);
                }
                if (function_exists('bakery_forget_table_exists')) {
                    bakery_forget_table_exists('login_audit');
                }
            } catch (Throwable $e) {
                error_log('Login audit schema error: ' . $e->getMessage());
            }
        }
    }
    if (bakery_login_audit_ready($db) && !bakery_login_audit_activity_ready($db)) {
        $path = $schemaDir . '/036_login_audit_activity.sql';
        if (is_readable($path)) {
            try {
                $sql = file_get_contents($path);
                if ($sql !== false && trim($sql) !== '') {
                    $db->exec($sql);
                }
                if (function_exists('bakery_forget_table_exists')) {
                    bakery_forget_table_exists('login_audit_activity');
                }
            } catch (Throwable $e) {
                error_log('Login audit activity schema error: ' . $e->getMessage());
            }
        }
    }
    $done = true;
}

function bakery_login_audit_parse_user_agent(string $ua): array
{
    $browser = 'Unknown browser';
    if (preg_match('/Edg(?:e|A|iOS)?\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Edge ' . $m[1];
    } elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Chrome ' . $m[1];
    } elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Firefox ' . $m[1];
    } elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) {
        $browser = 'Safari ' . $m[1];
    } elseif (preg_match('/(?:OPR|Opera)\/([\d.]+)/i', $ua, $m)) {
        $browser = 'Opera ' . $m[1];
    }

    $os = 'Unknown OS';
    if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
        $os = 'Windows ' . $m[1];
    } elseif (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
        $os = 'Android ' . $m[1];
    } elseif (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
        $os = 'iOS ' . str_replace('_', '.', $m[1]);
    } elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
        $os = 'macOS ' . str_replace('_', '.', $m[1]);
    } elseif (stripos($ua, 'Linux') !== false) {
        $os = 'Linux';
    }

    $device = (preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'Mobile' : 'Desktop');
    if (stripos($ua, 'iPad') !== false) {
        $device = 'Tablet';
    }
    return ['browser' => $browser, 'operating_system' => $os, 'device_type' => $device];
}

function bakery_login_audit_request_context(): array
{
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
    $parsed = bakery_login_audit_parse_user_agent($ua);
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        $ip = null;
    }
    return array_merge($parsed, [
        'ip_address' => $ip,
        'user_agent' => $ua !== '' ? $ua : null,
        'request_method' => substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10) ?: null,
        'request_uri' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 1024) ?: null,
        'referer' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 1024) ?: null,
        'accept_language' => substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 255) ?: null,
        'forwarded_for' => substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), 0, 1000) ?: null,
        'server_protocol' => substr((string)($_SERVER['SERVER_PROTOCOL'] ?? ''), 0, 20) ?: null,
        'server_port' => !empty($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null,
        'session_id_hash' => session_id() !== '' ? hash_hmac('sha256', session_id(), bakery_login_audit_key()) : null,
    ]);
}

function bakery_login_audit_key(): string
{
    $dbKey = defined('DB_PASS') ? (string)DB_PASS : '';
    $dbName = defined('DB_NAME') ? (string)DB_NAME : 'bakery';
    return hash('sha256', 'sour-flour-login-audit|' . $dbName . '|' . $dbKey);
}

function bakery_login_audit_credential_context(string $credentialCode, string $authType): array
{
    $code = bakery_normalize_login_code($credentialCode);
    return [
        'method' => $authType === 'customer' ? 'customer_4_digit_code' : 'staff_4_digit_code',
        'fingerprint' => $code !== '' ? hash_hmac('sha256', $code, bakery_login_audit_key()) : null,
        'suffix' => $code !== '' ? substr($code, -2) : null,
    ];
}

function bakery_login_audit_record_failure(PDO $db, string $authType, string $principal = 'Unknown', string $credentialCode = ''): void
{
    try {
        bakery_ensure_login_audit_schema($db);
        if (!bakery_login_audit_ready($db)) {
            return;
        }
        $context = bakery_login_audit_request_context();
        $credential = bakery_login_audit_credential_context($credentialCode, $authType);
        $stmt = $db->prepare(
            'INSERT INTO login_audit
             (auth_type, principal, outcome, failure_reason, credential_method, credential_fingerprint, credential_suffix,
              ip_address, request_method, request_uri, referer, accept_language, forwarded_for, server_protocol,
              server_port, session_id_hash, user_agent, browser, operating_system, device_type)
             VALUES (?, ?, \'failure\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $authType === 'customer' ? 'customer' : 'staff',
            substr(trim($principal) !== '' ? trim($principal) : 'Unknown', 0, 255),
            'Invalid credentials',
            $credential['method'], $credential['fingerprint'], $credential['suffix'],
            $context['ip_address'], $context['request_method'], $context['request_uri'], $context['referer'],
            $context['accept_language'], $context['forwarded_for'], $context['server_protocol'], $context['server_port'],
            $context['session_id_hash'], $context['user_agent'], $context['browser'], $context['operating_system'],
            $context['device_type'],
        ]);
    } catch (Throwable $e) {
        error_log('Login audit failure record error: ' . $e->getMessage());
    }
}

function bakery_login_audit_start(PDO $db, string $authType, array $identity): void
{
    try {
        bakery_ensure_login_audit_schema($db);
        if (!bakery_login_audit_ready($db)) {
            return;
        }
        $context = bakery_login_audit_request_context();
        $metadata = [
            'timezone' => (string)($_SERVER['HTTP_TIMEZONE'] ?? ''),
            'started_by' => (string)($identity['started_by'] ?? 'server_login'),
        ];
        if (!empty($identity['impersonated_by_user_id'])) {
            $metadata['impersonated_by_user_id'] = (int)$identity['impersonated_by_user_id'];
            $metadata['impersonated_by'] = (string)($identity['impersonated_by'] ?? 'SFAdmin');
        }
        $stmt = $db->prepare(
            'INSERT INTO login_audit
             (auth_type, user_id, customer_id, principal, outcome, failure_reason, credential_method,
              credential_fingerprint, credential_suffix, login_at, last_seen_at, ip_address, request_method,
              request_uri, referer, accept_language, forwarded_for, server_protocol, server_port, session_id_hash,
              user_agent, browser, operating_system, device_type, client_metadata)
             VALUES (?, ?, ?, ?, \'success\', NULL, ?, ?, ?, COALESCE(?, NOW()), COALESCE(?, NOW()), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $credential = bakery_login_audit_credential_context((string)($identity['credential_code'] ?? ''), $authType);
        $loginAt = !empty($identity['login_at'])
            ? date('Y-m-d H:i:s', (int)$identity['login_at'])
            : null;
        $stmt->execute([
            $authType === 'customer' ? 'customer' : 'staff',
            !empty($identity['user_id']) ? (int)$identity['user_id'] : null,
            !empty($identity['customer_id']) ? (int)$identity['customer_id'] : null,
            substr(trim((string)($identity['principal'] ?? 'Unknown')), 0, 255),
            $credential['method'], $credential['fingerprint'], $credential['suffix'],
            $loginAt, $loginAt,
            $context['ip_address'], $context['request_method'], $context['request_uri'], $context['referer'],
            $context['accept_language'], $context['forwarded_for'], $context['server_protocol'], $context['server_port'],
            $context['session_id_hash'], $context['user_agent'], $context['browser'], $context['operating_system'],
            $context['device_type'],
            json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
        $_SESSION['login_audit_id'] = (int)$db->lastInsertId();
        $_SESSION['login_audit_auth_type'] = $authType === 'customer' ? 'customer' : 'staff';
        bakery_login_audit_record_activity($db, (int)$_SESSION['login_audit_id'], 'session_started', [
            'page_path' => $context['request_uri'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Login audit start error: ' . $e->getMessage());
    }
}

function bakery_login_audit_current_id(): int
{
    return !empty($_SESSION['login_audit_id']) ? (int)$_SESSION['login_audit_id'] : 0;
}

function bakery_login_audit_touch(PDO $db, array $client = [], bool $close = false): void
{
    $id = bakery_login_audit_current_id();
    if ($id <= 0) {
        return;
    }
    try {
        bakery_ensure_login_audit_schema($db);
        if (!bakery_login_audit_ready($db)) {
            return;
        }
        $client = is_array($client) ? $client : [];
        $status = strtolower(trim((string)($client['location_status'] ?? '')));
        $allowed = ['not_requested', 'granted', 'denied', 'unavailable', 'error'];
        if (!in_array($status, $allowed, true)) {
            $status = null;
        }
        $lat = filter_var($client['gps_latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($client['gps_longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $accuracy = filter_var($client['gps_accuracy_m'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($accuracy === false) {
            $accuracy = null;
        }
        if ($status !== 'granted' || $lat === false || $lng === false) {
            $lat = $lng = null;
            if ($status === 'granted') {
                $status = 'error';
            }
        }
        $metadata = [];
        foreach (['screen', 'viewport', 'platform', 'language', 'languages', 'timezone', 'device_memory', 'hardware_concurrency', 'touch_points', 'cookie_enabled', 'online', 'do_not_track', 'color_depth', 'orientation', 'vendor', 'app_version', 'connection', 'storage', 'user_agent_data', 'page_path'] as $key) {
            if (isset($client[$key]) && is_scalar($client[$key])) {
                $metadata[$key] = substr((string)$client[$key], 0, 100);
            }
        }
        if (isset($client['page_path']) && is_scalar($client['page_path'])) {
            $client['page_path'] = substr((string)$client['page_path'], 0, 1024);
        }
        $metadata['last_client_update'] = date('c');
        $metadataJson = $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null;
        $context = bakery_login_audit_request_context();
        $pagePath = !empty($client['page_path']) ? $client['page_path'] : null;
        if ($close) {
            $stmt = $db->prepare(
                'UPDATE login_audit
                 SET last_seen_at = NOW(), logout_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, login_at, NOW()),
                     client_metadata = COALESCE(?, client_metadata),
                     last_page_path = COALESCE(?, last_page_path), last_page_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE last_page_at END,
                     page_views_count = page_views_count + CASE WHEN ? IS NOT NULL THEN 1 ELSE 0 END,
                     location_status = COALESCE(?, location_status), gps_latitude = COALESCE(?, gps_latitude),
                     gps_longitude = COALESCE(?, gps_longitude), gps_accuracy_m = COALESCE(?, gps_accuracy_m),
                     location_captured_at = CASE WHEN ? = \'granted\' THEN NOW() ELSE location_captured_at END
                 WHERE id = ? AND outcome = \'success\' AND logout_at IS NULL'
            );
            $stmt->execute([$metadataJson, $pagePath, $pagePath, $pagePath, $status, $lat, $lng, $accuracy, $status, $id]);
            bakery_login_audit_record_activity($db, $id, 'session_ended', [
                'page_path' => $pagePath,
                'page_title' => $client['page_title'] ?? null,
            ]);
            unset($_SESSION['login_audit_id']);
            unset($_SESSION['login_audit_auth_type']);
            return;
        }
        $stmt = $db->prepare(
            'UPDATE login_audit
             SET last_seen_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, login_at, NOW()),
                 browser = COALESCE(NULLIF(?, \'Unknown browser\'), browser),
                 operating_system = COALESCE(NULLIF(?, \'Unknown OS\'), operating_system),
                 device_type = COALESCE(NULLIF(?, \'\'), device_type), client_metadata = COALESCE(?, client_metadata),
                 last_page_path = COALESCE(?, last_page_path), last_page_at = CASE WHEN ? IS NOT NULL THEN NOW() ELSE last_page_at END,
                 page_views_count = page_views_count + CASE WHEN ? IS NOT NULL THEN 1 ELSE 0 END,
                 location_status = COALESCE(?, location_status), gps_latitude = COALESCE(?, gps_latitude),
                 gps_longitude = COALESCE(?, gps_longitude), gps_accuracy_m = COALESCE(?, gps_accuracy_m),
                 location_captured_at = CASE WHEN ? = \'granted\' THEN NOW() ELSE location_captured_at END
             WHERE id = ? AND outcome = \'success\' AND logout_at IS NULL'
        );
        $stmt->execute([
            $context['browser'], $context['operating_system'], $context['device_type'], $metadataJson,
            $pagePath, $pagePath, $pagePath,
            $status, $lat, $lng, $accuracy, $status, $id,
        ]);
        if (($client['event_type'] ?? '') === 'page_view' && $pagePath) {
            bakery_login_audit_record_activity($db, $id, 'page_view', $client);
        }
    } catch (Throwable $e) {
        error_log('Login audit touch error: ' . $e->getMessage());
    }
}

function bakery_login_audit_close(PDO $db): void
{
    bakery_login_audit_touch($db, [], true);
}
