<?php
/**
 * Local-only JSON API for staging auto-push toggle + manual sync.
 * POST actions: status (also GET), enable, disable, sync, promote, direct_live
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auto_push_control.php';

header('Content-Type: application/json; charset=utf-8');

function bakery_auto_push_api_fail($message, $code = 403) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (!defined('IS_LOCAL') || !IS_LOCAL) {
    bakery_auto_push_api_fail('Only available on the local development app', 404);
}

$user = bakery_current_user();
if (!bakery_user_can_control_auto_push($user)) {
    bakery_auto_push_api_fail('Only the local admin (danny@sourflour.org) can control staging sync', 403);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$action = strtolower(trim((string)$action));

if ($method === 'GET' && $action === 'status') {
    // If sync is ON, make sure the filesystem watcher is running (covers non-Cursor edits).
    echo json_encode(bakery_auto_push_status(true));
    exit;
}

if ($method !== 'POST') {
    bakery_auto_push_api_fail('POST required', 405);
}

bakery_require_csrf();

try {
    switch ($action) {
        case 'status':
            echo json_encode(bakery_auto_push_status(true));
            break;

        case 'enable':
            bakery_auto_push_set_enabled(true);
            $status = bakery_auto_push_status(true);
            echo json_encode(array_merge($status, [
                'message' => !empty($status['watching'])
                    ? 'Auto-push ON — watching all local file changes'
                    : 'Auto-push ON — watcher failed to start; use Sync or check auto_push.log',
            ]));
            break;

        case 'disable':
            bakery_auto_push_set_enabled(false);
            echo json_encode(array_merge(bakery_auto_push_status(false), [
                'message' => 'Auto-push OFF — watcher stopped; use Sync to staging when ready',
            ]));
            break;

        case 'sync':
            @set_time_limit(400);
            $result = bakery_auto_push_run_sync();
            if (!$result['ok']) {
                http_response_code(500);
            }
            $output = (string)($result['output'] ?? '');
            $failDetail = '';
            if (!$result['ok']) {
                $lines = preg_split("/\r\n|\n|\r/", trim($output)) ?: [];
                $lines = array_values(array_filter($lines, function ($line) {
                    return trim((string)$line) !== '';
                }));
                $failDetail = implode("\n", array_slice($lines, -8));
            }
            echo json_encode([
                'ok' => $result['ok'],
                'exit_code' => $result['exit_code'],
                'output' => $output,
                'error' => $result['ok'] ? null : ($failDetail !== '' ? $failDetail : 'Sync failed (no output)'),
                'message' => $result['ok'] ? 'Sync finished' : 'Sync failed',
                'enabled' => $result['status']['enabled'],
                'last' => $result['status']['last'],
                'live_url' => $result['status']['live_url'],
            ]);
            break;

        case 'promote':
        case 'direct_live':
            @set_time_limit(900);
            $result = bakery_auto_push_run_live_promotion($action === 'direct_live');
            if (!$result['ok']) http_response_code(500);
            echo json_encode($result + ['message' => $result['ok'] ? 'Live promotion complete' : 'Live promotion failed']);
            break;

        default:
            bakery_auto_push_api_fail('Unknown action', 400);
    }
} catch (Throwable $e) {
    bakery_auto_push_api_fail($e->getMessage(), 500);
}
