<?php
/**
 * Agent Learning Studio / Homebase — domain layer.
 * GUI (agent_homebase.php) and CLI (scripts/agent_homebase.php) share these writes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/schema_sql.php';
require_once __DIR__ . '/agent_homebase_seed.php';

function bakery_agent_homebase_tables(): array
{
    return [
        'agent_lessons',
        'agent_sessions',
        'agent_whiteboard',
        'agent_bugs',
        'agent_notes',
        'agent_lesson_progress',
    ];
}

function bakery_agent_homebase_ready(PDO $db): bool
{
    foreach (bakery_agent_homebase_tables() as $table) {
        if (!table_exists($db, $table)) {
            return false;
        }
    }
    return true;
}

function bakery_agent_homebase_forget_tables(): void
{
    if (!function_exists('bakery_forget_table_exists')) {
        return;
    }
    foreach (bakery_agent_homebase_tables() as $table) {
        bakery_forget_table_exists($table);
    }
}

function bakery_agent_homebase_ensure(PDO $db): void
{
    $sql = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema' . DIRECTORY_SEPARATOR . '044_agent_homebase.sql';
    $createErrors = [];
    if (is_readable($sql)) {
        foreach (bakery_parse_sql_file($sql) as $statement) {
            try {
                $db->exec($statement);
            } catch (Throwable $e) {
                $createErrors[] = $e->getMessage();
            }
        }
    }
    bakery_agent_homebase_forget_tables();

    if (!table_exists($db, 'agent_sessions')) {
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS agent_sessions (
                  id INT NOT NULL AUTO_INCREMENT,
                  agent_name VARCHAR(120) NOT NULL,
                  mission VARCHAR(240) NOT NULL DEFAULT '',
                  status ENUM('open','handed_off','abandoned') NOT NULL DEFAULT 'open',
                  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  ended_at TIMESTAMP NULL DEFAULT NULL,
                  files_touched TEXT NULL,
                  handoff_md MEDIUMTEXT NULL,
                  created_by_user_id INT NULL DEFAULT NULL,
                  PRIMARY KEY (id),
                  KEY idx_agent_sessions_open (status, started_at),
                  KEY idx_agent_sessions_agent (agent_name, started_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable $e) {
            $createErrors[] = $e->getMessage();
        }
        bakery_forget_table_exists('agent_sessions');
    }

    if (!bakery_agent_homebase_ready($db)) {
        $hint = $createErrors !== []
            ? implode('; ', array_slice($createErrors, 0, 3))
            : 'schema file missing or not readable';
        throw new RuntimeException('Agent Homebase tables are not installed. ' . $hint);
    }

    try {
        bakery_agent_homebase_seed($db);
    } catch (Throwable $e) {
        error_log('Agent Homebase seed failed: ' . $e->getMessage());
    }
}

function bakery_agent_homebase_seed(PDO $db): void
{
    $lessonStmt = $db->prepare(
        'INSERT INTO agent_lessons (slug, track, title, summary, body_md, sort_order, is_required)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           track = VALUES(track), title = VALUES(title), summary = VALUES(summary),
           body_md = VALUES(body_md), sort_order = VALUES(sort_order), is_required = VALUES(is_required)'
    );
    foreach (bakery_agent_homebase_seed_lessons() as $lesson) {
        $lessonStmt->execute([
            $lesson['slug'], $lesson['track'], $lesson['title'], $lesson['summary'],
            $lesson['body_md'], (int)$lesson['sort_order'], (int)$lesson['is_required'],
        ]);
    }

    $bugStmt = $db->prepare(
        'INSERT INTO agent_bugs (slug, title, detail, severity, status, focus_area, source, agent_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           title = VALUES(title), detail = VALUES(detail), severity = VALUES(severity),
           focus_area = VALUES(focus_area), source = VALUES(source)'
    );
    foreach (bakery_agent_homebase_seed_bugs() as $bug) {
        $bugStmt->execute([
            $bug['slug'], $bug['title'], $bug['detail'], $bug['severity'],
            $bug['status'], $bug['focus_area'], $bug['source'], 'homebase',
        ]);
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM agent_whiteboard WHERE archived_at IS NULL')->fetchColumn();
    if ($count === 0) {
        $cardStmt = $db->prepare(
            'INSERT INTO agent_whiteboard (column_key, title, body, agent_name, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (bakery_agent_homebase_seed_whiteboard() as $card) {
            $cardStmt->execute([
                $card['column_key'], $card['title'], $card['body'],
                $card['agent_name'], (int)$card['sort_order'],
            ]);
        }
    }
}

function bakery_agent_homebase_tracks(): array
{
    return [
        'product' => 'What we are building',
        'practices' => 'Practices',
        'bugs' => 'Bugs to focus on',
        'craft' => 'Professional craft',
    ];
}

function bakery_agent_homebase_whiteboard_columns(): array
{
    return [
        'now' => 'Now',
        'next' => 'Next',
        'decided' => 'Decided',
        'parked' => 'Parked',
    ];
}

function bakery_agent_homebase_clean_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'anonymous-agent';
    }
    if (strlen($name) > 120) {
        $name = substr($name, 0, 120);
    }
    return $name;
}

function bakery_agent_homebase_lessons(PDO $db, ?string $track = null): array
{
    if ($track) {
        $stmt = $db->prepare('SELECT * FROM agent_lessons WHERE track = ? ORDER BY sort_order, id');
        $stmt->execute([$track]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $db->query('SELECT * FROM agent_lessons ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_lesson_by_slug(PDO $db, string $slug): ?array
{
    $stmt = $db->prepare('SELECT * FROM agent_lessons WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_agent_homebase_progress_for(PDO $db, string $agentName): array
{
    $stmt = $db->prepare(
        'SELECT p.*, l.slug, l.title
         FROM agent_lesson_progress p
         JOIN agent_lessons l ON l.id = p.lesson_id
         WHERE p.agent_name = ?
         ORDER BY p.completed_at'
    );
    $stmt->execute([bakery_agent_homebase_clean_name($agentName)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_unread_required(PDO $db, string $agentName): array
{
    $stmt = $db->prepare(
        'SELECT l.*
         FROM agent_lessons l
         LEFT JOIN agent_lesson_progress p
           ON p.lesson_id = l.id AND p.agent_name = ?
         WHERE l.is_required = 1 AND p.id IS NULL
         ORDER BY l.sort_order, l.id'
    );
    $stmt->execute([bakery_agent_homebase_clean_name($agentName)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_complete_lesson(PDO $db, string $agentName, string $slug, string $notes = ''): array
{
    $lesson = bakery_agent_homebase_lesson_by_slug($db, $slug);
    if (!$lesson) {
        throw new InvalidArgumentException('Unknown lesson: ' . $slug);
    }
    $stmt = $db->prepare(
        'INSERT INTO agent_lesson_progress (agent_name, lesson_id, notes)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE notes = VALUES(notes), completed_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        bakery_agent_homebase_clean_name($agentName),
        (int)$lesson['id'],
        trim($notes) !== '' ? trim($notes) : null,
    ]);
    return $lesson;
}

function bakery_agent_homebase_open_session(PDO $db, string $agentName): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM agent_sessions WHERE agent_name = ? AND status = 'open' ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([bakery_agent_homebase_clean_name($agentName)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_agent_homebase_start_session(
    PDO $db,
    string $agentName,
    string $mission,
    ?int $userId = null
): array {
    $agentName = bakery_agent_homebase_clean_name($agentName);
    $existing = bakery_agent_homebase_open_session($db, $agentName);
    if ($existing) {
        if ($mission !== '' && (string)$existing['mission'] === '') {
            $upd = $db->prepare('UPDATE agent_sessions SET mission = ? WHERE id = ?');
            $upd->execute([trim($mission), (int)$existing['id']]);
            $existing['mission'] = trim($mission);
        }
        return $existing;
    }
    $stmt = $db->prepare(
        'INSERT INTO agent_sessions (agent_name, mission, created_by_user_id) VALUES (?, ?, ?)'
    );
    $stmt->execute([$agentName, trim($mission), $userId]);
    $id = (int)$db->lastInsertId();
    $get = $db->prepare('SELECT * FROM agent_sessions WHERE id = ?');
    $get->execute([$id]);
    return $get->fetch(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_handoff(
    PDO $db,
    string $agentName,
    string $handoffMd,
    string $filesTouched = '',
    ?int $sessionId = null
): array {
    $agentName = bakery_agent_homebase_clean_name($agentName);
    $handoffMd = trim($handoffMd);
    if ($handoffMd === '') {
        throw new InvalidArgumentException('Handoff markdown is required');
    }
    $session = null;
    if ($sessionId) {
        $stmt = $db->prepare('SELECT * FROM agent_sessions WHERE id = ? LIMIT 1');
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$session) {
        $session = bakery_agent_homebase_open_session($db, $agentName);
    }
    if (!$session) {
        $session = bakery_agent_homebase_start_session($db, $agentName, 'Ad hoc handoff');
    }
    $upd = $db->prepare(
        "UPDATE agent_sessions
         SET status = 'handed_off', ended_at = CURRENT_TIMESTAMP, handoff_md = ?, files_touched = ?
         WHERE id = ?"
    );
    $upd->execute([$handoffMd, trim($filesTouched) !== '' ? trim($filesTouched) : null, (int)$session['id']]);
    $get = $db->prepare('SELECT * FROM agent_sessions WHERE id = ?');
    $get->execute([(int)$session['id']]);
    return $get->fetch(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_sessions(PDO $db, int $limit = 30): array
{
    $limit = max(1, min(200, $limit));
    return $db->query('SELECT * FROM agent_sessions ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_pin(
    PDO $db,
    string $title,
    string $body,
    string $column = 'now',
    string $agentName = '',
    int $sortOrder = 0
): array {
    $title = trim($title);
    $body = trim($body);
    if ($title === '' || $body === '') {
        throw new InvalidArgumentException('Whiteboard cards need a title and a body');
    }
    $columns = bakery_agent_homebase_whiteboard_columns();
    if (!isset($columns[$column])) {
        $column = 'now';
    }
    $stmt = $db->prepare(
        'INSERT INTO agent_whiteboard (column_key, title, body, agent_name, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$column, $title, $body, bakery_agent_homebase_clean_name($agentName), $sortOrder]);
    $id = (int)$db->lastInsertId();
    $get = $db->prepare('SELECT * FROM agent_whiteboard WHERE id = ?');
    $get->execute([$id]);
    return $get->fetch(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_move_card(PDO $db, int $id, string $column): void
{
    $columns = bakery_agent_homebase_whiteboard_columns();
    if (!isset($columns[$column])) {
        throw new InvalidArgumentException('Unknown whiteboard column');
    }
    $stmt = $db->prepare('UPDATE agent_whiteboard SET column_key = ? WHERE id = ? AND archived_at IS NULL');
    $stmt->execute([$column, $id]);
}

function bakery_agent_homebase_archive_card(PDO $db, int $id): void
{
    $stmt = $db->prepare('UPDATE agent_whiteboard SET archived_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$id]);
}

function bakery_agent_homebase_board(PDO $db, bool $includeArchived = false): array
{
    $sql = 'SELECT * FROM agent_whiteboard';
    if (!$includeArchived) {
        $sql .= ' WHERE archived_at IS NULL';
    }
    $sql .= ' ORDER BY sort_order, id';
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $grouped = [];
    foreach (array_keys(bakery_agent_homebase_whiteboard_columns()) as $key) {
        $grouped[$key] = [];
    }
    foreach ($rows as $row) {
        $key = (string)$row['column_key'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $row;
    }
    return $grouped;
}

function bakery_agent_homebase_log_bug(
    PDO $db,
    string $title,
    string $detail,
    string $severity = 'watch',
    string $focusArea = 'ops',
    string $agentName = '',
    ?string $slug = null
): array {
    $title = trim($title);
    $detail = trim($detail);
    if ($title === '' || $detail === '') {
        throw new InvalidArgumentException('Bugs need a title and detail');
    }
    if (!in_array($severity, ['critical', 'watch', 'broken-window'], true)) {
        $severity = 'watch';
    }
    if ($slug === null || $slug === '') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
        $slug = trim($slug, '-');
        if (strlen($slug) > 70) {
            $slug = substr($slug, 0, 70);
        }
        $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
    $stmt = $db->prepare(
        'INSERT INTO agent_bugs (slug, title, detail, severity, status, focus_area, source, agent_name)
         VALUES (?, ?, ?, ?, \'open\', ?, \'agent\', ?)'
    );
    $stmt->execute([$slug, $title, $detail, $severity, $focusArea, bakery_agent_homebase_clean_name($agentName)]);
    $id = (int)$db->lastInsertId();
    $get = $db->prepare('SELECT * FROM agent_bugs WHERE id = ?');
    $get->execute([$id]);
    return $get->fetch(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_set_bug_status(PDO $db, int $id, string $status): void
{
    if (!in_array($status, ['open', 'watching', 'fixed', 'wont-fix'], true)) {
        throw new InvalidArgumentException('Unknown bug status');
    }
    $stmt = $db->prepare('UPDATE agent_bugs SET status = ? WHERE id = ?');
    $stmt->execute([$status, $id]);
}

function bakery_agent_homebase_bugs(PDO $db, ?string $status = null): array
{
    if ($status) {
        $stmt = $db->prepare('SELECT * FROM agent_bugs WHERE status = ? ORDER BY FIELD(severity,\'critical\',\'watch\',\'broken-window\'), id');
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $db->query(
        "SELECT * FROM agent_bugs ORDER BY FIELD(status,'open','watching','fixed','wont-fix'), FIELD(severity,'critical','watch','broken-window'), id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_add_note(
    PDO $db,
    string $kind,
    string $body,
    string $title = '',
    string $agentName = ''
): array {
    if (!in_array($kind, ['insight', 'question', 'coach'], true)) {
        $kind = 'insight';
    }
    $body = trim($body);
    if ($body === '') {
        throw new InvalidArgumentException('Note body is required');
    }
    $stmt = $db->prepare(
        'INSERT INTO agent_notes (kind, title, body, agent_name) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$kind, trim($title), $body, bakery_agent_homebase_clean_name($agentName)]);
    $id = (int)$db->lastInsertId();
    $get = $db->prepare('SELECT * FROM agent_notes WHERE id = ?');
    $get->execute([$id]);
    return $get->fetch(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_notes(PDO $db, int $limit = 40): array
{
    $limit = max(1, min(200, $limit));
    return $db->query('SELECT * FROM agent_notes ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_agent_homebase_brief(PDO $db, string $agentName = ''): array
{
    $agentName = bakery_agent_homebase_clean_name($agentName);
    $openBugs = bakery_agent_homebase_bugs($db, 'open');
    $board = bakery_agent_homebase_board($db);
    $nowTitles = array_map(static fn ($c) => $c['title'], $board['now'] ?? []);
    $decidedTitles = array_map(static fn ($c) => $c['title'], $board['decided'] ?? []);
    $openSession = bakery_agent_homebase_open_session($db, $agentName);
    $recent = bakery_agent_homebase_sessions($db, 8);
    $questions = [];
    foreach (bakery_agent_homebase_notes($db, 20) as $note) {
        if ($note['kind'] === 'question') {
            $questions[] = $note;
        }
    }
    return [
        'product' => 'Sour Flour OS runs one wholesale bakery day. Close loops; do not add modules.',
        'manual' => 'BAKERY_PRODUCT_CONTEXT.md',
        'cli' => 'php scripts/agent_homebase.php brief|start|learn|pin|bug|note|handoff --json',
        'admin' => 'agent_homebase.php',
        'agent' => $agentName,
        'open_session' => $openSession,
        'unread_required_lessons' => bakery_agent_homebase_unread_required($db, $agentName),
        'open_bugs' => $openBugs,
        'whiteboard_now' => $nowTitles,
        'whiteboard_decided' => $decidedTitles,
        'open_questions' => $questions,
        'recent_sessions' => $recent,
        'next_actions' => [
            'Complete unread required lessons (learn --lesson=slug)',
            'Start a session if you are doing work (start --mission=)',
            'Pin decisions; log durable bugs; hand off with the eight §10 fields',
        ],
    ];
}

function bakery_agent_homebase_format_body(?string $md): string
{
    $escaped = htmlspecialchars((string)$md, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $replacements = [
        '/`([^`]+)`/' => '<code>$1</code>',
        '/\*\*([^*]+)\*\*/' => '<strong>$1</strong>',
        '/^[-*] (.+)$/m' => '• $1',
        '/^\d+\. (.+)$/m' => '$1',
    ];
    foreach ($replacements as $pattern => $replacement) {
        $next = preg_replace($pattern, $replacement, $escaped);
        if (is_string($next)) {
            $escaped = $next;
        }
    }
    return nl2br($escaped);
}

function bakery_agent_homebase_stats(PDO $db): array
{
    $openSessions = (int)$db->query("SELECT COUNT(*) FROM agent_sessions WHERE status = 'open'")->fetchColumn();
    $handoffs = (int)$db->query("SELECT COUNT(*) FROM agent_sessions WHERE status = 'handed_off'")->fetchColumn();
    $openBugs = (int)$db->query("SELECT COUNT(*) FROM agent_bugs WHERE status IN ('open','watching')")->fetchColumn();
    $cards = (int)$db->query('SELECT COUNT(*) FROM agent_whiteboard WHERE archived_at IS NULL')->fetchColumn();
    $lessons = (int)$db->query('SELECT COUNT(*) FROM agent_lessons')->fetchColumn();
    $questions = (int)$db->query("SELECT COUNT(*) FROM agent_notes WHERE kind = 'question'")->fetchColumn();
    return [
        'open_sessions' => $openSessions,
        'handoffs' => $handoffs,
        'open_bugs' => $openBugs,
        'cards' => $cards,
        'lessons' => $lessons,
        'questions' => $questions,
    ];
}
