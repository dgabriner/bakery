<?php
/**
 * Synthetic Studio clock: pace, tick, logs, humans never move.
 *
 * Usage: C:\php\php.exe tests/run_sfb_studio_clock_tests.php
 */
require_once __DIR__ . '/isolate_test_db.php';
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/sfb_agent.php';
require_once dirname(__DIR__) . '/includes/sfb_studio_clock.php';
require_once dirname(__DIR__) . '/includes/i18n.php';

$GLOBALS['db'] = $db;

$finish = function () {
    echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
    exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
};

$actualDb = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
assert_eq('bakerysf_test', $actualDb, 'studio clock tests run on bakerysf_test');

assert_eq(true, bakery_sfb_studio_ensure_schema($db), 'studio clock schema is ready');
$settings = bakery_sfb_studio_settings($db);
assert_eq(6, (int)$settings['min_interval_minutes'], 'default min interval is 6 minutes');
assert_eq(10, (int)$settings['max_interval_minutes'], 'default max interval is 10 minutes');
assert_eq(1, (int)$settings['clock_enabled'], 'clock starts enabled');

$en = bakery_load_lang_catalog('en');
$es = bakery_load_lang_catalog('es');
assert_true(!empty($en['sfb.studio_manager']) && !empty($es['sfb.studio_manager']), 'manager copy exists in en and es');
assert_true($en['sfb.studio_manager'] !== $es['sfb.studio_manager'], 'manager title is translated');

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$createdIds = [];
$humanId = 0;

try {
    bakery_sfb_agent_ensure_admin($db);
    bakery_sfb_agent_login($db);

    $created = bakery_sfb_agent_create_baker($db, 'Studio Clock ' . $suffix, '', [
        'origin' => 'synthetic',
        'persona' => 'beginner',
        'locale' => 'en',
    ]);
    $syntheticId = (int)$created['customer']['id'];
    $createdIds[] = $syntheticId;
    assert_eq('synthetic', (string)$created['customer']['sfb_origin'], 'clock baker is synthetic');

    $humanSql = 'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active, sfb_origin)
                 VALUES (?, ?, ?, 1, 1, 1, ?)';
    $db->prepare($humanSql)->execute(['Studio Clock Human ' . $suffix, '555-0177', '9 Test Way', 'human']);
    $humanId = (int)$db->lastInsertId();
    $createdIds[] = $humanId;

    bakery_sfb_studio_enroll($db, 'stagger');
    $rosterIds = array_map('intval', array_column(bakery_sfb_studio_roster($db), 'id'));
    assert_true(in_array($syntheticId, $rosterIds, true), 'synthetic is on the clock roster');
    assert_true(!in_array($humanId, $rosterIds, true), 'human baker is not enrolled on the clock');

    bakery_sfb_studio_save_settings($db, [
        'clock_enabled' => 1,
        'min_interval_minutes' => 6,
        'max_interval_minutes' => 10,
        'max_actions_per_baker' => 3,
        'max_bakers_per_tick' => 20,
    ], (int)($_SESSION['user_id'] ?? 0));

    $idle = bakery_sfb_studio_tick($db);
    assert_eq(true, $idle['ok'], 'unforced tick of a future baker is ok');

    $seen = [];
    $lastTick = null;
    for ($i = 0; $i < 8; $i++) {
        $lastTick = bakery_sfb_studio_tick($db, ['force' => true, 'customer_id' => $syntheticId]);
        assert_eq(true, $lastTick['ok'], 'forced baker tick ' . ($i + 1) . ' ok');
        foreach ($lastTick['results'] as $row) {
            foreach ($row['actions'] ?? [] as $act) {
                $seen[(string)$act['action']] = (string)($act['status'] ?? '');
            }
        }
    }
    assert_true(isset($seen['feed_starter']), 'clock fed the starter');
    assert_true(isset($seen['start_batch']) || isset($seen['log_turn']) || isset($seen['log_temp']), 'clock moved a bake forward');

    $logs = bakery_sfb_studio_logs($db, $syntheticId, 50);
    assert_true(count($logs) > 0, 'action log has rows for the baker');
    $okLogs = array_filter($logs, static function ($row) {
        return ($row['status'] ?? '') === 'ok';
    });
    assert_true(count($okLogs) > 0, 'at least one clock action succeeded');

    $clock = $db->prepare('SELECT next_action_at, last_action, actions_taken FROM sfb_studio_clock WHERE customer_id = ?');
    $clock->execute([$syntheticId]);
    $clockRow = $clock->fetch();
    assert_true(is_array($clockRow), 'clock row exists after ticks');
    $delta = strtotime((string)$clockRow['next_action_at']) - time();
    assert_true($delta >= 5 * 60 - 5 && $delta <= 11 * 60, 'next action is scheduled 6-10 minutes out (got ' . $delta . 's)');
    assert_true((int)$clockRow['actions_taken'] > 0, 'actions_taken incremented');

    $humanLogs = bakery_sfb_studio_logs($db, $humanId, 10);
    assert_eq(0, count($humanLogs), 'human baker has no clock log rows');

    $topics = $db->prepare('SELECT title, body FROM sfb_community_topics WHERE author_customer_id = ?');
    $topics->execute([$syntheticId]);
    foreach ($topics->fetchAll() as $topic) {
        $eval = bakery_sfb_eval_synthetic_text($topic['title'] . "\n" . $topic['body'], ['origin' => 'synthetic']);
        assert_eq(true, $eval['ok'], 'clock community post passes synthetic eval');
    }

    bakery_sfb_studio_set_baker_paused($db, $syntheticId, true);
    $pausedTick = bakery_sfb_studio_tick($db, ['customer_id' => $syntheticId]);
    $pausedBakers = array_column($pausedTick['results'], 'customer_id');
    assert_true(!in_array($syntheticId, $pausedBakers, true), 'paused baker is skipped by an unforced tick');
    bakery_sfb_studio_set_baker_paused($db, $syntheticId, false);

    $source = file_get_contents(dirname(__DIR__) . '/sfb_admin_studio.php');
    assert_true(strpos($source, 'sfb.studio_run_tick') !== false, 'manager can run a tick');
    assert_true(is_file(dirname(__DIR__) . '/sfb_admin_studio_baker.php'), 'baker drill-in page exists');
    assert_true(is_file(dirname(__DIR__) . '/scripts/sfb_studio_tick.php'), 'cron tick script exists');
    assert_true(!is_file(dirname(__DIR__) . '/scripts/install_sfb_studio_clock.ps1'), 'Windows scheduler installer is not present');

    $tickSrc = (string)file_get_contents(dirname(__DIR__) . '/scripts/sfb_studio_tick.php');
    assert_true(strpos($tickSrc, 'bakery_sfb_agent_assert_local') === false, 'cron script does not use the local-only agent guard');
    assert_true(strpos($tickSrc, 'bakery_sfb_studio_assert_tick_cli') !== false, 'cron script uses the DreamHost/local CLI guard');

    $cliRefused = false;
    try {
        bakery_sfb_studio_assert_tick_cli($db, false);
    } catch (RuntimeException $e) {
        $cliRefused = strpos($e->getMessage(), 'DreamHost') !== false;
    }
    assert_true($cliRefused, 'local unforced tick CLI names DreamHost and refuses');
    bakery_sfb_studio_assert_tick_cli($db, true);

    assert_true(strpos((string)$en['sfb.studio_cron_help'], 'DreamHost') !== false, 'manager cron help is DreamHost-only');
    assert_true(strpos((string)$en['sfb.studio_cron_cmd'], 'bakery.sourflour.org') !== false, 'manager cron command is the DreamHost path');
    assert_true(strpos((string)$en['sfb.studio_log_empty'], 'Run tick now') !== false, 'empty log points at Run tick now');
} finally {
    foreach ($createdIds as $id) {
        if ($id <= 0) {
            continue;
        }
        $db->prepare('DELETE FROM sfb_studio_action_log WHERE customer_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM sfb_studio_clock WHERE customer_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM sfb_community_replies WHERE author_customer_id = ?')->execute([$id]);
        $topicStmt = $db->prepare('SELECT id FROM sfb_community_topics WHERE author_customer_id = ?');
        $topicStmt->execute([$id]);
        foreach ($topicStmt->fetchAll() as $topic) {
            $db->prepare('DELETE FROM sfb_community_replies WHERE topic_id = ?')->execute([(int)$topic['id']]);
            $db->prepare('DELETE FROM sfb_community_topics WHERE id = ?')->execute([(int)$topic['id']]);
        }
        $batchStmt = $db->prepare('SELECT id FROM sfb_batches WHERE customer_id = ?');
        $batchStmt->execute([$id]);
        foreach ($batchStmt->fetchAll() as $batch) {
            $bid = (int)$batch['id'];
            $db->prepare('DELETE FROM sfb_batch_shares WHERE batch_id = ?')->execute([$bid]);
            $db->prepare('DELETE FROM sfb_batch_messages WHERE batch_id = ?')->execute([$bid]);
            $db->prepare('DELETE FROM sfb_batches WHERE id = ?')->execute([$bid]);
        }
        $db->prepare('DELETE FROM sfb_persona_profiles WHERE customer_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
    }
}

$finish();
