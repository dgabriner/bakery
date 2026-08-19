<?php
/**
 * Content, trust, and quality: library i18n, synthetic eval, unlabeled authors,
 * ops firewall, bilingual pins, demo refused off bakerysf_test.
 *
 * Usage: C:\php\php.exe tests/run_sfb_content_trust_tests.php
 */
require_once __DIR__ . '/isolate_test_db.php';
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/sf_baker.php';
require_once dirname(__DIR__) . '/includes/sfb_agent.php';
require_once dirname(__DIR__) . '/includes/daily_order_generation.php';
require_once dirname(__DIR__) . '/includes/i18n.php';

$GLOBALS['db'] = $db;

$finish = function () {
    echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
    exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
};

$canonical = bakery_sfb_library_kind('canonical');
$trouble = bakery_sfb_library_kind('troubleshooting');
assert_true(count($canonical) >= 12, 'library has at least 12 canonical pieces (' . count($canonical) . ')');
assert_true(count($trouble) >= 20, 'library has at least 20 troubleshooting pieces (' . count($trouble) . ')');

$en = bakery_load_lang_catalog('en');
$es = bakery_load_lang_catalog('es');
assert_true(isset($en['sfb.community_disclosure']) && trim($en['sfb.community_disclosure']) !== '', 'English disclosure copy exists');
assert_true(isset($es['sfb.community_disclosure']) && trim($es['sfb.community_disclosure']) !== '', 'Spanish disclosure copy exists');
assert_true($en['sfb.community_disclosure'] !== $es['sfb.community_disclosure'], 'disclosure is translated');
assert_true(strpos($en['sfb.community_disclosure'], 'synthetic') !== false, 'disclosure names synthetic bakers');
assert_true(
    strpos(strtolower($en['sfb.community_disclosure']), 'real baker') !== false
        || strpos($en['sfb.community_disclosure'], 'labeled') !== false,
    'disclosure says names are labeled'
);

$missing = [];
$copied = [];
foreach (bakery_sfb_library_i18n_keys() as $key) {
    $enText = trim((string)($en[$key] ?? ''));
    $esText = trim((string)($es[$key] ?? ''));
    if ($enText === '' || $esText === '') {
        $missing[] = $key;
        continue;
    }
    if ($enText === $esText) {
        $copied[] = $key;
    }
}
assert_eq([], $missing, 'every library key exists in en and es');
assert_eq([], $copied, 'Spanish library copy is not English pasted');

$_SESSION = [];
$_COOKIE = [];
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
$enTitle = bakery_t('sfb.debrief_fermentation_title');
$enTrouble = bakery_t('sfb.trouble_acetone_next');
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
$esTitle = bakery_t('sfb.debrief_fermentation_title');
$esTrouble = bakery_t('sfb.trouble_acetone_next');
assert_true($enTitle !== '' && $esTitle !== '' && $enTitle !== $esTitle, 'debrief fermentation renders in en and es');
assert_true($enTrouble !== '' && $esTrouble !== '' && $enTrouble !== $esTrouble, 'troubleshooting next-action renders in en and es');

$pass = bakery_sfb_eval_synthetic_text(
    'Bulk was 4 hours at 78F, 75% hydration, bread flour. The dough felt slack after the third fold.',
    ['origin' => 'synthetic']
);
assert_eq(true, $pass['ok'], 'process-fact synthetic post passes eval');

$noFact = bakery_sfb_eval_synthetic_text('Love this community, great vibes today.', ['origin' => 'synthetic']);
assert_eq(false, $noFact['ok'], 'post with no process fact is rejected');
assert_true(in_array('no_process_fact', $noFact['reasons'], true), 'no_process_fact is reported');

$secret = bakery_sfb_eval_synthetic_text(
    'Bulk 4 hours at 78F. The Fairmount standing order and pack list are 40 loaves.',
    ['origin' => 'synthetic']
);
assert_eq(false, $secret['ok'], 'wholesale secret post is rejected');
assert_true(in_array('wholesale_secret', $secret['reasons'], true), 'wholesale_secret is reported');

$humanClaim = bakery_sfb_eval_synthetic_text(
    'I am a real baker. Bulk was 4 hours at 78F with bread flour.',
    ['origin' => 'synthetic']
);
assert_eq(false, $humanClaim['ok'], 'unlabeled human claim is rejected');
assert_true(in_array('unlabeled_human_claim', $humanClaim['reasons'], true), 'unlabeled_human_claim is reported');

$wrongOrigin = bakery_sfb_eval_synthetic_text(
    'Bulk was 4 hours at 78F, 75% hydration, bread flour.',
    ['origin' => 'human']
);
assert_eq(false, $wrongOrigin['ok'], 'eval rejects a non-synthetic origin context');

$ask = bakery_sfb_library_ask_url('gummy_crumb');
assert_true(strpos($ask, 'library=gummy_crumb') !== false, 'Ask URL carries the library slug');
assert_true(strpos($ask, 'compose=1') !== false, 'Ask URL opens compose');
assert_true(strpos($ask, '#start-discussion') !== false, 'Ask URL lands on the compose panel');
assert_true(strpos($ask, 'category=failures') === false, 'Ask URL does not force failures without a bake card');

$askWithBatch = bakery_sfb_library_ask_url('gummy_crumb', 42);
assert_true(strpos($askWithBatch, 'batch=42') !== false, 'Ask URL attaches the bake card');
assert_true(strpos($askWithBatch, 'category=failures') !== false, 'Ask URL uses the failures circle when a bake is attached');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
$prefill = bakery_sfb_library_compose_prefill('fermentation');
assert_true(is_array($prefill), 'library prefill exists for a canonical slug');
assert_true(trim((string)($prefill['title'] ?? '')) !== '', 'prefill has a title');
assert_true(trim((string)($prefill['body'] ?? '')) !== '', 'prefill has a body');
assert_eq('fermentation', (string)($prefill['category'] ?? ''), 'prefill keeps the piece circle');
$failPrefill = bakery_sfb_library_compose_prefill('gummy_crumb');
assert_eq('general', (string)($failPrefill['category'] ?? ''), 'failure prefill falls back to general without a bake');
$failPrefillBatch = bakery_sfb_library_compose_prefill('gummy_crumb', 42);
assert_eq('failures', (string)($failPrefillBatch['category'] ?? ''), 'failure prefill stays in failures with a bake');

$diagnose = bakery_sfb_library_diagnose_suggestions(['name' => 'Test loaf', 'status' => 'active'], [], [], []);
assert_true(in_array('dough_temp', $diagnose, true), 'missing temps suggest dough temperature');
assert_true(in_array('strength', $diagnose, true), 'missing turns suggest strength');

assert_eq(true, bakery_sfb_agent_demo_database_allowed('bakerysf_test'), 'demo allowed on bakerysf_test');
assert_eq(false, bakery_sfb_agent_demo_database_allowed('bakerysf_local'), 'demo refused on bakerysf_local');
assert_eq(false, bakery_sfb_agent_demo_database_allowed('bakerysf'), 'demo refused on production bakerysf');
$demoSource = file_get_contents(dirname(__DIR__) . '/includes/sfb_agent.php');
assert_true(strpos($demoSource, 'bakery_sfb_agent_assert_demo_target') !== false, 'demo command uses the test-only guard');
bakery_sfb_agent_assert_demo_target($db);
assert_true(true, 'demo guard accepts the isolated test database');

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
assert_true($productId > 0, 'catalog has a product');
$date = '2099-12-30';
$dayOfWeek = bakery_standing_day_from_date($date);
$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$createdIds = [];
$topicIds = [];
$unlabeledId = 0;

try {
    bakery_sfb_agent_ensure_admin($db);
    bakery_sfb_agent_login($db);

    $created = bakery_sfb_agent_create_customer($db, 'SFB Trust Synthetic ' . $suffix, '');
    $synthetic = $created['customer'];
    $syntheticId = (int)$synthetic['id'];
    $createdIds[] = $syntheticId;
    assert_eq('synthetic', (string)$synthetic['sfb_origin'], 'agent baker is synthetic');

    standing_save($db, $syntheticId, $productId, $dayOfWeek, 3);
    bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => true,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $countStmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $countStmt->execute([$syntheticId, $date]);
    assert_eq(0, (int)$countStmt->fetchColumn(), 'synthetic excluded from bakery_generate_daily_orders_from_standing');

    $asSynthetic = bakery_sfb_agent_login_as_customer($db, $syntheticId);
    $topicIds[] = bakery_sfb_create_community_topic(
        $db,
        (int)$asSynthetic['id'],
        '78F bulk, 74% hydration',
        'Bulk at 78F for 4 hours, 74% hydration, bread flour. What would you change?',
        'fermentation'
    );
    $syntheticNoFact = false;
    try {
        bakery_sfb_create_community_topic(
            $db,
            (int)$asSynthetic['id'],
            'Just vibes',
            'Love this community, great vibes today.',
            'general'
        );
    } catch (Throwable $e) {
        $syntheticNoFact = strpos($e->getMessage(), 'no_process_fact') !== false;
    }
    assert_true($syntheticNoFact, 'synthetic GUI post without a process fact is refused');

    $humanSql = 'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active';
    $humanSql .= column_exists($db, 'customers', 'sfb_origin') ? ', sfb_origin' : '';
    $humanSql .= ') VALUES (?, ?, ?, 1, 1, 1';
    $humanSql .= column_exists($db, 'customers', 'sfb_origin') ? ', ?' : '';
    $humanSql .= ')';
    $humanParams = ['SFB Trust Human ' . $suffix, '555-0188', '3 Test Way'];
    if (column_exists($db, 'customers', 'sfb_origin')) {
        $humanParams[] = 'human';
    }
    $db->prepare($humanSql)->execute($humanParams);
    $humanId = (int)$db->lastInsertId();
    $createdIds[] = $humanId;
    $topicIds[] = bakery_sfb_create_community_topic(
        $db,
        $humanId,
        'Just vibes',
        'Love this community, great vibes today.',
        'general'
    );
    assert_true($topicIds[count($topicIds) - 1] > 0, 'human can post without a process fact');
    $listed = bakery_sfb_community_topics($db, 'fermentation', 50, 'both', '');
    $found = null;
    foreach ($listed as $row) {
        if ((int)$row['id'] === $topicIds[0]) {
            $found = $row;
            break;
        }
    }
    assert_true($found !== null, 'synthetic community topic is listed');
    assert_eq('synthetic', bakery_sfb_normalize_origin($found['sfb_origin'] ?? ''), 'listed topic exposes origin');
    $badge = bakery_sfb_render_origin_badge($found, (string)($found['author_kind'] ?? 'baker'));
    assert_true(strpos($badge, 'sfb-origin-badge--synthetic') !== false, 'synthetic cannot appear unlabeled');

    $beforeHuman = bakery_sfb_human_loaf_total($db);
    $db->prepare(
        'INSERT INTO sfb_batches (customer_id, name, status, loaf_count, started_at)
         VALUES (?, ?, "completed", 7, NOW())'
    )->execute([$syntheticId, 'Trust synthetic loaves ' . $suffix]);
    assert_eq($beforeHuman, bakery_sfb_human_loaf_total($db), 'synthetic loaves are excluded from human journey total');

    if (bakery_sfb_library_pin_schema_ready($db)) {
        $pinned = bakery_sfb_upsert_library_pins($db, (int)($_SESSION['user_id'] ?? 0));
        assert_true($pinned >= 32, 'library pins upserted (' . $pinned . ')');
        $pinStmt = $db->prepare('SELECT * FROM sfb_community_topics WHERE body = ? LIMIT 1');
        $pinStmt->execute([bakery_sfb_library_body_sentinel('fermentation')]);
        $pinRow = $pinStmt->fetch();
        assert_true(is_array($pinRow), 'fermentation library pin exists');
        assert_eq(1, (int)$pinRow['is_pinned'], 'library topic is pinned');
        assert_eq('coach', (string)$pinRow['author_kind'], 'library topic is coach-authored');

        $visible = bakery_sfb_community_topic($db, (int)$pinRow['id']);
        assert_true(is_array($visible), 'coach library pin is visible without a baker customer');
        $GLOBALS['bakery_i18n_catalog'] = null;
        bakery_set_locale('en', false);
        $enCopy = bakery_sfb_community_topic_copy($visible);
        $GLOBALS['bakery_i18n_catalog'] = null;
        bakery_set_locale('es', false);
        $esCopy = bakery_sfb_community_topic_copy($visible);
        assert_true($enCopy['title'] !== '' && $esCopy['title'] !== '' && $enCopy['title'] !== $esCopy['title'], 'pinned debrief title renders in en and es');
        assert_true($enCopy['body'] !== '' && $esCopy['body'] !== '' && $enCopy['body'] !== $esCopy['body'], 'pinned debrief body renders in en and es');
        $coachBadge = bakery_sfb_render_origin_badge($visible, (string)($visible['author_kind'] ?? 'coach'));
        assert_true(strpos($coachBadge, 'sfb-origin-badge--coach') !== false, 'pinned library post is labeled coach');
        $ensured = bakery_sfb_ensure_library_pins($db, (int)($_SESSION['user_id'] ?? 0));
        $ensuredAgain = bakery_sfb_ensure_library_pins($db, (int)($_SESSION['user_id'] ?? 0));
        assert_eq($ensured, $ensuredAgain, 'ensure_library_pins is idempotent');
        assert_true($ensured >= 32, 'ensure_library_pins reports a full library');
    } else {
        assert_true(false, 'library pin schema (is_pinned, coach author, nullable customer) is ready');
    }

    if (bakery_sfb_library_pin_schema_ready($db)) {
        $db->prepare(
            'INSERT INTO sfb_community_topics (author_customer_id, author_kind, category, title, body, is_pinned)
             VALUES (NULL, \'baker\', \'general\', ?, ?, 0)'
        )->execute(['Unlabeled baker ' . $suffix, 'This baker has no origin.']);
        $unlabeledId = (int)$db->lastInsertId();
        $unlabeled = bakery_sfb_community_topic($db, $unlabeledId);
        assert_eq(null, $unlabeled, 'baker topic without origin/customer is not visible');
        $feed = bakery_sfb_community_topics($db, 'general', 50, 'both', 'Unlabeled baker');
        $leaked = false;
        foreach ($feed as $row) {
            if ((int)$row['id'] === $unlabeledId) {
                $leaked = true;
            }
        }
        assert_eq(false, $leaked, 'unlabeled baker topic is excluded from the feed');
    }

    $threw = false;
    try {
        bakery_sfb_create_community_topic($db, 0, 'No author', 'Bulk at 78F for 4 hours.', 'general');
    } catch (Throwable $e) {
        $threw = true;
    }
    assert_true($threw, 'community create without a baker identity is refused');
} finally {
    $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$date]);
    if ($unlabeledId > 0) {
        $db->prepare('DELETE FROM sfb_community_topics WHERE id = ?')->execute([$unlabeledId]);
    }
    foreach ($topicIds as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM sfb_community_topics WHERE id = ?')->execute([$id]);
        }
    }
    foreach ($createdIds as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        }
    }
}

$communitySource = file_get_contents(dirname(__DIR__) . '/sfb_community.php');
assert_true(strpos($communitySource, 'sfb.community_disclosure') !== false, 'community hero includes disclosure');
assert_true(strpos($communitySource, 'sfb.community_human_loaves') !== false, 'community hero includes the human loaf total');
assert_true(strpos($communitySource, 'sfb.community_process_hint') !== false, 'compose includes the process-fact hint');
assert_true(strpos($communitySource, 'bakery_sfb_ensure_library_pins') !== false, 'community ensures library pins instead of rewriting them');
assert_eq(1, substr_count($communitySource, 'id="start-discussion"'), 'community has a single compose panel');
assert_eq(1, substr_count($communitySource, 'id="sfbCommunityCategory"'), 'community has a single category select');
assert_true(strpos(file_get_contents(dirname(__DIR__) . '/sfb_resources.php'), 'bakery_sfb_library_ask_url') !== false, 'resources cards can ask the circle');
assert_true(strpos(file_get_contents(dirname(__DIR__) . '/sfb_batch.php'), 'sfb_library_panel.php') !== false, 'batch page includes the review/diagnose panel');
assert_true(strpos(file_get_contents(dirname(__DIR__) . '/sfb_dashboard.php'), 'sfb.library_review_open') !== false, 'dashboard offers a last-bake review');
assert_true(is_file(dirname(__DIR__) . '/docs/sfb_synthetic_eval.md'), 'synthetic eval document exists');

$finish();
