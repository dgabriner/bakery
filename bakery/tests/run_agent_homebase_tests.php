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
assert_eq(2, count($unreadBefore), 'new agent has two unread required lessons');
$unreadSlugs = array_column($unreadBefore, 'slug');
assert_true(in_array('invariants', $unreadSlugs, true), 'invariants is required');
assert_true(in_array('simple-practices', $unreadSlugs, true), 'simple-practices is required');

bakery_agent_homebase_complete_lesson($db, $agent, 'invariants', 'test');
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

$flattened = "1. Investigated X. 2. Decided Y. 3. Files: a.php. 4. Visible: none."
    . " 5. Rules kept. 6. Tests: suite 5/5. 7. Questions: none. 8. Next: continue.";
$flatScore = bakery_agent_homebase_score_handoff($flattened);
assert_eq(8, $flatScore['score'], 'shell-flattened one-line §10 handoff scores 8/8');
$linedScore = bakery_agent_homebase_score_handoff("1. A\n2. B\n3. C\n4. D\n5. E\n6. F\n7. G\n8. H");
assert_eq(8, $linedScore['score'], 'one-field-per-line still scores 8/8');
$partial = bakery_agent_homebase_score_handoff('1. Read Live rows. 2. No writes. 3. No files.');
assert_true(!$partial['complete'] && $partial['score'] === 3, 'partial handoff stays incomplete');
$versionTrap = bakery_agent_homebase_score_handoff('1. Used PHP 5.6 features and route v2. shipped.');
assert_true(!in_array(5, $versionTrap['present'], true), 'version numbers do not fake field 5');

bakery_agent_homebase_add_note($db, 'coach', 'Coach note body for listing test', 'Coach', $agent);
$noteList = bakery_agent_homebase_notes($db, 10);
assert_true(count($noteList) >= 2, 'notes listing returns stored notes');
$kinds = array_column($noteList, 'kind');
assert_true(in_array('coach', $kinds, true) && in_array('question', $kinds, true), 'notes listing keeps kinds');

$brief = bakery_agent_homebase_brief($db, $agent);
assert_true(isset($brief['product'], $brief['unread_required_lessons'], $brief['open_bugs']), 'brief has core keys');
assert_true(isset($brief['mission_packet'], $brief['doc_trust'], $brief['agent_family']), 'brief is a packed packet');
assert_true(isset($brief['craft_stanza'], $brief['craft_manual'], $brief['database']), 'brief includes craft + database');
assert_true(strpos((string)$brief['craft_stanza'], 'ovens') !== false || strpos((string)$brief['craft_stanza'], 'ledger') !== false, 'craft stanza is present');
assert_true(is_array($brief['whiteboard_now']), 'brief includes whiteboard now');
assert_true($brief['agent_family'] === $agent, 'test agents keep a unique lesson family');
assert_true(!isset($brief['unread_required_lessons'][0]['body_md']), 'brief omits lesson bodies');

$aliasFamily = bakery_agent_homebase_agent_family('commit-production-plan');
assert_eq('production-plan', $aliasFamily, 'aliases share a lesson family');
$aliasUnread = bakery_agent_homebase_unread_required($db, 'commit-production-plan');
bakery_agent_homebase_complete_lesson($db, '20-commit-production-plan', 'invariants', 'family-share');
$aliasUnreadAfter = bakery_agent_homebase_unread_required($db, 'commit-production-plan');
assert_eq(count($aliasUnread) - 1, count($aliasUnreadAfter), 'lesson progress is shared across a mission family');
$db->prepare('DELETE FROM agent_lesson_progress WHERE agent_name = ?')->execute(['production-plan']);

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
assert_true(strpos($page, 'homebase.tab_craft') !== false, 'homebase page has a Craft tab');
assert_true(strpos($page, 'bakery_agent_homebase_score_handoff') !== false, 'log panel scores §10 handoffs');
assert_true(strpos($page, 'catch (Throwable $e)') !== false, 'homebase page catches bootstrap failures instead of emitting HTTP 500');
assert_true(strpos(file_get_contents(dirname(__DIR__) . '/includes/agent_homebase.php'), 'LIMIT ?') === false, 'homebase list queries do not bind LIMIT placeholders');

$cli = file_get_contents(dirname(__DIR__) . '/scripts/agent_homebase.php');
assert_true($cli !== false && strpos($cli, 'case \'brief\':') !== false, 'CLI exposes brief');
assert_true(strpos($cli, 'bakery_homebase_durable_connection') !== false, 'CLI hops onto durable staging');
assert_true(strpos($cli, "case 'tests-for':") !== false || strpos($cli, "'tests-for'") !== false, 'CLI exposes tests-for');
assert_true(strpos($cli, "'craft'") !== false, 'CLI exposes craft');
assert_true(strpos($cli, "case 'notes':") !== false, 'CLI exposes notes listing');
assert_true(is_readable(dirname(__DIR__) . '/docs/AGENT_DEVELOPMENT_MANUAL.md'), 'development manual exists');
assert_true(is_readable(dirname(__DIR__) . '/.cursor/skills/test-gate/SKILL.md'), 'test-gate skill exists');
assert_true(is_readable(dirname(__DIR__) . '/.cursor/skills/sfb-agent/SKILL.md'), 'sfb-agent skill exists');
assert_true(is_readable(dirname(__DIR__) . '/.cursor/skills/close-a-loop/SKILL.md'), 'close-a-loop skill exists');
assert_true(strpos(file_get_contents(dirname(__DIR__) . '/includes/test_target_guard.php'), 'function bakery_homebase_durable_connection') !== false, 'durable Homebase hop exists');
$hooks = file_get_contents(dirname(__DIR__) . '/.cursor/hooks.json');
assert_true($hooks !== false && strpos($hooks, 'session-brief.cmd') !== false, 'sessionStart injects craft brief');
assert_true($hooks !== false && strpos($hooks, 'handoff-reminder.cmd') !== false, 'stop hook reminds agents to hand off');
$safetyRule = file_get_contents(dirname(__DIR__) . '/.cursor/rules/data-environment-safety.mdc');
assert_true($safetyRule !== false && strpos($safetyRule, 'alwaysApply: false') !== false, 'data-safety rule is glob-scoped');
assert_true(is_readable(dirname(__DIR__) . '/.cursor/rules/bakery-docs-trust.mdc'), 'doc-trust rule exists');

// Cleanup synthetic rows (keep seeded curriculum/bugs).
$db->prepare('DELETE FROM agent_lesson_progress WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_sessions WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_whiteboard WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_bugs WHERE agent_name = ?')->execute([$agent]);
$db->prepare('DELETE FROM agent_notes WHERE agent_name = ?')->execute([$agent]);

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
