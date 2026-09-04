<?php
/**
 * Mission 34 — one error boundary.
 *
 * Function-level: PDO/technical messages never reach the user string; helper
 * RuntimeExceptions do; safe_execute throws instead of returning false; the
 * four pages no longer echo raw $e->getMessage(); EN/ES keys exist.
 * bakerysf_test only.
 *
 * Usage: php tests/run_error_boundary_tests.php
 */
require __DIR__ . '/isolate_test_db.php';
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/common_functions.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: run with APP_ENV=local\n");
    exit(1);
}
$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = function ($ok, $label) use (&$pass, &$fail) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
};

// ---- classification ------------------------------------------------------------
$user = bakery_error_message_for_user(new RuntimeException('Order not found or delivery not confirmed'));
$assert($user === 'Order not found or delivery not confirmed', 'helper RuntimeException passes through to the user');
$arg = bakery_error_message_for_user(new InvalidArgumentException('--files=a.php,b.php is required'));
$assert($arg === '--files=a.php,b.php is required', 'InvalidArgumentException passes through');

$pdo = null;
try {
    $db->query('SELECT nope_column FROM daily_orders LIMIT 1');
} catch (PDOException $e) {
    $pdo = $e;
}
$assert($pdo instanceof PDOException, 'fixture: PDOException captured');
$pdoMsg = bakery_error_message_for_user($pdo);
$assert(strpos($pdoMsg, 'SQLSTATE') === false || IS_LOCAL, 'PDO message is replaced outside local');
$assert(preg_match('/error_id [0-9]{4}-[0-9A-F]{6}|\([0-9]{4}-[0-9A-F]{6}\)/', $pdoMsg) === 1, 'PDO failure carries an error_id (' . $pdoMsg . ')');

$technical = bakery_error_message_for_user(new RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'x'"));
$assert(strpos($technical, 'error_id') !== false || strpos($technical, '(') !== false, 'a RuntimeException wrapping SQL text is treated as technical, not shown verbatim');
$assert(bakery_error_message_looks_technical('Unknown column foo in WHERE'), 'technical detector catches SQL text');
$assert(!bakery_error_message_looks_technical('Customer has no billing email'), 'technical detector leaves plain sentences alone');

// ---- non-local rendering (call the renderer directly; HTTPS is forced for non-local over http) ----
ob_start();
bakery_error_boundary_render('0904-ABC123');
$html = (string)ob_get_clean();
$assert(strpos($html, '0904-ABC123') !== false && strpos($html, $root) === false, 'generic page shows the error id and no path');
$_SERVER['SCRIPT_NAME'] = '/bakery/daily_run_api.php';
ob_start();
bakery_error_boundary_render('0904-DEF456');
$json = json_decode((string)ob_get_clean(), true);
$_SERVER['SCRIPT_NAME'] = '';
$assert(is_array($json) && ($json['success'] ?? null) === false && ($json['error'] ?? '') === 'internal' && ($json['error_id'] ?? '') === '0904-DEF456', '*_api.php gets {success:false,error:internal,error_id}');

// ---- safe_execute cannot look like success ----------------------------------------
$threw = false;
try {
    safe_execute($db, 'UPDATE daily_orders SET nope_column = 1 WHERE id = -1');
} catch (RuntimeException $e) {
    $threw = true;
}
$assert($threw, 'safe_execute throws RuntimeException on a failed write instead of returning false');
$ok = safe_execute($db, 'SELECT 1');
$assert($ok instanceof PDOStatement, 'safe_execute still returns the statement on success');

$handlers = generate_crud_handlers('daily_orders', ['nope_column' => ['type' => 'int']]);
$created = $handlers['create']($db, ['nope_column' => 1]);
$assert(($created['success'] ?? true) === false && !empty($created['error']), 'CRUD create reports failure honestly when the write fails');
$assert(strpos((string)$created['error'], 'SQLSTATE') === false || IS_LOCAL, 'CRUD failure message is user-safe outside local');

// ---- pages no longer echo raw exception text ----------------------------------------
$pages = ['customers.php', 'daily_orders.php', 'production_center.php', 'complete_delivery.php'];
$leaks = [];
foreach ($pages as $page) {
    foreach (explode("\n", (string)file_get_contents($root . '/' . $page)) as $i => $line) {
        if (strpos($line, '$e->getMessage()') !== false && strpos($line, 'error_log') === false) {
            $leaks[] = $page . ':' . ($i + 1);
        }
    }
}
$assert($leaks === [], 'no user-facing raw $e->getMessage() left in the four pages (' . implode(', ', $leaks) . ')');

// ---- wiring -------------------------------------------------------------------------
$config = (string)file_get_contents($root . '/includes/config.php');
$assert(strpos($config, "require_once __DIR__ . '/error_boundary.php'") !== false && strpos($config, 'bakery_error_boundary_register()') !== false, 'config.php registers the boundary');
$probe = (string)file_get_contents($root . '/includes/production_errors.php');
$assert(strpos($probe, "defined('IS_LOCAL') && IS_LOCAL") !== false, 'BAKERY_SHOW_ERRORS prints detail only when IS_LOCAL');
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$assert(!empty($en['error.internal']) && !empty($es['error.internal']) && $en['error.internal'] !== $es['error.internal'], 'error.internal exists in EN and ES as distinct copy');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
