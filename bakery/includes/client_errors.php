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
function bakery_client_error_normalize_reference(string $reference): string
{
    $reference = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $reference));
    if (strlen($reference) < 4 || strlen($reference) > 8) {
        return '';
    }
    return $reference;
}

function bakery_client_error_reference_code(array $row): string
{
    $message = (string)($row['message'] ?? '');
    if (preg_match('/\bRef ([A-Z0-9]{4,8})\b/', $message, $match)) {
        return $match[1];
    }
    return '';
}

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
    $message = mb_substr((string)($row['message'] ?? ''), 0, 500);
    $reference = bakery_client_error_normalize_reference((string)($row['reference'] ?? ''));
    if ($reference !== '' && !preg_match('/\bRef ' . preg_quote($reference, '/') . '\b/', $message)) {
        $message = mb_substr('Ref ' . $reference . ' ' . $message, 0, 500);
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
        $message,
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

/**
 * App-calendar instant as a MySQL session wall time.
 * created_at is CURRENT_TIMESTAMP in the session zone. Staff dates are America/Los_Angeles.
 */
function bakery_client_error_session_wall(PDO $db, DateTimeImmutable $moment): string
{
    $offset = (int)$db->query('SELECT TIME_TO_SEC(TIMEDIFF(NOW(), UTC_TIMESTAMP()))')->fetchColumn();
    $sign = $offset >= 0 ? '+' : '-';
    $abs = abs($offset);
    $zone = new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60)));
    return $moment->setTimezone($zone)->format('Y-m-d H:i:s');
}

/**
 * Read-only admin list. Filters: user_id, page_path (substring), from, until (Y-m-d).
 *
 * @param array{user_id?:int,page_path?:string,from?:string,until?:string} $filters
 * @return list<array<string,mixed>>
 */
function bakery_client_errors_search(PDO $db, array $filters, int $limit = 100): array
{
    if (!bakery_client_errors_ready($db)) {
        return [];
    }
    $where = ['1=1'];
    $params = [];
    $from = (string)($filters['from'] ?? '');
    $until = (string)($filters['until'] ?? '');
    $appTz = new DateTimeZone(date_default_timezone_get());
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where[] = 'ce.created_at >= ?';
        $params[] = bakery_client_error_session_wall($db, new DateTimeImmutable($from . ' 00:00:00', $appTz));
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $until)) {
        $end = (new DateTimeImmutable($until . ' 00:00:00', $appTz))->modify('+1 day');
        $where[] = 'ce.created_at < ?';
        $params[] = bakery_client_error_session_wall($db, $end);
    }
    $userId = (int)($filters['user_id'] ?? 0);
    if ($userId > 0) {
        $where[] = 'ce.user_id = ?';
        $params[] = $userId;
    }
    $page = trim((string)($filters['page_path'] ?? ''));
    if ($page !== '') {
        $page = mb_substr($page, 0, 200);
        $where[] = 'ce.page_path LIKE ?';
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $page) . '%';
    }
    $limit = max(1, min(200, $limit));
    $stmt = $db->prepare(
        "SELECT ce.id, ce.user_id, ce.kind, ce.message, ce.page_path, ce.build_id, ce.created_at,
                COALESCE(u.display_name, u.email, CONCAT('user #', ce.user_id)) AS display_name
         FROM client_errors ce
         LEFT JOIN users u ON u.id = ce.user_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY ce.created_at DESC, ce.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['reference_code'] = bakery_client_error_reference_code($row);
    }
    unset($row);
    return $rows;
}
