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

function bakery_time_clock_overrides_ready(PDO $db): bool
{
    if (!bakery_time_clock_ready($db)) {
        return false;
    }
    try {
        $stmt = $db->query("SHOW COLUMNS FROM time_clock_punches LIKE 'override_note'");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return is_array($row);
    } catch (Throwable $e) {
        return false;
    }
}

/** Monday of the week that contains $date, in the app timezone. */
function bakery_time_clock_week_monday(?string $date = null): string
{
    $date = trim((string)$date);
    $ts = $date !== '' ? strtotime($date . ' 12:00:00') : false;
    if ($ts === false) {
        $ts = strtotime(date('Y-m-d') . ' 12:00:00') ?: time();
    }
    $offset = (int)date('N', $ts) - 1;
    return date('Y-m-d', strtotime('-' . $offset . ' days', $ts));
}

function bakery_time_clock_minutes(?string $in, ?string $out, ?int $now = null): int
{
    $inTs = $in !== null && $in !== '' ? strtotime($in) : false;
    if ($inTs === false) {
        return 0;
    }
    if ($out === null || $out === '') {
        $outTs = $now ?? time();
    } else {
        $outTs = strtotime($out);
    }
    if ($outTs === false || $outTs < $inTs) {
        return 0;
    }
    return (int)round(($outTs - $inTs) / 60);
}

function bakery_time_clock_format_duration(int $minutes): string
{
    if ($minutes < 0) {
        $minutes = 0;
    }
    return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/**
 * @param array<string, mixed> $punch
 * @return list<string>
 */
function bakery_time_clock_flags(array $punch, ?int $now = null): array
{
    $now = $now ?? time();
    $inTs = strtotime((string)($punch['clock_in_at'] ?? ''));
    if ($inTs === false) {
        return [];
    }
    $outRaw = $punch['clock_out_at'] ?? null;
    $outTs = ($outRaw !== null && $outRaw !== '') ? strtotime((string)$outRaw) : null;
    $flags = [];
    $todayStart = strtotime(date('Y-m-d', $now) . ' 00:00:00');
    if ($outTs === null || $outTs === false) {
        if ($todayStart !== false && ($inTs < $todayStart || ($now - $inTs) > 12 * 3600)) {
            $flags[] = 'no_out';
        }
        return $flags;
    }
    if (($outTs - $inTs) > 12 * 3600) {
        $flags[] = 'long';
    }
    if (date('Y-m-d', $inTs) !== date('Y-m-d', $outTs)) {
        $flags[] = 'next_day';
    }
    return $flags;
}

function bakery_time_clock_local_input(?string $stamp): string
{
    if ($stamp === null || $stamp === '') {
        return '';
    }
    $ts = strtotime($stamp);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i', $ts);
}

function bakery_time_clock_parse_local(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dt || $dt->format('Y-m-d\TH:i') !== $value) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Punches that start this Monday–Sunday, plus anyone still open from earlier.
 *
 * @return array{monday: string, days: list<string>, people: list<array<string, mixed>>}
 */
function bakery_time_clock_week(PDO $db, string $monday, ?int $now = null): array
{
    $monday = bakery_time_clock_week_monday($monday);
    $now = $now ?? time();
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $days[] = date('Y-m-d', strtotime($monday . ' +' . $i . ' days'));
    }
    $empty = ['monday' => $monday, 'days' => $days, 'people' => []];
    if (!bakery_time_clock_ready($db)) {
        return $empty;
    }
    $start = $monday . ' 00:00:00';
    $end = date('Y-m-d', strtotime($monday . ' +7 days')) . ' 00:00:00';
    $extra = bakery_time_clock_overrides_ready($db)
        ? ', p.original_clock_in_at, p.original_clock_out_at, p.override_note, p.overridden_at'
        : '';
    $stmt = $db->prepare(
        'SELECT p.id, p.user_id, p.clock_in_at, p.clock_out_at, u.display_name, r.slug AS role_slug'
        . $extra . '
         FROM time_clock_punches p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE (p.clock_in_at >= ? AND p.clock_in_at < ?)
            OR (p.clock_out_at IS NULL AND p.clock_in_at < ?)
         ORDER BY u.display_name ASC, p.clock_in_at ASC'
    );
    $stmt->execute([$start, $end, $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return $empty;
    }
    $people = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int)$row['user_id'];
        if (!isset($people[$userId])) {
            $people[$userId] = [
                'user_id' => $userId,
                'display_name' => (string)$row['display_name'],
                'role_slug' => (string)($row['role_slug'] ?? ''),
                'minutes' => 0,
                'days' => array_fill_keys($days, 0),
                'earlier' => [],
                'flagged' => [],
                'other' => [],
            ];
        }
        $inDay = date('Y-m-d', strtotime((string)$row['clock_in_at']));
        $inThisWeek = $inDay >= $days[0] && $inDay <= $days[6];
        $row['flags'] = bakery_time_clock_flags($row, $now);
        $row['minutes'] = $inThisWeek
            ? bakery_time_clock_minutes((string)$row['clock_in_at'], $row['clock_out_at'] !== null ? (string)$row['clock_out_at'] : null, $now)
            : 0;
        if (!$inThisWeek) {
            $people[$userId]['earlier'][] = $row;
            continue;
        }
        $people[$userId]['minutes'] += $row['minutes'];
        $people[$userId]['days'][$inDay] += $row['minutes'];
        if ($row['flags'] !== []) {
            $people[$userId]['flagged'][] = $row;
        } else {
            $people[$userId]['other'][] = $row;
        }
    }
    return ['monday' => $monday, 'days' => $days, 'people' => array_values($people)];
}

/**
 * Replace the working times. The first correction copies the original punch.
 *
 * @return array{ok: bool, error?: string}
 */
function bakery_time_clock_override(PDO $db, int $editorId, string $editorRole, int $punchId, string $inLocal, string $outLocal, string $note): array
{
    if (!in_array($editorRole, ['administrator', 'manager'], true) || $editorId <= 0) {
        return ['ok' => false, 'error' => 'forbidden'];
    }
    if (!bakery_time_clock_overrides_ready($db)) {
        return ['ok' => false, 'error' => 'not_ready'];
    }
    $note = trim($note);
    if ($note === '') {
        return ['ok' => false, 'error' => 'note_required'];
    }
    if (strlen($note) > 255) {
        $note = substr($note, 0, 255);
    }
    $inAt = bakery_time_clock_parse_local($inLocal);
    if ($inAt === null) {
        return ['ok' => false, 'error' => 'bad_time'];
    }
    $outAt = null;
    if (trim($outLocal) !== '') {
        $outAt = bakery_time_clock_parse_local($outLocal);
        if ($outAt === null) {
            return ['ok' => false, 'error' => 'bad_time'];
        }
    }
    $now = time();
    if (strtotime($inAt) > $now + 60 || ($outAt !== null && strtotime($outAt) > $now + 60)) {
        return ['ok' => false, 'error' => 'future'];
    }
    if ($outAt !== null && strtotime($outAt) <= strtotime($inAt)) {
        return ['ok' => false, 'error' => 'out_before_in'];
    }
    $stmt = $db->prepare(
        'SELECT id, clock_in_at, clock_out_at, original_clock_in_at, original_clock_out_at
         FROM time_clock_punches WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$punchId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    if ($outAt === null && $row['clock_out_at'] !== null && $row['clock_out_at'] !== '') {
        return ['ok' => false, 'error' => 'bad_time'];
    }
    $already = $row['original_clock_in_at'] !== null && $row['original_clock_in_at'] !== '';
    $origIn = $already ? (string)$row['original_clock_in_at'] : (string)$row['clock_in_at'];
    $origOut = $already ? $row['original_clock_out_at'] : $row['clock_out_at'];
    try {
        $update = $db->prepare(
            'UPDATE time_clock_punches
             SET clock_in_at = ?, clock_out_at = ?, original_clock_in_at = ?, original_clock_out_at = ?,
                 override_note = ?, overridden_by_user_id = ?, overridden_at = ?
             WHERE id = ?'
        );
        $update->execute([$inAt, $outAt, $origIn, $origOut, $note, $editorId, bakery_time_clock_stamp(), $punchId]);
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            return ['ok' => false, 'error' => 'already_in'];
        }
        error_log('time clock override failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'save_failed'];
    }
    return ['ok' => true];
}
