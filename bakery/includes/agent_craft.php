<?php
/**
 * Agent craft helpers: development manual, poem, §10 handoff score, map suggestions.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_agent_craft_manual_rel(): string
{
    return 'docs/AGENT_DEVELOPMENT_MANUAL.md';
}

function bakery_agent_craft_manual_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'AGENT_DEVELOPMENT_MANUAL.md';
}

function bakery_agent_craft_read_manual(): string
{
    $path = bakery_agent_craft_manual_path();
    if (!is_readable($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    return is_string($raw) ? $raw : '';
}

function bakery_agent_craft_poem(): string
{
    $md = bakery_agent_craft_read_manual();
    if ($md === '') {
        return '';
    }
    if (preg_match('/<!-- poem:start -->(.*)<!-- poem:end -->/s', $md, $m)) {
        return trim($m[1]);
    }
    return '';
}

function bakery_agent_craft_stanza(): string
{
    $poem = bakery_agent_craft_poem();
    if ($poem === '') {
        return 'Chat is not the ledger. Close the loop you are in.';
    }
    $parts = preg_split('/\n\s*\n/', $poem) ?: [];
    return trim((string)($parts[0] ?? $poem));
}

/**
 * Score a §10 handoff. Warns; does not block.
 *
 * @return array{score:int,max:int,missing:list<int>,present:list<int>,complete:bool}
 */
function bakery_agent_homebase_score_handoff(string $md): array
{
    $md = str_replace("\r\n", "\n", $md);
    $present = [];
    $missing = [];
    for ($n = 1; $n <= 8; $n++) {
        $ok = (bool)preg_match('/(?:^|\n)\s*' . $n . '\s*[\.\:\)]\s+\S/u', $md);
        if ($ok) {
            $present[] = $n;
        } else {
            $missing[] = $n;
        }
    }
    return [
        'score' => count($present),
        'max' => 8,
        'present' => $present,
        'missing' => $missing,
        'complete' => $missing === [],
    ];
}

/**
 * Tests listed anywhere in the work map (relative paths).
 *
 * @return list<string>
 */
function bakery_agent_work_map_listed_tests(): array
{
    $listed = [];
    foreach (bakery_agent_work_map() as $mission) {
        foreach ($mission['tests'] as $test) {
            $listed[] = str_replace('\\', '/', (string)$test);
        }
    }
    return array_values(array_unique($listed));
}

/**
 * @return list<string> basenames of tests/run_*.php missing from the map
 */
function bakery_agent_work_map_unmapped_suites(?string $testsDir = null): array
{
    $dir = $testsDir ?: (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests');
    $onDisk = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'run_*.php') ?: [] as $path) {
        $onDisk[] = 'tests/' . basename($path);
    }
    $listed = bakery_agent_work_map_listed_tests();
    $missing = array_values(array_diff($onDisk, $listed));
    sort($missing);
    return $missing;
}

/**
 * After a handoff, name map holes for files the agent actually touched.
 *
 * @param list<string>|string $files
 */
function bakery_agent_work_map_suggest($files): array
{
    if (is_string($files)) {
        $paths = array_values(array_filter(array_map('trim', explode(',', $files))));
    } else {
        $paths = $files;
    }
    $matched = bakery_agent_work_map_for_files($paths);
    $unmapped = [];
    foreach ($paths as $path) {
        $hit = false;
        foreach (bakery_agent_work_map() as $slug => $mission) {
            if ($slug === 'general') {
                continue;
            }
            foreach ($mission['files'] as $pattern) {
                if (bakery_agent_work_map_path_matches($path, (string)$pattern)) {
                    $hit = true;
                    break 2;
                }
            }
        }
        $norm = str_replace('\\', '/', strtolower($path));
        if (preg_match('#(?:^|/)lang/(en|es)\.php$#', $norm) || basename($norm) === 'en.php' || basename($norm) === 'es.php') {
            continue;
        }
        if (basename($norm) === 'bakery_product_context.md' || basename($norm) === 'agent_development_manual.md') {
            continue;
        }
        if (strpos($norm, 'tests/run_') !== false) {
            $rel = 'tests/' . basename(str_replace('\\', '/', $path));
            if (!in_array($rel, bakery_agent_work_map_listed_tests(), true)) {
                $unmapped[] = $path;
            }
            continue;
        }
        if (!$hit) {
            $unmapped[] = $path;
        }
    }
    return [
        'missions' => $matched['missions'],
        'tests' => $matched['tests'],
        'invariants' => $matched['invariants'],
        'unmapped_files' => array_values(array_unique($unmapped)),
        'hint' => $unmapped === []
            ? 'Map already covers these files.'
            : 'Add these paths to includes/agent_work_map.php so the next brief is honest.',
    ];
}

/**
 * Open-session check for the stop hook. Fail-open: empty array on any error.
 *
 * @return array{open_count:int,should_remind:bool,followup:?string,database:?string}
 */
function bakery_agent_homebase_nag_state(PDO $db): array
{
    try {
        $count = (int)$db->query("SELECT COUNT(*) FROM agent_sessions WHERE status = 'open'")->fetchColumn();
        $actual = (string)$db->query('SELECT DATABASE()')->fetchColumn();
        $remind = $count > 0;
        return [
            'open_count' => $count,
            'should_remind' => $remind,
            'database' => $actual,
            'followup' => $remind
                ? 'Open Homebase session has no §10 handoff. php scripts/agent_homebase.php handoff --agent=YOUR-MISSION --summary="1. ... 8. ..." --files="a.php". Durable DB is bakerysf_stage_local.'
                : null,
        ];
    } catch (Throwable $e) {
        return [
            'open_count' => 0,
            'should_remind' => false,
            'database' => null,
            'followup' => null,
        ];
    }
}
