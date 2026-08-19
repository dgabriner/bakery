<?php
/**
 * Agent Homebase / Learning Studio contracts.
 * Usage: php tests/run_agent_homebase_tests.php
 */
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/agent_homebase.php';
require_once dirname(__DIR__) . '/includes/navigation_catalog.php';

bakery_agent_homebase_ensure($db);

assert_true(bakery_agent_homebase_ready($db), 'homebase tables exist');

$lessons = bakery_agent_homebase_lessons($db);
$slugs = array_column($lessons, 'slug');
assert_true(count($lessons) >= 8, 'curriculum has at least 8 lessons');
assert_true(in_array('product-thesis', $slugs, true), 'product-thesis lesson exists');
assert_true(in_array('invariants', $slugs, true), 'invariants lesson exists');
assert_true(in_array('handoff-shape', $slugs, true), 'handoff-shape lesson exists');

$bugs = bakery_agent_homebase_bugs($db);
$bugSlugs = array_column($bugs, 'slug');
assert_true(in_array('plan-not-on-bake-sheet', $bugSlugs, true), 'plan-not-on-bake-sheet is on the watchlist');
assert_true(in_array('demand-flip', $bugSlugs, true), 'demand-flip is on the watchlist');

$agent = 'homebase-test-' . substr(bin2hex(random_bytes(3)), 0, 6);
$unreadBefore = bakery_agent_homebase_unread_required($db, $agent);
assert_true(count($unreadBefore) >= 6, 'new agent has unread required lessons');

bakery_agent_homebase_complete_lesson($db, $agent, 'product-thesis', 'test');
$unreadAfter = bakery_agent_homebase_unread_required($db, $agent);
assert_eq(count($unreadBefore) - 1, count($unreadAfter), 'completing a lesson reduces unread required');

$session = bakery_agent_homebase_start_session($db, $agent, 'Homebase contract test');
assert_eq('open', $session['status'], 'start opens a session');
$again = bakery_agent_homebase_start_session($db, $agent, 'ignored second start');
assert_eq((int)$session['id'], (int)$again['id'], 'second start reuses the open session');

$card = bakery_agent_homebase_pin($db, 'Test card ' . $agent, 'Body for contract test', 'now', $agent);
assert_eq('now', $card['column_key'], 'pin lands on now');
bakery_agent_homebase_move_card($db, (int)$card['id'], 'decided');
$board = bakery_agent_homebase_board($db);
$decidedIds = array_map('intval', array_column($board['decided'] ?? [], 'id'));
assert_true(in_array((int)$card['id'], $decidedIds, true), 'card moved to decided');

$bug = bakery_agent_homebase_log_bug($db, 'Test bug ' . $agent, 'Synthetic watchlist row', 'watch', 'test', $agent);
assert_eq('open', $bug['status'], 'logged bug starts open');
bakery_agent_homebase_set_bug_status($db, (int)$bug['id'], 'fixed');

$note = bakery_agent_homebase_add_note($db, 'question', 'Is the bake sheet still demand-based?', 'Bake sheet', $agent);
assert_eq('question', $note['kind'], 'question note stored');

$handoff = bakery_agent_homebase_handoff($db, $agent, "1. Investigated homebase\n8. Next: keep using brief", 'tests/run_agent_homebase_tests.php');
assert_eq('handed_off', $handoff['status'], 'handoff closes the session');

$brief = bakery_agent_homebase_brief($db, $agent);
assert_true(isset($brief['product'], $brief['unread_required_lessons'], $brief['open_bugs']), 'brief has core keys');
assert_true(is_array($brief['whiteboard_now']), 'brief includes whiteboard now');

$adminItems = [];
foreach (bakery_navigation_groups_for_role('administrator') as $group) {
    $adminItems = array_merge($adminItems, array_column($group['items'], 'href'));
}
$managerItems = [];
foreach (bakery_navigation_groups_for_role('manager') as $group) {
    $managerItems = array_merge($managerItems, array_column($group['items'], 'href'));
}
assert_true(in_array('agent_homebase.php', $adminItems, true), 'administrators receive Agent Homebase');
assert_true(!in_array('agent_homebase.php', $managerItems, true), 'managers do not receive Agent Homebase');

$formattedEmpty = bakery_agent_homebase_format_body(null);
assert_true(is_string($formattedEmpty), 'format_body accepts null without throwing');

$page = file_get_contents(dirname(__DIR__) . '/agent_homebase.php');
assert_true($page !== false && strpos($page, 'bakery_require_role([\'administrator\'])') !== false, 'homebase page is administrator-gated');
assert_true(strpos($page, 'ah-board') !== false, 'homebase page includes the whiteboard');
assert_true(strpos($page, 'catch (Throwable $e)') !== false, 'homebase page catches bootstrap failures instead of emitting HTTP 500');
assert_true(strpos(file_get_contents(dirname(__DIR__) . '/includes/agent_homebase.php'), 'LIMIT ?') === false, 'homebase list queries do not bind LIMIT placeholders');

$cli = file_get_contents(dirname(__DIR__) . '/scripts/agent_homebase.php');
assert_true($cli !== false && strpos($cli, 'case \'brief\':') !== false, 'CLI exposes brief');
assert_true(strpos($cli, 'bakery_assert_homebase_target') !== false, 'CLI refuses non-local targets by default');

// Cleanup synthetic rows (keep seeded curriculum/bugs).
$db->prepare('DELETE FROM agent_lesson_progress WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_sessions WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_whiteboard WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_bugs WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_notes WHERE agent_name = ?')->execute([$agent]);

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
