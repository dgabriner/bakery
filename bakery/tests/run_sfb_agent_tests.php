<?php
/**
 * SFAdmin agent-operator tests (bakerysf_test only).
 *
 * Usage: C:\php\php.exe tests\run_sfb_agent_tests.php
 */
require_once __DIR__ . '/isolate_test_db.php';
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/sfb_agent.php';
require_once dirname(__DIR__) . '/includes/sfb_personas.php';
require_once dirname(__DIR__) . '/includes/sfb_synthetic_eval.php';
require_once dirname(__DIR__) . '/includes/daily_order_generation.php';

$GLOBALS['db'] = $db;

$finish = function () {
    echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
    exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
};

$actualDb = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
assert_eq('bakerysf_test', $actualDb, 'agent tests run on bakerysf_test');

$admin = bakery_sfb_agent_ensure_admin($db);
assert_true((int)$admin['id'] > 0, 'SFAdmin user exists');
assert_eq('administrator', $admin['role_slug'], 'SFAdmin is an administrator');
assert_eq(bakery_sfb_agent_display_name(), $admin['display_name'], 'SFAdmin display name');
assert_true(bakery_normalize_login_code($admin['login_code']) !== '', 'SFAdmin has a 4-digit login code');

$loggedIn = bakery_sfb_agent_login($db);
assert_eq((int)$admin['id'], (int)$loggedIn['id'], 'SFAdmin login returns the same user');
assert_eq((int)$admin['id'], (int)($_SESSION['user_id'] ?? 0), 'SFAdmin staff session is active');

$catalog = bakery_sfb_persona_catalog();
assert_eq(100, count($catalog), 'persona catalog has 100 bakers');
assert_eq(20, count(bakery_sfb_persona_seed_set(20)), 'seed wave 1 is 20 bakers');
$reuseNames = array_map(function ($p) {
    return $p['name'];
}, array_filter($catalog, function ($p) {
    return !empty($p['reuse']);
}));
assert_true(in_array('Customer1', $reuseNames, true), 'Customer1 is a reused persona');
assert_true(in_array('Customer2', $reuseNames, true), 'Customer2 is a reused persona');

$evalOk = bakery_sfb_synthetic_eval_post([
    'title' => '76F bulk',
    'body' => 'Bulk at 76F for 4 hours, 75% hydration, bread flour.',
    'origin' => 'synthetic',
    'author_type' => 'baker',
]);
assert_true($evalOk['ok'], 'process-fact post passes eval');
$evalBad = bakery_sfb_synthetic_eval_post([
    'title' => 'Hello friends',
    'body' => 'Had a nice time in the kitchen today.',
    'origin' => 'synthetic',
    'author_type' => 'baker',
]);
assert_true(!$evalBad['ok'] && in_array('no_process_fact', $evalBad['reasons'], true), 'post without process fact is rejected');
$evalSecret = bakery_sfb_synthetic_eval_post([
    'title' => '76F bulk',
    'body' => 'Bulk at 76F. Also the Daily Run invoice for the standing order is $40.',
    'origin' => 'synthetic',
    'author_type' => 'baker',
]);
assert_true(!$evalSecret['ok'] && in_array('wholesale_secret', $evalSecret['reasons'], true), 'wholesale-secret post is rejected');
$evalMentor = bakery_sfb_synthetic_eval_post([
    'body' => 'Keep 75% hydration and bread flour; bulk 4 hours at 76F.',
    'origin' => 'synthetic',
    'is_mentor' => true,
    'author_type' => 'admin',
]);
assert_true(!$evalMentor['ok'] && in_array('mentor posted as administrator', $evalMentor['reasons'], true), 'mentor cannot post as admin');

foreach (bakery_sfb_persona_catalog() as $persona) {
    $evalTopic = bakery_sfb_eval_synthetic_text(
        $persona['topic_title'] . "\n" . $persona['topic_body'],
        ['origin' => 'synthetic']
    );
    assert_true($evalTopic['ok'], $persona['name'] . ' topic copy passes eval');
    $evalReply = bakery_sfb_eval_synthetic_text($persona['reply_body'], ['origin' => 'synthetic']);
    assert_true($evalReply['ok'], $persona['name'] . ' reply copy passes eval');
    if (!empty($persona['post_failure'])) {
        $evalFail = bakery_sfb_eval_synthetic_text(
            $persona['failure_title'] . "\n" . $persona['failure_body'],
            ['origin' => 'synthetic']
        );
        assert_true($evalFail['ok'], $persona['name'] . ' failure copy passes eval');
    }
    foreach (bakery_sfb_persona_extra_posts($persona) as $extra) {
        $evalExtra = bakery_sfb_eval_synthetic_text($extra['title'] . "\n" . $extra['body'], ['origin' => 'synthetic']);
        assert_true($evalExtra['ok'], $persona['name'] . ' extra post passes eval');
    }
}
$wave1 = bakery_sfb_persona_seed_set(20);
$waveTitles = array_map(function ($p) { return $p['topic_title']; }, $wave1);
$waveAsks = array_map(function ($p) { return $p['coach_ask']; }, $wave1);
assert_eq(20, count(array_unique($waveTitles)), 'wave-1 topic titles are unique');
assert_eq(20, count(array_unique($waveAsks)), 'wave-1 coach questions are unique');
foreach ($wave1 as $persona) {
    assert_true(count($persona['formulas'] ?? []) >= 3, $persona['name'] . ' copies at least 3 formulas');
}

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$names = ['SFB Agent Test A ' . $suffix, 'SFB Agent Test B ' . $suffix];
$createdIds = [];
$date = '2099-12-30';

try {
    foreach ($names as $i => $name) {
        $created = bakery_sfb_agent_create_baker($db, $name, '', [
            'phone' => '555-01' . (10 + $i),
            'origin' => 'synthetic',
            'persona' => 'beginner',
            'locale' => 'en',
        ]);
        $customer = $created['customer'];
        $createdIds[] = (int)$customer['id'];
        assert_true((int)$customer['id'] > 0, $name . ' created');
        assert_eq(1, (int)$customer['portal_enabled'], $name . ' portal enabled');
        assert_eq(1, (int)$customer['sf_baker_enabled'], $name . ' SF Baker enabled');
        assert_eq('synthetic', (string)$customer['sfb_origin'], $name . ' origin is synthetic');
        assert_true(($customer['zone'] ?? null) === null || $customer['zone'] === '', $name . ' has no zone');
        assert_true(($customer['delivery_time'] ?? null) === null || $customer['delivery_time'] === '', $name . ' has no delivery window');
        $soCount = $db->prepare('SELECT COUNT(*) FROM standing_orders WHERE customer_id = ?');
        $soCount->execute([(int)$customer['id']]);
        assert_eq(0, (int)$soCount->fetchColumn(), $name . ' has zero standing orders');
        assert_true(bakery_normalize_login_code($created['portal_code']) !== '', $name . ' portal code assigned');

        $again = bakery_sfb_agent_create_baker($db, $name, $created['portal_code']);
        assert_eq((int)$customer['id'], (int)$again['customer']['id'], $name . ' create is idempotent');
        assert_eq(false, $again['created'], $name . ' second create updates');

        $asCustomer = bakery_sfb_agent_login_as_customer($db, $name);
        assert_eq((int)$customer['id'], (int)$asCustomer['id'], 'SFAdmin logged in as ' . $name);
        assert_eq((int)$customer['id'], bakery_portal_customer_id(), 'portal session is ' . $name);
        $impersonation = bakery_sfb_agent_impersonating();
        assert_true($impersonation !== null, 'impersonation flag set for ' . $name);
        assert_eq((int)$admin['id'], (int)$impersonation['admin_user_id'], 'impersonator is SFAdmin');

        $fed = bakery_sfb_agent_feed_starter($db, [
            'starter' => 'Test starter',
            'starter-g' => 50,
            'flour-g' => 100,
            'water-g' => 100,
        ], $name);
        assert_true($fed['feeding_id'] > 0, 'starter feeding logged for ' . $name);

        $batch = bakery_sfb_agent_start_batch($db, 'Agent test ' . $name);
        assert_true($batch['batch_id'] > 0, 'batch started for ' . $name);
        $stored = bakery_sfb_batch($db, (int)$customer['id'], $batch['batch_id']);
        assert_true($stored !== null, 'batch belongs to ' . $name);
        assert_eq('in_progress', $stored['status'], $name . ' batch is in progress');

        $turn = bakery_sfb_agent_log_turn($db, [
            'batch' => $batch['batch_id'],
            'temp' => 76,
            'type' => 'stretch_fold',
        ], $name);
        assert_true($turn['turn_id'] > 0, 'turn logged for ' . $name);
        $temp = bakery_sfb_agent_log_temp($db, [
            'batch' => $batch['batch_id'],
            'temp' => 76,
            'phase' => 'development',
        ], $name);
        assert_true($temp['temp_id'] > 0, 'temp logged for ' . $name);
        $done = bakery_sfb_agent_complete_batch($db, [
            'batch' => $batch['batch_id'],
            'loaves' => 2,
            'notes' => '76F dough, 75% water, bread flour.',
        ], $name);
        assert_eq('completed', $done['batch']['status'], $name . ' batch completed');

        $share = bakery_sfb_agent_share_batch($db, ['batch' => $batch['batch_id']], $name);
        assert_true(!empty($share['share']['batch_id']), 'batch shared for ' . $name);

        $posted = bakery_sfb_agent_post_topic($db, [
            'category' => 'fermentation',
            'title' => '76F bulk, 75% water',
            'body' => 'Bulk at 76F for 4 hours with bread flour at 75% hydration.',
            'batch' => $batch['batch_id'],
        ], $name);
        assert_true($posted['topic_id'] > 0, 'topic posted for ' . $name);
        $topic = bakery_sfb_community_topic($db, $posted['topic_id']);
        assert_eq('synthetic', bakery_sfb_normalize_origin($topic['sfb_origin'] ?? ''), 'topic exposes synthetic origin');

        if ($i === 1) {
            $reply = bakery_sfb_agent_reply($db, [
                'topic' => $posted['topic_id'],
                'body' => 'If dough is 76F, keep 75% hydration and bread flour; shorten bulk 30 minutes.',
            ], $names[0]);
            assert_true($reply['reply_id'] > 0, 'reply posted via agent');
        }

        $asked = bakery_sfb_agent_ask_coach($db, [
            'batch' => $batch['batch_id'],
            'body' => 'Bulk at 76F for 4 hours with bread flour at 75%. Should I shorten it?',
        ], $name);
        assert_true($asked['message_id'] > 0, 'private coach question posted for ' . $name);
    }

    $productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
    assert_true($productId > 0, 'catalog has a product for daily-order check');
    $syntheticId = $createdIds[0];
    $dayOfWeek = bakery_standing_day_from_date($date);
    standing_save($db, $syntheticId, $productId, $dayOfWeek, 3);
    bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => true,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $daily = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $daily->execute([$syntheticId, $date]);
    assert_eq(0, (int)$daily->fetchColumn(), 'synthetic standing order produces zero daily_orders');

    $c1Before = $db->query("SELECT COUNT(*) FROM customers WHERE name = 'Customer1'")->fetchColumn();
    $adopt = bakery_sfb_agent_create_baker($db, 'Customer1', '1101', [
        'origin' => 'synthetic',
        'persona' => 'customer1',
        'adopt_reserved' => true,
    ]);
    $c1After = $db->query("SELECT COUNT(*) FROM customers WHERE name = 'Customer1'")->fetchColumn();
    assert_eq((int)$c1Before === 0 ? 1 : (int)$c1Before, (int)$c1After, 'Customer1 is reused, not cloned');
    assert_eq('synthetic', bakery_sfb_normalize_origin($adopt['customer']['sfb_origin'] ?? ''), 'Customer1 origin is synthetic');
    if ((int)$c1Before === 0) {
        $createdIds[] = (int)$adopt['customer']['id'];
    }

    $cliName = 'SFB CLI Baker ' . $suffix;
    $cliCmd = '"' . PHP_BINARY . '" ' . escapeshellarg(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'sfb_agent.php');
    $runCli = function (array $args) use ($cliCmd) {
        $cmd = $cliCmd;
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        $raw = implode("\n", $output);
        return [$code, json_decode($raw, true), $raw];
    };
    [$code, $cliCreated] = $runCli([
        'create-baker',
        '--name=' . $cliName,
        '--origin=synthetic',
        '--persona=beginner',
        '--locale=en',
        '--json',
    ]);
    assert_eq(0, $code, 'CLI create-baker exits 0');
    assert_true(!empty($cliCreated['ok']) && !empty($cliCreated['customer']['id']), 'CLI create-baker returns a baker');
    $createdIds[] = (int)$cliCreated['customer']['id'];
    assert_eq('synthetic', bakery_sfb_normalize_origin($cliCreated['customer']['sfb_origin'] ?? ''), 'CLI baker is synthetic');

    [$code, $cliPosted] = $runCli([
        'post-topic',
        '--customer=' . $cliName,
        '--category=fermentation',
        '--title=76F bulk from CLI',
        '--body=Bulk at 76F for 4 hours with bread flour at 75% hydration.',
        '--json',
    ]);
    assert_eq(0, $code, 'CLI post-topic exits 0');
    assert_true(!empty($cliPosted['ok']) && (int)($cliPosted['topic_id'] ?? 0) > 0, 'CLI posted a topic');

    [$code, $cliErr] = $runCli(['start-batch', '--name=ShouldNotBeACustomer', '--json']);
    assert_true($code !== 0, 'start-batch without --customer fails');
    assert_true(is_array($cliErr) && empty($cliErr['ok']), 'CLI JSON error payload when --customer is missing');

    $verified = bakery_sfb_persona_verify($db, 20);
    if (!$verified['ok']) {
        bakery_sfb_persona_seed($db, 20, true);
        $verified = bakery_sfb_persona_verify($db, 20);
    }
    assert_true($verified['ok'], 'verify-studio: ' . implode('; ', $verified['errors']));
    assert_eq(20, $verified['bakers'], 'verify-studio counts 20 wave-1 bakers');
    assert_eq(1, $verified['customer1'], 'verify-studio Customer1 once');
    assert_eq(1, $verified['customer2'], 'verify-studio Customer2 once');
    assert_eq(0, $verified['standing_orders'], 'verify-studio zero standing orders');

    bakery_sfb_agent_stop_impersonation();
    assert_eq(0, bakery_portal_customer_id(), 'impersonation stopped');
    assert_eq(null, bakery_sfb_agent_impersonating(), 'impersonation flag cleared');
    assert_eq((int)$admin['id'], (int)($_SESSION['user_id'] ?? 0), 'SFAdmin staff session survived impersonation');
} finally {
    $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$date]);
    foreach ($createdIds as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        }
    }
}

$finish();
