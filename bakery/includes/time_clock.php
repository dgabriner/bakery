<?php
/**
 * Staff time clock. Cashiers and drivers punch themselves. Managers read
 * who is in and the punches that started today. One open punch per person.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_time_clock_ready(PDO $db): bool
{
    if (!function_exists('table_exists')) {
        return false;
    }
    return table_exists($db, 'time_clock_punches');
}

function bakery_time_clock_stamp(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Same-origin return path after a successful punch.
 * Empty when the candidate is off this app or not a page this role may open.
 */
function bakery_time_clock_safe_return(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate)) {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $candidate) || str_starts_with($candidate, '//')) {
        return '';
    }
    if (!str_starts_with($candidate, '/') || str_contains($candidate, '..')) {
        return '';
    }
    $parts = parse_url($candidate);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return '';
    }
    $path = (string)($parts['path'] ?? '');
    $base = basename($path);
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $base)) {
        return '';
    }
    $role = '';
    if (function_exists('bakery_current_user')) {
        $user = bakery_current_user();
        $role = strtolower(trim((string)($user['role_slug'] ?? '')));
    }
    if ($role === '' || !function_exists('bakery_navigation_scripts_for_role')) {
        return '';
    }
    if (!in_array($base, bakery_navigation_scripts_for_role($role), true)) {
        return '';
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    return $path . $query;
}

function bakery_time_clock_format_time(string $stamp): string
{
    $ts = strtotime($stamp);
    if ($ts === false) {
        return $stamp;
    }
    $clock = (function_exists('bakery_locale') && bakery_locale() === 'es')
        ? date('H:i', $ts)
        : date('g:i A', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return $clock;
    }
    return date('n/j', $ts) . ' ' . $clock;
}

/** @return array<string, mixed>|null */
function bakery_time_clock_open_punch(PDO $db, int $userId): ?array
{
    if ($userId <= 0 || !bakery_time_clock_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, user_id, clock_in_at, clock_out_at
         FROM time_clock_punches
         WHERE user_id = ? AND clock_out_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array{ok: bool, error?: string, punch?: array<string, mixed>} */
function bakery_time_clock_in(PDO $db, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    if (!bakery_time_clock_ready($db)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    if (bakery_time_clock_open_punch($db, $userId) !== null) {
        return ['ok' => false, 'error' => 'already_in'];
    }
    try {
        $stmt = $db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at) VALUES (?, ?)');
        $stmt->execute([$userId, bakery_time_clock_stamp()]);
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            return ['ok' => false, 'error' => 'already_in'];
        }
        error_log('time clock in failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'save_failed'];
    }
    $punch = bakery_time_clock_open_punch($db, $userId);
    return $punch ? ['ok' => true, 'punch' => $punch] : ['ok' => false, 'error' => 'save_failed'];
}

/** @return array{ok: bool, error?: string, punch?: array<string, mixed>} */
function bakery_time_clock_out(PDO $db, int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'invalid_user'];
    }
    if (!bakery_time_clock_ready($db)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    $open = bakery_time_clock_open_punch($db, $userId);
    if ($open === null) {
        return ['ok' => false, 'error' => 'not_in'];
    }
    $outAt = bakery_time_clock_stamp();
    $stmt = $db->prepare(
        'UPDATE time_clock_punches
         SET clock_out_at = ?
         WHERE id = ? AND user_id = ? AND clock_out_at IS NULL'
    );
    $stmt->execute([$outAt, (int)$open['id'], $userId]);
    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'not_in'];
    }
    return [
        'ok' => true,
        'punch' => [
            'id' => (int)$open['id'],
            'user_id' => $userId,
            'clock_in_at' => (string)$open['clock_in_at'],
            'clock_out_at' => $outAt,
        ],
    ];
}

/** @return list<array<string, mixed>> */
function bakery_time_clock_who_is_in(PDO $db): array
{
    if (!bakery_time_clock_ready($db)) {
        return [];
    }
    $stmt = $db->query(
        'SELECT p.id, p.user_id, p.clock_in_at, u.display_name, r.slug AS role_slug
         FROM time_clock_punches p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE p.clock_out_at IS NULL
         ORDER BY p.clock_in_at ASC, u.display_name ASC'
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

/** @return list<array<string, mixed>> */
function bakery_time_clock_punches_for_day(PDO $db, string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date . ' 00:00:00') === false) {
        return [];
    }
    if (!bakery_time_clock_ready($db)) {
        return [];
    }
    $start = $date . ' 00:00:00';
    $end = date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00';
    $stmt = $db->prepare(
        'SELECT p.id, p.user_id, p.clock_in_at, p.clock_out_at, u.display_name, r.slug AS role_slug
         FROM time_clock_punches p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE p.clock_in_at >= ? AND p.clock_in_at < ?
         ORDER BY p.clock_in_at ASC, u.display_name ASC'
    );
    $stmt->execute([$start, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}
