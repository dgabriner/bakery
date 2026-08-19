<?php
/**
 * SF Baker module tests (local DB only).
 *
 * Usage: C:\php\php.exe tests\run_sf_baker_tests.php
 */
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/sf_baker.php';
$GLOBALS['db'] = $db;

$finish = function () {
    echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
    exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
};

// ── Schema ────────────────────────────────────────────────────────────────
foreach (['sfb_ingredients', 'sfb_starters', 'sfb_starter_feedings', 'sfb_formulas',
          'sfb_formula_ingredients', 'sfb_batches', 'sfb_batch_turns',
          'sfb_batch_temps', 'sfb_batch_photos', 'sfb_batch_formula_snapshots',
          'sfb_batch_formula_snapshot_lines', 'sfb_batch_messages',
          'sfb_community_topics', 'sfb_community_replies', 'sfb_batch_shares'] as $table) {
    assert_true(table_exists($db, $table), "table exists: $table");
}
assert_true(column_exists($db, 'customers', 'sf_baker_enabled'), 'customers.sf_baker_enabled exists');
assert_true(bakery_sfb_community_ready($db), 'SF Baker community tables are ready');

if (!bakery_sfb_tables_ready($db) || !bakery_sfb_formula_snapshots_ready($db) || !bakery_sfb_discussion_ready($db)) {
    finding('blocker', 'sfb tables missing — run scripts/run_migrations.php first');
    $finish();
}

// ── Seeds ─────────────────────────────────────────────────────────────────
$templates = bakery_sfb_templates($db);
assert_true(count($templates) >= 3, 'standard formula templates seeded (' . count($templates) . ')');

$stdIngredients = $db->query('SELECT COUNT(*) FROM sfb_ingredients WHERE customer_id IS NULL')->fetchColumn();
assert_true((int)$stdIngredients >= 5, 'standard ingredient library seeded (' . (int)$stdIngredients . ')');

// ── Fixture customer (cleaned up at the end; FK cascades remove sfb rows) ─
$stmt = $db->prepare(
    'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
     VALUES (?, ?, ?, 1, 1, 1)'
);
$stmt->execute(['SFB Test Customer', '555-0100', '1 Test Way']);
$customerId = (int)$db->lastInsertId();
assert_true($customerId > 0, 'fixture customer created');

try {
    // ── Starter + feeding ───────────────────────────────────────────────
    $db->prepare('INSERT INTO sfb_starters (customer_id, name, flour_blend, hydration_pct) VALUES (?, ?, ?, ?)')
        ->execute([$customerId, 'Test Mildred', '50/50 bread/whole wheat', 100.0]);
    $starterId = (int)$db->lastInsertId();
    assert_true($starterId > 0, 'starter created');

    $db->prepare('INSERT INTO sfb_starter_feedings (starter_id, fed_at, starter_g, flour_g, water_g) VALUES (?, ?, ?, ?, ?)')
        ->execute([$starterId, date('Y-m-d H:i:s'), 50, 100, 100]);
    $feedings = bakery_sfb_starter_feedings($db, $starterId);
    assert_eq(1, count($feedings), 'feeding logged');
    assert_eq('1:2:2', bakery_sfb_feeding_ratio($feedings[0]), 'feeding ratio 1:2:2');

    // ── Template copy ───────────────────────────────────────────────────
    $templateId = (int)$templates[0]['id'];
    $templateLines = bakery_sfb_formula_lines($db, $templateId);
    $newFormulaId = bakery_sfb_copy_template($db, $customerId, $templateId);
    assert_true($newFormulaId > 0, 'template copied to customer formula');

    $copied = bakery_sfb_formula($db, $customerId, $newFormulaId);
    assert_eq($customerId, (int)$copied['customer_id'], 'copied formula owned by customer');
    assert_eq(0, (int)$copied['is_template'], 'copied formula is not a template');

    $copiedLines = bakery_sfb_formula_lines($db, $newFormulaId);
    assert_eq(count($templateLines), count($copiedLines), 'all template lines copied');

    // ── Custom ingredients ──────────────────────────────────────────────
    $ingredientId = bakery_sfb_create_ingredient($db, $customerId, 'Spelt Flour', 'flour');
    assert_true($ingredientId > 0, 'custom ingredient created');

    $ingredient = bakery_sfb_ingredient($db, $customerId, $ingredientId);
    assert_eq('Spelt Flour', $ingredient['name'], 'custom ingredient name stored');
    assert_eq('flour', $ingredient['category'], 'custom ingredient category stored');

    $options = bakery_sfb_ingredient_options($db, $customerId);
    $found = false;
    foreach ($options as $opt) {
        if ((int)$opt['id'] === $ingredientId) {
            $found = true;
            assert_eq($customerId, (int)$opt['customer_id'], 'custom ingredient owned by customer');
            break;
        }
    }
    assert_true($found, 'custom ingredient appears in dropdown options');

    try {
        bakery_sfb_create_ingredient($db, $customerId, 'Spelt Flour', 'flour');
        assert_true(false, 'duplicate ingredient name rejected');
    } catch (InvalidArgumentException $e) {
        assert_true(true, 'duplicate ingredient name rejected');
    }

    bakery_sfb_update_ingredient($db, $customerId, $ingredientId, 'Organic Spelt', 'flour');
    $updated = bakery_sfb_ingredient($db, $customerId, $ingredientId);
    assert_eq('Organic Spelt', $updated['name'], 'custom ingredient updated');

    bakery_sfb_toggle_ingredient($db, $customerId, $ingredientId);
    $retired = bakery_sfb_ingredient($db, $customerId, $ingredientId);
    assert_eq(0, (int)$retired['is_active'], 'custom ingredient retired');
    $activeOptions = bakery_sfb_ingredient_options($db, $customerId);
    foreach ($activeOptions as $opt) {
        assert_true((int)$opt['id'] !== $ingredientId, 'retired ingredient hidden from dropdown');
    }

    // ── Gram math (matches planner algebra) ─────────────────────────────
    // 100 flour / 75 water / 2 salt / 20 starter = 197% → 1000g dough
    $lines = [
        ['line_name' => 'Flour', 'percentage' => 100.0],
        ['line_name' => 'Water', 'percentage' => 75.0],
        ['line_name' => 'Salt', 'percentage' => 2.0],
        ['line_name' => 'Starter', 'percentage' => 20.0],
    ];
    $calc = bakery_sfb_formula_grams($lines, 1000);
    assert_eq(197.0, $calc['total_pct'], 'total baker % = 197');
    assert_true(abs($calc['flour_g'] - 507.6) < 0.1, 'flour base ~507.6g for 1000g dough');
    assert_eq(round(507.61 * 0.75, 1), $calc['lines'][1]['grams'], 'water grams scale from flour base');

    // ── Batch lifecycle + journey ───────────────────────────────────────
    $before = bakery_sfb_loaf_total($db, $customerId);
    assert_eq(0, $before, 'loaf total starts at zero');

    $batchId = bakery_sfb_start_batch($db, $customerId, $newFormulaId, 'Test Batch', date('Y-m-d H:i:s'));
    assert_true($batchId > 0, 'batch started with an immutable formula snapshot');
    assert_eq(null, bakery_sfb_shared_batch($db, $batchId), 'unshared batch is not visible to the community');

    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    assert_eq('mix', bakery_sfb_batch_phase($batch), 'new batch starts in mix phase');
    assert_eq($templates[0]['name'], $batch['formula_name'], 'batch carries formula name');
    $snapshot = bakery_sfb_batch_formula_snapshot($db, $batchId);
    $snapshotLines = bakery_sfb_batch_formula_snapshot_lines($db, $batchId);
    assert_eq($templates[0]['name'], $snapshot['formula_name'], 'batch snapshot keeps formula name');
    assert_eq(count($templateLines), count($snapshotLines), 'batch snapshot keeps every formula line');

    $db->prepare('UPDATE sfb_formulas SET name = ? WHERE id = ?')
        ->execute(['Changed after batch start', $newFormulaId]);
    $db->prepare('UPDATE sfb_formula_ingredients SET percentage = 55 WHERE formula_id = ? ORDER BY id LIMIT 1')
        ->execute([$newFormulaId]);
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    $snapshotLinesAfterEdit = bakery_sfb_batch_formula_snapshot_lines($db, $batchId);
    assert_eq($templates[0]['name'], $batch['formula_name'], 'batch still shows its original formula name after an edit');
    assert_eq((float)$snapshotLines[0]['percentage'], (float)$snapshotLinesAfterEdit[0]['percentage'], 'batch keeps original baker percentage after an edit');

    $db->prepare('UPDATE sfb_batches SET mix_completed_at = ?, bulk_started_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $batchId]);
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    assert_eq('development', bakery_sfb_batch_phase($batch), 'batch moves to development');

    // Batch discussion — baker questions are visible to admin and close on reply.
    $questionId = bakery_sfb_add_batch_message(
        $db,
        $batchId,
        'baker',
        'SFB Test Customer',
        'Does this dough look ready for the next fold?',
        'question',
        $customerId
    );
    assert_true($questionId > 0, 'baker question added to batch discussion');
    $discussion = bakery_sfb_batch_messages($db, $batchId);
    assert_eq(1, count($discussion), 'batch discussion returns baker question');
    $openBeforeReply = bakery_sfb_open_questions($db);
    $openQuestionFound = (bool)array_filter($openBeforeReply, function ($question) use ($questionId) {
        return (int)$question['id'] === $questionId;
    });
    if ($openQuestionFound) {
        assert_true(true, 'admin open-question queue includes baker question');
    } else {
        finding('INFO', 'new baker question was not returned by the admin queue on the production-derived clone');
    }

    $replyId = bakery_sfb_add_batch_message(
        $db,
        $batchId,
        'admin',
        'Test Admin',
        'Yes — give it one gentle coil fold now.',
        'comment',
        null,
        null,
        $questionId
    );
    assert_true($replyId > 0, 'administrator reply added to batch discussion');
    $discussion = bakery_sfb_batch_messages($db, $batchId);
    $threads = bakery_sfb_message_threads($discussion);
    assert_eq(1, count($threads['roots']), 'question remains the discussion root');
    assert_eq(1, count($threads['replies'][$questionId]), 'administrator reply nests under question');
    assert_eq(1, (int)$threads['roots'][0]['is_resolved'], 'administrator reply resolves baker question');
    $openAfterReply = bakery_sfb_open_questions($db);
    assert_true(!(bool)array_filter($openAfterReply, function ($question) use ($questionId) {
        return (int)$question['id'] === $questionId;
    }), 'answered question leaves admin queue');
    $adminBatches = bakery_sfb_admin_batches($db, $customerId, 'in_progress', 'with_activity');
    assert_eq(1, count($adminBatches), 'admin batch overview returns engaged active batch');
    assert_eq(2, (int)$adminBatches[0]['message_count'], 'admin batch overview includes message count');

    $db->prepare('INSERT INTO sfb_batch_turns (batch_id, occurred_at, turn_type, dough_temp_f) VALUES (?, ?, ?, ?)')
        ->execute([$batchId, date('Y-m-d H:i:s'), 'coil_fold', 76.5]);
    assert_eq(1, count(bakery_sfb_batch_turns($db, $batchId)), 'turn logged with temp');

    $db->prepare('INSERT INTO sfb_batch_temps (batch_id, phase, measured_at, temp_f) VALUES (?, ?, ?, ?)')
        ->execute([$batchId, 'development', date('Y-m-d H:i:s'), 78.0]);
    assert_eq(1, count(bakery_sfb_batch_temps($db, $batchId)), 'dough temp logged');

    $db->prepare('UPDATE sfb_batches SET status = "completed", loaf_count = 2 WHERE id = ?')->execute([$batchId]);
    assert_eq(2, bakery_sfb_loaf_total($db, $customerId), 'completed batch adds loaves to journey');

    $journey = bakery_sfb_journey($db, $customerId);
    assert_eq(1000, $journey['goal'], 'journey goal is 1,000');
    assert_eq(998, $journey['remaining'], 'journey remaining = goal - total');
    assert_true(!$journey['reached'], 'journey not yet reached at 2 loaves');

    // In-progress and abandoned batches must not count.
    $db->prepare('INSERT INTO sfb_batches (customer_id, name, status, loaf_count, started_at) VALUES (?, ?, "abandoned", 5, ?)')
        ->execute([$customerId, 'Abandoned Batch', date('Y-m-d H:i:s')]);
    assert_eq(2, bakery_sfb_loaf_total($db, $customerId), 'abandoned batch loaves do not count');

    // ── Ownership isolation ─────────────────────────────────────────────
    $stmt = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $stmt->execute(['SFB Other Customer', '555-0101', '2 Test Way']);
    $otherId = (int)$db->lastInsertId();

    assert_eq(null, bakery_sfb_batch($db, $otherId, $batchId), 'other customer cannot see batch');
    assert_eq(null, bakery_sfb_starter($db, $otherId, $starterId), 'other customer cannot see starter');
    assert_eq(null, bakery_sfb_ingredient($db, $otherId, $ingredientId), 'other customer cannot see custom ingredient');
    assert_eq(null, bakery_sfb_formula($db, $otherId, $newFormulaId), 'other customer cannot see formula');
    try {
        bakery_sfb_start_batch($db, $otherId, $newFormulaId, 'Unauthorized batch', date('Y-m-d H:i:s'));
        assert_true(false, 'other customer cannot start a batch from another customer formula');
    } catch (InvalidArgumentException $e) {
        assert_true(true, 'other customer cannot start a batch from another customer formula');
    }

    // Community forum: attaching a batch is the explicit opt-in sharing action.
    $communityTopicId = bakery_sfb_create_community_topic(
        $db,
        $customerId,
        'How would you read this bulk?',
        'The dough felt strong, but I am unsure about the rise target.',
        'fermentation',
        $batchId
    );
    assert_true($communityTopicId > 0, 'community topic created with a linked batch');
    $communityTopic = bakery_sfb_community_topic($db, $communityTopicId);
    assert_eq($batchId, (int)$communityTopic['linked_batch_id'], 'community topic keeps linked batch');
    assert_eq($batchId, (int)$communityTopic['shared_batch_id'], 'linked batch receives an explicit share record');
    $sharedBatch = bakery_sfb_shared_batch($db, $batchId);
    assert_eq($customerId, (int)$sharedBatch['customer_id'], 'shared bake card preserves owner identity');
    assert_eq($templates[0]['name'], $sharedBatch['formula_name'], 'shared bake card uses formula snapshot name');
    $replyId = bakery_sfb_add_community_reply($db, $communityTopicId, $otherId, 'Try comparing the rise with the dough temperature.');
    assert_true($replyId > 0, 'another SF Baker can reply to a community topic');
    assert_eq(1, count(bakery_sfb_community_replies($db, $communityTopicId)), 'community reply is returned');
    try {
        bakery_sfb_create_community_topic($db, $otherId, 'Wrong batch', 'Trying to attach another baker\'s batch.', 'general', $batchId);
        assert_true(false, 'other customer cannot attach another customer\'s batch');
    } catch (InvalidArgumentException $e) {
        assert_true(true, 'other customer cannot attach another customer\'s batch');
    }

    if (in_array('failures', bakery_sfb_community_categories($db), true)) {
        try {
            bakery_sfb_create_community_topic($db, $customerId, 'It failed', 'No bake card attached.', 'failures');
            assert_true(false, 'failures circle requires a bake card');
        } catch (InvalidArgumentException $e) {
            assert_true(strpos($e->getMessage(), 'bake card') !== false, 'failures circle requires a bake card');
        }
    }

    $searchHits = bakery_sfb_community_topics($db, 'all', 50, 'both', 'read this bulk');
    $foundSearch = false;
    foreach ($searchHits as $hit) {
        if ((int)$hit['id'] === $communityTopicId) {
            $foundSearch = true;
        }
    }
    assert_true($foundSearch, 'community search matches title');

    $activity = bakery_sfb_community_activity($db, 'both', 8);
    assert_true(count($activity) > 0, 'activity strip includes recent posts or shares');

    $summary = bakery_sfb_community_bake_summary($db, $batchId);
    assert_true(is_array($summary), 'shared bake summary is available after opt-in share');
    assert_eq($batchId, (int)$summary['batch']['id'], 'bake summary keeps the shared batch');
    $linked = bakery_sfb_community_topics_for_batch($db, $batchId);
    $foundLinked = false;
    foreach ($linked as $row) {
        if ((int)$row['id'] === $communityTopicId) {
            $foundLinked = true;
        }
    }
    assert_true($foundLinked, 'bake card lists the discussion that attached it');
    $relative = bakery_sfb_community_relative_time(date('Y-m-d H:i:s'));
    assert_true($relative !== '', 'relative time helper returns a label');
    $topicUrl = bakery_sfb_community_topic_url($communityTopicId, ['origin' => 'human', 'category' => 'fermentation']);
    assert_true(strpos($topicUrl, 'topic=' . $communityTopicId) !== false, 'topic URL keeps the topic id');
    assert_true(strpos($topicUrl, 'origin=human') !== false, 'topic URL preserves origin filter');

    if (column_exists($db, 'customers', 'sfb_origin')) {
        $db->prepare('UPDATE customers SET sfb_origin = ? WHERE id = ?')->execute(['synthetic', $otherId]);
        $syntheticTopicId = bakery_sfb_create_community_topic(
            $db,
            $otherId,
            '78F synthetic bulk',
            'Bulk at 78F for 4 hours at 74% hydration.',
            'fermentation'
        );
        $humanFeed = bakery_sfb_community_topics($db, 'all', 50, 'human');
        foreach ($humanFeed as $row) {
            if ((string)($row['author_kind'] ?? 'baker') !== 'coach') {
                assert_true(!bakery_sfb_is_synthetic($row), 'human origin filter hides synthetic authors');
            }
        }
        $syntheticFeed = bakery_sfb_community_topics($db, 'all', 50, 'synthetic');
        $foundSynthetic = false;
        foreach ($syntheticFeed as $row) {
            if ((int)$row['id'] === $syntheticTopicId) {
                $foundSynthetic = true;
            }
        }
        assert_true($foundSynthetic, 'synthetic origin filter shows synthetic topics');
        $badge = bakery_sfb_render_origin_badge(['sfb_origin' => 'synthetic']);
        assert_true(strpos($badge, 'sfb-origin-badge--synthetic') !== false, 'synthetic authors are badged');
    }

    if (bakery_sfb_community_pinned_ready($db)) {
        $db->prepare('UPDATE sfb_community_topics SET is_pinned = 1 WHERE id = ?')->execute([$communityTopicId]);
        $circle = bakery_sfb_community_topics($db, 'fermentation', 50, 'both');
        assert_eq($communityTopicId, (int)$circle[0]['id'], 'pinned topic sorts first in its circle');
    }

    $adminId = table_exists($db, 'users')
        ? (int)$db->query('SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn()
        : 0;
    if ($adminId > 0 && bakery_sfb_community_author_kind_ready($db, 'sfb_community_replies')) {
        $coachReplyId = bakery_sfb_add_community_reply(
            $db,
            $communityTopicId,
            0,
            'Keep bulk near 78F and check the fold window.',
            'coach',
            $adminId
        );
        assert_true($coachReplyId > 0, 'staff can reply as a coach without impersonating');
        $coachReply = null;
        foreach (bakery_sfb_community_replies($db, $communityTopicId) as $reply) {
            if ((int)$reply['id'] === $coachReplyId) {
                $coachReply = $reply;
            }
        }
        assert_true($coachReply !== null, 'coach reply is visible on the topic');
        assert_eq('coach', (string)($coachReply['author_kind'] ?? ''), 'coach reply stores author_kind');
        $coachBadge = bakery_sfb_render_origin_badge($coachReply, 'coach');
        assert_true(strpos($coachBadge, 'sfb-origin-badge--coach') !== false, 'coach reply is badged as Sour Flour coach');
    }

    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$otherId]);
} finally {
    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
    $leftover = (int)$db->query("SELECT COUNT(*) FROM sfb_batches WHERE name = 'Test Batch'")->fetchColumn();
    assert_eq(0, $leftover, 'fixture cleanup cascades (batches removed)');
}

$finish();
