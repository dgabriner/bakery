<?php
/**
 * Agent Homebase CLI — how Cursor agents check in, learn, pin, and hand off.
 *
 *   php scripts/agent_homebase.php brief --agent=exception-connections --json
 *   php scripts/agent_homebase.php start --agent=... --mission="..."
 *   php scripts/agent_homebase.php learn --agent=... --lesson=product-thesis
 *   php scripts/agent_homebase.php pin --title=... --body=... --column=now
 *   php scripts/agent_homebase.php bug --title=... --detail=...
 *   php scripts/agent_homebase.php note --kind=question --body=...
 *   php scripts/agent_homebase.php handoff --agent=... --summary="..." --files="a.php,b.php"
 *
 * Everyday local staging (bakerysf_stage_local) or bakerysf_test unless
 * --allow-production AND USE_PROD_DB=true. Never the nightly mirror.
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/agent_homebase.php';

function bakery_agent_cli_help(): void
{
    echo <<<TXT
Agent Homebase (local staging/test unless --allow-production AND USE_PROD_DB)

Commands:
  brief       Opening briefing (unread lessons, bugs, board, recent handoffs)
  start       Open a development session
  learn       Mark a lesson complete (--lesson=slug)
  lessons     List curriculum
  pin         Add a whiteboard card (--title --body --column=now|next|decided|parked)
  move        Move a card (--id --column)
  board       Show the whiteboard
  bug         Log a durable bug (--title --detail [--severity=watch|critical|broken-window])
  bug-status  Update a bug (--id --status=open|watching|fixed|wont-fix)
  bugs        List bugs
  note        Insight, question, or coach note (--kind --body [--title])
  handoff     Close the open session with §10 markdown (--summary or --body, --files)
  sessions    Recent sessions

Options:
  --agent=name
  --mission="Exception connections"
  --lesson=product-thesis
  --notes="..."
  --title=...
  --body=...
  --column=now
  --id=12
  --severity=watch
  --status=open
  --kind=insight|question|coach
  --summary=...          Handoff markdown (alias of --body on handoff)
  --files=a.php,b.php
  --session=12
  --json
  --allow-production     Requires USE_PROD_DB=true as well

TXT;
}

function bakery_agent_cli_args(array $argv): array
{
    $out = ['command' => '', 'json' => false, 'allow_production' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--json') {
            $out['json'] = true;
            continue;
        }
        if ($arg === '--allow-production') {
            $out['allow_production'] = true;
            continue;
        }
        if (strpos($arg, '--') === 0 && strpos($arg, '=') !== false) {
            [$k, $v] = explode('=', substr($arg, 2), 2);
            $out[str_replace('-', '_', $k)] = $v;
            continue;
        }
        if ($arg !== '' && $arg[0] !== '-' && $out['command'] === '') {
            $out['command'] = $arg;
        }
    }
    return $out;
}

function bakery_agent_cli_emit($data, bool $asJson): void
{
    if ($asJson) {
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }
    if (is_string($data)) {
        echo $data . "\n";
        return;
    }
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function bakery_agent_cli_assert_target(PDO $db, bool $allowProduction): void
{
    if ($allowProduction && defined('USE_PROD_DB') && USE_PROD_DB) {
        return;
    }
    bakery_assert_homebase_target($db);
}

$args = bakery_agent_cli_args($argv);
$command = (string)($args['command'] ?? '');
if ($command === '' || in_array($command, ['help', '-h', '--help'], true)) {
    bakery_agent_cli_help();
    exit(0);
}

try {
    $db = check_mysql_connection();
    bakery_agent_cli_assert_target($db, !empty($args['allow_production']));
    bakery_agent_homebase_ensure($db);

    $agent = bakery_agent_homebase_clean_name((string)($args['agent'] ?? 'cursor-agent'));
    $json = !empty($args['json']);

    switch ($command) {
        case 'brief':
            bakery_agent_cli_emit(bakery_agent_homebase_brief($db, $agent), $json);
            break;
        case 'start':
            $session = bakery_agent_homebase_start_session($db, $agent, (string)($args['mission'] ?? ''));
            bakery_agent_cli_emit($session, $json);
            break;
        case 'learn':
            $slug = (string)($args['lesson'] ?? '');
            if ($slug === '') {
                throw new InvalidArgumentException('--lesson=slug is required');
            }
            $lesson = bakery_agent_homebase_complete_lesson($db, $agent, $slug, (string)($args['notes'] ?? ''));
            bakery_agent_cli_emit(['ok' => true, 'lesson' => $lesson['slug'], 'agent' => $agent], $json);
            break;
        case 'lessons':
            bakery_agent_cli_emit(bakery_agent_homebase_lessons($db), $json);
            break;
        case 'pin':
            $card = bakery_agent_homebase_pin(
                $db,
                (string)($args['title'] ?? ''),
                (string)($args['body'] ?? ''),
                (string)($args['column'] ?? 'now'),
                $agent
            );
            bakery_agent_cli_emit($card, $json);
            break;
        case 'move':
            bakery_agent_homebase_move_card($db, (int)($args['id'] ?? 0), (string)($args['column'] ?? ''));
            bakery_agent_cli_emit(['ok' => true], $json);
            break;
        case 'board':
            bakery_agent_cli_emit(bakery_agent_homebase_board($db), $json);
            break;
        case 'bug':
            $bug = bakery_agent_homebase_log_bug(
                $db,
                (string)($args['title'] ?? ''),
                (string)($args['detail'] ?? (string)($args['body'] ?? '')),
                (string)($args['severity'] ?? 'watch'),
                (string)($args['focus_area'] ?? 'ops'),
                $agent
            );
            bakery_agent_cli_emit($bug, $json);
            break;
        case 'bug-status':
            bakery_agent_homebase_set_bug_status($db, (int)($args['id'] ?? 0), (string)($args['status'] ?? ''));
            bakery_agent_cli_emit(['ok' => true], $json);
            break;
        case 'bugs':
            bakery_agent_cli_emit(bakery_agent_homebase_bugs($db, $args['status'] ?? null), $json);
            break;
        case 'note':
            $note = bakery_agent_homebase_add_note(
                $db,
                (string)($args['kind'] ?? 'insight'),
                (string)($args['body'] ?? ''),
                (string)($args['title'] ?? ''),
                $agent
            );
            bakery_agent_cli_emit($note, $json);
            break;
        case 'handoff':
            $md = trim((string)($args['summary'] ?? $args['body'] ?? ''));
            $session = bakery_agent_homebase_handoff(
                $db,
                $agent,
                $md,
                (string)($args['files'] ?? ''),
                isset($args['session']) ? (int)$args['session'] : null
            );
            bakery_agent_cli_emit($session, $json);
            break;
        case 'sessions':
            bakery_agent_cli_emit(bakery_agent_homebase_sessions($db), $json);
            break;
        default:
            throw new InvalidArgumentException('Unknown command: ' . $command);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
