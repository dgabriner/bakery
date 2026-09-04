<?php
/**
 * Browser error beacons (mission 36 js-safety-net).
 * Table: client_errors (schema 077). Written by client_error_api.php.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_client_errors_ready(PDO $db): bool
{
    return table_exists($db, 'client_errors');
}

function bakery_client_errors_ensure(PDO $db): void
{
    if (bakery_client_errors_ready($db)) {
        return;
    }
    $db->exec("
        CREATE TABLE IF NOT EXISTS client_errors (
          id BIGINT NOT NULL AUTO_INCREMENT,
          user_id INT NULL DEFAULT NULL,
          login_audit_id BIGINT NULL DEFAULT NULL,
          kind VARCHAR(40) NOT NULL DEFAULT 'error',
          message VARCHAR(500) NOT NULL DEFAULT '',
          stack_head VARCHAR(2000) NULL DEFAULT NULL,
          page_path VARCHAR(1024) NULL DEFAULT NULL,
          page_href VARCHAR(1024) NULL DEFAULT NULL,
          build_id VARCHAR(64) NULL DEFAULT NULL,
          user_agent VARCHAR(512) NULL DEFAULT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_client_errors_created (created_at),
          KEY idx_client_errors_user (user_id, created_at),
          KEY idx_client_errors_audit (login_audit_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * Same-origin check for CSRF-exempt beacons (sendBeacon cannot carry CSRF).
 */
function bakery_client_error_same_origin(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return false;
    }
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = trim((string)($_SERVER[$header] ?? ''));
        if ($value === '') {
            continue;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            continue;
        }
        $headerHost = strtolower((string)$parts['host']);
        if ($headerHost === $host) {
            return true;
        }
    }

    // sendBeacon from same document often omits Origin; accept when Referer/Origin absent
    // only for same-site navigations that still present a session cookie (login-gated below).
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    return $origin === '' && $referer === '';
}

/**
 * Session rate limit: max $maxPerMinute beacons per rolling minute.
 *
 * @return array{allowed:bool, remaining:int}
 */
function bakery_client_error_rate_limit(int $maxPerMinute = 20): array
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return ['allowed' => false, 'remaining' => 0];
    }
    $window = (int)floor(time() / 60);
    $bucket = $_SESSION['client_error_rl'] ?? null;
    if (!is_array($bucket) || (int)($bucket['window'] ?? -1) !== $window) {
        $bucket = ['window' => $window, 'n' => 0];
    }
    $n = (int)($bucket['n'] ?? 0);
    if ($n >= $maxPerMinute) {
        $_SESSION['client_error_rl'] = $bucket;
        return ['allowed' => false, 'remaining' => 0];
    }
    $bucket['n'] = $n + 1;
    $_SESSION['client_error_rl'] = $bucket;
    return ['allowed' => true, 'remaining' => max(0, $maxPerMinute - $bucket['n'])];
}

/**
 * @param array{
 *   kind?:string, message?:string, stack_head?:string, page_path?:string,
 *   page_href?:string, build_id?:string, user_id?:?int, login_audit_id?:?int,
 *   user_agent?:string
 * } $row
 */
function bakery_client_error_record(PDO $db, array $row): int
{
    bakery_client_errors_ensure($db);
    if (!bakery_client_errors_ready($db)) {
        return 0;
    }
    $kind = preg_replace('/[^a-z_]/', '', strtolower((string)($row['kind'] ?? 'error'))) ?: 'error';
    if (!in_array($kind, ['error', 'unhandledrejection'], true)) {
        $kind = 'error';
    }
    $stmt = $db->prepare(
        'INSERT INTO client_errors
            (user_id, login_audit_id, kind, message, stack_head, page_path, page_href, build_id, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        isset($row['user_id']) ? (int)$row['user_id'] : null,
        isset($row['login_audit_id']) ? (int)$row['login_audit_id'] : null,
        $kind,
        mb_substr((string)($row['message'] ?? ''), 0, 500),
        mb_substr((string)($row['stack_head'] ?? ''), 0, 2000) ?: null,
        mb_substr((string)($row['page_path'] ?? ''), 0, 1024) ?: null,
        mb_substr((string)($row['page_href'] ?? ''), 0, 1024) ?: null,
        mb_substr((string)($row['build_id'] ?? ''), 0, 64) ?: null,
        mb_substr((string)($row['user_agent'] ?? ''), 0, 512) ?: null,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_client_errors_recent(PDO $db, int $limit = 20): array
{
    if (!bakery_client_errors_ready($db)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $stmt = $db->query(
        "SELECT ce.id, ce.user_id, ce.kind, ce.message, ce.page_path, ce.build_id, ce.created_at,
                COALESCE(u.display_name, u.email, CONCAT('user #', ce.user_id)) AS display_name
         FROM client_errors ce
         LEFT JOIN users u ON u.id = ce.user_id
         ORDER BY ce.created_at DESC, ce.id DESC
         LIMIT {$limit}"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}
